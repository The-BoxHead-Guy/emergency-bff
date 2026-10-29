# Zero to panel

This guide takes a developer from an empty directory to a working emergency
panel. It is deliberately linear: complete each step before moving to the next
one.

If you only want to see the panel work on your machine first, use the mock
upstream in [Examples](../examples.md). The mock needs no credentials and sends
no real WhatsApp messages.

## Before you start

You need:

- PHP 8.4 or newer with `curl`, `json` and `mbstring`.
- Composer 2.
- A DataEmergencia API key and application identifier.
- A phone or browser that can share a location over HTTPS (or `localhost`).
- Node.js 24.22.1 or newer only if you use the JavaScript client from npm.
  The no-build client needs no Node.js.

The emergency flow sends real WhatsApp messages in production mode. Test with
codes and phone numbers that your team controls.

## 1. Install the BFF

```bash
mkdir emergency-demo && cd emergency-demo
composer require dataemergencia/emergency-bff:^0.1
```

Confirm the package is installed:

```bash
composer show dataemergencia/emergency-bff
```

Expected result: the package name, version `0.1.x`, and its Guzzle/PSR
dependencies.

## 2. Create the front controller

Create `public/index.php`:

```php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Support\Bootstrap;

Bootstrap::create()->handle(Request::fromGlobals())->send();
```

The BFF is a library, not an application. Your front controller owns the
environment, the document root and the web server. There are no assets to
publish and no migrations to run.

## 3. Configure the environment

Set these variables in the process that runs PHP-FPM or the PHP built-in
server:

```bash
export EMERGENCY_BFF_API_KEY="your-api-key"
export EMERGENCY_BFF_APPLICATION_IDENTIFIER="your-application-identifier"
export EMERGENCY_BFF_API_ORIGIN="https://api.dataemergencia.com"
export EMERGENCY_BFF_COOKIE_SECURE="true"
```

Defaults are sensible for production. The two required values have no
defaults. If either is missing, `Bootstrap::create()` throws a
`ConfigurationException` before serving a request.

Full reference: [BFF configuration](../bff/configuration.md).

## 4. Serve and check health

For a local check with the built-in server:

```bash
php -S 127.0.0.1:8080 -t public public/index.php
```

In another terminal:

```bash
curl -sS http://127.0.0.1:8080/de-bff/emergency/health
```

Expected response:

```json
{ "status": "ok", "service": "dataemergencia/emergency-bff" }
```

If this fails, check the route prefix and the front controller before
continuing.

## 5. Start a session

The BFF stores the identity code in the PHP session. Every browser call must
use the same cookie jar and the same origin.

```bash
curl -sS -c cookies.txt \
  http://127.0.0.1:8080/de-bff/emergency/config
```

Copy the `csrf_token` value from the response, then:

```bash
curl -sS -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: <csrf_token>" \
  -d '{"code":"DABC12"}' \
  http://127.0.0.1:8080/de-bff/emergency/session
```

Expected status: `201`. The response contains a new `csrf_token`; use that
token for the remaining write requests. A `422 invalid_code` means the format
is wrong, `404 inactive_code` means the code does not exist upstream.

The code format is six characters: a letter followed by five letters or
digits.

## 6. Mount the panel

The browser client is distributed as `@dataemergencia/emergency`. You can use
it without a build step.

Create `public/panel.html`:

```html
<!doctype html>
<html lang="es">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Emergency panel</title>
  </head>
  <body>
    <button id="sos" type="button">SOS</button>
    <div id="emergency-root"></div>

    <script src="https://cdn.jsdelivr.net/npm/@dataemergencia/emergency@0.1.0/dist/iife/emergency.iife.global.js"></script>
    <script>
      const client = window.DataEmergenciaEmergency.mount({
        root: '#emergency-root',
        bffBaseUrl: 'http://127.0.0.1:8080/de-bff/emergency',
        code: 'DABC12',
        trigger: '#sos',
        fallback: {
          phone: '+57 300 123 4567',
          label: 'Línea de emergencia',
        },
        onEvent: (event, payload) => console.info(event, payload),
      });
    </script>
  </body>
</html>
```

`bffBaseUrl` must include the route prefix and must be the same origin that
served the page (or a same-origin proxy). Cross-origin requests are rejected
by the BFF.

For the full client reference, see the
[client quickstart](https://github.com/The-BoxHead-Guy/emergency/blob/v0.1.0/docs/client/quickstart.md).

## 7. Activate and verify

Open `panel.html` from the same origin as the BFF and press **SOS**.

1. The browser asks for location permission. Allow it, or use the fallback
   phone link if permission is denied.
2. The panel shows `Activando emergencia…` and calls
   `POST /de-bff/emergency/activate`.
3. The BFF contacts the upstream API, sends the WhatsApp templates and creates
   the emergency.
4. The panel shows the responder list and delivery statuses.

Verify the emergency exists:

```bash
curl -sS -b cookies.txt \
  http://127.0.0.1:8080/de-bff/emergency/current
```

Expected: `"active": true` and an `emergency.emergency_id`.

## 8. Cancel the test emergency

```bash
curl -sS -b cookies.txt \
  -H "X-CSRF-Token: <csrf_token>" \
  -X DELETE \
  http://127.0.0.1:8080/de-bff/emergency/<emergency_id>
```

The BFF sends the abort WhatsApp message and deletes the upstream state.
`GET /current` should return `"active": false`.

## What to do next

- Run the complete checklist: [First integration checklist](checklist.md).
- Run the packaged example: [Vanilla PHP example](../examples.md#vanilla-php-primary).
- Read every request and response: [API contract](../api-contract.md).
- Deploy behind Apache or Nginx: [Deployment](../bff/deployment.md).
- Integrate with an existing Laravel application: [Examples](../examples.md).
- Investigate a failure: [Troubleshooting](../operations/troubleshooting.md).
