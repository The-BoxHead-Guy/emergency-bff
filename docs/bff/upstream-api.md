# Upstream API mapping

Applies to: integrators debugging BFF behavior and maintainers.

The BFF is a thin translation layer over the existing DataEmergencia API. It
does not introduce a second database and it does not duplicate the emergency
domain model.

## Endpoint map

| BFF use                   | Upstream method | Upstream path                                          |
| ------------------------- | --------------- | ------------------------------------------------------ |
| Validate an identity code | `GET`           | `/v2/fmd/{code}/exists`                                |
| Fetch the medical profile | `GET`           | `/v2/fmd/{code}/models/data?model=personal,emergencia` |
| Create an emergency       | `POST`          | `/v2/emergency`                                        |
| Read an emergency         | `GET`           | `/v2/emergency/{id}`                                   |
| Delete an emergency       | `DELETE`        | `/v2/emergency/{id}`                                   |
| Find the active emergency | `GET`           | `/v2/emergency/qr/{code}/active`                       |
| Send a WhatsApp template  | `POST`          | `/v2/whatsapp/templates/send`                          |

The canonical endpoint list lives in `src/Http/Endpoints.php`.

## Authentication

Every upstream request carries:

```http
Authorization: Bearer <EMERGENCY_BFF_API_KEY>
X-Application-Identifier: <EMERGENCY_BFF_APPLICATION_IDENTIFIER>
Accept: application/json
```

Credentials belong to one customer. There is no shared key and no fallback key.
The browser never sees these headers.

## Code validation

`GET /v2/fmd/{code}/exists` returns a boolean. The BFF validates the format
locally first (`^[A-Za-z][A-Za-z0-9]{5}$`), then asks upstream whether the code
exists.

The BFF deliberately uses the boolean existence endpoint instead of the
per-model existence endpoint, because the per-model variant requires a user
token that the BFF does not hold. A `404` from upstream means the code does not
exist; a network or `5xx` failure becomes `502 upstream_error`, not
`inactive_code`.

Profile data is fetched only at activation or simulation time, not when the
session starts.

## Profile normalization

The profile response may nest its payload in any of these shapes:

```text
body.data.attributes.data
body.attributes.data
body.data
```

Personal information is read from `data.personal_info` or
`data.personal.personal_info`. Emergency contacts are read from
`data.emergency_info` or `data.emergencia.emergency_info`. Product codes are
read from `data.product_codes` and filtered to string values.

A missing payload, missing personal information or missing contacts becomes a
domain exception (`missing_medical_profile` or `missing_emergency_contacts`),
not a generic `500`.

## Emergency normalization

The emergency repository reads the payload from `body.data.attributes`,
`body.attributes`, `body.data`, or the body itself. It preserves unknown fields
inside the snapshot, so newer upstream fields reach API consumers without a BFF
release.

A numeric `emergency_id` (or `id`) is coerced to its decimal string in the
repository, so every BFF payload exposes the identifier as a string. The value is
`null` only when the upstream response has no id.

`404` on show or delete becomes `EmergencyNotFoundException`. `404` on the
active lookup becomes `null`, which lets `GET /current` return `active: false`.

## HTTP behavior

- Timeout applies to connect and total request time.
- `GET` and `HEAD` are retried `EMERGENCY_BFF_RETRY_COUNT` times on transport
  errors and `5xx` responses, with a short linear backoff.
- `POST` and `DELETE` are never retried automatically. Callers can make them
  idempotent with `Idempotency-Key`.
- Upstream error bodies are translated into `ApiException`, then into
  `502 upstream_error` with `details.status` and `details.upstream_code`.
- The upstream raw body is preserved on `ApiResponse` for debugging, but it is
  not returned to the browser.

## What the BFF adds

- Same-origin enforcement, CSRF and rate limiting.
- Session-scoped identity instead of a code in every browser request.
- Per-customer credentials held server-side.
- WhatsApp payload construction and delivery fan-out.
- Delivery summaries (`partial_failure`, `total_failure`) and idempotency.
- A stable error envelope for API consumers.

## What the BFF does not do

- It does not create, edit or delete medical profiles.
- It does not manage the contact list; it reads it from the profile.
- It does not implement private Pusher channels in the MVP.
- It does not provide an admin UI or a database migration.

## Next step

[Activation flow](activation-flow.md).
