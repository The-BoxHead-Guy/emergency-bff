# `emergency-bff` sessions: how they work today

Applies to: package maintainers and host integrators.

Status: research note. It describes the current implementation (`0.1.x`) and the
integration risk for a host that has its own login session. It is not an API
contract; the contract stays in [Session and CSRF](../bff/session-and-csrf.md)
and [Configuration](../bff/configuration.md).

Behaviour marked **verified** was reproduced against the real package on
**PHP 8.4.27** with the default `files` session handler.

## 1. How the package handles sessions today

### 1.1 Wiring: `Bootstrap::create()`

`src/Support/Bootstrap.php:31-71` builds the whole stack from the environment:

```php
$config  = Config::fromEnvironment($environment);          // line 33
$http    = Psr18ApiClient::create($config);                // line 34

$session = new SessionManager(
    new NativeSessionStore(                                 // lines 37-43
        sessionName: $config->sessionName,
        cookiePath: $config->cookiePath,
        cookieSecure: $config->cookieSecure,
        cookieHttpOnly: $config->cookieHttpOnly,
        cookieSameSite: $config->cookieSameSite,
    ),
    $config->sessionTtl,                                    // line 44
);
```

The store is constructed eagerly, and its constructor calls `start()`
(`NativeSessionStore.php:21`). **The PHP session therefore starts inside
`Bootstrap::create()`**, not on the first route that touches it. Every request
that builds the kernel — including `GET /health` — opens a session, sends
`Set-Cookie: de_bff_emergency=...` and creates a storage entry. Verified.

This is why the rule for hosts is "do not have an active session when you call
`Bootstrap::create()`", not "before the first BFF route".

### 1.2 `NativeSessionStore`

`src/Session/NativeSessionStore.php`:

| Member               | Lines | Behaviour                                                                                                                                                                                                                          |
| -------------------- | ----- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| constructor          | 14-22 | Stores name, path, secure, HttpOnly, SameSite; calls `start()`                                                                                                                                                                     |
| `start()`            | 65-88 | Early-returns when `$started` or `session_status() === PHP_SESSION_ACTIVE` (67-71); returns silently when `headers_sent()` (73-75); otherwise `session_name()` (77), `session_set_cookie_params()` (78-84), `session_start()` (86) |
| `get`/`set`/`remove` | 24-41 | Plain `$_SESSION` access after `start()`                                                                                                                                                                                           |
| `regenerate()`       | 43-47 | `session_regenerate_id(true)` — deletes the old storage entry                                                                                                                                                                      |
| `destroy()`          | 49-63 | `$_SESSION = []`, `session_destroy()`, unsets `$_COOKIE[name]`, resets `$started`                                                                                                                                                  |

Two properties matter for hosts:

- **The early return is silent.** When a host session is active, the store
  keeps the host's session name and id, writes `de_bff_*` keys into the host
  session, and never sets the BFF cookie. No warning, no log line.
- **`destroy()` does not expire the browser cookie.** It only removes
  `$_COOKIE` for the current request and deletes the server-side data. The
  browser keeps sending `de_bff_emergency`; the next request recreates an empty
  session with that id when `session.use_strict_mode=0` (the default).

### 1.3 `SessionManager`: TTL and identity lifecycle

`src/Session/SessionManager.php`:

| Member                                                  | Lines   | Behaviour                                                                                               |
| ------------------------------------------------------- | ------- | ------------------------------------------------------------------------------------------------------- |
| keys                                                    | 15-19   | `de_bff_identity`, `de_bff_csrf_token`, `de_bff_emergency`, `de_bff_started_at`, `de_bff_last_activity` |
| `__construct`                                           | 21-26   | Store, TTL (default `3600`), optional clock for tests                                                   |
| `load()`                                                | 28-39   | If expired: `destroy()` and return. Otherwise, when `started_at` exists, refresh `last_activity`        |
| `isExpired()`                                           | 41-53   | `now - last_activity > ttl`; no `started_at` means "not expired"                                        |
| `storeIdentity()`                                       | 55-64   | `regenerate()` (unless disabled), then writes identity, `started_at`, `last_activity`                   |
| `hasIdentity()` / `identity()`                          | 66-80   | `identity()` throws `InactiveCodeException` when the code is missing                                    |
| `storeEmergency()` / `emergency()` / `clearEmergency()` | 92-110  | Last upstream snapshot, cached in the session                                                           |
| `csrfToken()` / `rotateCsrf()` / `validateCsrf()`       | 117-130 | Delegates to `CsrfTokenManager`                                                                         |
| `destroy()`                                             | 132-135 | Delegates to the store                                                                                  |

