# Configuration

Applies to: vanilla PHP and Laravel integrators.

The BFF reads environment variables. Secrets are supplied by the host; nothing
is hardcoded in the package.

## Environment variables

| Variable                               | Default                          | Required | Description                                                              |
| -------------------------------------- | -------------------------------- | -------- | ------------------------------------------------------------------------ |
| `EMERGENCY_BFF_API_ORIGIN`             | `https://api.dataemergencia.com` | No       | Upstream DataEmergencia API origin. Must be a valid absolute URL.        |
| `EMERGENCY_BFF_API_KEY`                | —                                | Yes      | Per-customer API key. Sent as `Authorization: Bearer <key>`.             |
| `EMERGENCY_BFF_APPLICATION_IDENTIFIER` | —                                | Yes      | Per-customer application identifier. Sent as `X-Application-Identifier`. |
| `EMERGENCY_BFF_TIMEOUT`                | `10`                             | No       | HTTP timeout in seconds. Must be at least 1.                             |
| `EMERGENCY_BFF_RETRY_COUNT`            | `1`                              | No       | Extra attempts for idempotent `GET`/`HEAD` requests. Must be 0 or more.  |
| `EMERGENCY_BFF_ROUTE_PREFIX`           | `/de-bff/emergency`              | No       | Route prefix. Must start with `/`.                                       |
| `EMERGENCY_BFF_SESSION_NAME`           | `de_bff_emergency`               | No       | PHP session cookie name.                                                 |
| `EMERGENCY_BFF_SESSION_TTL`            | `3600`                           | No       | Idle session lifetime in seconds, sliding on activity.                   |
| `EMERGENCY_BFF_COOKIE_PATH`            | `/`                              | No       | Session cookie path.                                                     |
| `EMERGENCY_BFF_COOKIE_SECURE`          | `true`                           | No       | Send the cookie only over HTTPS. Set `false` for local HTTP demos.       |
| `EMERGENCY_BFF_COOKIE_HTTP_ONLY`       | `true`                           | No       | Hide the cookie from JavaScript. Keep this `true`.                       |
| `EMERGENCY_BFF_COOKIE_SAME_SITE`       | `Lax`                            | No       | `Lax`, `Strict` or `None`.                                               |
| `EMERGENCY_BFF_PUSHER_KEY`             | empty                            | No       | Public Pusher key. Without it, realtime is reported as unavailable.      |
| `EMERGENCY_BFF_PUSHER_CLUSTER`         | empty                            | No       | Public Pusher cluster, for example `us2`.                                |
| `EMERGENCY_BFF_QR_BASE_HOST`           | `zur.dataemergencia.com`         | No       | Host used to build QR links inside WhatsApp templates.                   |
| `EMERGENCY_BFF_FALLBACK_ORIGIN_URL`    | `https://dataemergencia.com`     | No       | Fallback origin reserved for payload generation.                         |
| `EMERGENCY_BFF_LOCAL`                  | `false`                          | No       | Build QR links against `http://localhost/<qr host>` for local testing.   |

`EMERGENCY_BFF_API_KEY` and `EMERGENCY_BFF_APPLICATION_IDENTIFIER` have no
defaults. Each customer gets its own pair. Never commit real values and never
send them to the browser.

## Example `.env`

```dotenv
EMERGENCY_BFF_API_ORIGIN=https://api.dataemergencia.com
EMERGENCY_BFF_API_KEY=
EMERGENCY_BFF_APPLICATION_IDENTIFIER=
EMERGENCY_BFF_TIMEOUT=10
EMERGENCY_BFF_RETRY_COUNT=1
EMERGENCY_BFF_ROUTE_PREFIX=/de-bff/emergency
EMERGENCY_BFF_SESSION_NAME=de_bff_emergency
EMERGENCY_BFF_SESSION_TTL=3600
EMERGENCY_BFF_COOKIE_PATH=/
EMERGENCY_BFF_COOKIE_SECURE=true
EMERGENCY_BFF_COOKIE_HTTP_ONLY=true
EMERGENCY_BFF_COOKIE_SAME_SITE=Lax
EMERGENCY_BFF_PUSHER_KEY=
EMERGENCY_BFF_PUSHER_CLUSTER=
```

The package does not load `.env` files. Your framework or process manager must
export these variables, or you can pass an explicit map to the bootstrap:

```php
$kernel = \DataEmergencia\EmergencyBff\Support\Bootstrap::create([
    'EMERGENCY_BFF_API_KEY' => $_ENV['EMERGENCY_BFF_API_KEY'],
    'EMERGENCY_BFF_APPLICATION_IDENTIFIER' => $_ENV['EMERGENCY_BFF_APPLICATION_IDENTIFIER'],
]);
```

Explicit values win over the process environment. An empty string counts as
missing.

## Programmatic configuration

`Config::fromArray()` uses camelCase keys and is useful when your framework
already has a typed configuration object:

```php
use DataEmergencia\EmergencyBff\Config\Config;

$config = Config::fromArray([
    'apiOrigin' => 'https://api.dataemergencia.com',
    'apiKey' => $apiKey,
    'applicationIdentifier' => $applicationIdentifier,
    'timeout' => 10,
    'retryCount' => 1,
    'routePrefix' => '/de-bff/emergency',
    'sessionName' => 'de_bff_emergency',
    'sessionTtl' => 3600,
    'cookieSecure' => true,
    'cookieSameSite' => 'Lax',
    'pusherKey' => $pusherKey,
    'pusherCluster' => $pusherCluster,
]);
```

Both `apiKey` and `applicationIdentifier` are required.

## Validation

`ConfigurationException` is thrown when:

- `EMERGENCY_BFF_API_KEY` or `EMERGENCY_BFF_APPLICATION_IDENTIFIER` is missing or
  empty.
- The API origin is not a valid URL.
- The timeout is less than 1 second.
- The retry count is negative.
- The session TTL is less than 1 second.
- The route prefix does not start with `/`.
- The SameSite value is not `Lax`, `Strict` or `None`.

The exception message never includes the credential values.

## Cookie flags

Production defaults:

- `Secure=true`: the session cookie is HTTPS-only.
- `HttpOnly=true`: JavaScript cannot read the session cookie.
- `SameSite=Lax`: the cookie survives top-level navigation but not cross-site
  POSTs.

Set `EMERGENCY_BFF_COOKIE_SECURE=false` only for local HTTP. If the BFF and the
page are on different sites, `SameSite=None` also requires `Secure=true` and
HTTPS on both sides. Cross-origin requests are rejected by the same-origin
middleware, so a different-site deployment requires a deliberate front-door
change, not only a cookie flag.

## Next step

[Session and CSRF](session-and-csrf.md).
