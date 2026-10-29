# Session and CSRF

Applies to: vanilla PHP and Laravel integrators.

The BFF keeps the browser identity in a server-side PHP session. The browser
never receives the API key, the application identifier or the upstream user
token.

## Session lifecycle

1. `GET /config` starts a native session and returns a CSRF token.
2. `POST /session` validates the 6-character code, regenerates the session ID
   and stores the identity. It returns a rotated CSRF token.
3. Every later route requires that identity. A missing identity returns
   `401 unauthenticated`.
4. The session expires after `EMERGENCY_BFF_SESSION_TTL` seconds of inactivity.
   Activity slides the window.

Session keys used by the package:

| Key                    | Contents                                              |
| ---------------------- | ----------------------------------------------------- |
| `de_bff_identity`      | The validated 6-character code.                       |
| `de_bff_csrf_token`    | The current CSRF token.                               |
| `de_bff_emergency`     | Last emergency snapshot returned by the upstream API. |
| `de_bff_started_at`    | Session start timestamp.                              |
| `de_bff_last_activity` | Last request timestamp used for the sliding TTL.      |

The exact key names are implementation details; do not read or write them from
your application.

## CSRF

Every unsafe request (`POST`, `PUT`, `PATCH`, `DELETE`) must present the token
returned by `GET /config` or `POST /session`:

```http
X-CSRF-Token: <token>
```

The token is also accepted in the JSON body as `csrf_token`. The package uses a
64-character token and constant-time comparison.

Keep the token in memory and send `X-CSRF-Token`. Do not store the token in
`localStorage`.

`POST /session` rotates the token. Discard the previous value after a successful
session start.

A missing or invalid token returns:

```json
{
  "error": {
    "code": "csrf_failed",
    "message": "The CSRF token is missing or invalid.",
    "details": {},
    "request_id": "9f3a1c7e2b4d8065"
  }
}
```

## Same-origin policy

`SameOriginMiddleware` compares the `Origin` request header with the `Host`
header. A different host or port is rejected with
`403 cross_origin_denied`. Same-origin requests receive an
`Access-Control-Allow-Origin` echo and `Vary: Origin`.

This means the BFF must be served from the same origin as the page that calls
it. If you terminate TLS or proxy at a different host, preserve the original
`Host` header rather than rewriting it to the internal upstream name.

## Replacing the session store

Hosts can replace the native store by implementing
`DataEmergencia\EmergencyBff\Session\SessionStore`:

```php
namespace DataEmergencia\EmergencyBff\Session;

interface SessionStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function destroy(): void;
}
```

`SessionManager` accepts any implementation. The default
`NativeSessionStore` configures `session_name`, `session_set_cookie_params()` and
`session_start()`, and calls `session_regenerate_id(true)` on identity changes.

A framework adapter must call `regenerate()` before storing a new identity and
must keep the store available for the whole request. The BFF calls the store
directly; it does not start a framework session for you.

## Laravel integration

The MVP supports Laravel in two ways.

### Forward the routes

Exempt the BFF route prefix from Laravel's CSRF middleware and forward those
requests to `public/index.php`, or register the BFF as a separate virtual host.
This is the safest option because Laravel and the BFF each keep their own
session and CSRF state.

```php
// bootstrap/app.php (Laravel 11+)
->withExceptions(function (Exceptions $exceptions): void {
    // No change needed; BFF routes are not registered in Laravel.
})
```

If you proxy instead of forwarding, preserve `Host`, `Origin`, cookies and the
raw request body.

### Replace the session store

Register a `SessionStore` backed by the Laravel session and construct the BFF
services with `Bootstrap` or manually. The CSRF token still comes from the BFF's
`SessionManager`; Laravel's own CSRF middleware must not validate BFF requests.
Frontends should read the token from `GET /config` and keep it in memory.

The BFF's CSRF manager is not replaceable in the MVP; the store is.

## Logout and code switching

Starting a session with a different code regenerates the session ID. Call
`DELETE /{id}` to end an active emergency, then `POST /session` with the new
code. Destroy the browser session explicitly if your application supports
logout; the BFF does not expose a logout route in the MVP.

## Next step

[Routes](routes.md).
