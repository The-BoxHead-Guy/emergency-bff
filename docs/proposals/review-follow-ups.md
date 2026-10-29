# Emergency BFF review follow-ups

Status: proposal, not implemented

Origin: code review of `2c90d3461...b7e191819` on branch
`bp_libs-chore/emergency-packages`. The review covered the 90-commit package
checkpoint, its tests, the vanilla example, and the release workflows.

Destination: a new branch, for example
`chore/emergency-bff-review-followups`.

This document records the findings that should be resolved before the package
is treated as stable. It is not a release blocker list for the local alpha; the
P0 item is the only issue that should be fixed before exposing the BFF to more
than one session.

## Priority legend

- **P0**: security or data exposure; fix before wider use.
- **P1**: correctness or quality gap that will cause maintenance problems.
- **P2**: cleanup, documentation, and test depth.
- **P3**: accepted scope or documentation decision; no code change required.

## Summary

| ID     | Axis                 | Priority | Finding                                                                |
| ------ | -------------------- | -------- | ---------------------------------------------------------------------- |
| EBF-01 | Spec / correctness   | P0       | `POST /{id}/refresh` does not verify emergency ownership               |
| EBF-02 | Spec / extensibility | P1       | CSRF handling cannot be replaced by a host adapter                     |
| EBF-03 | Spec / correctness   | P2       | Emergency QR fallback URL is configured but unreachable                |
| EBF-04 | Standards / process  | P1       | Package has no Nx project integration                                  |
| EBF-05 | Standards / quality  | P1       | No Pint or static-analysis gate                                        |
| EBF-06 | Standards / tests    | P1       | Refresh ownership and simulacrum idempotency are untested              |
| EBF-07 | Standards / style    | P2       | Comments and PHPDoc contain rationale and history                      |
| EBF-08 | Standards / design   | P2       | Activation and simulacrum duplicate most orchestration                 |
| EBF-09 | Standards / design   | P2       | Unused abstractions and dead configuration                             |
| EBF-10 | Standards / design   | P2       | `Kernel` mixes routing, middleware, validation, and request state      |
| EBF-11 | Standards / design   | P2       | Data clumps and primitive obsession around contacts and geolocation    |
| EBF-12 | Spec / tests         | P2       | Error-response fixtures exist but are never loaded                     |
| EBF-13 | Spec / state         | P2       | Identity regeneration keeps the previous emergency snapshot            |
| EBF-14 | Spec / API contract  | P2       | Error fields are nested under `error`, not at the documented top level |
| EBF-15 | Spec / scope         | P2       | Root CI duplicates the package CI                                      |
| EBF-16 | Spec / scope         | P3       | Example and documentation extras beyond the minimum                    |
| EBF-17 | Spec / API contract  | P3       | `/config` and activation return unspecified additive fields            |

## P0

### EBF-01: Refresh must enforce emergency ownership

**Evidence**

- `src/Http/Kernel.php:221` checks only `$this->identity()` before calling `refresh()`.
- `src/Emergency/EmergencyService.php:208` forwards the requested ID directly to `EmergencyRepository::show()`.
- `abort()` at `src/Emergency/EmergencyService.php:163` already performs an ownership check through `findActiveByCode()`.

**Why it matters**

Any valid session can read another code's emergency by guessing or observing an
emergency ID. The response contains responder names, phone numbers, and message
status. This is a read-only leak but still a cross-tenant data exposure.

**Proposed change**

- Change `refresh()` to accept `Identity $identity` plus the emergency ID.
- Resolve the caller's active emergency with `findActiveByCode($identity->code)`.
- Compare the resolved ID with the requested ID. Return `EmergencyNotFoundException`
  when no active emergency exists; return `ForbiddenEmergencyException` when the
  IDs differ, matching the abort contract.
- Pass the identity from the route.
- Reuse the ownership helper between `abort()` and `refresh()` rather than
  duplicating the comparison.

**Acceptance**