TTL is **sliding on activity**, enforced lazily by `load()` on each BFF
request. It is an application-level check on top of PHP's own storage lifetime,
so the effective idle limit is the smaller of `EMERGENCY_BFF_SESSION_TTL` and
`session.gc_maxlifetime` (default 1440 s, i.e. 24 minutes). A 1-hour BFF TTL on
a default PHP install expires after about 24 minutes of idleness. Verified
config values on 8.4.27.

### 1.4 Middleware order

`src/Http/Kernel.php:95-100` builds, and `86-122` executes, in this order:

```text
SameOriginMiddleware  ->  SessionMiddleware  ->  RateLimitMiddleware  ->  CsrfMiddleware  ->  route
```

| Layer                  | File                                                 | Effect                                                                                                                                           |
| ---------------------- | ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| `SameOriginMiddleware` | `src/Http/Middleware/SameOriginMiddleware.php:16-33` | Rejects `Origin` != `Host` with `403 cross_origin_denied`; echoes `Access-Control-Allow-Origin` and `Vary: Origin` for same-origin browser calls |
| `SessionMiddleware`    | `src/Http/Middleware/SessionMiddleware.php:20-25`    | `SessionManager::load()` — expires an idle session, refreshes `last_activity`                                                                    |
| `RateLimitMiddleware`  | `src/Http/Middleware/RateLimitMiddleware.php:21-32`  | 60 requests / 60 s per `METHOD:path` bucket, stored in the session                                                                               |
| `CsrfMiddleware`       | `src/Http/Middleware/CsrfMiddleware.php:23-35`       | Validates `X-CSRF-Token` or body `csrf_token` on `POST`/`PUT`/`PATCH`/`DELETE`                                                                   |

Because `SessionMiddleware` runs first, an expired session is destroyed before
the rate limiter writes. The limiter's write restarts a fresh session (new id,
new cookie) inside the same request — verified. The client sees a normal
response plus a new cookie.

### 1.5 Session keys

`SessionManager.php:15-19`:

| Key                    | Contents                    | Written by                                                                        |
| ---------------------- | --------------------------- | --------------------------------------------------------------------------------- |
| `de_bff_identity`      | Validated 6-character code  | `POST /session` (`Kernel.php:170`)                                                |
| `de_bff_csrf_token`    | 64-hex-character CSRF token | `GET /config` on first call, `POST /session` on rotation                          |
| `de_bff_emergency`     | Last upstream snapshot      | `GET /current`, `POST /activate`, `POST /{id}/refresh`; cleared by `DELETE /{id}` |
| `de_bff_started_at`    | Session start timestamp     | `POST /session`                                                                   |
| `de_bff_last_activity` | Last request timestamp      | `SessionMiddleware` on every request where `started_at` exists                    |
| `de_bff_rate_limits`   | Fixed-window counters       | `RateLimiter` (`src/Support/RateLimiter.php:14`)                                  |

The docs tell hosts not to read or write these keys
(`docs/bff/session-and-csrf.md:29-30`); they are implementation details.

### 1.6 `POST /session`: regeneration and CSRF rotation

`Kernel.php:159-179`:

1. Reads `code`, rejects missing/blank with `422 validation_failed`.
2. `CodeValidator::validate()` — format check plus an upstream existence check;
   failures map to `422 invalid_code` / `404 inactive_code`.
