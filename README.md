# DataEmergencia Emergency BFF

Standalone backend-for-frontend for the DataEmergencia emergency module.

The package exposes the `/de-bff/emergency/*` JSON API, validates the
6-character identity code, fetches the medical profile only when an emergency
is activated, builds WhatsApp template payloads server-side, and proxies the
existing DataEmergencia API. It ships backend PHP only: no JavaScript, CSS or
other frontend assets are included.

## Split mapping

| Location                                    | Role                                  |
| ------------------------------------------- | ------------------------------------- |
| `packages/emergency-bff` in the monorepo    | Source of truth                       |
| `The-BoxHead-Guy/emergency-bff`             | Public release root (Composer source) |
| `dataemergencia/emergency-bff` on Packagist | Published package name                |

The package versions itself from Git tags: the monorepo `php-v*` tag triggers
the split workflow, and the public repository receives the matching `v*` tag.
`composer.json` deliberately has no `version` field. First-release and Packagist
steps are documented in [docs/publishing.md](docs/publishing.md).

## Documentation

- [Documentation index](docs/README.md)
- [Zero to panel](docs/onboarding/zero-to-panel.md)
- [BFF configuration](docs/bff/configuration.md)
- [API contract](docs/api-contract.md)
- [Troubleshooting](docs/operations/troubleshooting.md)

