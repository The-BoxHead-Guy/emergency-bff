# PHP sessions: one request, one active session

Applies to: host integrators and `dataemergencia/emergency-bff` maintainers.

Status: research note. It explains PHP's session model and the integration
options. It is not an API contract; the package contract stays in
[Session and CSRF](../bff/session-and-csrf.md).

All behaviour below was verified on **PHP 8.4.27** with the default `files`
session handler unless stated otherwise. Session behaviour that depends on the
PHP version, the session handler or `php.ini` is marked
**[version/handler-dependent]**.

## 1. What a PHP session is, in plain language

HTTP is stateless: each request arrives with no memory of the previous one.
A PHP session is the standard way to give one browser a small server-side
"locker" that survives between requests.

The pieces:

1. **Session id** — a random string, 32 hex characters by default
   (`session.sid_length=32`, `session.sid_bits_per_character=4`, so 128 bits).
2. **Session cookie** — the browser stores the id under a cookie name
   (`session.name`, default `PHPSESSID`) and sends it back on every request to
   the same host and path.
3. **Server-side storage** — PHP reads the id, opens the matching storage entry
   (by default a file in `session.save_path` named `sess_<id>`), unserializes
   the data into `$_SESSION`, and writes it back when the request ends.
4. **Lifecycle** — `session_start()` opens (or creates) the session;
   `session_write_close()` or the end of the request writes and closes it;
   `session_regenerate_id()` swaps the id; `session_destroy()` deletes the
   server-side data.

```text
Browser                          PHP process
  |  Cookie: PHPSESSID=abc123        |
  |--------------------------------->| session_start()
  |                                  |   read sess_abc123 -> $_SESSION
  |                                  |   script runs, may write $_SESSION
  |<---------------------------------|   write sess_abc123 (or close early)
  |  Set-Cookie: PHPSESSID=abc123    |
```

Two properties drive every integration problem in this document:

- The **cookie only carries an id**. All state is server-side. Two cookie names
  can still point at the same server-side entry if they carry the same id.
- PHP keeps **one** session open per request. It is a process-wide global
  (`$_SESSION`, the id, the name), not an object you can instantiate twice.

## 2. The functions and directives that matter

### 2.1 Functions

| Function                                                  | What it actually does                                                                                                                                                                                   | Practical gotcha                                                                                                                                                                                                                                                                                                           |
| --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `session_start()`                                         | Resolves the id (from the cookie named `session.name`, or a previously set `session_id()`), locks and reads the storage, fills `$_SESSION`, sends the session cookie if needed. Returns `true`/`false`. | Since PHP 7.1 it returns `false` and leaves `$_SESSION` uninitialized on failure. Calling it while a session is already active is **not** an error: PHP emits `E_NOTICE: Ignoring session_start() because a session is already active` and returns `true` (verified on 8.4.27).                                            |
| `session_name()`                                          | Gets or sets `session.name`; returns the previous name.                                                                                                                                                 | Must be called **before** `session_start()`. After headers are sent, or while a session is active, it fails with `E_WARNING` and returns `false` (verified). Since 7.2 it checks the session status, not only the cookie state. The name is reset to `session.name` on every request, so you must set it on every request. |
| `session_set_cookie_params()`                             | Sets the cookie attributes used when `session_start()` sends the cookie: `lifetime`, `path`, `domain`, `secure`, `httponly`, `samesite`.                                                                | Must be called **before** `session_start()`. It configures the _next_ cookie, not an existing one. `domain` is not settable through the BFF's `EMERGENCY_BFF_*` variables; it comes from `session.cookie_domain`.                                                                                                          |
| `session_write_close()`                                   | Writes `$_SESSION` to storage and releases the lock. The session becomes inactive; the id stays readable via `session_id()`.                                                                            | This is how one request can use two sessions _sequentially_. It does **not** clear the id — see §3.3, this is the trap.                                                                                                                                                                                                    |
| `session_regenerate_id(bool $delete_old_session = false)` | Issues a new id, keeps the data, sends a new cookie.                                                                                                                                                    | With `true` it **deletes the old storage entry**. Anything else holding the old id (another cookie, another tab, a host login) is logged out.                                                                                                                                                                              |
| `session_destroy()`                                       | Deletes the current session's server-side data.                                                                                                                                                         | It does **not** unset `$_SESSION` and does **not** expire the browser cookie. On 8.4.27 `session_id()` becomes empty and `session_status()` returns `PHP_SESSION_NONE` after it (verified), but the browser keeps sending the dead cookie.                                                                                 |
| `session_status()`                                        | Returns `PHP_SESSION_DISABLED` (0), `PHP_SESSION_NONE` (1) or `PHP_SESSION_ACTIVE` (2).                                                                                                                 | The only reliable "is a session open?" check. `session_id() !== ''` is not equivalent: the id survives `session_write_close()`.                                                                                                                                                                                            |
| `session_abort()`                                         | Discards changes and closes, like `session_write_close()` without writing.                                                                                                                              | Rarely useful; still leaves the id set.                                                                                                                                                                                                                                                                                    |
| `session_id(?string $id)`                                 | Gets the id, or sets it when called before `session_start()`.                                                                                                                                           | Setting `''` means "generate a new one". Setting an id makes PHP use it without consulting the cookie.                                                                                                                                                                                                                     |