- A second session cannot refresh the first session's emergency.
- The failure does not reveal whether the target ID exists.
- `KernelTest` covers same-owner success, different-owner rejection, and an
  inactive caller with no emergency.
- Existing refresh and abort tests remain unchanged.

## P1

### EBF-02: Add a replaceable CSRF seam

**Evidence**

- `src/Session/SessionManager.php:117-130` constructs `new CsrfTokenManager($this->store)` inside every CSRF method.
- Only `SessionStore` is injectable; the token manager and its storage key are fixed.
- The plan requires: "Document the host adapter contract so Laravel or another framework can replace `NativeSessionStore` and its CSRF mechanism."

**Why it matters**

A Laravel host can replace session storage but cannot replace token generation,
validation, or the storage key, so it cannot reuse its own CSRF middleware.

**Proposed change**

- Define a small `CsrfManager` contract (`token`, `rotate`, `validate`) and make
  the current `CsrfTokenManager` implement it.
- Inject the contract into `SessionManager`, defaulting to the native
  implementation.
- Keep the current 64-character token and constant-time comparison as the
  default.
- Document the contract in `README.md` next to the `SessionStore` adapter.

**Acceptance**

- A fake CSRF manager can be injected and observed by `SessionManager`.
- Default behavior and all existing session tests are unchanged.
- README shows both session and CSRF replacement.

### EBF-04: Register the package with Nx

**Evidence**

- The monorepo rule in `AGENTS.md` and `docs/agent/workflow.md` requires tasks
  to run through Nx.
- `packages/php-shared/project.json` is the sibling pattern; `packages/emergency-bff`
  has no `project.json`.
- `.github/workflows/emergency-bff.yml` calls Composer, `php -l`, and PHPUnit
  directly.

**Why it matters**

The package is invisible to `nx affected`, so monorepo CI cannot select it by
dependency and the documented task surface is inconsistent with the sibling
package. The split-repository workflow must keep raw Composer commands because
Nx does not exist there; only the root workflow should change.

**Proposed change**

- Add `packages/emergency-bff/project.json` named `emergency-bff`, with
  `test`, `lint`/`format` (after EBF-05), and `composer-install` targets modeled
  on `packages/php-shared`.
- Make `.github/workflows/emergency-bff.yml` call
  `pnpm nx run emergency-bff:test` and the other targets.
- Leave `packages/emergency-bff/.github/workflows/ci.yml` self-contained for
  the split repository.

**Acceptance**

- `pnpm nx show project emergency-bff` lists the targets.
- `pnpm nx affected -t test` selects the package when its files change.
- The root workflow uses Nx; the split workflow stays independent.

### EBF-05: Add formatting and static-analysis gates

**Evidence**

- `docs/agent/laravel-architecture.md` lists `:pint`, `:pint-check`, and
  `:analyse`.
- `packages/php-shared/composer.json` includes `laravel/pint` and has
  `lint`/`format` scripts.
- `packages/emergency-bff/composer.json` has only `test`; neither CI workflow
  runs a formatter or analyzer.

**Why it matters**

The package is now the largest new PHP surface in the repository and has no
automated style or type-safety gate. The review already found dead properties
and duplicated shapes that static analysis would surface.

**Proposed change**

- Add `laravel/pint` as a dev dependency and `lint`/`format` scripts.
- Add `phpstan/phpstan` at a conservative level (start at 6, include `src/`),
  with a baseline only for unavoidable framework-free edge cases.
- Add matching Nx targets and run `pint --test` plus PHPStan in both CI
  workflows.
- Keep `php -l` only if it remains useful for the example directory.

**Acceptance**

- `composer lint`, `composer analyse`, and `composer test` pass locally.
- CI fails on an intentionally unformatted file or a type error.
- The Nx targets mirror the Composer scripts.

### EBF-06: Cover refresh ownership and simulacrum idempotency

**Evidence**

