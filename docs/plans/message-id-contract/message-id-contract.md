# Plan: Correct `message_id` extraction and normalization

## Spec

**Goal.** The BFF must forward the real WhatsApp WAMID to `POST /v2/emergency`, so the API resolves it through `getIdByWamid()` and stores a valid `emergency_responders.whatsapp_message_id`. It must also stop leaking raw upstream types from proxy endpoints: activation, current and refresh responses must all expose every responder's `message_id` as `string | null`, and the mocks/tests must describe the real upstream envelopes instead of a convenient fiction. With that, status webhooks can match the responder and delivered/read/failed updates start flowing again.

**Contract reference.** `tmp/opencode/emergency-creation-json-contract.md` and the API source it names. The contract has **two different identifiers that must not be mixed**:

- **WAMID** — Meta's id, e.g. `wamid.HBgM...`. Sent to `POST /v2/emergency` as `responders[].message_id`, and received in webhooks as `statuses[].id`.
- **Internal message id** — `whatsapp_messages.id` (int). Stored in `emergency_responders.whatsapp_message_id` and echoed by `GET /v2/emergency/{id}` as `responders[].message_id`.

**Exact shapes.**

- Send response: `data.attributes.details.messages[0].id` is the WAMID (string). `data.attributes.id` is the internal int. `data.id` is a synthetic `"success|<int>"` that changes per response. Only the first is valid for the create request.
- Emergency response: `data.attributes.responders[*].message_id` is the internal **int** (or `null`), not a WAMID. It is a display/correlation value and must never be fed back into `POST /v2/emergency`.
- `meta.status` is hard-coded to `200`; trust the HTTP status.

**What is actually broken.**

1. `WhatsAppClient` passes `body.data` (the whole JSON:API resource) to `MessageResult`. `MessageResult::messageId()` then falls back to `data.id`, which is `"success|42"` — not a WAMID. The API runs `getIdByWamid("success|42")`, gets `null`, and creates the responder with no message reference. The real WAMID at `data.attributes.details.messages[0].id` is never read.
2. Proxy endpoints return `responders[*].message_id` as an int. `EmergencyRepository::normalize()` coerces only `emergency_id`/`id`, so `/refresh` leaks the int. `@dataemergencia/emergency` guards require `string | null`, so refresh fails with `invalid_response` and the UI never refreshes responder status.

**Assumptions.**

- The upstream shapes above are the contract; the BFF must adapt to them, not the other way around. No upstream API changes are required or desired.
- The BFF public contract stays `message_id: string | null`. The int → decimal-string conversion is a deliberate boundary normalization, consistent with the existing `emergency_id` coercion and the `0.1.2` changelog precedent. It does not turn the internal id into a WAMID; the two values keep different meanings per endpoint.
- `@dataemergencia/emergency` guards/types stay unchanged because the BFF normalizes before the client sees the payload.
- Test mode is **deferred**: implementation phases carry no test runs; Phase 3 writes the tests and runs every check once.

**Out of scope.**

- Changing `apps/api.dataemergencia.com` resources, request validation, `getIdByWamid()` or the status pipeline.
- Changing the `@dataemergencia/emergency` public types or guards.
- Fixing the send-then-create race from gotcha 1 of the contract (a webhook that arrives before the `emergency_responders` row exists is still lost). This is a separate operational concern; Phase 3 records the residual risk instead of widening the patch.
- Other fields (`is_active`, `last_messages`, timestamps) and realtime/Pusher behavior.

**KISS rule.** One fix per boundary: the send parser reads the WAMID from its real path; the emergency repository normalizes the internal int to a decimal string. No new abstraction layer, no duplicated parsing logic, no contract widening.

## Phase 1: Make the send-result parser contract-accurate

