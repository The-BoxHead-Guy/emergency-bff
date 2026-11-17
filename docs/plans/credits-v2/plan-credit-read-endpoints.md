# Plan: Credits v2 read endpoints and insufficient-credit handling

## Spec

Expose the two read-only upstream Credits endpoints that already exist in
`apps/api.dataemergencia.com` to the browser through the emergency BFF:
`GET /v2/credits/{code}` (balance, contacts count, derived rounds and extra
alerts, human label) and `GET /v2/credits/{code}/history` (paginated
append-only ledger). The BFF serves them as session-scoped routes
`GET /credits` and `GET /credits/history`, reads the code from the validated
session identity and normalizes the upstream JSON:API envelope into flat BFF
payloads, consistent with `GET /current` and `POST /{id}/refresh`. In the same
release, surface the upstream `402 INSUFFICIENT_CREDITS` response of the typed
WhatsApp template endpoints as a dedicated `402 insufficient_credits` BFF error
instead of the misleading `502 whatsapp_delivery_failed`, because template
sends now debit credits. The consumer library `@dataemergencia/emergency`
(`libs/emergency`) gains the matching client side: it recognizes
`insufficient_credits`, renders its own Spanish copy, returns to the `ready`
phase, and — for a real activation — emits `emergency:fallback` so the host can
offer the hotline.

Assumptions (correct before implementation if wrong):

- The repo's only development mock is `examples/vanilla-php/mock-api.php` plus
  the `demo.sh` / `smoke.sh` harness; there is no separate fixture server, so
  the new mock routes belong there.
- The API is the source of truth for `rounds`, `extra_alerts`, `rounds_label`
  and contact counts. The BFF copies those values; it never recomputes them.
- A credits `404` means the session code no longer exists upstream, so it maps
  to the existing `404 inactive_code` contract rather than a new error code.
- `CreditRepository` is a required `Kernel` constructor collaborator. This is a
  breaking change only for hosts that build `Kernel` by hand (not for
  `Bootstrap` users), documented in the upgrade guide and released as `0.5.0`
  under the existing pre-1.0 policy.
- Adding `insufficient_credits` to `EMERGENCY_ERROR_CODES` in `libs/emergency`
  is a new union member: it is a compile break only for consumers with
  exhaustive `switch` statements, released as a minor bump under the pre-1.0
  policy and flagged in the library changelog.
- The BFF exception carries the identity code plus the upstream status and code
  in `details`; the client library only relies on the public `code` and
  `message`, never on `details` contents.
- Test mode for this plan is **deferred**: Phases 1–6 build the feature and
  Phase 7 writes all tests and runs every gate once.

Out of scope:

- No write endpoints (top-up, refund, revocation); the API exposes reads only.
- No UI work beyond the error feedback and fallback event (no pre-SOS balance
  display, no recharge flow).
- No change to the abort path: it keeps using the legacy, unbilled
  `POST /v2/whatsapp/templates/send`.
- No rate-limit, CSRF or session-lifetime changes.

## Phase 1: Upstream endpoint map and credit repository

- [x] Add `Endpoints::creditSummary(string $code)` returning
      `/v2/credits/{rawurlencode($code)}` and
      `Endpoints::creditHistory(string $code, int $perPage = 15, int $page = 1)`
      returning `/v2/credits/{rawurlencode($code)}/history?per_page=…&page=…`
      (built with `http_build_query`), placed next to the other `/v2/...`
      methods.
- [x] Create `src/Api/CreditRepository.php` with
      `summary(string $code): array` and
      `history(string $code, int $perPage, int $page): array`, both taking an
      `ApiClient` in the constructor.
- [x] In `summary()`, read the payload from `body.data.attributes`, falling back
      to `body.attributes`, then `body.data`; return the flat keys
      `code`, `domain`, `balance` (int), `contacts_count` (int), `rounds` (int),
      `extra_alerts` (int), `rounds_label` (string).