- `tests/Feature/KernelTest.php` covers refresh for the owning session only.
- `tests/Unit/EmergencyServiceTest.php` covers activation idempotency but not
  repeated simulacrum calls with the same key.

**Why it matters**

These are the two paths where a regression is most expensive: cross-session
reads and duplicate WhatsApp sends.

**Proposed change**

- Add the refresh cases from EBF-01.
- Add a simulacrum test that calls twice with one key and asserts only one send
  per responder and one cached result.
- Add a test that `storeIdentity()` clears the previous emergency snapshot
  (EBF-13).

**Acceptance**

- Each new test fails against the current code and passes after the fix.
- No WhatsApp client call count changes in unrelated tests.

## P2

### EBF-03: Implement or remove the emergency fallback URL

**Evidence**

- `src/WhatsApp/WhatsAppPayloadBuilder.php:40` accepts `fallbackOriginUrl`.
- `buildQrUrl()` at line 172 never reads it; an empty code returns `''`.
- The JavaScript `_buildFmdUrl()` returns `FALLBACK_ORIGIN_URL` for a missing
  emergency QR code, while simulacrum `_buildQrCodeUrl()` returns `''`.
- `README.md:66` advertises a fallback that cannot happen.

**Why it matters**

The PHP port does not preserve the documented emergency template behavior. In
normal activation `EmergencyService` substitutes the identity code, so the
branch is currently unreachable, but the configuration and documentation are
misleading.

**Proposed change**

- Separate emergency and simulacrum URL construction.
- Emergency: use the configured fallback origin when the code is empty or URL
  construction fails.
- Simulacrum: keep the current empty-string behavior.
- Add fixture coverage for both cases.

**Acceptance**

- Empty emergency QR yields the fallback origin.
- Empty simulacrum QR stays empty.
- README and `.env.example` describe the behavior accurately.

### EBF-07: Make comments describe behavior only

**Evidence**

- `docs/agent/code-style.md` forbids rationale, history, and reasoning in
  comments.
- Examples: `src/Http/Endpoints.php:20-24`, `src/WhatsApp/WhatsAppPayloadBuilder.php:10`,
  `src/WhatsApp/ProductCodeSelector.php:8`, `src/Emergency/CodeValidator.php:12-16`,
  `src/Config/Config.php:10-13`, `src/Support/Bootstrap.php:25-28`, and
  `examples/vanilla-php/public/index.php:10-14`.

**Proposed change**

- Replace each with one sentence stating what the code does.
- Move the user-token explanation for `fmdExists()` into README or the proposal
  notes, where design rationale belongs.
- Keep the PHPUnit fixture descriptions as test data, not code comments.

**Acceptance**

- A review of `src/`, `public/`, and `examples/` finds no why/history comments.
- No behavior changes; tests remain green.

### EBF-08: Remove duplication between activation and simulacrum

**Evidence**

- `src/Emergency/EmergencyService.php:43` and `:113` repeat idempotency lookup,
  profile fetch, personal/contact guards, responder creation, QR selection,
  send loop, all-failed handling, logging, and cache write.
- `src/Config/Config.php:43` and `:101` repeat field mapping.
- The three payload builders repeat base-payload assembly and body-parameter
  construction.

**Why it matters**

A change to contact validation, logging, or caching must currently be made
twice, which is a classic divergence risk.

**Proposed change**

- Extract a private `prepareDelivery(Identity)` returning profile, responders,
  and QR code, with a flag for the active-emergency check.
- Extract `deliver()` for the send/cache/log tail.
- Extract payload body-component helpers; keep the three public builders thin.
- Extract a `mapEnvironment()` helper for `Config` without hiding validation.

**Acceptance**

- Activation and simulacrum behavior and fixtures are unchanged.
- The duplicated block is reduced to the genuinely different steps.
- Existing orchestration tests pass without edits.

### EBF-09: Remove or use dead abstractions

**Evidence**

- `SessionManager::emergency()` at line 100 is never read; snapshots are only
  written and cleared.