- [x] Add real-shape fixtures under `tests/fixtures/responses/`: one full send envelope (synthetic `data.id`, int `data.attributes.id`, WAMID in `data.attributes.details.messages[0].id`) and one emergency response whose responders carry int `message_id` values.
- [x] Add a small private helper in `src/WhatsApp/MessageResult.php` that reads the WAMID from `attributes.details.messages[0].id`, `details.messages[0].id`, `messages[0].id`, `message_id`, or `attributes.message_id`, in that order.
- [x] Update `MessageResult::messageId()` to return only a non-empty **string** WAMID. Remove the `data.id` fallback entirely, and never treat `attributes.id` (internal int) or a numeric `message_id` as a WAMID — return `null` in those cases.
- [x] Update `src/Api/WhatsAppClient.php` to pass `body.data.attributes` when present (`body.data` otherwise), so the parser receives the real fields and still accepts the legacy/mock `{ data: { message_id } }` shape.
- [x] Update `src/WhatsApp/Responder.php` so `fromContact()` accepts `messageId`/`message_id` as `string | int | null`: int → decimal string, non-empty string kept, everything else → `null`. This is the type-honest boundary for contact-shaped input; it does not affect the WAMID parser.
- [x] Document the real send envelope, the WAMID path and the internal-int path in `docs/bff/upstream-api.md`, next to the existing normalization notes.
- [x] **Gate:** deferred → Phase 3

## Phase 2: Normalize proxied responder references

- [x] Add a `normalizeResponders()` step to `src/Api/EmergencyRepository.php` and call it from `normalize()` after the existing `normalizeId()` pass.
- [x] For each responder, coerce `message_id` with the same rules already used for `emergency_id`: int → decimal string, non-empty string kept, `null`/missing/unsupported → `null`; leave every other field untouched.
- [x] Confirm the normalization applies to both `show()`/`refresh()` and `findActiveByCode()`/`current()` because both go through `normalize()`.
- [x] Update `docs/api-contract.md` and `README.md` so the responder examples explain the two cases: activation returns the WAMID; proxy/read endpoints return the upstream internal id as a decimal string. Remove the implication that every `message_id` is a WAMID.
- [x] Add a `Fixed` entry under `## [Unreleased]` in `CHANGELOG.md` describing the WAMID extraction and the int → string responder normalization.
- [x] **Gate:** deferred → Phase 3

## Phase 3: Verification

- [x] Replace the infected upstream mocks with the real envelopes: `tests/Unit/EmergencyServiceTest.php`, `tests/Feature/KernelTest.php` and `examples/vanilla-php/mock-api.php` must return `data.attributes.details.messages[0].id` (plus the int `attributes.id` and synthetic `data.id`) instead of a flat `data.message_id`.
- [x] Add `tests/Unit/MessageResultTest.php` covering: real envelope → WAMID; attributes-only payload → WAMID; legacy flat `data.message_id` string; synthetic `data.id`/`attributes.id` are never returned; missing `details` → `null`; numeric `message_id` → `null` (not a WAMID).
- [x] Extend `tests/Unit/ProfileAndWhatsAppDataTest.php` to assert a numeric contact `messageId` becomes its decimal string and a non-scalar value becomes `null`.
- [x] Add repository coverage (new `tests/Unit/EmergencyRepositoryTest.php`) for responder `message_id` int → string, non-empty string kept, `null`/missing → `null`, and unsupported types → `null`; assert other responder fields are untouched.
- [x] Extend `tests/Unit/EmergencyServiceTest.php` to assert the `POST /v2/emergency` body carries the WAMID from `details.messages[0].id`, not the envelope id or the internal int.
- [x] Extend `tests/Feature/KernelTest.php` so `/refresh` returns `responders[0].message_id` as a string and the response matches the client contract (`isRefreshResponse`-compatible shape).
- [x] Add a short note to the plan/PR about the residual ordering risk from contract gotcha 1 (webhook before responder row creation), so it is not silently forgotten.
- [x] Run `vendor/bin/phpunit` from `packages/emergency-bff` and make the suite pass.
- [x] Run `php -l` on every changed `src/` file.
- [x] **Gate:** `vendor/bin/phpunit` exits 0 with the new regression tests included.
- [x] **Gate:** `php -l` exits 0 for every changed `src/` file.
- [x] **Gate:** `git diff --stat` shows changes only under `packages/emergency-bff` (no API or `libs/emergency` edits).

## Residual risk

Contract gotcha 1 remains open: a WhatsApp status webhook that arrives before the
`emergency_responders` row exists is still lost. This plan fixes the identifier
and the proxy types, but it does not add buffering or retry for out-of-order
webhooks; that is a separate operational concern.