3. `SessionManager::storeIdentity()` → `session_regenerate_id(true)`, then
   stores the code and both timestamps (`SessionManager.php:55-64`).
4. Responds `201` with `code`, `csrf_token` (freshly rotated via
   `rotateCsrf()`, `Kernel.php:176`) and `expires_in` = configured TTL.

Order matters for hosts: the id changes **before** the identity is written, and
the CSRF token rotates **after**. Any other tab holding the previous token gets
`403 csrf_failed` on its next write (`docs/operations/troubleshooting.md:83-84`).

`GET /config` (`Kernel.php:152-158`) returns `route_prefix`, `realtime` and the
current CSRF token, creating one if absent or shorter than 32 characters
(`src/Session/CsrfTokenManager.php:16-25`).

### 1.7 Cookie configuration

`NativeSessionStore.php:78-84` always sends:

| Attribute  | Value                                                                                                        |
| ---------- | ------------------------------------------------------------------------------------------------------------ |
| `lifetime` | `0` — browser-session cookie, hardcoded                                                                      |
| `path`     | `EMERGENCY_BFF_COOKIE_PATH` (default `/`)                                                                    |
| `secure`   | `EMERGENCY_BFF_COOKIE_SECURE` (default `true`)                                                               |
| `httponly` | `EMERGENCY_BFF_COOKIE_HTTP_ONLY` (default `true`)                                                            |
| `samesite` | `EMERGENCY_BFF_COOKIE_SAME_SITE` (default `Lax`, validated to `Lax`/`Strict`/`None` in `Config.php:158-160`) |
| `domain`   | not configurable — inherited from `session.cookie_domain`                                                    |

The name comes from `EMERGENCY_BFF_SESSION_NAME` (default `de_bff_emergency`,
`Config.php:84`). Because `lifetime` is `0`, a browser restart drops the cookie
even when the server-side session would still be within its TTL.

### 1.8 Rate limiting and the session

`RateLimiter` keeps a fixed window in `$_SESSION['de_bff_rate_limits']`
(`RateLimiter.php:29-54`): 60 requests per 60 seconds per `METHOD:path` bucket
(`RateLimitMiddleware.php:23`). Practical consequences:

- Counters are **per session**, not per user or IP. A client that drops its
  cookies gets a fresh budget. It is abuse control, not a security boundary.
- An expired or destroyed session resets every bucket.
- The limiter **writes on every request** (it appends the current timestamp),
  so the session storage changes constantly. That keeps the storage mtime fresh
  and therefore keeps PHP's garbage collector away — but it also means
  `session.lazy_write` never saves a write.
- The limiter runs before CSRF, so unauthenticated floods consume the budget.
- `details.retry_after` is the window length (60), not a countdown
  (`docs/operations/troubleshooting.md:135-139`).

### 1.9 Logout

There is **no logout route** and no code path that calls
`SessionManager::destroy()` except expiry. Verified: no `logout` occurrence in
the package's PHP source, and `destroy()` is only reached from
`SessionManager::load()` (`SessionManager.php:31`).

A host that supports logout must, itself:

1. end any active emergency (`DELETE /{id}`) if that is desired;
2. destroy the BFF session — either by constructing the same
   `NativeSessionStore`/`SessionManager` and calling `destroy()`, or by
   starting the BFF session and calling `session_destroy()`;
3. expire the `de_bff_emergency` cookie in the browser
   (`setcookie($name, '', time() - 3600, '/')`), because `destroy()` does not.

## 2. Pros and cons of the current handling

### Pros

- **Zero dependencies.** Native PHP only; works on any PHP 8.4 host with a
  writable `session.save_path`, including shared hosting.
- **Server-side identity.** The API key, application identifier and code stay
  on the server; the browser holds only an opaque id.
- **CSRF rotation.** 64-hex token, `hash_equals()` comparison, rotated on
  `POST /session` (`CsrfTokenManager.php:27-44`).
- **Same-origin enforcement.** `SameOriginMiddleware` rejects cross-origin
  browser calls, so a stolen cookie is not usable from another site.
