# DataEmergencia Emergency BFF

Backend-for-frontend for the DataEmergencia emergency flow. It runs the
server-side work behind an SOS activation: validating the identity code,
coordinating the emergency contacts and keeping the upstream emergency state in
sync. Your application owns the front controller, the web server and the
session backend.

## Recommended library

Use [`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency)
for the browser panel. It consumes this backend and provides the user-facing SOS
experience; this package supplies the API it needs.

## Requirements

- PHP 8.4 or newer with `json`, `curl` and `mbstring`.
- Composer 2.x.
- A PSR-18 HTTP client, PSR-17 request/stream factories and a PSR-3 logger
  (Guzzle and its factories are installed by default).

## Install

```bash
composer require dataemergencia/emergency-bff
```

## Configuration

Configuration is read from the environment. The same values can be passed
explicitly to `Config::fromArray()`.

| Variable                               | Purpose                                        |
| -------------------------------------- | ---------------------------------------------- |
| `EMERGENCY_BFF_API_KEY`                | Per-customer API credential. Server-side only. |
| `EMERGENCY_BFF_APPLICATION_IDENTIFIER` | Per-customer application identifier.           |

Both values are issued per deployment. Keep them in the server environment and
never commit them or expose them to the browser. If either is missing,
`Bootstrap::create()` throws a `ConfigurationException` before serving a
request.

See the [configuration reference](docs/bff/configuration.md) for the optional
tuning variables.

## Usage

The package is a library, not an application. A minimal front controller:

```php
use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Support\Bootstrap;

Bootstrap::create()
    ->handle(Request::fromGlobals())
    ->send();
```

`public/index.php` in this package is the reference front controller.

## HTTP API

All responses are JSON. Routes are relative to the configured prefix, which is
`/de-bff/emergency` by default. Read the CSRF token from `GET /config` and send
it as `X-CSRF-Token` on unsafe requests; `POST /session` rotates it.

### `GET /health`

```json
{ "status": "ok", "service": "dataemergencia/emergency-bff" }
```

### `GET /config`

Returns the route prefix and the first CSRF token.

```json
{
  "route_prefix": "/de-bff/emergency",
  "csrf_token": "…"
}
```

### `POST /session`

Validates the identity code and starts a session.

```json
{ "code": "DABC12" }
```

```json
{ "code": "DABC12", "csrf_token": "…", "expires_in": 3600 }
```

### `GET /current`

Returns the active emergency for the session, or `"active": false` when there
is none.

```json
{
  "active": true,
  "emergency": {
    "emergency_id": "EMG123456",
    "is_active": true,
    "is_simulated": false,
    "responders": []
  }
}
```

### `POST /activate`

Activates the emergency and messages the emergency contacts.

```json
{ "geolocation": { "latitude": 4.711, "longitude": -74.0721 } }
```

```json
{
  "emergency_id": "EMG123456",
  "simulated": false,
  "responders": [
    {
      "phone": "573001234567",
      "name": "Grace Hopper",
      "sent": true,
      "delivered": false,
      "read": false,
      "failed": false,
      "message_id": "wamid.…"
    }
  ],
  "failures": [],
  "partial_failure": false,
  "total_failure": false
}
```

### `POST /simulacrum`

Sends the simulation messages to the emergency contacts. The response has the
same shape as activation with `"simulated": true` and `"emergency_id": null`.
It never creates an emergency.

### `POST /{id}/refresh`

Returns the current upstream state for an emergency.

```json
{
  "emergency": {
    "emergency_id": "EMG123456",
    "responders": [
      {
        "phone": "573001234567",
        "name": "Grace Hopper",
        "sent": true,
        "delivered": false,
        "read": false,
        "failed": false,
        "message_id": "42"
      }
    ]
  }
}
```

On activation, `message_id` is the WhatsApp WAMID returned by the send
endpoint. On `GET /current` and `POST /{id}/refresh`, it is the upstream
internal message id normalized to a decimal string. Both are `string | null`.

### `DELETE /{id}`

Aborts the emergency and clears the session state.

```json
{
  "emergency_id": "EMG123456",
  "simulated": false,
  "responders": [],
  "failures": [],
  "partial_failure": false,
  "total_failure": false,
  "deleted": true
}
```

Errors use one envelope and carry the same id as the `X-Request-Id` response
header:

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

## Host integration

`Bootstrap::create()` wires native sessions, Guzzle, the payload builder and the
emergency service. Hosts that own their framework can construct `Kernel`
directly and replace any collaborator:

- **Session storage** — implement `SessionStore` and pass it to `SessionManager`.
- **Upstream transport** — implement `ApiClient` or inject any PSR-18 client
  through `Psr18ApiClient`.
- **Profiles, emergencies and WhatsApp** — `ProfileRepository`,
  `EmergencyRepository` and `WhatsAppClient` are replaceable.
- **Logging** — pass a PSR-3 logger. Tokens and API keys are never logged.

## Realtime

Realtime delivery updates are published by the DataEmergencia API on public
channels. The BFF does not own, forward or configure Pusher; it only proxies
session and emergency reads. Configure the public Pusher key and cluster in the
browser library's own mount options. Channels are public and carry metadata
only, so treat their events as hints and reconcile through `GET /current` or
`POST /{id}/refresh`.

## Example

`examples/vanilla-php` contains a dependency-free demo and a mock upstream. See
its README for the two-server command sequence.

## Tests

```bash
composer validate --strict
composer install
composer test
```

## Releasing

The BFF is published from the split repository
`The-BoxHead-Guy/emergency-bff` and consumed through Packagist. The full runbook
is [Releasing the BFF](docs/release/releasing.md). The short version:

```bash
git tag -a emergency-bff-v0.1.3 -m "release: 0.1.3"
git push origin emergency-bff-v0.1.3
```

The tag push runs the split workflow, which mirrors `packages/emergency-bff` and
pushes the split tag `v0.1.3`; Packagist picks it up from there. `composer.json`
has no `version` field by design.

## Documentation

Start at the [documentation index](docs/README.md).

## License

MIT. See [LICENSE](LICENSE).
