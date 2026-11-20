# HTTP contract

Applies to: integrators calling the BFF from a frontend, and frontend authors.

This is the contract between the browser and the BFF. It is versioned with the
package: additive fields are compatible, removing or renaming a field is
breaking.

## Base URL

```text
{origin}{EMERGENCY_BFF_ROUTE_PREFIX}
```

Default: `https://example.com/de-bff/emergency`.

Requests and responses are JSON. The browser must send cookies; the session
cookie is HTTP-only. Every response carries:

```http
X-Request-Id: 9f2c1a7b4e8d0c31
Cache-Control: no-store
```

## Request pipeline

```text
same origin → session → rate limit → CSRF → route
```

- **Same origin.** `Origin` must match `Host`, including the port. A request
  without `Origin` (server-to-server, curl) is allowed. A cross-origin browser
  call gets `403 cross_origin_denied` before the session is read.
- **Session.** The validated code, the CSRF token and the last emergency
  snapshot live in the PHP session, never in the browser.
- **Rate limit.** 60 requests per 60 seconds per `METHOD:path` bucket, scoped to
  the session. Exceeding it returns `429 rate_limited` with
  `details.retry_after`.
- **CSRF.** `POST`, `PUT`, `PATCH` and `DELETE` require the token issued by
  `GET /config` or `POST /session`, in `X-CSRF-Token` or the `csrf_token` body
  field.

`GET /health` and `GET /config` need no identity but still pass the pipeline.
Only unsafe methods need a token, so `GET /credits` and `GET /credits/history`
need a session but no CSRF token.

## Routes

| Method   | Path               | Auth           | Success | Purpose                                                                    |
| -------- | ------------------ | -------------- | ------- | -------------------------------------------------------------------------- |
| `GET`    | `/health`          | None           | `200`   | Liveness probe.                                                            |
| `GET`    | `/config`          | None           | `200`   | Route prefix and CSRF token.                                               |
| `POST`   | `/session`         | CSRF           | `201`   | Validate the code and start the session.                                   |
| `GET`    | `/current`         | Session        | `200`   | Resume an active emergency for the session code.                           |
| `GET`    | `/credits`         | Session        | `200`   | Credit balance for the session code.                                       |
| `GET`    | `/credits/history` | Session        | `200`   | Paginated credit ledger, newest first.                                     |
| `POST`   | `/activate`        | Session + CSRF | `201`   | Send WhatsApp alerts and create the emergency.                             |
| `POST`   | `/simulacrum`      | Session + CSRF | `200`   | Send the simulation template via a pre-created active simulated emergency. |
| `POST`   | `/{id}/refresh`    | Session + CSRF | `200`   | Read the current upstream emergency state.                                 |
| `DELETE` | `/{id}`            | Session + CSRF | `200`   | Send the abort template and close the emergency.                           |

An unknown path returns `404 not_found`. A known path with the wrong method
returns `405 method_not_allowed` with `details.allowed`.

## `GET /health`

```json
{ "status": "ok", "service": "dataemergencia/emergency-bff" }
```

No session and no CSRF. It does not call upstream, so it only proves PHP and the
routing layer are alive.

## `GET /config`

Returns the route prefix and a CSRF token. Call it before the first unsafe
request.

```json
{
  "route_prefix": "/de-bff/emergency",
  "csrf_token": "…"
}
```

The BFF publishes no realtime configuration. Delivery events are published by
the DataEmergencia API and the browser library mounts with its own public
settings.

## `POST /session`

```json
{ "code": "DABC12" }
```

```json
{
  "code": "DABC12",
  "csrf_token": "…",
  "expires_in": 3600
}
```

The code is validated locally against `^[A-Za-z][A-Za-z0-9]{5}$` and then
checked upstream. The identity is stored only after upstream confirms it; the
session ID is regenerated and the CSRF token rotated. A different code starts a
new identity.

Validation failures:

| Status | Code                | Cause                               |
| ------ | ------------------- | ----------------------------------- |
| 422    | `validation_failed` | Missing or blank `code`.            |
| 422    | `invalid_code`      | Code does not match the format.     |
| 404    | `inactive_code`     | Code is well formed but not active. |

A transport or upstream `5xx` failure is `502 upstream_error`, never
`inactive_code`: "cannot verify" and "does not exist" are different answers.

## `GET /current`

```json
{
  "active": true,
  "emergency": {
    "emergency_id": "EMG123456",
    "initiator_id": "DABC12",
    "reporter_phone": "+584121301234",
    "is_active": true,
    "is_simulated": false,
    "responders": []
  }
}
```

When no emergency exists upstream:

```json
{ "active": false, "emergency": null }
```

