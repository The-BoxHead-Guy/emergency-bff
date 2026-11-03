# Troubleshooting

Start with the response body. Every BFF error uses the same envelope:

```json
{
  "error": {
    "code": "inactive_code",
    "message": "The code is not active.",
    "details": null,
    "request_id": "9f2c1a7d4b6e8c01"
  }
}
```

The `code` is stable; the `message` is for humans. Quote the `request_id` when
you contact support, and never paste an API key into a ticket.

## The BFF returns 404 for every route

- Confirm the request path starts with `EMERGENCY_BFF_ROUTE_PREFIX`
  (default `/de-bff/emergency`).
- Confirm the web server forwards all paths to the front controller. Apache
  needs the rewrite in [Deployment](../bff/deployment.md); Nginx needs
  `try_files`.
- Confirm the front controller calls `Bootstrap::create()` and not only the
  autoloader.

`404 not_found` means the BFF handled the request but has no such route.
A framework-generated 404 means the request never reached the BFF.

## `500 internal_error` on every request

- Check the PHP error log. The BFF converts unhandled exceptions into
  `internal_error` and does not expose the message.
- Confirm PHP 8.4+ and the `curl`, `json` and `mbstring` extensions.
- Confirm the session directory is writable.
- If you passed a PSR-3 logger to `Bootstrap::create(null, $logger)`, inspect
  its output.

## Startup fails with `ConfigurationException`

`EMERGENCY_BFF_API_KEY` and `EMERGENCY_BFF_APPLICATION_IDENTIFIER` are
required and must be non-empty. The exception lists the missing names.

Other configuration errors:

| Message                          | Fix                                                           |
| -------------------------------- | ------------------------------------------------------------- |
| API origin must be a valid URL   | Include the scheme, e.g. `https://api.dataemergencia.com`.    |
| timeout must be at least 1       | Use a positive number of seconds.                             |
| route prefix must start with `/` | Prefix the value with `/`.                                    |
| cookie SameSite                  | Use `Lax`, `Strict` or `None`. `None` also requires `Secure`. |

## `401 unauthenticated`

There is no BFF session for this request.

- The browser is not sending the `de_bff_emergency` cookie. Check
  `SameSite`, `Secure` and the cookie domain.
- The session expired after `EMERGENCY_BFF_SESSION_TTL` seconds of inactivity.
- The load balancer routed to a worker without shared session storage.
- A server-side call forgot the cookie jar.

Fix the cookie or session storage, then call `POST /session` again.

## `403 cross_origin_denied`

The request `Origin` and `Host` differ. This is a deliberate same-origin
policy, not a CORS misconfiguration.

- Serve the page from the same scheme, host and port as the BFF.
- If a reverse proxy terminates TLS, preserve the original `Host`.
- Do not add wildcard CORS headers; the BFF does not use them.

## `403 csrf_failed`

The `X-CSRF-Token` header (or `csrf_token` body field) is missing or stale.

- `POST /session` rotates the token. Read the new token from the response and
  use it for subsequent writes.
- Fetch a token from `GET /config` before the first write.
- Two tabs share a session; the last tab to start a session invalidates the
  other tab's token. Reload the stale tab.
- A Laravel host using its own CSRF middleware must forward the BFF token, not
  replace it. See [Session and CSRF](../bff/session-and-csrf.md).

## `422 invalid_code`

The code does not match `^[A-Za-z][A-Za-z0-9]{5}$`. Check for spaces, a
missing character, or a scanned QR value that includes a URL instead of the
six-character code.

## `404 inactive_code`

The format is valid but the upstream reports that the code does not exist or
is not active. Verify the code in the issuing system; the BFF deliberately
does not distinguish "unknown" from "inactive".

## `409 emergency_already_active`

The code already has an active emergency. Do not create another one. Call
`GET /current` to load it, or use `GET /v2/emergency/qr/{code}/active`
upstream semantics through the BFF refresh flow. If the previous emergency is
stale, resolve it in the operator tooling before retrying.

## `422 missing_medical_profile` or `422 missing_emergency_contacts`

The upstream profile is incomplete. The BFF refuses to activate without
personal information and at least one emergency contact with a phone number.
Ask the customer to complete the profile in the issuing system.

## `502 upstream_error`

The upstream API returned a non-success response or could not be reached.
`details` contains the upstream status and code when available.

- Check `EMERGENCY_BFF_API_ORIGIN`.
- Check that the API key and application identifier are valid for that origin.
- Check upstream availability. Transient GET failures are retried once by
  default; writes are not retried automatically.
- Use the `request_id` to correlate with upstream logs.

## `502 whatsapp_delivery_failed`

Every WhatsApp send failed. The emergency was **not** created. The payload is
validated before sending, so the usual causes are upstream WhatsApp outages,
invalid recipient numbers or template approval problems. The person can retry
or use the fallback phone number.

Partial failure is different: activation succeeds and the response lists
failed responders. The frontend should surface the partial failure and keep the
emergency active.

## `429 rate_limited`

The session exceeded 60 requests in 60 seconds for one method and route
bucket. `details.retry_after` is the window length, not a countdown. Wait that
long, then retry. Do not loop on activation.

## The panel opens but activation does nothing

- Check the browser console for `emergency:failed`, especially
  `geolocation_denied` or `geolocation_timeout`.
- Location requires a secure context: HTTPS or `localhost`.
- The BFF requires a location for activation. If permission is denied, the
  panel shows the host-supplied fallback phone number instead.
- Confirm `bffBaseUrl` includes the route prefix and no accidental double
  slash.

## The panel shows no realtime updates

- Configure the public Pusher key and cluster in the browser library's
  `realtime` mount option. The BFF no longer returns them.
- Confirm the API's broadcaster is publishing the `whatsapp.{recipientId}`
  events for the deployment.
- Check that `wss://` traffic to Pusher is allowed by the network.
- Realtime is metadata only. Refresh still converges through `GET /current`
  when the tab regains focus or the network reconnects.

## Session is lost after a deploy

Sessions are stored by PHP, not by the package. A deploy that wipes the
session directory, changes the session name or switches to a different worker
pool logs everyone out. Use persistent shared storage and keep
`EMERGENCY_BFF_SESSION_NAME` stable.

## Cookies are not set on `localhost`

`EMERGENCY_BFF_COOKIE_SECURE=true` prevents cookies over plain HTTP. Set
`EMERGENCY_BFF_COOKIE_SECURE=false` only for local development.

## Apache serves the PHP source or returns 500

- `AllowOverride All` is required if you use `.htaccess`.
- `mod_rewrite` must be enabled.
- The document root must be the directory that contains the front controller.
- Do not point the document root at the package directory.

## Nginx returns 404 for `/de-bff/emergency/...`

Add the `try_files` fallback from [Deployment](../bff/deployment.md) and reload
Nginx. Confirm `fastcgi_param HTTP_HOST` or `Host` is preserved so the
same-origin check sees the original host.

## Getting more detail

The BFF does not write its own log file. Unhandled exceptions go to the PHP
error log; handled errors are returned to the caller. To capture structured
logs, pass a PSR-3 logger:

```php
$logger = new \Monolog\Logger('emergency-bff');
$kernel = \DataEmergencia\EmergencyBff\Support\Bootstrap::create(null, $logger);
```

Never log the API key, full phone numbers or complete WhatsApp payloads in
production.