- [x] In `history()`, read `body.data.items` and `body.data.pagination`; map each
      item through its `attributes` array (falling back to the item itself) and
      return flat keys `id` (int), `type` (string), `amount` (int),
      `balance_after` (int), `contacts_count` (int), `rounds` (int),
      `extra_alerts` (int), `emergency_id` (int|null), `reference` (string|null),
      `created_at` (string|null); return
      `['items' => [...], 'pagination' => ['total' => int, 'per_page' => int,
'current_page' => int, 'last_page' => int]]`.
- [x] Treat a missing or non-array payload defensively: summary returns the
      documented keys with `0` / empty-string values, history returns
      `items: []` and a single-page pagination block. Never throw a `TypeError`.
- [x] Catch `ApiException` with status `404` and throw
      `InactiveCodeException($code)`; rethrow every other `ApiException`
      untouched so the Kernel maps it to `502 upstream_error` (do **not** wrap it
      in `ApiFailureException`, which degrades to `500 emergency_error`).
- [x] Wire the repository in `src/Support/Bootstrap.php`:
      `new CreditRepository($http)` passed to the `Kernel`.
- [x] **Gate:** deferred → Phase 7

## Phase 2: Kernel routes and pagination validation

- [x] Add a required `CreditRepository $credits` constructor parameter to
      `src/Http/Kernel.php`, immediately after `$emergency`, and import it.
- [x] Register `GET /credits` in `Kernel::routes()`: require
      `$this->identity()`, call `$this->credits->summary($identity->code)` and
      respond `Response::json(['credit' => $summary])` with status `200`.
- [x] Register `GET /credits/history` in `Kernel::routes()`: require
      `$this->identity()`, parse the pagination query, call
      `$this->credits->history($identity->code, $perPage, $page)` and respond
      with the `{items, pagination}` payload at status `200`.
- [x] Add a private `intQuery(Request $request, string $key, int $default,
int $min, ?int $max = null): int` helper to `Kernel`: absent or empty
      returns the default; non-integer, below `$min` or above `$max` throws
      `HttpError(422, 'validation_failed', …, ['field' => $key])` before any
      upstream call. Use `per_page` with bounds `1..100` and default `15`, and
      `page` with minimum `1` and default `1`.
- [x] Confirm the two literal routes do not collide with the existing
      `{id}` emergency routes (they are registered on `GET`, and no
      `/credits/{...}` pattern exists); keep the literal `/credits/history`
      route registered before any future dynamic credits route.
- [x] **Gate:** deferred → Phase 7

## Phase 3: Insufficient-credit upstream handling

- [x] Extend `src/Http/ApiException.php::fromResponse()` to also parse the
      API's real error envelope `{meta, errors: [{status, code, title, detail,