This is how a reload discovers an emergency, and the reconciliation source when
realtime is unavailable. The BFF caches the snapshot in the session; upstream
stays the source of truth.

## `GET /credits`

```json
{
  "credit": {
    "code": "DABC12",
    "domain": "emergency",
    "balance": 9,
    "contacts_count": 1,
    "rounds": 9,
    "extra_alerts": 0,
    "rounds_label": "9 rounds"
  }
}
```

The BFF copies these values from the upstream credits endpoint and never
recomputes them. A `404` becomes `inactive_code`; another upstream failure
becomes `502 upstream_error`.

## `GET /credits/history`

```text
GET /credits/history?per_page=5&page=1
```

```json
{
  "items": [
    {
      "id": 3,
      "type": "refund",
      "amount": 1,
      "balance_after": 10,
      "contacts_count": 1,
      "rounds": 10,
      "extra_alerts": 0,
      "emergency_id": 501,
      "reference": "refund:EMG000501",
      "created_at": "2026-11-16T09:30:00+00:00"
    }
  ],
  "pagination": {
    "total": 3,
    "per_page": 5,
    "current_page": 1,
    "last_page": 1
  }
}
```

- `items` is newest first.
- `type` is the ledger entry kind (`recharge`, `emergency`, `refund`, …).
- `amount` is signed; `balance_after`, `contacts_count`, `rounds` and
  `extra_alerts` are the balances after the entry.
- `emergency_id` and `reference` are `null` when the entry is not tied to an
  emergency.

| Name       | Type | Default | Bounds   |
| ---------- | ---- | ------- | -------- |
| `per_page` | int  | `15`    | `1..100` |
| `page`     | int  | `1`     | `>= 1`   |

Absent or empty values use the default. A non-integer or out-of-range value
returns `422 validation_failed` with `details.field` before any upstream call.

## `POST /activate`

```json
{
  "geolocation": { "latitude": 4.711, "longitude": -74.0721, "accuracy": 12 },
  "reporter_phone": "+584121301234"
}
```

`geolocation` is optional. Both flat coordinates and a nested `coords` object
are accepted; coordinates and `accuracy` must be numeric. The BFF flattens them
and forwards them to the upstream `POST /v2/emergency`. It does not persist the
location; the API stores it through its own domain event.

`reporter_phone` is optional. When present it must be an optional `+` followed
by 7–15 digits, up to 20 characters. It is forwarded to the upstream create
call and echoed in the response.

Optional idempotency:

```http
Idempotency-Key: 2b7a…
```

The same key can be sent as `idempotency_key` in the body. Repeating a request
with the same key returns the stored result instead of sending WhatsApp messages
again. Generate one key per user action and reuse it on retry. The default store
is in-memory and per process; a multi-worker deployment needs a shared store.