- **Sliding TTL.** Idle sessions expire (`SessionManager.php:28-53`), and the
  emergency snapshot is discarded with them.
- **Replaceable store.** `SessionStore` is a five-method interface
  (`src/Session/SessionStore.php:14-25`), so frameworks can adapt it.
- **Small surface.** Five session keys, one cookie, one middleware chain.

### Cons

- **Silent host collision.** The `session_status() === PHP_SESSION_ACTIVE`
  early return (`NativeSessionStore.php:67`) is indistinguishable from success.
  A host session makes the BFF reuse the wrong storage and skip its cookie. No
  warning, no log.
- **Session-id inheritance.** `session_write_close()` alone is not enough to
  switch sessions; the id survives, so a host that "closes first" still ends up
  sharing one storage entry with the BFF (verified; see §3.2).
- **No logout route.** Hosts must destroy the session and expire the cookie
  themselves; the docs mention it in one paragraph
  (`docs/bff/session-and-csrf.md:136-139`) and the package offers no helper.
- **`destroy()` leaves the cookie.** The browser keeps sending a dead id.
- **No multi-session abstraction.** `NativeSessionStore` assumes it owns the
  request-global session. There is no way to give the BFF a second, independent
  native session without host code.
- **File-handler locking.** The session stays locked for the whole request,
  including upstream calls (default 10 s timeout, one WhatsApp request per
  contact). A poll from a second tab waits.
- **No shared storage out of the box.** Multiple app servers need a shared
  handler, configured outside the package.
- **TTL depends on GC.** `gc_maxlifetime` (1440 s default) can cut a 3600 s TTL
  short, and GC timing is probabilistic or driven by an external cron.
- **Cookie name collisions.** A host that reuses `de_bff_emergency`, or keeps
  `PHPSESSID` for both apps on the same path, overwrites ids.
- **`headers_sent()` silent no-op.** If anything echoes before
  `Bootstrap::create()`, the store never starts, the issued CSRF token is never
  stored, and the first write fails with `403 csrf_failed`
  (`NativeSessionStore.php:73-75`).
- **`GET /health` creates sessions.** A load-balancer probe writes a storage
  entry and sends a cookie on every check.
- **Session fixation is possible by default.** `session.use_strict_mode=0` is
  the shipped default; the package does not raise it and does not document it.
- **Snapshot size.** `de_bff_emergency` holds the whole upstream snapshot in
  the session; large responder lists inflate the file and the write cost.

## 3. The specific host scenario: a vanilla PHP app with its own login session

### 3.1 What the host wants

- `GET /login`, `POST /login` with an env-defined user list, no database.
- `GET /` renders the page that mounts the client panel.
- `/de-bff/emergency/*` is served by the package from the same front
  controller and the same origin.
- Two independent lifetimes: the host login and the BFF session.

### 3.2 Failure modes, in order of subtlety

**(a) Host session left open.** `NativeSessionStore::start()` sees
`PHP_SESSION_ACTIVE` and returns. The BFF session name stays `mirror_session`,
no `de_bff_emergency` cookie is ever set, and `de_bff_csrf_token` /
`de_bff_rate_limits` are written into the login session. Verified:

```text
[naive] host_id=ea7f... bff_session_name=mirror_session bff_session_id=ea7f...
        keys=mirror_user,de_bff_rate_limits,de_bff_csrf_token
```

**(b) Host session closed with `session_write_close()` only.** The name
changes, but the id does not. Both cookies carry the **same** id and share one
storage entry. Verified:

```text
Set-Cookie: mirror_session=03edb8...
Set-Cookie: de_bff_emergency=03edb8...   <- same value
[close] host_id=03edb8... bff_session_name=de_bff_emergency bff_session_id=03edb8...
        keys=mirror_user,de_bff_rate_limits,de_bff_csrf_token
```

The damage appears on `POST /session`: `storeIdentity()` calls
`session_regenerate_id(true)`, which **deletes the shared storage entry**. The
host's `mirror_session` cookie now points at nothing, so the next page load
renders as logged out. Verified in isolation:

