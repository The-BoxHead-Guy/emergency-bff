# Zero to panel

Applies to: developers integrating the BFF for the first time.

This guide goes from an empty directory to a working emergency flow. If you only
want to see the flow run locally, skip to
[run the demo](#5-run-the-flow-offline).

## What you are building

| Piece                                                           | Owner                       |
| --------------------------------------------------------------- | --------------------------- |
| Landing page, QR entry, branding, legal copy                    | Your application            |
| Accounts, consent, operator tooling                             | Your application            |
| Front controller, web server, PHP-FPM, session storage          | Your application            |
| Code validation, session binding, CSRF, same-origin, rate limit | BFF                         |
| WhatsApp payload selection and per-contact fan-out              | BFF                         |
| Emergency create, read, refresh, abort                          | BFF                         |
| API credentials and upstream calls                              | BFF (server-side only)      |
| SOS panel UI and browser state                                  | `@dataemergencia/emergency` |

The browser talks only to the BFF. It never sees the DataEmergencia API key, the
application identifier or an upstream token.

## Before you start

- PHP 8.4 or newer
- Composer 2.x
- An API key and application identifier issued by DataEmergencia
- A host that can share a location over HTTPS, or `localhost` for testing

The flow sends real WhatsApp messages in production. Test with codes and phone
numbers your team controls.

## 1. Install the package

```bash
composer require dataemergencia/emergency-bff
```

## 2. Create the front controller

The package is a library, not an application. It ships `public/index.php` as the
reference front controller; copy it, or mount the kernel yourself:

```php
use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Support\Bootstrap;

Bootstrap::create()->handle(Request::fromGlobals())->send();
```

Point the document root at the directory that contains that file and forward
every path under the route prefix to it. Never expose `src/`, `vendor/` or
`examples/` over HTTP.

If you embed the kernel in an existing application, read
[Session and CSRF](../bff/session-and-csrf.md) first: the BFF owns its session
and must not share yours.

## 3. Configure the credentials

```bash
export EMERGENCY_BFF_API_KEY="your-api-key"
export EMERGENCY_BFF_APPLICATION_IDENTIFIER="your-application-identifier"
```

Both are required and have no defaults; the bootstrap fails before serving a
request if either is missing. The remaining settings have safe defaults and are
listed in [Configuration](../bff/configuration.md).

## 4. Serve and check health

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

```bash
curl -sS http://127.0.0.1:8080/de-bff/emergency/health
```

```json
{ "status": "ok", "service": "dataemergencia/emergency-bff" }
```

If this fails, check the route prefix and the front controller before
continuing. A framework-generated 404 means the request never reached the BFF.

## 5. Run the flow offline

`examples/vanilla-php` is a complete host with a mock upstream. It needs no
credentials and sends no real messages:

```bash
cd examples/vanilla-php
./demo.sh
```

`demo.sh` installs dependencies, starts the mock API and the BFF, runs the
end-to-end smoke test (`smoke.sh`) and leaves a browser demo at
<http://127.0.0.1:8080>. Use it to see the exact request and response shapes.

The demo codes are `DABC12` (valid), `ZZZZZZ` (inactive) and `bad` (malformed).
`ZERO12` has no credits and returns `402 insufficient_credits` on activation.

## 6. Connect the browser panel

The panel is the separate
[`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency)
library. Mount it on the same origin as the BFF and point it at the route prefix
(`http://127.0.0.1:8080/de-bff/emergency` in the example). Cross-origin mounts
are rejected by design.

The library owns geolocation, the SOS interaction and the fallback phone number.
Its mount options are documented in its own repository.

## 7. Verify a real deployment

1. Start a session with a test code and confirm `GET /current` returns
   `active: false`.
2. Activate and confirm a test contact receives the message.
3. Refresh and confirm the responder statuses.
4. Abort and confirm `GET /current` returns `active: false` again.

## Next

- [Examples](examples.md) for the Laravel reference and the API collection.
- [HTTP contract](../bff/routes.md) for every route, field and error code.
- [Activation flow](../bff/activation-flow.md) for the order and failure paths.
- [Troubleshooting](../operations/troubleshooting.md) when a step fails.
