# Plan: Point the emergency BFF at the typed emergency WhatsApp endpoints

## Spec

Replace `POST /v2/whatsapp/templates/send` for emergency activation and simulacrum with the typed endpoints `POST /v2/whatsapp/templates/emergency/activation` and `POST /v2/whatsapp/templates/emergency/simulacrum`. The BFF now sends only `phone` plus the endpoint's `variables`; the API builds the Meta template payload.

Because the templates use the emergency id as the URL button suffix (`emergency_code`), the emergency must exist before the messages are sent. Activation creates the emergency active first, sends the messages, and then writes the WAMIDs and statuses back through `PUT /v2/emergency/{id}`. Simulacrum pre-creates an inactive emergency only to obtain a valid `emergency_code` for its `/test` link and leaves it inactive. When every send fails, the BFF deletes the record it created.

Assumptions:

- Activation variables are `user_name` and `emergency_code`; simulacrum variables are `contact_name`, `user_name`, and `emergency_code`, matching `config/whatsapp-templates/emergency.php` and scripts `11-emergency-activation.sh` / `12-emergency-simulacrum.sh`.
- The typed endpoints answer `200` even when an individual recipient fails; the BFF must read `data.attributes.data.results[0].status` and `whatsapp_message_id` instead of trusting the HTTP status alone.
- The API update plan (`apps/api.dataemergencia.com/docs/plans/emergency-whatsapp-flow/plan-emergency-update-endpoint.md`) lands first; the BFF depends on `PUT /v2/emergency/{id}`.
- Abort keeps using the legacy `/v2/whatsapp/templates/send` with `emergency_status_aborting_v1`, because no typed abort endpoint exists.
- The BFF keeps one recipient per request to preserve per-contact failure aggregation.
- Test mode: deferred. Implementation phases run no tests; Phase 4 writes the tests and runs every gate.

Out of scope: API implementation details, frontend or `libs/emergency` changes, batching several recipients into one request, and realtime delivery handling.

## Phase 1: Typed endpoints, client, and response parsing

- [x] Add `whatsappEmergencyActivation()` and `whatsappEmergencySimulacrum()` to `src/Http/Endpoints.php`; keep `whatsappTemplates()` for abort.
- [x] Add `sendActivation(string $phone, string $userName, string $emergencyCode): MessageResult` to `src/Api/WhatsAppClient.php`, posting one `recipients[]` entry to the activation endpoint.
- [x] Add `sendSimulacrum(string $phone, string $contactName, string $userName, string $emergencyCode): MessageResult` to `src/Api/WhatsAppClient.php`, posting one `recipients[]` entry to the simulacrum endpoint.
- [x] Add response parsing for the nested payload `data.attributes.data.results[0]`: a send succeeds only when `status` is `sent` and `whatsapp_message_id` is a valid `wamid.` value; a `200` with `status: failed` becomes a failed `MessageResult`.
- [x] Keep `WhatsAppClient::send()` unchanged for the abort payload.
- [x] In `src/WhatsApp/WhatsAppPayloadBuilder.php`, keep `buildAbortPayload()` and replace the old emergency/simulacrum payload builders with variable builders that return only the typed endpoint's `variables`.
- [x] Remove the now-unused emergency/simulacrum template names, image URLs, date formatting, QR URL building, and map data from the builder; the API owns them.
- [x] **Gate:** deferred → Phase 4

## Phase 2: Service orchestration and repository update

- [x] Add an `isActive` argument (default `true`) to `EmergencyRepository::create()` and send it as `is_active`.
- [x] Add `EmergencyRepository::update(string $emergencyId, string $code, array $responders, bool $isActive)` that issues `PUT /v2/emergency/{id}` through `ApiClient::request()` and normalizes the response like the other repository methods.
- [x] In `EmergencyService::activate()`, keep the active-emergency check, profile load, and contact checks, then create the emergency active before sending.
- [x] Send the activation template per responder with `emergency_code` set to the created emergency id.
- [x] When every send fails, delete the just-created emergency and throw `WhatsAppFailureException`; when at least one succeeds, update the emergency with the responder statuses and WAMIDs and return the updated snapshot.
- [x] In `EmergencyService::simulacrum()`, create an inactive emergency with the profile contacts to obtain the id, send the simulacrum template with that id, and delete the record when every send fails.
- [x] Keep the simulacrum response contract (`simulated: true`, `emergency_id: null`), logging, and idempotency caching.
- [x] Map an upstream `409` from emergency creation to `AlreadyActiveEmergencyException` so a race still returns the existing domain error.
- [x] **Gate:** deferred → Phase 4

## Phase 3: Documentation

- [x] Update `docs/bff/whatsapp-payloads.md` to describe the typed endpoints, their variables, response shape, and the fact that the API builds the Meta payload.
- [x] Update `docs/bff/activation-flow.md` with the new order: active check → profile → create emergency → send typed templates → update statuses, plus the total-failure delete.
- [x] Update `docs/bff/upstream-api.md` with the two typed endpoints, the nested response path, and the emergency update call.
- [x] Update `docs/bff/routes.md` and `docs/api-contract.md` where the activation/simulacrum response contract mentions the old template names or order.
- [x] Update `CHANGELOG.md` with the endpoint migration.
- [x] **Gate:** deferred → Phase 4

## Phase 4: Verification

- [x] Update `tests/Unit/EndpointsTest.php` for the two new paths.
- [x] Update `tests/Unit/MessageResultTest.php` (or add a typed result test) for `data.attributes.data.results[0].status` and `whatsapp_message_id`, including the `200` + `failed` case.
- [x] Rewrite `tests/Unit/WhatsAppPayloadBuilderTest.php` around the two variable builders and the unchanged abort payload.
- [x] Update `tests/Unit/EmergencyServiceTest.php` for: create-before-send ordering, `emergency_code` equal to the created id, update call carrying the WAMIDs, delete on total failure, and simulacrum pre-creating an inactive emergency.
- [x] Update `tests/Feature/KernelTest.php` if the activation/simulacrum response shape changes.
- [x] Replace `tests/fixtures/payloads/emergency.json` and `simulacrum.json` and add a fixture for the nested typed response.
- [x] **Gate:** `composer test` passes inside `packages/emergency-bff`.
- [x] **Gate:** `node scripts/docs/check-links.mjs --root packages/emergency-bff` passes from the repository root.
- [x] **Gate:** `grep -rn "templates/send" packages/emergency-bff/src` shows only the abort path.