### 2.2 Directives

Defaults below are from the shipped `php.ini` of PHP 8.4.27.

| Directive                                               | Default         | Meaning                                                                                                                                                              | Practical implication                                                                                                                                                                                                                        |
| ------------------------------------------------------- | --------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `session.name`                                          | `PHPSESSID`     | Cookie name that carries the id.                                                                                                                                     | Every app on the same host and path that keeps the default shares one cookie name. Set a distinct name per app.                                                                                                                              |
| `session.use_strict_mode`                               | `0`             | When `1`, PHP rejects a client-supplied id that has no existing storage entry and generates a new one (with handlers that support id validation, including `files`). | Without it, an attacker can pin any id (session fixation). Verified over HTTP: with `1`, an unknown cookie id is replaced; with `0` it is adopted.                                                                                           |
| `session.gc_maxlifetime`                                | `1440` (24 min) | Age after which idle storage is treated as garbage.                                                                                                                  | The **real** idle limit is the smaller of this and any application TTL. A 1-hour application TTL with a 24-minute GC lifetime expires after 24 minutes.                                                                                      |
| `session.gc_probability` / `session.gc_divisor`         | `1` / `1000`    | Probability that a request runs GC.                                                                                                                                  | GC is probabilistic: storage may survive much longer than `gc_maxlifetime`, or be removed by an external cron/systemd timer. Many Linux distributions set `gc_probability=0` and clean the directory externally **[environment-dependent]**. |
| `session.cookie_samesite`                               | empty           | `Lax`, `Strict`, `None`.                                                                                                                                             | Empty means the browser default (modern browsers treat it as `Lax`). `None` requires `Secure`.                                                                                                                                               |
| `session.cookie_secure`                                 | empty (off)     | Send the cookie only over HTTPS.                                                                                                                                     | Must be `true`/`1` on HTTPS. The `__Host-`/`__Secure-` cookie prefixes require it.                                                                                                                                                           |
| `session.cookie_httponly`                               | empty (off)     | Hide the cookie from JavaScript.                                                                                                                                     | Always enable for session cookies.                                                                                                                                                                                                           |
| `session.save_path`                                     | empty           | Where the `files` handler writes `sess_<id>`. Empty means the system temp directory.                                                                                 | Must be writable by PHP-FPM, not world-readable, and **shared** across app servers.                                                                                                                                                          |
| `session.lazy_write`                                    | `1` (On)        | Skip the write when `$_SESSION` did not change.                                                                                                                      | With the `files` handler, an unchanged session may not refresh the storage mtime, so GC can collect a session that is being polled. Writing anything on each request (a timestamp, a counter) keeps it fresh.                                |
| `session.cookie_lifetime`                               | `0`             | `0` = browser-session cookie (deleted when the browser closes).                                                                                                      | A server-side TTL does not survive a browser restart if the cookie has lifetime `0`.                                                                                                                                                         |
| `session.sid_length` / `session.sid_bits_per_character` | `32` / `4`      | Id entropy.                                                                                                                                                          | **Deprecated in PHP 8.4** (verified: `ini_set()` raises `E_DEPRECATED`). Do not build new code on them.                                                                                                                                      |

