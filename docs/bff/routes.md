# Routes

Applies to: integrators calling the JSON API directly and frontend authors.

All routes are relative to `EMERGENCY_BFF_ROUTE_PREFIX`, default
`/de-bff/emergency`. Every response is JSON, carries `X-Request-Id` and
`Cache-Control: no-store`.

## Route table

| Method   | Path            | Auth           | Success | Purpose                                                   |
| -------- | --------------- | -------------- | ------- | --------------------------------------------------------- |
| `GET`    | `/health`       | None           | `200`   | Liveness probe.                                           |
| `GET`    | `/config`       | None           | `200`   | Route prefix and CSRF token.                              |
| `POST`   | `/session`      | CSRF           | `201`   | Validate the code and start the session.                  |
| `GET`    | `/current`      | Session        | `200`   | Resume an active emergency for the session code.          |
| `POST`   | `/activate`     | Session + CSRF | `201`   | Send WhatsApp alerts and create the emergency.            |
| `POST`   | `/simulacrum`   | Session + CSRF | `200`   | Send the simulation template. Never creates an emergency. |
| `POST`   | `/{id}/refresh` | Session + CSRF | `200`   | Read the current upstream emergency state.                |
| `DELETE` | `/{id}`         | Session + CSRF | `200`   | Send the abort template and close the emergency.          |

Unknown paths return `404 not_found`. Known paths with the wrong method return
`405 method_not_allowed` and an `allowed` array in `details`.

## Middleware order

1. Same origin.
2. Session start and TTL check.
3. Session-backed rate limit (60 requests per 60 seconds per `METHOD:path`).
4. CSRF validation for unsafe methods.

`GET /health` and `GET /config` do not require an identity but still pass
through the pipeline.

## `GET /health`

```bash
curl -i http://localhost/de-bff/emergency/health
```

```json
{ "status": "ok", "service": "dataemergencia/emergency-bff" }
```

## `GET /config`

Returns the route prefix and a CSRF token:

```json
{
  "route_prefix": "/de-bff/emergency",
  "csrf_token": "…"
}
```

The CSRF token is the value to send in `X-CSRF-Token` for the next unsafe
request.

## `POST /session`

Request:

```json
{ "code": "DABC12" }
```

Response `201`:

```json
{
  "code": "DABC12",
  "csrf_token": "…",
  "expires_in": 3600
}
```

Validation:

- Missing or blank `code` → `422 validation_failed`.
- Code that does not match `^[A-Za-z][A-Za-z0-9]{5}$` → `422 invalid_code`.
- Well-formed code that does not exist upstream → `404 inactive_code`.

The session identity is stored only after upstream validation. The session ID is
regenerated and the CSRF token is rotated.

## `GET /current`

```json
{
  "active": true,
  "emergency": {
    "emergency_id": "EMG123456",
    "initiator_id": "DABC12",
    "is_active": true,
    "is_simulated": false,
    "responders": []
  }
}
```

When no emergency exists upstream for the code, `active` is `false` and
`emergency` is `null`. The BFF clears the session snapshot in that case.

## `POST /activate`

Request:

```json
{
  "geolocation": { "latitude": 4.711, "longitude": -74.0721 }
}
```

`geolocation` may be nested under `coords` (`{ "coords": { "latitude": … } }`).
Coordinates are validated as numeric when present. Send a position when the
browser allows it and fall back to the configured phone number when it does
not.

Optional idempotency:

```http
Idempotency-Key: 2b7a…
```

The key can also be sent as `idempotency_key` in the body. Repeating a request
with the same key returns the stored result instead of sending WhatsApp messages
again. Generate a UUID per operation.

Response `201`:

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

`partial_failure` is `true` when at least one responder failed and at least one
succeeded. If every responder fails, the BFF returns
`502 whatsapp_delivery_failed` and does not create the upstream emergency.

## `POST /simulacrum`

Sends the simulation template to the emergency contacts. The response has the
same shape as activation with `simulated: true` and `emergency_id: null`. It
never creates an upstream emergency.

The BFF does not prevent a simulation while a real emergency is active;
frontends should block that case in the UI.

## `POST /{id}/refresh`

```json
{
  "emergency": { "emergency_id": "EMG123456", "…": "…" }
}
```

`404 emergency_not_found` when the upstream emergency does not exist.

## `DELETE /{id}`

Sends the abort template to the responders, deletes the upstream emergency and
clears the session snapshot:

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

`403 emergency_forbidden` when the ID does not belong to the session code.
`404 emergency_not_found` when no active emergency matches.

## Error envelope

Every error uses the same shape:

```json
{
  "error": {
    "code": "invalid_code",
    "message": "The identity code is not valid.",
    "details": {},
    "request_id": "9f3a1c7e2b4d8065"
  }
}
```

| HTTP | `code`                       | Meaning                                            |
| ---- | ---------------------------- | -------------------------------------------------- |
| 401  | `unauthenticated`            | No identity in the session; start a session first. |
| 403  | `cross_origin_denied`        | `Origin` host or port differs from `Host`.         |
| 403  | `csrf_failed`                | Missing or invalid CSRF token.                     |
| 403  | `emergency_forbidden`        | The emergency belongs to another code.             |
| 404  | `not_found`                  | Unknown route.                                     |
| 404  | `inactive_code`              | Code is well formed but not active upstream.       |
| 404  | `emergency_not_found`        | No emergency with that ID.                         |
| 405  | `method_not_allowed`         | Wrong method; see `details.allowed`.               |
| 409  | `emergency_already_active`   | The code already has an active emergency.          |
| 422  | `validation_failed`          | Missing or malformed input.                        |
| 422  | `invalid_code`               | Code does not match the 6-character format.        |
| 422  | `missing_medical_profile`    | Profile lacks required personal information.       |
| 422  | `missing_emergency_contacts` | No emergency contacts configured.                  |
| 429  | `rate_limited`               | Too many requests; see `details.retry_after`.      |
| 502  | `whatsapp_delivery_failed`   | Every WhatsApp message failed.                     |
| 502  | `upstream_error`             | The upstream API failed; see `details.status`.     |
| 500  | `internal_error`             | Unhandled server error.                            |
| 500  | `emergency_error`            | Unmapped emergency domain error.                   |

## Complete curl flow

```bash
BASE=http://localhost/de-bff/emergency

curl -sS -c /tmp/de-bff.cookies "$BASE/config" -o /tmp/de-bff-config.json
TOKEN=$(php -r '$c=json_decode(file_get_contents("/tmp/de-bff-config.json"),true); echo $c["csrf_token"];')

curl -sS -b /tmp/de-bff.cookies -c /tmp/de-bff.cookies \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"code":"DABC12"}' "$BASE/session" -o /tmp/de-bff-session.json

TOKEN=$(php -r '$s=json_decode(file_get_contents("/tmp/de-bff-session.json"),true); echo $s["csrf_token"];')

curl -sS -b /tmp/de-bff.cookies \
  -H "X-CSRF-Token: $TOKEN" -H 'Content-Type: application/json' \
  -d '{"geolocation":{"latitude":4.711,"longitude":-74.0721}}' \
  "$BASE/activate"
```

The example above sends a **real** WhatsApp message when pointed at the
production API. Use the mock API for local tests.

## Next step

[Upstream API mapping](upstream-api.md).
