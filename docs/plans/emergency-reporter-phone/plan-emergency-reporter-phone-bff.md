# Plan: Accept and forward the reporter phone in the emergency BFF

## Spec

`POST /activate` accepts an optional `reporter_phone` alongside `geolocation`, validates its shape, and forwards it to the upstream `POST /v2/emergency` create payload. The activation result returns `reporter_phone` so the client can hydrate its snapshot immediately. `POST /simulacrum` sends `null` because it creates an inactive test emergency.

Accepted value: optional leading `+` plus 7–15 digits. A malformed value returns `422 validation_failed` before any upstream call.

Assumptions:

- The API lands first and accepts `reporter_phone` on `POST /v2/emergency`, returning it in the emergency resource.
- The browser sends E.164, for example `+584121301234`; the BFF trims the value and stores it as received.
- The value is informational. No lookup, dedupe, FK, or identity resolution is added.
- Test mode: TDD (each phase writes tests first and ends with its own test gate).
- The BFF remains a proxy: `geolocation` is still validated and not forwarded, and no new upstream endpoint is introduced.

Out of scope: API implementation, `libs/emergency` implementation, UI display, registered-phone lookup, and abort/refresh contract changes.

## Phase 1: Accept, validate, and forward on activation

- [x] Add failing tests in `tests/Feature/KernelTest.php`: activation with `reporter_phone` sends it in the upstream `POST /v2/emergency` body; activation without it still succeeds; a malformed value returns `422` with `error.code = validation_failed`.
- [x] Run `composer test -- --filter KernelTest` and confirm the new assertions fail.
- [x] In `src/Http/Kernel.php`, add a private `reporterPhone(Request $request): ?string` helper that trims the input, returns `null` when absent or empty, rejects non-strings and values outside `^\+?\d{7,15}$` with `HttpError(422, 'validation_failed', ...)`, and enforces `max:20`.
- [x] Pass the helper's result to `EmergencyService::activate()` from the `/activate` route.
- [x] Change `EmergencyService::activate()` to accept `?string $reporterPhone = null` as its third argument and pass it through `createEmergency()` to `EmergencyRepository::create()`.
- [x] Change `EmergencyRepository::create()` to accept `?string $reporterPhone = null` and include `'reporter_phone' => $reporterPhone` in the upstream create body.
- [x] **Gate:** `composer test -- --filter KernelTest` passes.

## Phase 2: Return reporter_phone and keep simulacrum null

- [x] Add failing tests in `tests/Unit/EmergencyServiceTest.php`: activation returns `reporter_phone`; simulacrum sends `null`.
- [x] Add failing tests in `tests/Unit/EmergencyRepositoryTest.php`: the create body includes `reporter_phone` when provided and omits or nulls it when absent.
- [x] Add `?string $reporterPhone = null` to `EmergencyResult` and include it in `toArray()` and `fromArray()` as `reporter_phone`.
- [x] In `EmergencyService::activate()`, pass the input value (or the snapshot value when present) into `EmergencyResult`; in `simulacrum()`, pass `null`.
- [x] **Gate:** `composer test` passes.

## Phase 3: Documentation, changelog, and full verification

- [x] Update `docs/api-contract.md`: add `reporter_phone` to the `POST /activate` request, the activation response, and the `GET /current` example/shape, noting that `null` means no reporter phone was supplied.
- [x] Add a `CHANGELOG.md` entry under `## [Unreleased]` → `### Added` describing the optional reporter phone on activation.
- [x] **Gate:** `composer test` passes.
- [x] **Gate:** `grep -rn "reporter_phone" packages/emergency-bff/src` shows the Kernel helper, the service forwarding, the repository payload, and the result serialization.