The browser panel is a separate package:
[`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency).

## Requirements

- PHP 8.4 or newer with `json`, `curl` and `mbstring`
- Composer 2.x
- A PSR-18 HTTP client, PSR-17 request/stream factories and a PSR-3 logger
  (Guzzle and its factories are installed by default)

## Install

```bash
composer require dataemergencia/emergency-bff
```

## Per-customer credentials

Every customer obtains an API key and its associated application identifier
from the DataEmergencia console. Both values are server-side only, are never
shared between customers, and the package fails fast when either is missing
(`ConfigurationException`).

## Configuration

Configuration is read from environment variables. The same values can be
passed explicitly to `Config::fromArray()`.

| Variable                               | Default                          | Purpose                                     |
| -------------------------------------- | -------------------------------- | ------------------------------------------- |
| `EMERGENCY_BFF_API_KEY`                | — (required)                     | Bearer token for the DataEmergencia API     |
| `EMERGENCY_BFF_APPLICATION_IDENTIFIER` | — (required)                     | `X-Application-Identifier` header           |
| `EMERGENCY_BFF_API_ORIGIN`             | `https://api.dataemergencia.com` | Upstream API origin                         |
| `EMERGENCY_BFF_TIMEOUT`                | `10`                             | Upstream request timeout (seconds)          |
| `EMERGENCY_BFF_RETRY_COUNT`            | `1`                              | Retries for idempotent upstream requests    |
| `EMERGENCY_BFF_ROUTE_PREFIX`           | `/de-bff/emergency`              | Public route prefix                         |
| `EMERGENCY_BFF_SESSION_NAME`           | `de_bff_emergency`               | Native session cookie name                  |
| `EMERGENCY_BFF_SESSION_TTL`            | `3600`                           | Session lifetime (seconds)                  |
| `EMERGENCY_BFF_COOKIE_PATH`            | `/`                              | Session cookie path                         |
| `EMERGENCY_BFF_COOKIE_SECURE`          | `true`                           | `Secure` cookie flag                        |
| `EMERGENCY_BFF_COOKIE_HTTP_ONLY`       | `true`                           | `HttpOnly` cookie flag                      |
| `EMERGENCY_BFF_COOKIE_SAME_SITE`       | `Lax`                            | `Strict`, `Lax` or `None`                   |
| `EMERGENCY_BFF_PUSHER_KEY`             | —                                | Public Pusher key, exposed by `GET /config` |
| `EMERGENCY_BFF_PUSHER_CLUSTER`         | —                                | Pusher cluster, exposed by `GET /config`    |
| `EMERGENCY_BFF_QR_BASE_HOST`           | `zur.dataemergencia.com`         | Host used in profile QR links               |
| `EMERGENCY_BFF_FALLBACK_ORIGIN_URL`    | `https://dataemergencia.com`     | Fallback when a QR link cannot be built     |
| `EMERGENCY_BFF_LOCAL`                  | `false`                          | Use the local QR host                       |

## HTTP API

All responses are JSON. Start with `GET /config` to obtain the first CSRF
token. Every mutating request — including the first `POST /session` — requires
that token either in the `X-CSRF-Token` header or in the `csrf_token` body
field; `POST /session` rotates it and returns the new value. Browser requests
with an `Origin` header must be same-origin.

| Method   | Route           | Purpose                                     |
| -------- | --------------- | ------------------------------------------- |
| `GET`    | `/health`       | Liveness probe                              |
| `GET`    | `/config`       | Route prefix, realtime config, CSRF token   |
| `POST`   | `/session`      | Validate the code and start a session       |
| `GET`    | `/current`      | Resume the active emergency, if any         |
| `POST`   | `/activate`     | Activate the emergency and message contacts |
| `POST`   | `/simulacrum`   | Send the support-contact test messages      |
| `POST`   | `/{id}/refresh` | Refresh emergency and responder state       |
| `DELETE` | `/{id}`         | Abort the emergency                         |

```jsonc
// POST /session  { "code": "DABC12" }
{ "code": "DABC12", "csrf_token": "…", "expires_in": 3600 }

// GET /config
{ "route_prefix": "/de-bff/emergency", "realtime": { … }, "csrf_token": "…" }

// GET /current (idle)
{ "active": false, "emergency": null, "realtime": { … } }

// POST /activate  { "geolocation": { "latitude": 4.711, "longitude": -74.0721 } }
{ "emergency_id": "…", "simulated": false, "responders": [ … ], "failures": [ … ] }

// POST /{id}/refresh
{ "emergency": { … }, "responders": [ … ] }

// DELETE /{id}
{ "emergency_id": "…", "deleted": true, "responders": [ … ] }
```

### Curl smoke test

```bash
B=http://127.0.0.1:8080/de-bff/emergency
TOKEN=$(curl -s -c jar.txt "$B/config" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["csrf_token"];')
SESSION=$(curl -s -b jar.txt -c jar.txt -H "X-CSRF-Token: $TOKEN" \
  -H 'Content-Type: application/json' -d '{"code":"DABC12"}' "$B/session")
TOKEN=$(printf '%s' "$SESSION" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["csrf_token"];')
curl -s -b jar.txt -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"geolocation":{"latitude":4.711,"longitude":-74.0721}}' "$B/activate"
curl -s -b jar.txt "$B/current"
```

Errors use one envelope and always carry the same id as the `X-Request-Id`
response header:

```json
{
  "error": {
    "code": "inactive_code",
    "message": "The code is not available for emergencies.",
    "details": [],
    "request_id": "…"
  }
}
```

Known error codes: `validation_failed`, `invalid_code`, `inactive_code`,
`unauthenticated`, `csrf_failed`, `cross_origin_denied`, `rate_limited`,
`not_found`, `method_not_allowed`, `emergency_already_active`,
`emergency_forbidden`, `emergency_not_found`, `missing_emergency_contacts`,
`missing_medical_profile`, `whatsapp_delivery_failed`, `upstream_error`,
`internal_error`.

## Host integration

The simplest host uses `Bootstrap::create()`, which wires native sessions,
Guzzle, the payload builder and the emergency service:

```php
use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Support\Bootstrap;

$kernel = Bootstrap::create();
$kernel->handle(Request::fromGlobals())->send();
```

`public/index.php` in this package is exactly that front controller.

Hosts that own their framework can construct `Kernel` directly and replace any
of its collaborators:

- **Session storage** — implement `SessionStore` (`get`, `set`, `remove`,
  `regenerate`, `destroy`) and pass it to `SessionManager`. The default
  `NativeSessionStore` issues a `de_bff_emergency` cookie with `HttpOnly`,
  `Secure`, `SameSite=Lax` and `Path=/`. A host adapter must preserve those
  flags; the kernel never sets the session cookie itself.
- **Upstream transport** — implement `ApiClient` or inject any PSR-18 client
  through `Psr18ApiClient`.
- **Profiles, emergencies and WhatsApp** — `ProfileRepository`,
  `EmergencyRepository` and `WhatsAppClient` can be replaced by host-specific
  implementations with the same behaviour.
- **Logging** — pass a PSR-3 logger. Tokens and API keys are never logged.

### Apache

```apache
DocumentRoot /path/to/app/public

<Directory /path/to/app/public>
    AllowOverride All
    Require all granted
</Directory>
```

with the shipped `public/.htaccess` rewriting every non-file request to
`index.php`.

### Nginx

```nginx
server {
    root /path/to/app/public;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

## Realtime

`GET /config` returns public Pusher credentials and the channel pattern
`whatsapp.{phone}` (leading `+` stripped). Only public channels are supported in
the MVP: message delivery updates are not authenticated, so treat them as
display-only state and keep the authoritative state in `GET /current` and
`POST /{id}/refresh`. The Pusher secret is never read or exposed, and
`/pusher/auth` is reserved but not implemented.

## Example

`examples/vanilla-php` installs this package through a Composer path repository
and runs a dependency-free browser demo against a local mock API. See its
README for the two-server command sequence.

## Tests

```bash
composer validate --strict
composer install
find src examples public -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
```

## License

MIT. See [LICENSE](LICENSE).