## 3. One active session per request

### 3.1 The rule

PHP's session extension is request-global state: one name, one id, one
`$_SESSION` array, one lock. You can use several sessions **sequentially** in a
single request (`start` → `write_close` → change name → `start`), but never two
at the same time.

Consequences:

- A library that calls `session_start()` cannot know whether the application
  already opened a session for its own purposes.
- Changing `session_name()` while a session is active does nothing except emit
  `E_WARNING: Session name cannot be changed when a session is active`
  (verified).
- Whichever code calls `session_start()` first decides the storage for the
  whole request, unless the next caller explicitly changes name **and** id.

### 3.2 What `NativeSessionStore::start()` does

`packages/emergency-bff/src/Session/NativeSessionStore.php:65-88`:

```php
private function start(): void
{
    if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
        $this->started = true;          // line 68: marks itself as ready

        return;                          // line 70
    }

    if (headers_sent()) {
        return;                          // line 74: silent no-op
    }

    session_name($this->sessionName);    // line 77
    session_set_cookie_params([...]);    // lines 78-84
    session_start();                     // line 86
    $this->started = true;
}
```

The constructor calls `start()` immediately
(`NativeSessionStore.php:21`), and `Bootstrap::create()` constructs the store
(`src/Support/Bootstrap.php:37-43`). So the BFF session starts during
`Bootstrap::create()`, on **every** request that builds the kernel — including
`GET /health`. Verified against the real package: `/health` returns
`Set-Cookie: de_bff_emergency=...` and creates a session file.

Two silent failure modes follow from the early return:

1. **A host session is already active.** `session_status()` is
   `PHP_SESSION_ACTIVE`, so the method returns without calling
   `session_name()`. The store then reads and writes the **host's** session and
   never sets `de_bff_emergency`. Nothing is logged; the BFF appears to work
   until the host's session expires or is destroyed.
2. **Output was already sent.** `headers_sent()` returns early _without_
   setting `$this->started`. Every later `get()` returns the default, every
   `set()` writes to an in-memory `$_SESSION` that is never persisted, and the
   BFF answers `403 csrf_failed` on the first write because the token it issued
   was never stored.

### 3.3 The trap after `session_write_close()`

`session_write_close()` ends the session but **keeps the id**. On the next
`session_start()`, PHP does not look at the second cookie: it reuses the id it
already has. The new name only changes _which cookie name_ carries that same
id.

Verified matrix (real package, real HTTP requests, `files` handler, PHP 8.4.27):