- `RealtimeConfig::channelFor()` and `authRoute()` have no callers.
- `Response::noContent()`, `Request::attribute()`/`withAttribute()`, and
  `ApiResponse::isNotFound()`/`isNoContent()` have no production callers.
- `fallbackOriginUrl` is covered by EBF-03.

**Why it matters**

Reserved API surface without a consumer increases the maintenance cost and
makes it unclear which contracts are real.

**Proposed change**

- Remove unused methods, or add a short `@api`/README note and a test if they are
  intentionally reserved for hosts.
- Remove the write-only emergency snapshot unless EBF-13 keeps it for a planned
  resume fallback. If kept, add a reader and test.

**Acceptance**

- Static analysis or a targeted search confirms every remaining public helper
  has a caller or documented host use.
- No behavior changes.

### EBF-10: Reduce `Kernel` responsibilities and request state

**Evidence**

- `src/Http/Kernel.php` is approximately 329 lines and contains route
  registration, middleware assembly, authentication, validation, error mapping,
  and response shaping.
- `private string $requestId` at line 40 stores per-request state on a
  long-lived object.

**Why it matters**

The class changes for unrelated reasons and would leak request IDs if reused
for concurrent requests under a long-running server. Native PHP creates one
kernel per request today, so this is not an active bug.

**Proposed change**

- Introduce a request context/value object carrying the request ID.
- Extract route registration into a `Routes` class or a `routes.php` builder.
- Keep middleware assembly in one focused method.
- Do not introduce a container solely for this refactor.

**Acceptance**

- `X-Request-Id` and the error envelope still use the same value.
- A kernel instance used for two sequential requests never reuses the first ID.
- Route tests and middleware tests remain unchanged.

### EBF-11: Introduce types for geolocation and delivery failures

**Evidence**

- `(array $contact, array $personalInfo)` travels through all payload builders
  and `sendToResponders()`.
- Geolocation is an untyped array; `WhatsAppPayloadBuilder.php:55-56` repeats
  the `coords` fallback while `Kernel::geolocation()` validates separately.
- Failure rows are hand-built in more than one branch.

**Proposed change**

- Add a small `GeoPoint` value object with named constructors for flat and
  `coords` input; let `Kernel` produce it once.
- Add a `DeliveryFailure` value object for phone, status, and message.
- Pass `Profile` rather than parallel arrays where practical.

**Acceptance**

- Invalid coordinates are rejected once, at the HTTP boundary.
- Payload tests keep their current JSON output.
- Failure arrays keep their current public key names.

### EBF-12: Use the error-response fixtures

**Evidence**

- `tests/fixtures/responses/404.json`, `422.json`, `500.json`, and `timeout.json`
  exist.
- No test references them; `ApiClientTest` builds inline responses.
- Only `success.json` is loaded.

**Proposed change**

- Load the 404, 422, and 500 fixtures in `ApiClientTest` and assert the mapped
  `ApiException` code, status, and details.
- Keep `timeout.json` as documentation for the transport scenario, or replace
  it with a test that reads it and asserts the transport mapping description.
- Remove any fixture that cannot be meaningfully exercised.

**Acceptance**

- Every response fixture is referenced by a test or explicitly documented as
  scenario data.
- Error mapping tests use the real upstream envelope shapes.

### EBF-13: Clear emergency state when the identity changes

**Evidence**

- `SessionManager::storeIdentity()` at line 55 regenerates the session and
  writes the new code but does not call `clearEmergency()`.
- `de_bff_emergency` therefore survives a code switch until a later route
  overwrites or clears it.
- The snapshot is currently write-only, so the impact is latent.

**Proposed change**

- Read the previous identity code before writing the new one.
- If it differs, call `clearEmergency()`.
- If the snapshot is not going to be read, remove it entirely (see EBF-09).

**Acceptance**

- Starting a session for code B after code A leaves no A snapshot.
- Re-starting the same code does not need to clear it, or clears it safely.
- Session tests cover the switch.