```json
{
  "emergency_id": "EMG123456",
  "simulated": false,
  "reporter_phone": "+584121301234",
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

Behavior:

1. If the code already has an active emergency, return `409` without fetching
   the profile or sending messages. The frontend should hydrate the active
   emergency instead of treating this as a hard failure.
2. Fetch the profile and require personal information and at least one contact.
3. Create the upstream emergency active, so the WhatsApp template has an
   `emergency_code`.
4. Send one typed activation message per contact.
5. If every send fails, delete the created emergency and return
   `502 whatsapp_delivery_failed`.
6. Otherwise write the responder statuses and WAMIDs back through
   `PUT /v2/emergency/{id}` and return the result.

A send counts as successful only when the upstream response carries a valid
WhatsApp message id. A responder is never reported as sent without the reference
the delivery webhooks match on; see
[WhatsApp payloads](whatsapp-payloads.md#response).

A `402 INSUFFICIENT_CREDITS` from a typed send stops the fan-out, deletes the
created emergency and returns `402 insufficient_credits` without a write-back.
Other delivery failures keep the `502` path. Partial failures keep the
emergency and appear in `failures`.

| Status | Code                         | Cause                                |
| ------ | ---------------------------- | ------------------------------------ |
| 409    | `emergency_already_active`   | An active emergency exists.          |
| 402    | `insufficient_credits`       | The account cannot pay for the send. |
| 422    | `missing_medical_profile`    | No personal information.             |
| 422    | `missing_emergency_contacts` | No emergency contacts.               |
| 502    | `whatsapp_delivery_failed`   | Every contact failed.                |
| 502    | `upstream_error`             | Upstream API failure.                |

## `POST /simulacrum`

The body is optional and accepts the same `geolocation` as `/activate`.

```json
{
  "emergency_id": null,
  "simulated": true,
  "reporter_phone": null,
  "responders": [ … ],
  "failures": [],
  "partial_failure": false,
  "total_failure": false
}
```

The BFF pre-creates an active simulated emergency only to obtain a valid
`emergency_code` for the template. The record stays active and is never written
back, so upstream active-emergency lookups can surface it until the API's
scheduled cleanup deactivates it. A total delivery failure or a `402` deletes
the record it created.

Simulation does not check for an existing real emergency. Frontends must block
simulation while a real emergency is active.

## `POST /{id}/refresh`

```json
{
  "emergency": { "emergency_id": "EMG123456", "…": "…" }
}
```

Re-reads the emergency and its responder statuses. Call it on mount, after
realtime events, on tab focus, on reconnect and at a throttled interval.

`404 emergency_not_found` when upstream no longer has the emergency.

> [!WARNING]
> `POST /{id}/refresh` requires a session but does not verify that the emergency
> belongs to the session identity. Treat emergency IDs as non-guessable and do
> not expose one deployment to mutually untrusted tenants. See
> [Security](security.md#known-limitation-refresh-ownership).

## `DELETE /{id}`

```json
{
  "emergency_id": "EMG123456",
  "simulated": false,
  "reporter_phone": null,
  "responders": [],
  "failures": [],
  "partial_failure": false,
  "total_failure": false,
  "deleted": true
}
```

Sends the abort template to the contacts, deletes the upstream emergency and
clears the session snapshot. The abort template is unbilled, so a WhatsApp
failure is reported in `failures` but does not roll back the deletion.
`deleted: true` means the upstream delete succeeded.

`403 emergency_forbidden` when the ID does not belong to the session code.
`404 emergency_not_found` when no active emergency matches.

## Responders, failures and `message_id`

```json
{
  "phone": "573001234567",
  "name": "Ana Pérez",
  "sent": true,
  "delivered": true,
  "read": false,
  "failed": false,
  "message_id": "42"
}
```

`message_id` is always `string | null`, but its meaning depends on the
endpoint:

- activation, simulacrum and abort carry the WhatsApp **WAMID** returned by the
  send endpoint (`wamid.…`);
- `GET /current` and `POST /{id}/refresh` carry the upstream **internal**
  message id as a decimal string (`42` → `"42"`).

The internal id is a display and correlation value. Never feed it back into the
upstream create or update call, which expects the WAMID.

`partial_failure` is true when at least one responder failed and at least one
succeeded. A failure has `{ phone, message, status? }`.

## Error envelope

```json
{
  "error": {
    "code": "invalid_code",
    "message": "…",
    "details": [],
    "request_id": "9f3a1c7e2b4d8065"
  }
}
```

`details` is an array (empty when there is nothing to add) and `request_id`
matches the `X-Request-Id` header. `error.message` and `failures[].message` are
Spanish and meant for humans; `error.code`, `details` keys, field names and enum
values are stable English machine values.

Clients must branch on `error.code` and never on the message text. Messages are
presentation and can change without a version bump.

| HTTP | `code`                       | Meaning                                            |
| ---- | ---------------------------- | -------------------------------------------------- |
| 401  | `unauthenticated`            | No identity in the session; start a session first. |
| 402  | `insufficient_credits`       | The account cannot pay for a typed WhatsApp send.  |
| 403  | `cross_origin_denied`        | `Origin` host or port differs from `Host`.         |
| 403  | `csrf_failed`                | Missing or invalid CSRF token.                     |
| 403  | `emergency_forbidden`        | The emergency belongs to another code.             |
| 404  | `inactive_code`              | Code is well formed but not active upstream.       |
| 404  | `emergency_not_found`        | No emergency with that ID.                         |
| 404  | `not_found`                  | Unknown route.                                     |
| 405  | `method_not_allowed`         | Wrong method; see `details.allowed`.               |
| 409  | `emergency_already_active`   | The code already has an active emergency.          |
| 422  | `invalid_code`               | Code does not match the 6-character format.        |
| 422  | `missing_emergency_contacts` | No emergency contacts configured.                  |
| 422  | `missing_medical_profile`    | Profile lacks required personal information.       |
| 422  | `validation_failed`          | Missing or malformed input.                        |
| 429  | `rate_limited`               | Too many requests; see `details.retry_after`.      |
| 500  | `emergency_error`            | Unmapped emergency domain error.                   |
| 500  | `internal_error`             | Unhandled server error.                            |
| 502  | `upstream_error`             | The upstream API failed; see `details.status`.     |
| 502  | `whatsapp_delivery_failed`   | Every WhatsApp message failed.                     |

## Retries

Reads are retried by the HTTP client; writes are not. Activation and simulation
are safe to retry only with the same idempotency key. A `5xx` from the BFF can be
retried once with backoff; a `429` waits `details.retry_after` seconds.

## Examples

Runnable requests live with the package:
[examples](../onboarding/examples.md).