source}]}`: prefer the existing `body.code` / `body.error.code` and
      `body.message` / `body.error.message` first, then fall back to
      `errors[0].code` (uppercased as-is) and `errors[0].detail`; keep the raw
      envelope available through `details`.
- [x] Create `src/Emergency/Exception/InsufficientCreditsException.php`
      extending `EmergencyException` with error code `insufficient_credits` and
      `details` = `{code: <identity code>, upstream_status: 402,
upstream_code: 'INSUFFICIENT_CREDITS'}`.
- [x] Add `InsufficientCreditsException => [402, 'insufficient_credits']` to
      `Kernel::mapEmergencyException()`, before the `default` arm.
- [x] In `src/Api/WhatsAppClient.php::sendTyped()`, when the caught
      `ApiException` has status `402` (compare the error code
      case-insensitively as `INSUFFICIENT_CREDITS` when present, but let the
      status alone be sufficient), return `MessageResult::failed()` carrying
      `code: 'insufficient_credits'`, the upstream message and status `402`.
      Leave `send()` (the legacy abort template) unchanged; those messages are
      not billed.
- [x] In `src/Emergency/EmergencyService.php::sendToResponders()`, stop the
      per-contact fan-out as soon as a result reports
      `insufficient_credits` (mark that responder failed, do not contact the
      remaining recipients) and throw `InsufficientCreditsException`.
- [x] In `activate()` and `simulacrum()`, wrap the `sendToResponders()` call in
      a `try`/`catch` for `InsufficientCreditsException`, delete the emergency
      the BFF just created (`$this->emergencies->delete($emergencyId)`) — the
      same cleanup used by the total-failure path — and rethrow. Wrap the
      delete in its own `try`/`catch`: log a warning if cleanup fails instead
      of letting `ApiFailureException` mask the payment error with a `500`.
- [x] Keep the existing `allFailed()` → `WhatsAppFailureException` path intact
      for every non-credit delivery failure.
- [x] **Gate:** deferred → Phase 7

## Phase 4: Development mock, demo page and smoke script

- [x] In `examples/vanilla-php/mock-api.php`, add
      `GET /v2/credits/{code}` returning
      `{meta, data: {type: 'credits', id: 'credits|CODE', attributes: {code,
domain: 'emergency', balance, contacts_count, rounds, extra_alerts,
rounds_label}}}`. Keep the numbers consistent with the mocked profile
      (one emergency contact): `balance: 9`, `contacts_count: 1`, `rounds: 9`,
      `extra_alerts: 0`, `rounds_label: '9 rounds'`.
- [x] Add `GET /v2/credits/{code}/history` returning
      `{meta, data: {type: 'credit_history', items: [...], pagination: {...}}}`.
      Include a few deterministic rows (`recharge`, `emergency`, `refund`),
      newest first, honor `per_page` (clamp `1..100`, default `15`) and `page`,
      and slice the rows so pagination is demonstrable.
- [x] Return `404` for a code that is not one of the mock's accepted codes.
- [x] Add a zero-credit demo code `ZERO12` to the mock: accept it in
      `/v2/fmd/{code}/exists` and `/v2/fmd/{code}/models/data`, answer its
      summary with `balance: 0`, and make its typed template send return the
      API's real envelope
      `{meta, errors: [{status: '402', code: 'INSUFFICIENT_CREDITS', title:
'Payment Required', detail: '…'}]}` with HTTP `402`.
- [x] Add two buttons to `examples/vanilla-php/public/demo.html` after the
      existing five (`6. Credits`, `7. Credit history`) using the existing
      `call()` helper: `GET /credits` and `GET /credits/history?per_page=5`,
      logging each response with the existing `log()` helper.
- [x] Extend `examples/vanilla-php/smoke.sh` after the session assertion:
      `GET /credits` → `200` with numeric `balance`/`rounds`; `GET