```text
host id = c66c0c92... (file exists: no)   <- deleted by the BFF's regenerate
bff  id = 37310690... (file exists: yes)
host reload sees = []
```

**(c) Host closes and clears the id with `session_id('')`.** This looks like
the fix and is worse: on PHP 8.4 the client-supplied id is resolved once per
request, so the BFF's `session_start()` generates a **new** id on every
request instead of reading `de_bff_emergency`. The BFF never persists its
session; the CSRF token changes on every request and every write fails. Verified:

```text
close_reset:  req 1 token=24b9e9b7  req 2 token=0c5bdd13  req 3 token=41b1020c
close_explicit: req 1 token=6b7e5e2e  req 2 token=6b7e5e2e  req 3 token=6b7e5e2e
```

**(d) `session_start(['read_and_close' => true])` for the host.** Same as (b):
the id survives, the BFF adopts it, and both cookies converge on one storage
entry. Verified.

### 3.3 Recommended integration

On requests that reach the BFF, do not leave PHP's single session to chance:
close the host session, switch to the BFF session name, and **hand the id over
explicitly** from the BFF cookie before `Bootstrap::create()`.

```php
// Host code, BFF routes only. Runs before Bootstrap::create().
session_name('mirror_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $mirrorCookieSecure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

$user = $_SESSION['mirror_user'] ?? null;   // authenticate here
session_write_close();                      // release the host lock

$bffName = getenv('EMERGENCY_BFF_SESSION_NAME') ?: 'de_bff_emergency';
session_name($bffName);
session_id($_COOKIE[$bffName] ?? '');       // hand the BFF session id over

Bootstrap::create($env)->handle(Request::fromGlobals())->send();
```

Rules that come with it:

- Read the name from the same environment variable the BFF uses; do not
  hardcode it twice.
- `session_id('')` when the cookie is absent is correct **only** here: it
  creates the BFF's first session. On later requests the cookie supplies the id.
- Never call `session_name()` while a session is active — it fails with
  `E_WARNING` and returns `false`.
- Assert the isolation once: the two cookies must carry different ids, and the
  BFF CSRF token must stay the same across two `GET /config` calls.
- Enable `session.use_strict_mode=1` so a forged `de_bff_emergency` id is
  rejected instead of adopted.

Alternative, and cleaner if the host can afford it: **do not use a PHP session
for host auth at all.** A signed, `HttpOnly`, `SameSite=Lax` cookie with a
short expiry and an HMAC leaves PHP's single session entirely to the BFF, so no
handoff code is needed. Trade-off: revocation requires a version/epoch value or
a short TTL.

### 3.4 Sequence

```text
Browser                  Host front controller                 emergency-bff
   |                            |                                   |
   | GET /de-bff/emergency/...  |                                   |
   | Cookie: mirror_session,    |                                   |
   |         de_bff_emergency   |                                   |
   |--------------------------->|                                   |
   |                            | session_name('mirror_session')    |
   |                            | session_start()  -> host storage  |
   |                            | read user; 401 JSON if anonymous  |
   |                            | session_write_close()  (unlock)   |
   |                            | session_name('de_bff_emergency')  |
   |                            | session_id($_COOKIE['de_bff_...'])|
   |                            | Bootstrap::create() ------------->| NativeSessionStore::start()
   |                            |                                   | session_start()
   |                            |                                   | handle() -> route
   |                            |<----------------------------------| JSON + Set-Cookie (BFF only)
   |<---------------------------|                                   |
```

What each step guarantees:

| Step                                   | Guarantee                                                                     |
| -------------------------------------- | ----------------------------------------------------------------------------- |
| `session_start()` on the host name     | Host data is read from the host storage                                       |
| `session_write_close()`                | Host lock released; host data persisted                                       |
| `session_name($bffName)`               | The BFF's cookie name is restored (PHP reset it at request start)             |
| `session_id($_COOKIE[$bffName] ?? '')` | The BFF resumes **its** storage, not the host's                               |
| `Bootstrap::create()`                  | `start()` sees `PHP_SESSION_NONE`, so it configures and opens the BFF session |
| `POST /session`                        | Regenerates only the BFF id; the host cookie is untouched                     |