### EBF-14: Decide and document the error envelope shape

**Evidence**

- `Kernel::error()` at line 318 returns `{"error": {"code", "message", "details", "request_id"}}`.
- The plan names the envelope fields but does not state whether they are top-level.
- Clients and tests already depend on the nested shape.

**Proposed change**

- Keep the nested `error` object as the canonical contract because it is
  idiomatic and already implemented.
- Update the plan/README wording to show the full JSON example and state that
  the four fields live under `error`.
- Add a contract test asserting the exact top-level keys.

**Acceptance**

- README, plan, and tests describe the same shape.
- No client-visible breaking change.

### EBF-15: Consolidate root and package CI

**Evidence**

- `.github/workflows/emergency-bff.yml` repeats the package workflow's Composer,
  `php -l`, and PHPUnit commands.
- The split repository cannot use Nx; the monorepo workflow can.

**Proposed change**

- Keep both workflows but give them distinct jobs: the root workflow runs Nx
  targets for monorepo changes; the package workflow remains the self-contained
  split-repository check.
- Remove duplicated command lists from the root workflow after EBF-04/05.
- Document the split-workflow purpose in `docs/publishing.md`.

**Acceptance**

- A package change runs the root Nx path.
- The split repository still runs its own CI without Nx.
- No workflow executes the same test suite twice unnecessarily.

## P3

### EBF-16: Keep the example extras and document them

**Evidence**

- The spec asks for a path repository, `.env.example`, and an `index.php`
  demonstrating session, activation, status, and abort.
- The example also adds `demo.html`, `demo.sh`, `mock-api.php`, `serve.sh`,
  `smoke.sh`, and `docs/publishing.md`.

**Why it matters**

The extras made the real-API integration test possible and are now the primary
local verification path. Removing them would reduce confidence.

**Proposed change**

- Keep them.
- Mark them as example/tooling scope in the example README and the feature
  history; do not describe them as part of the package API.

**Acceptance**

- The README distinguishes the library package from the example harness.
- No extra runtime dependency is added to the package itself.

### EBF-17: Document additive response fields as stable API

**Evidence**

- `/config` returns `csrf_token`; the client needs it to bootstrap mutations.
- Activation results include `partial_failure` and `total_failure`.

**Proposed change**

- Document both as part of the BFF contract.
- Keep them additive and avoid renaming existing fields.
- Add a response-schema section or table to the package README.

**Acceptance**

- README lists every field returned by the public routes.
- Tests assert the additive fields.

## Suggested delivery plan

Create `chore/emergency-bff-review-followups` from the checkpoint and work in
this order:

- [ ] **Phase 1, security:** EBF-01 and its tests; EBF-13 if the snapshot stays.
- [ ] **Phase 2, quality gates:** EBF-04 and EBF-05; make both CI paths pass.
- [ ] **Phase 3, behavior:** EBF-02, EBF-03, EBF-14; update README and fixtures.
- [ ] **Phase 4, refactor:** EBF-08 through EBF-11; keep public output unchanged.
- [ ] **Phase 5, tests and docs:** EBF-06, EBF-12, EBF-15 through EBF-17.
- [ ] **Gate:** `composer test`, `composer lint`, `composer analyse`, the mock
      demo smoke test, and a real-API session/current check all pass.

## Deferred and dismissed during verification

- **Plan lifecycle:** the review flagged the plan not being removed. Four
  release tasks remain open (Packagist, tags, split CI), so the plan is still a
  working document and was not counted as a finding.
- **`ProductCodeSelector` map:** the constant is used by `findValidProductCode()`;
  it is not dead code.
- **`Config` constructor defaults:** they remain valid for non-blank
  construction and are not unreachable.
- **Split-repository Nx use:** the split repository has no Nx workspace, so its
  self-contained Composer workflow is intentional.
- **Packagist registration, release tags, and split CI observation:** external
  release steps, tracked in `docs/publishing.md`.
