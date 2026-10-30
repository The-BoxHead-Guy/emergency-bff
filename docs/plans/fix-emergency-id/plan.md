# Plan: Coerce upstream emergency_id to a string

## Spec

An emergency activated through the BFF reaches clients with
`"emergency_id": null`, so the published client maps it to `""` and its
`refresh()`/`abort()` guards no-op. The upstream API returns the id as an
integer (`data.attributes.emergency_id = 130`), and the BFF only accepts strings
at its typed boundary, so `130` becomes `null`.

Goal: every BFF payload (`activate`, `simulacrum`, `current`, `refresh`,
`abort`) exposes `emergency_id` as a decimal string when the upstream sent a
value, and `null` only when the upstream has no id. All other fields and unknown
attributes keep their current shape and type.

Assumptions:

- `EmergencyRepository` is the only path to raw upstream bodies, so normalizing
  there makes every downstream reader correct without touching them.
- KISS: one private method, no new class, no new test file, no service-layer
  duplicate normalization. The existing `is_string()` check in
  `EmergencyService::emergencyId()` becomes correct once the repository emits
  strings.
- Tests are extended, not duplicated: the existing `'EMG1'` cases stay as the
  backward-compatibility baseline; numeric `130` is added as data-provider
  cases on the tests that already cover these flows.
- Test mode: TDD (one gate per phase), as declared by the source plan.

Out of scope: `libs/emergency`, `apps/mirror-bp.scaem.app`, the upstream API
shape, responder ids, and any refactor of `EmergencyService` or
`EmergencyResult`.

## Phase 1: Normalize in the repository

- [x] In `tests/Unit/EmergencyServiceTest.php`, add
      `private const EMERGENCY_NUMERIC` shaped like the real API
      (`data.attributes.emergency_id => 130`) and let `client()` accept the
      emergency body and derive the `/v2/emergency/{id}` paths from it
      (default `self::EMERGENCY`).
- [x] Add one failing test
      `test_it_returns_a_numeric_upstream_id_as_a_string` calling `activate()`
      against the numeric fixture and asserting
      `$result->emergencyId === '130'` and
      `$result->toArray()['emergency_id'] === '130'`.
- [x] In `src/Api/EmergencyRepository.php`, add one private
      `normalizeId(array $shape): array` that, for each of `emergency_id` and
      `id` **when the key exists**, maps `is_int` to `(string)`, keeps a
      non-empty string as-is, and maps anything else (including `''`) to
      `null`. Apply it to both returned shapes in `normalize()`
      (`data.attributes` / `attributes`, and the `data` / whole-body fallback).
- [x] Touch nothing else in the payload; keys must not be added when absent.
- [x] **Gate:** the new test fails before the repository change and passes after
      it; `vendor/bin/phpunit --filter EmergencyServiceTest` is green.

## Phase 2: Lock the HTTP contract

- [x] In `tests/Feature/KernelTest.php`, give `kernel()` a
      `string|int $emergencyId = 'EMG1'` parameter and build the upstream
      bodies and `/v2/emergency/{id}` paths from it instead of hardcoding
      `'EMG1'`.
- [x] Convert `test_it_runs_the_full_session_to_abort_flow` to a PHPUnit data
      provider over `['EMG1', 'EMG1']` and `[130, '130']`, asserting for each
      case: activation `201` with `emergency_id === $expected`, `current`
      `emergency.emergency_id === $expected`, refresh `200` with the same
      string id, and delete `200` with `emergency_id === $expected`.
- [x] Assert the numeric activation body has no `null` id (`array_key_exists` +
      `assertIsString`).
- [x] **Gate:** full suite green from a clean install:
      `composer --working-dir=packages/emergency-bff install` then
      `composer --working-dir=packages/emergency-bff test`.

## Phase 3: Docs and changelog

- [x] Update `docs/bff/upstream-api.md` "Emergency normalization": a numeric
      `emergency_id` is coerced to its decimal string in the repository; `null`
      only when the upstream has no id.
- [x] Add an `Unreleased → Fixed` entry to `CHANGELOG.md` covering the integer
      id bug and the `refresh()`/`abort()` no-op impact. Do not bump or publish.
- [x] Confirm `README.md` already documents the string contract and leave it
      unchanged; record that `apps/mirror-bp.scaem.app` only needs a
      `composer update` after release. (README unchanged; downstream app needs
      only `composer update` after release.)
- [x] **Gate:** full suite still green, no remaining claim in
      `docs/bff/upstream-api.md` or `CHANGELOG.md` contradicts the string
      contract, and `git status --short` shows no changes under `apps/` or
      `libs/`.
