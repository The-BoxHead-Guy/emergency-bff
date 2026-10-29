# Laravel example

This is a **reference integration**, not a standalone Laravel application. Copy
these files into an existing Laravel 12 application, or use them as the shape
of your own integration.

There are two supported topologies.

## A. Separate BFF (recommended)

Deploy the BFF as its own PHP application on the same origin as the Laravel
site, for example under `/de-bff/emergency` through an Nginx location. Laravel
serves the page; the browser client calls the BFF path directly.

Advantages:

- The BFF owns its session and CSRF lifecycle.
- Laravel's session, auth and CSRF middleware are untouched.
- The BFF can be scaled or deployed independently.
- The API key never enters the Laravel application.

Files:

- `resources/views/emergency.blade.php` — page with the SOS trigger.
- `resources/js/emergency.ts` — Vite entry point that imports the client.
- `app/Http/Controllers/EmergencyPageController.php` — passes the code and BFF
  URL to the view.
- `routes/web.php` — route registration.

Set the BFF URL in `config/services.php`:

```php
'emergency_bff' => [
    'url' => env('EMERGENCY_BFF_URL', '/de-bff/emergency'),
],
```

and in `.env`:

```dotenv
EMERGENCY_BFF_URL=/de-bff/emergency
```

The relative URL is resolved by the browser against the current origin, which
is what the same-origin policy requires.

## B. Embedded kernel (advanced)

If you cannot deploy a separate application, Laravel can host the BFF inside a
route group. This mode replaces the native session with Laravel's session.

Files:

- `app/Emergency/LaravelSessionStore.php` — `SessionStore` adapter.
- `app/Emergency/EmbeddedBff.php` — builds the kernel with the adapter and the
  configured upstream credentials.

Register the catch-all route in `routes/web.php` (the example file includes it
as a comment). The route must be in the `web` middleware group and must be
excluded from Laravel's CSRF check, because the BFF validates its own token:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->validateCsrfTokens(except: ['de-bff/emergency/*']);
})
```

> [!WARNING]
> Excluding a path from Laravel CSRF is only safe because the BFF enforces
> `X-CSRF-Token` itself. Do not expose other mutating routes under the same
> exclusion.

In embedded mode:

- `EMERGENCY_BFF_COOKIE_*` and `EMERGENCY_BFF_SESSION_NAME` are ignored; the
  Laravel session cookie is used.
- The Laravel session must be started before the kernel handles the request.
- The BFF prefix must match the route, e.g. `/de-bff/emergency`.
- Laravel's `Host` header must be preserved; the same-origin check still runs.
- The BFF's own login is not Laravel auth. The identity code is the credential.

Embedded mode is a deployment convenience, not a security boundary. If the
Laravel app is multi-tenant, deploy one BFF per trust boundary.

## Verify

Lint the example files:

```bash
cd packages/emergency-bff/examples/laravel
./check.sh
```

The check runs `php -l` on every PHP file. It does not boot Laravel, because
the example intentionally has no application skeleton.

## Client entry point

`resources/js/emergency.ts` imports the ESM build:

```ts
import { mount } from '@dataemergencia/emergency';
```

Install the client in the Laravel application:

```bash
pnpm add @dataemergencia/emergency
```

Vite processes the import. The client is a browser-only module; do not import
it in a server-side bundle or in an SSR pass.

## CSRF strategy

Do not use `createCookieCsrfStrategy()` for the BFF. That strategy reads
Laravel's `XSRF-TOKEN` cookie for Laravel endpoints. The BFF returns its own
token from `GET /config` and `POST /session`, and the client already uses it.
Mixing the two produces `403 csrf_failed`.

## Related

- [Zero to panel](../../docs/onboarding/zero-to-panel.md)
- [Session and CSRF](../../docs/bff/session-and-csrf.md)
- [Examples](../../docs/examples.md)
