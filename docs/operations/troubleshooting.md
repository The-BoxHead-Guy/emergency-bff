# Troubleshooting

Start with `error.code`. It is a stable machine value; `error.message` is
Spanish presentation copy and can change without a version bump. Quote
`error.request_id` when you escalate, and never paste an API key into a ticket.

## Failures by code

| Code                                                             | Usual cause                                          | What to check                                                                                                           |
| ---------------------------------------------------------------- | ---------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------- |
| `401 unauthenticated`                                            | No session for this request                          | Cookie sent, `SameSite`/`Secure`, `EMERGENCY_BFF_SESSION_TTL`, shared session storage when multiple nodes serve traffic |
| `403 cross_origin_denied`                                        | `Origin` and `Host` differ                           | Same scheme/host/port; reverse proxy preserves `Host`; no wildcard CORS                                                 |
| `403 csrf_failed`                                                | Missing or stale token                               | Read the token from `GET /config`; `POST /session` rotates it; reload a stale second tab                                |
| `403 emergency_forbidden`                                        | The ID belongs to another code                       | Refresh the ID returned by your own session, not an old one                                                             |
| `404 inactive_code`                                              | Code is well formed but not active upstream          | Verify the code in the issuing system; the BFF does not distinguish unknown from inactive                               |
| `404 emergency_not_found`                                        | No active emergency with that ID                     | `GET /current` first; do not retry with a stale id                                                                      |
| `404 not_found`                                                  | Path does not match a route                          | Route prefix and front-controller rewrite                                                                               |
| `405 method_not_allowed`                                         | Wrong verb                                           | `details.allowed` lists the methods for that path                                                                       |
| `409 emergency_already_active`                                   | The code already has an active emergency             | Load it with `GET /current`; do not create a second one                                                                 |
| `422 invalid_code`                                               | Code does not match `^[A-Za-z][A-Za-z0-9]{5}$`       | Spaces, missing characters, or a scanned QR URL instead of the code                                                     |
| `422 missing_medical_profile` / `422 missing_emergency_contacts` | Incomplete upstream profile                          | Ask the customer to complete the profile in the issuing system                                                          |
| `422 validation_failed`                                          | Malformed body or query                              | `details.field` names the field; geolocation must be numeric                                                            |
| `429 rate_limited`                                               | More than 60 requests in 60 seconds for one endpoint | Wait `details.retry_after` seconds; never loop on activation                                                            |
| `402 insufficient_credits`                                       | Account cannot pay for a billed send                 | Top up upstream; the BFF deleted the emergency it created                                                               |
| `502 upstream_error`                                             | Upstream rejected or was unreachable                 | `EMERGENCY_BFF_API_ORIGIN`, credentials for that origin, upstream health                                                |
| `502 whatsapp_delivery_failed`                                   | Every WhatsApp send failed                           | WhatsApp outage, invalid recipient numbers, template approval; retry or use the fallback phone                          |
| `500 internal_error`                                             | Unhandled exception                                  | PHP error log; pass a PSR-3 logger and inspect it                                                                       |

## 404 for every route

- The request path must start with `EMERGENCY_BFF_ROUTE_PREFIX`
  (default `/de-bff/emergency`).
- The web server must forward every path under the prefix to the front
  controller. Apache needs `mod_rewrite` with `AllowOverride All`; Nginx needs
  `try_files $uri $uri/ /index.php?$query_string`.
- The front controller must call `Bootstrap::create()`, not only the autoloader.
- The document root must contain the front controller; never point it at the
  package directory.

A BFF `404 not_found` means the request reached the BFF. A framework-generated
404 means it never did.

## Boot fails

`ConfigurationException` lists the missing or invalid values. The API key and
application identifier are required and must be non-empty; the API origin needs
a scheme; the route prefix needs a leading `/`; `SameSite` is one of
`Lax`/`Strict`/`None`.

`500` on every request also means: PHP below 8.4, a missing
`curl`/`json`/`mbstring` extension, or an unwritable session directory.

## Session is lost

- After a deploy that wiped the session directory, changed the session name or
  switched worker pools.
- Under a load balancer without shared session storage or sticky sessions.
- On plain HTTP when `EMERGENCY_BFF_COOKIE_SECURE=true`. Disable `Secure` only
  for local development.

Keep `EMERGENCY_BFF_SESSION_NAME` stable and use persistent storage for shared
workers.

## The panel never sends

- Location requires a secure context: HTTPS or `localhost`.
- Check the browser console for `geolocation_denied` or `geolocation_timeout`.
  When permission is denied, the panel must show the host fallback phone instead
  of claiming an alert was sent.
- `bffBaseUrl` must include the route prefix and no double slash.

## No realtime updates

The BFF does not publish or configure realtime. Configure the public Pusher key
and cluster in the browser library's own mount options, and confirm the API's
broadcaster is publishing for the deployment. Events are metadata only; call
`GET /current` or `POST /{id}/refresh` to reconcile, especially on focus and
reconnect.

## More detail

The BFF writes no log file of its own. Unhandled exceptions go to the PHP error
log; handled errors are returned to the caller. Pass a PSR-3 logger to
`Bootstrap::create(null, $logger)` to capture structured logs, and keep API
keys, full phone numbers and payload bodies out of production logs.