/credits/history?per_page=1` → `200` with `pagination.per_page = 1` and
      one item; `GET /credits/history?per_page=0` → `422 validation_failed`.
      Also assert `GET /credits` before `POST /session` returns `401`.
- [x] **Gate:** deferred → Phase 7

## Phase 5: Consumer library error handling

- [x] Add `'insufficient_credits'` to `EMERGENCY_ERROR_CODES` in
      `libs/emergency/src/core/types.ts`.
- [x] Add Spanish copy `insufficientCredits` to `libs/emergency/src/core/copy.ts`.
- [x] In `libs/emergency/src/core/controller.ts` `activate()`, add an
      `insufficient_credits` branch next to `emergency_already_active` that
      calls `fail(normalized, 'activate', { phase: { kind: 'ready' },
message: copy.insufficientCredits })` and then `emitFallback(normalized)`.
- [x] In `simulate()`, render `copy.insufficientCredits` for the same code
      (phase stays `ready`); do not emit the emergency fallback, consistent
      with the other simulation failures.
- [x] Leave `libs/emergency/src/core/bff-client.ts` and `guards.ts` unchanged:
      the existing envelope branch already accepts the new code through
      `isEmergencyErrorCode`, and `redactDetails` bounds the new `details`
      payload.
- [x] **Gate:** deferred → Phase 7

## Phase 6: Documentation and changelog

- [x] Add the two routes to the HTTP API section of `README.md` with short
      payload examples.
- [x] Update `docs/bff/routes.md`: route table rows, middleware note (GET, no
      CSRF), request/response sections for `/credits` and `/credits/history`,
      the `per_page`/`page` bounds, and the `402 insufficient_credits` error
      row.
- [x] Update `docs/api-contract.md`: add `GET /credits` and
      `GET /credits/history` to the endpoints table and document the exact
      response shapes, pagination rules, `inactive_code` on a vanished code and
      the new `402 insufficient_credits` behavior for activate/simulacrum.
- [x] Update `docs/bff/upstream-api.md`: add `/v2/credits/{code}` and
      `/v2/credits/{code}/history` to the endpoint map; document the envelope
      normalization and a short "Insufficient credits" subsection (402,
      `errors[0].code`, emergency cleanup, abort messages unbilled).
- [x] Update `docs/bff/upgrade.md`: note that `Kernel` gained a required
      `CreditRepository` collaborator for hosts that do not use `Bootstrap`.
- [x] Update `examples/vanilla-php/README.md`: mention the credits buttons and
      the `ZERO12` zero-credit code.
- [x] Add `[Unreleased]` entries to `CHANGELOG.md` under `Added` (two routes,
      credit repository, mock/demo coverage) and `Changed` (402 mapping, Kernel
      constructor).
- [x] Add `[Unreleased]` entries to `libs/emergency/CHANGELOG.md` under `Added`
      (recognizes `insufficient_credits`, dedicated Spanish copy,
      `emergency:fallback` on activation) and note that the new union member can
      break consumers with exhaustive `switch` statements.
- [x] Update `libs/emergency/docs/client/troubleshooting.md` and `safety.md` to
      document the `insufficient_credits` outcome next to
      `whatsapp_delivery_failed`.
- [x] **Gate:** deferred → Phase 7

## Phase 7: Verification

- [x] Add fixtures `tests/fixtures/responses/credits.json`,
      `tests/fixtures/responses/credit-history.json` and
      `tests/fixtures/responses/insufficient-credits.json` matching the real
      upstream payloads.
- [x] Extend `tests/Unit/EndpointsTest.php` with the two new paths, the query
      string and percent-encoding cases.
- [x] Create `tests/Unit/CreditRepositoryTest.php` covering summary
      normalization, history item/flat fallbacks, numeric coercion
      (`emergency_id` int|null), empty payloads, `404 → InactiveCodeException`
      and rethrown `5xx` as `ApiException`.
- [x] Extend `tests/Unit/ApiClientTest.php` with `errors[0].code` /
      `errors[0].detail` parsing while keeping the legacy `code`/`message`
      cases green.
- [x] Add Kernel/feature coverage (in `tests/Feature/KernelTest.php` or a new
      `tests/Feature/CreditsTest.php`): `401` without a session, summary
      payload, history forwards `per_page`/`page`, `422 validation_failed` for
      `per_page=0` and `page=0`, `404 inactive_code`, `502 upstream_error`, and
      an activation case where the typed send returns the `402` envelope —
      expecting `402 insufficient_credits`, no `PUT` write-back and one
      `DELETE /v2/emergency/{id}` cleanup call.
- [x] Add `tests/Unit/EmergencyServiceTest.php` coverage that the fan-out stops
      after the first `insufficient_credits` result (no further typed sends).
- [x] Extend `libs/emergency/tests/controller.test.ts` with an
      `insufficient_credits` activation case (phase `ready`,
      `copy.insufficientCredits` rendered, `emergency:fallback` emitted once)
      and a `simulate()` case (copy rendered, no fallback).
- [x] Extend `libs/emergency/tests/bff-client.test.ts` with an error envelope
      carrying `code: insufficient_credits` and `details`, asserting `status`,
      `requestId` and the bounded `details` pass through.
- [x] **Gate:** `composer validate --strict` exits 0.
- [x] **Gate:** `composer test` passes with the pre-existing 102 tests plus the
      new ones, no skips and no warnings.
- [x] **Gate:** with the mock API and demo BFF running, `./smoke.sh` exits 0,
      including the credits and pagination assertions.
- [x] **Gate:** `NX_NO_CLOUD=true pnpm nx run @dataemergencia/emergency:check --outputStyle=static`
      exits 0.
- [x] **Gate:** `NX_NO_CLOUD=true pnpm nx run @dataemergencia/emergency:test --outputStyle=static`
      exits 0, including the new `insufficient_credits` cases.
