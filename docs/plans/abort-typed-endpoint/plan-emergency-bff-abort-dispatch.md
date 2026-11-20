# Plan: Emergency BFF typed abort dispatch

## Spec

Switch the `emergency-bff` abort dispatch from the generic, unbilled `POST /v2/whatsapp/templates/send` to the new typed, billed `POST /v2/whatsapp/templates/emergency/abort`, without changing the BFF's public `DELETE /{id}` route or its response envelope. The switch reuses the BFF's existing typed client path (`WhatsAppClient::sendTyped`, the typed response parser and the `402`/`422` mapping), so the work is a small additive client surface plus a closure change in `EmergencyService::abort`. Abort remains a safety action: even when the typed send fails, the BFF still deletes the emergency and returns the current `200` body with `deleted: true`, recording the failed sends in `failures`.

Assumptions: the API endpoint from the API plan is merged and authenticated with the existing `Authorization` and `X-Application-Identifier` headers; the BFF keeps its one-recipient-per-request fan-out; `billed: false` is retained so a `402` is a failure entry rather than a breaking `DELETE` error; `emergency_id` is the authoritative `$activeId` returned by `findActiveByCode`, not the path id; `user_name` comes from `personal_info.full_name`; the old generic surface (`WhatsAppClient::send`, `WhatsAppPayloadBuilder::buildAbortPayload`, `TEMPLATE_ABORT`, `Endpoints::whatsappTemplates`) is kept and deprecated for one minor, not removed. The in-flight ArrayReader refactor touches `EmergencyService.php`, `WhatsAppClient.php`, `WhatsAppPayloadBuilder.php` and `Endpoints.php`; the abort work must land after it or add methods without reformatting those files.

Out of scope: the API endpoint itself, the `libs/emergency` outcome work, a local credit precheck or ledger, recipient batching, changing the public `DELETE` route or its status/error semantics, and any removal of the old generic methods.

Verification mode: TDD. Each implementation phase carries its own tests and ends with a test gate.

## Phase 1: Freeze the API contract and the billing decision

- [ ] Confirm against the merged API contract that `POST /v2/whatsapp/templates/emergency/abort` accepts `{emergency_id, recipients: [{phone, variables: {user_name}}]}`, returns `data.attributes.data.results[]` with `status` and `whatsapp_message_id`, returns `402` with `INSUFFICIENT_CREDITS` when the code has no balance, and returns `422` when `user_name` is blank.
- [ ] Record decision D1: keep `billed: false` so a `402` becomes a `failures` entry and the emergency is still deleted, preserving the current `DELETE` contract; do not surface a new HTTP `402` on the destructive route unless the product explicitly accepts the breaking change.
- [ ] Record decision D2 for a blank `personal_info.full_name`: choose a temporary fallback or record a failure and skip the typed send; do not silently fall back to the unbilled generic endpoint without explicit approval.
- [ ] Confirm the API plan has amended `apps/api.dataemergencia.com/docs/plans/emergency-alert-credits/credits-deferred-phases.md` section 3.8, which currently records abort as intentionally unbilled.
- [ ] Run the baseline `vendor/bin/phpunit` from `packages/emergency-bff` and record the result.
- [ ] **Gate:** the API abort tests pass, an authenticated abort request in the available environment returns the typed envelope with a `wamid.*`, a zero-balance request returns `402 INSUFFICIENT_CREDITS` without a Meta call, D1 and D2 are recorded, the API plan conflict is amended, and the baseline `vendor/bin/phpunit` passes.

## Phase 2: Additive typed abort client surface

- [ ] Add `Endpoints::whatsappEmergencyAbort()` returning `/v2/whatsapp/templates/emergency/abort`, next to the existing typed endpoints.
- [ ] Add `WhatsAppPayloadBuilder::buildAbortVariables(string $userName): array` returning `['user_name' => $this->sanitizeName($userName)]`, using `ArrayReader` for any new array reads and preserving the existing sanitization rules.
- [ ] Add `WhatsAppClient::sendAbort(string $phone, array $variables, string $emergencyId): MessageResult` delegating to the existing `sendTyped()` with the new endpoint constant.
- [ ] Add the `abort-variables` payload fixture and the typed abort response fixture; extend `tests/Unit/EndpointsTest.php`, `tests/Unit/WhatsAppPayloadBuilderTest.php` and `tests/Unit/WhatsAppClientTest.php` with the exact path, body, sanitization, WAMID, `status: failed`, missing-result and missing-WAMID cases.
- [ ] Keep `EmergencyService::abort` on the generic path in this phase so the client change is independently verifiable.
- [ ] **Gate:** `vendor/bin/phpunit --filter 'EndpointsTest|WhatsAppPayloadBuilderTest|WhatsAppClientTest'` passes from `packages/emergency-bff`, the diff is additive, and `ClientMessageCoverageTest` passes without a new `ClientMessages` key.

## Phase 3: Switch the abort dispatch

- [ ] Replace the abort closure in `EmergencyService.php` so it calls `sendAbort(Responder::phoneFromContact($contact), $this->payloads->buildAbortVariables((string) (ArrayReader::get($personalInfo, 'full_name') ?? '')), $activeId)`, using the authoritative active emergency id and the existing profile data.
- [ ] Keep `billed: false` and the unconditional delete, or apply the approved D2 empty-name behavior, without changing the public `EmergencyResult`/`deleted` contract.
- [ ] Update `tests/Unit/EmergencyServiceTest.php` and `tests/Feature/KernelTest.php`: the typed abort endpoint is called once per usable contact, the generic `templates/send` is never called by abort, the active id is used, ownership mismatch never sends, and a `402`, `422`, `status: failed` or missing WAMID still deletes and returns `deleted: true`.
- [ ] Update the vanilla mock API to answer the typed abort route if the runnable example is part of acceptance.
- [ ] **Gate:** `vendor/bin/phpunit` passes from `packages/emergency-bff` with `failOnRisky` and `failOnWarning`; `rg -n "whatsapp/templates/send" packages/emergency-bff/src` shows only the retained generic client method and never `EmergencyService`; a test proves one typed abort request per contact and no generic send; an abort with a typed failure still returns `200` with the failure entry and `deleted: true`.

## Phase 4: Docs, deprecation and release hygiene

- [ ] Update `docs/bff/whatsapp-payloads.md` (endpoint table and the removal of the unbilled-abort claim), `docs/bff/routes.md`, `docs/bff/activation-flow.md`, `docs/operations/troubleshooting.md`, `docs/contributing/documentation.md` and `AGENTS.md` so abort is classified as current and billed while the generic endpoint remains older-but-supported.
- [ ] Deprecate `WhatsAppClient::send()`, `WhatsAppPayloadBuilder::buildAbortPayload()`, `TEMPLATE_ABORT` and `Endpoints::whatsappTemplates()` for one minor, keeping them functional.
- [ ] Add a `CHANGELOG.md` entry under `## [Unreleased]` and classify the release as at least a pre-1.0 minor; do not remove the old surface in this release.
- [ ] **Gate:** `vendor/bin/phpunit` passes from `packages/emergency-bff`; `rg -n "unbilled|no typed abort|never blocks abort|legacy.*abort" packages/emergency-bff` returns only historical CHANGELOG entries and explicit deprecation notes; `CHANGELOG.md` has the Unreleased entry; the old generic surface is still present and callable.
