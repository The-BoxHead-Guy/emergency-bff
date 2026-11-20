# Session and CSRF

Applies to: vanilla PHP and Laravel integrators.

The browser holds a PHP session; the BFF holds the credentials. That split is
the reason the package exists: the API key, the application identifier and the
identity binding never reach JavaScript.

## Why a session if the panel already has a code

The code identifies a person. The session proves that the same browser started
the flow, and it carries the CSRF token, the rate-limit counters and the last
emergency snapshot. Without it, anyone who knew a code could read or abort an
emergency. Possession of the code is therefore enough to _start_ a session — if
a bracelet is lost, revoke the code upstream.

## Lifecycle

1. `GET /config` starts a session and returns a CSRF token.
2. `POST /session` validates the code upstream, regenerates the session ID and
   stores the identity. It returns a rotated token.
3. Every later route requires that identity; otherwise `401 unauthenticated`.
4. After `EMERGENCY_BFF_SESSION_TTL` seconds of inactivity the session is
   destroyed. Activity slides the window.

Starting a session with a different code replaces the identity. There is no
logout route: call `DELETE /{id}` to end an active emergency, then start a new
session. A host that supports logout must destroy the BFF session itself and
expire its cookie; destroying the server-side session does not expire the
browser cookie.

## CSRF

Unsafe requests (`POST`, `PUT`, `PATCH`, `DELETE`) must send the current token:

```http
X-CSRF-Token: <token>
```

The JSON body field `csrf_token` is also accepted. `POST /session` rotates the
token, so discard the previous value after a successful start. Keep the token in
memory and send the header; do not store it in `localStorage`.

Two tabs share one session, so the last tab to start a session invalidates the
other tab's token. The stale tab must reload and read a fresh token.

## Same origin

The BFF requires the page and the API to share scheme, host and port. `Origin`
is compared with `Host`, and a mismatch returns `403 cross_origin_denied` before
the session is read. This protects a signed-in visitor from a malicious page
starting sessions or activating emergencies in the background.

Behind a reverse proxy, forward the original `Host` and `Origin` unchanged. A
proxy that rewrites `Host` to an internal name breaks the check; fix the proxy,
not the middleware.

## Host constraints

- `Bootstrap::create()` starts the PHP session while it builds the stack. Do not
  call it while your application already has a native session open unless you
  replace the session store: PHP keeps one session per request, and the BFF would
  silently adopt your session name and write its keys into it.
- The session store is the integration point for framework hosts. Implement
  `DataEmergencia\EmergencyBff\Session\SessionStore` and pass it to
  `SessionManager`. The adapter must call `regenerate()` before storing a new
  identity and stay available for the whole request.
- `destroy()` removes the server-side data but not the browser cookie. A host
  performing logout must expire the cookie explicitly.
- The default store is PHP's file handler. Multiple nodes need a shared handler
  (redis, memcached or database), or a request can land on a node that does not
  know the session and return `401`.

## Laravel

Keep the two session systems separate. Either forward the BFF route prefix to
`public/index.php` (recommended) or register the BFF as its own virtual host.
Exempt the prefix from Laravel's CSRF middleware; the BFF validates its own
token. Do not let both layers validate the same request.

The alternative is to implement `SessionStore` over the Laravel session. The
BFF's CSRF manager is not replaceable in the MVP; only the store is.

## Security

For the threat model, credential handling and the one known authorization
limitation, read [Security](security.md).