## 4. Recommendations for the package

No code changes are proposed here; these are documentation and design
recommendations for maintainers.

1. **Document the rule, prominently.** "Do not have an active PHP session when
   you call `Bootstrap::create()`" belongs in
   [Installation](../bff/installation.md) and
   [Session and CSRF](../bff/session-and-csrf.md), not only in a troubleshooting
   bullet. State that the session starts in the constructor, and that
   `GET /health` creates one too.
2. **Document the id handoff.** The current text says "give the BFF its own
   session cookie" (`docs/bff/installation.md:63-67`) without saying that
   `session_write_close()` is insufficient. Add the three-line handoff and the
   `session_id('')` trap.
3. **Consider a session-name guard.** `NativeSessionStore::start()` could
   compare `session_name()` with `$this->sessionName` when the session is
   already active and fail loudly (exception or PSR-3 warning) instead of
   silently reusing the host session. A `strict` flag would keep the current
   behaviour for hosts that rely on it.
4. **Consider a native multi-session store.** A `SessionStore` implementation
   that owns a second cookie and a separate storage namespace (or the host's
   framework session) would remove the handoff from host code. The interface
   already allows it; what is missing is a shipped implementation and a
   documented contract for "the host session is not mine".
5. **Document logout.** Add a short recipe: `DELETE /{id}`, destroy the BFF
   session, expire `de_bff_emergency` with `setcookie()`. Mention that
   `destroy()` does not expire the browser cookie.
6. **Document shared storage.** [Deployment](../bff/deployment.md) (the
   "Sessions" section, lines 107-118) mentions Redis/Memcached in one paragraph.
   Add the concrete `session.save_handler`/`session.save_path` settings, the
   locking caveat, and the requirement that all nodes share the same
   `session.name` and cookie parameters.
7. **Document the cookie flags and their failure modes.** `Secure=true` breaks
   plain HTTP; `SameSite=None` requires `Secure`; `domain` is not configurable;
   `lifetime=0` drops the session on browser restart; a `__Host-` name is the
   strongest option on HTTPS.
8. **Document the GC interaction.** State that `EMERGENCY_BFF_SESSION_TTL`
   cannot exceed the effective `session.gc_maxlifetime`, and that external
   cleanup jobs may shorten it further.
9. **Fix the store snippet.** `docs/bff/session-and-csrf.md:84` shows
   `get(string $key): mixed` while the interface is
   `get(string $key, mixed $default = null): mixed`
   (`src/Session/SessionStore.php:16`). A host copying the snippet gets an
   incompatible signature.
10. **Correct the lifecycle description.** `docs/bff/session-and-csrf.md:11`
    says `GET /config` starts the session; it is started by
    `Bootstrap::create()`.
11. **State the rate-limit scope.** "Per session, resettable by dropping the
    cookie" prevents hosts from treating it as an authorization control.

## 5. Scenario table