| Host code before `Bootstrap::create()`                                                                          | BFF session name          | BFF session id           | Result                                                                              |
| --------------------------------------------------------------------------------------------------------------- | ------------------------- | ------------------------ | ----------------------------------------------------------------------------------- |
| `session_start()` and leave it open                                                                             | `mirror_session` (host's) | host id                  | BFF writes `de_bff_csrf_token` into the login session; no `de_bff_emergency` cookie |
| `session_write_close()` only                                                                                    | `de_bff_emergency`        | **host id**              | Two cookies, **one** session file. Host data and BFF data mixed                     |
| `session_write_close()` + `session_id('')`                                                                      | `de_bff_emergency`        | new id **every request** | BFF session never persists; CSRF token changes on every request                     |
| `session_write_close()` + `session_name('de_bff_emergency')` + `session_id($_COOKIE['de_bff_emergency'] ?? '')` | `de_bff_emergency`        | stable BFF id            | Correct isolation; host and BFF have separate ids and files                         |

The third row is the one that surprises people. On PHP 8.4 the client-supplied
id is resolved once per request; after you clear it, a later `session_start()`
generates a fresh id instead of reading the second cookie. The fourth row works
because the id is supplied explicitly.

This matters because `SessionManager::storeIdentity()` calls
`session_regenerate_id(true)` (`src/Session/SessionManager.php:58`,
`NativeSessionStore.php:46`), which **deletes the old storage entry**. In the
shared-id case that deletes the host's login session: the user is silently
logged out after the first `POST /session`.

## 4. Table of understandings

| Concept                          | Simple meaning                                      | Practical implication                                                                                                                |
| -------------------------------- | --------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------ |
| Session id                       | Random string that names one storage entry          | Whoever knows it is that session. Treat it as a secret; never put it in URLs.                                                        |
| Session cookie                   | Cookie that carries the id                          | Its name (`session.name`) must be unique per app; its flags (`Secure`, `HttpOnly`, `SameSite`) decide who can read and send it.      |
| Session file                     | Default storage: `sess_<id>` in `session.save_path` | Needs a writable, private, persistent directory. Wiping it logs everyone out. Not shared between servers by default.                 |
| Session name                     | The cookie name PHP uses                            | Set before `session_start()`, on every request. Changing it later fails with a warning.                                              |
| Session status                   | `DISABLED` / `NONE` / `ACTIVE`                      | The only reliable check. `session_id()` survives `session_write_close()`.                                                            |
| Session fixation                 | Attacker forces a known id on the victim            | Prevent with `session.use_strict_mode=1` plus `session_regenerate_id(true)` on login and privilege change.                           |
| Session regeneration             | Swap the id, keep the data                          | With `delete_old_session=true` the old entry is deleted. Safe for one owner, destructive when two cookies share one id.              |
| Session write close              | Persist and release the lock, keep the id           | Enables sequential sessions, and is required before long work so other requests are not blocked. Does not reset the id.              |
| Session destroy                  | Delete the server-side data                         | Does not expire the browser cookie; the client keeps sending a dead id. Expire the cookie yourself.                                  |
| Session locking                  | The handler locks the entry for the whole request   | Concurrent requests of the same session serialize. Long upstream calls hold the lock. Close early when possible.                     |
| Session garbage collection       | Probabilistic deletion of idle entries              | The effective idle limit is the smaller of `gc_maxlifetime` and the app TTL, and may be driven by an external cron.                  |
| Multiple sessions in one request | Sequential only: close, rename, reopen              | Two libraries cannot both own the request-global session. One must adapt, use a different transport, or hand the id over explicitly. |

## 5. Workarounds for common host problems

### 5.1 Host authentication plus a library that wants its own session

**Problem.** The host starts `session_start()` for login; the library expects
to own the session and its cookie.

**Options.**

| Option                                         | Pros                                                                | Cons                                                                                                                                                  |
| ---------------------------------------------- | ------------------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------- |
| Let the library reuse the host session         | Zero code                                                           | Library keys land in the login session; library regeneration deletes the login; TTLs and cookie names collide; host logout kills the library. Silent. |
| Host closes, then hands the id over explicitly | Two real sessions, two cookies, two lifetimes; works on vanilla PHP | Host must know the library's cookie name; four lines of non-obvious code                                                                              |
| Host stops using a PHP session for auth        | Library owns the session outright                                   | Host must implement a signed cookie (see §5.3)                                                                                                        |

**Recommendation.** Hand the id over explicitly (last row of the §3.3 table):
`session_write_close()`, `session_name(<library name>)`,
`session_id($_COOKIE[<library name>] ?? '')`, then bootstrap the library. Do
**not** rely on `session_write_close()` alone, and do **not** "fix" it with
`session_id('')` — that gives the library a new session on every request.

### 5.2 Two different session names in one request (sequential open/close)

**Pros.** Native, no dependencies; each app keeps its own cookie and storage;
independent TTLs and logout.

**Cons.** The id must be handed over explicitly (§3.3); every extra
`session_start()` sends another `Set-Cookie` header; the request holds two
locks in sequence; a mistake is silent, not an error.

**Recommendation.** Acceptable when the sequence is fixed and tested. Wrap it
in one helper function with a comment, and assert after bootstrap that
`session_name()` is the library's name and that the ids differ.

### 5.3 Signed (stateless) authentication cookie instead of a PHP session

**Pros.** No PHP session is started before the library, so the library owns
PHP's single session; no locking; trivially shareable across servers; no
server-side storage to clean.

**Cons.** Revocation is hard (you need a version/epoch or short expiry); the
cookie grows with the claims; you must implement signing, expiry, rotation and
`Secure`/`HttpOnly`/`SameSite` correctly; large payloads hit the 4 KB cookie
limit.

**Recommendation.** Best fit for a small host login in front of a session-owning
library. Sign with HMAC-SHA256 over the payload, include `exp` and a random
nonce, verify with `hash_equals()`, keep it small, and rotate it on login.

### 5.4 Framework session plus a library that uses the native session (Laravel, Symfony)

**Problem.** The framework's middleware starts its own session before the
library sees the request. The library's native store then early-returns into the
framework session.

**Pros of adapting.** One storage backend, one cookie policy, the framework's
driver (Redis, database) is reused.

**Cons.** The adapter must implement the library's store contract exactly
(`get`/`set`/`remove`/`regenerate`/`destroy`), must not let the framework's CSRF
middleware validate the library's requests, and `regenerate()` semantics differ
per framework.

**Recommendation.** Implement the library's `SessionStore` interface over the
framework session (the package ships
`examples/laravel/app/Emergency/LaravelSessionStore.php`), or forward the
library's route prefix to a separate front controller so the framework session
never coexists with it. Never let the framework's session middleware and the
library's native store both run on the same request.

### 5.5 Multiple application servers

**Problem.** The `files` handler writes to the local disk; the next request may
land on another node and find nothing.

**Options.**

| Option                                                                                                | Pros                           | Cons                                                                                      |
| ----------------------------------------------------------------------------------------------------- | ------------------------------ | ----------------------------------------------------------------------------------------- |
| Sticky sessions                                                                                       | No new infrastructure          | Breaks on deploy/scale-in; one lost node logs users out                                   |
| Shared handler (Redis, Memcached, database) via `session.save_handler` / `session_set_save_handler()` | Any node can serve any request | New dependency; locking semantics differ per handler; another thing to secure and monitor |

**Recommendation.** Use shared storage, not sticky sessions. Keep the session
name stable across deploys and set `gc_maxlifetime` deliberately. Locking
behaviour is handler-specific **[handler-dependent]**; do not assume the file
handler's `flock` semantics carry over.

### 5.6 Shared hosting with a fixed document root

**Problem.** You cannot point the document root at `public/`, and
`session.save_path` may be shared with other tenants.

**Pros of working around it.** Deployable anywhere; no server changes.

**Cons.** Source, `.env` and `vendor/` may become reachable; a shared
`save_path` is a confidentiality risk; cookie path/domain may be forced.

**Recommendation.** Prefer a subdomain or vhost pointing at `public/`. If that
is impossible, deny dotfiles and non-public directories in `.htaccess`, keep
`.env` outside the served tree, and set a private `session.save_path` (or a
custom handler) instead of the host default.

### 5.7 Cookie name collisions

**Problem.** Two apps on the same host and path both use `PHPSESSID`, or the
host and the library use the same session name.

**Pros of a distinct name.** Complete isolation of ids and storage; the
browser keeps both cookies.

**Cons.** One more name to configure and document; a name change logs everyone
out.

**Recommendation.** Give every app a distinct `session.name`
(`EMERGENCY_BFF_SESSION_NAME` for the BFF). Never reuse the host's name. On
HTTPS, consider the `__Host-` prefix: it requires `Secure`, `Path=/` and no
`Domain`, and browsers then refuse a cookie injected from a subdomain.

### 5.8 Session fixation and regeneration

**Problem.** An attacker who can set a session id before login can reuse the
victim's session afterwards.

**Recommendation.** Set `session.use_strict_mode=1`; regenerate the id on login
and on any privilege change with `session_regenerate_id(true)`; expire the old
cookie; never accept an id from a URL. Note that
`session_regenerate_id(true)` deletes the old entry — make sure no other
component is still using that id.

### 5.9 Concurrent requests and session file locking

**Problem.** The `files` handler locks the session for the whole request. A
second request from the same browser (a poll, a second tab) blocks until the
first finishes.

**Pros of closing early.** Parallel requests; no user-visible stalls.

**Cons.** Writes after `session_write_close()` are lost; reopening costs
another read/lock; repeated `session_start()` calls append duplicate
`Set-Cookie` headers.

**Recommendation.** Do the session read, then `session_write_close()` before
slow I/O (upstream HTTP, email, WhatsApp). Where a library holds the lock for
the whole request, keep upstream timeouts short and expect serialization.

## 6. What to tell a host integrator

- PHP keeps **one** active session per request. Decide who owns it before you
  write any code.
- If your app starts a session for login, a library that calls
  `session_start()` will silently reuse it. Check `session_status()`.
- `session_write_close()` is not enough to switch sessions: the id survives.
  Hand the id over explicitly, or do not start a session at all.
- Use a distinct session cookie name per application, and keep it stable across
  deploys.
- Set `session.use_strict_mode=1` and regenerate the id on login.
- Enable `Secure`, `HttpOnly` and `SameSite=Lax` (or `Strict`) in production.
- Keep `session.gc_maxlifetime` at least as long as your longest application
  TTL, and make sure nothing external deletes storage earlier.
- Use shared session storage before adding a second app server.
- Expire the cookie yourself on logout; `session_destroy()` only deletes the
  server-side data.
- Verify with the browser, not by reasoning: check that the two cookies carry
  **different** ids and that both sessions survive a second request.

## Sources

PHP manual:

- [Session introduction](https://www.php.net/manual/en/intro.session.php)
- [Session configuration (`php.ini` directives)](https://www.php.net/manual/en/session.configuration.php)
- [`session_start()`](https://www.php.net/manual/en/function.session-start.php)
- [`session_name()`](https://www.php.net/manual/en/function.session-name.php)
- [`session_set_cookie_params()`](https://www.php.net/manual/en/function.session-set-cookie-params.php)
- [`session_write_close()`](https://www.php.net/manual/en/function.session-write-close.php)
- [`session_regenerate_id()`](https://www.php.net/manual/en/function.session-regenerate-id.php)
- [`session_destroy()`](https://www.php.net/manual/en/function.session-destroy.php)
- [`session_status()`](https://www.php.net/manual/en/function.session-status.php)
- [`session_id()`](https://www.php.net/manual/en/function.session-id.php)
- [Session security](https://www.php.net/manual/en/security.sessions.php)
- [Session handlers (`session_set_save_handler()`)](https://www.php.net/manual/en/function.session-set-save-handler.php)

Package sources used for the examples:

- `packages/emergency-bff/src/Session/NativeSessionStore.php`
- `packages/emergency-bff/src/Session/SessionManager.php`
- `packages/emergency-bff/src/Support/Bootstrap.php`