| Scenario                                        | What happens today                                                       | Risk                                                                    | Recommended host action                                              |
| ----------------------------------------------- | ------------------------------------------------------------------------ | ----------------------------------------------------------------------- | -------------------------------------------------------------------- |
| Host calls `session_start()` and leaves it open | BFF early-returns; uses the host session; no `de_bff_emergency` cookie   | BFF keys in the login session; host logout/expiry kills the BFF; silent | Close the host session and hand the id over, or avoid a host session |
| Host calls `session_write_close()` only         | BFF renames the cookie but inherits the host id                          | Shared storage; `POST /session` deletes the login session               | Add `session_name()` + `session_id($_COOKIE[bffName] ?? '')`         |
| Host calls `session_id('')` before the BFF      | BFF generates a new id on every request                                  | BFF session never persists; every write returns `403 csrf_failed`       | Never clear the id; set it from the BFF cookie                       |
| Host uses `read_and_close` for auth             | BFF inherits the host id                                                 | Same as `write_close` only                                              | Same handoff, or a stateless auth cookie                             |
| Host outputs before `Bootstrap::create()`       | `headers_sent()` makes `start()` a silent no-op                          | Token issued but never stored; first write `403`                        | Buffer output; never echo before the kernel                          |
| Both apps use the same session name             | One cookie, one id, one storage entry                                    | Random logouts; data crossover                                          | Distinct `EMERGENCY_BFF_SESSION_NAME`                                |
| Host and BFF on different origins               | `SameOriginMiddleware` returns `403 cross_origin_denied`                 | Panel cannot call the BFF                                               | Serve both from one origin; preserve `Host` behind a proxy           |
| `POST /session` from a second tab               | BFF id and CSRF token rotate                                             | First tab gets `403 csrf_failed`                                        | Reload the stale tab; keep one token in memory                       |
| Session idle past the TTL                       | `SessionMiddleware` destroys it; the rate limiter restarts a new session | `401 unauthenticated`; the client must `POST /session` again            | Document the TTL; keep it below `gc_maxlifetime`                     |
| Session storage collected by GC first           | Storage disappears before the TTL                                        | Unexpected `401`                                                        | Raise `gc_maxlifetime` or use a handler with its own TTL             |
| Two app servers, file handler                   | The second request finds no storage                                      | Random `401`                                                            | Shared session handler, same name and cookie parameters              |
| Long upstream call while a tab polls            | The file lock serializes both requests                                   | Slow panel, apparent hangs                                              | Close the session early (package change) or accept serialization     |
| Client drops cookies                            | New session, new rate-limit budget                                       | Rate limit is not a security boundary                                   | Do not rely on it for authorization                                  |
| Host "logout" only destroys the host session    | BFF session and cookie survive                                           | The next user on the browser inherits the BFF identity                  | Destroy the BFF session and expire its cookie too                    |
| `EMERGENCY_BFF_COOKIE_SECURE=true` over HTTP    | The browser never stores the cookie                                      | Every request is a new session                                          | `false` for local HTTP only                                          |
| Browser restart                                 | `lifetime=0` drops the cookie                                            | Session appears lost despite a valid server TTL                         | Document it; use a persistent cookie only if intended                |
| `session.use_strict_mode=0` with a forged id    | A client-supplied `de_bff_emergency` id is adopted                       | Session fixation on the BFF                                             | Enable strict mode; use a `__Host-` cookie name                      |
| Health check monitored by a load balancer       | Every probe creates a session and sets a cookie                          | Storage growth; noisy `Set-Cookie`                                      | Expect it, or monitor `/health` without a cookie jar                 |

## Sources

- `packages/emergency-bff/src/Support/Bootstrap.php`
- `packages/emergency-bff/src/Session/NativeSessionStore.php`
- `packages/emergency-bff/src/Session/SessionManager.php`
- `packages/emergency-bff/src/Session/SessionStore.php`
- `packages/emergency-bff/src/Session/CsrfTokenManager.php`
- `packages/emergency-bff/src/Http/Kernel.php`
- `packages/emergency-bff/src/Http/Middleware/SessionMiddleware.php`
- `packages/emergency-bff/src/Http/Middleware/CsrfMiddleware.php`
- `packages/emergency-bff/src/Http/Middleware/RateLimitMiddleware.php`
- `packages/emergency-bff/src/Support/RateLimiter.php`
- `packages/emergency-bff/src/Config/Config.php`
- `packages/emergency-bff/docs/bff/session-and-csrf.md`
- `packages/emergency-bff/docs/bff/installation.md`
- `packages/emergency-bff/docs/bff/configuration.md`
- `packages/emergency-bff/docs/bff/deployment.md`
- `packages/emergency-bff/docs/operations/troubleshooting.md`
- [PHP manual: session functions](https://www.php.net/manual/en/ref.session.php)
- [PHP manual: session configuration](https://www.php.net/manual/en/session.configuration.php)
- [PHP manual: session security](https://www.php.net/manual/en/security.sessions.php)
