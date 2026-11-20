# Plan: Pest 5 migration for emergency-bff

## Spec

Move `packages/emergency-bff` from a direct PHPUnit 11 dev dependency to
Pest 5 while preserving the existing test behavior. The package is a
framework-free Composer library, so the migration uses Pest core only: no
`pestphp/pest-plugin-laravel`, no `artisan`, no database or factory helpers.
Pest 5 requires PHP 8.4 and PHPUnit 13; both are compatible with the package's
`php: ^8.4` constraint and the current CI matrix.

The end state is:

- `pestphp/pest:^5.0` is the test runner and `phpunit/phpunit` is no longer a
  direct dependency.
- `vendor/bin/pest` runs the full suite on PHP 8.4.
- `tests/Pest.php` binds the existing
  `DataEmergencia\EmergencyBff\Tests\TestCase` to the `Unit` and `Feature`
  directories.
- All 15 test classes are converted to Pest's functional style, except
  `KernelTest` may remain class-based if a behavior-preserving conversion is
  not achievable; Pest runs both styles.
- Composer scripts, CI, and current release documentation point to
  `vendor/bin/pest`.

Assumptions (correct before implementation if wrong):

- Pest **5.x** is mandatory. Do not downgrade to Pest 4 or fall back to
  PHPUnit 11.
- Verification is **per-phase**: every conversion wave ends with targeted
  tests and the full suite.
- The existing suite is the specification. No production code, fixture data,
  response shape, or test intent changes.
- The conversion is **manual**. Drift is deliberately not used suite-wide: it
  globalizes helper methods (`client()` is defined in three test files),
  removes properties, does not convert this suite's 470 `self::assert*` calls,
  and has no dry-run. It may be used only as an optional one-file pilot if the
  executor records why.
- `tests/TestCase.php` keeps `fixture()` and `fixturePath()` initially.
  Converting them to global helpers is out of scope.
- Test counts may shift slightly when data providers become datasets;
  per-file behavior is authoritative.
- No commits are made by the executor. The repository's atomic-commit policy
  is handled separately by the user.
- The `ArrayReader` work is committed before Phase 1; the migration starts
  from a clean tree.

Out of scope:

- Production `src/` changes, refactors, or bug fixes.
- Coverage targets, mutation testing, browser testing, TIA, sharding, or
  parallel CI.
- Laravel packages, `RefreshDatabase`, factories, or HTTP test helpers.
- PHPStan or Rector adoption beyond the optional assertion-cleanup phase.
- Rewriting historical plans or changing fixture payloads.
- Commit, push, or PR creation.

### Conversion rules

1. One test file per conversion step; run the targeted test and the full suite
   before moving on.
2. Preserve every behavior, exception message, data-provider case, and fixture
   path.
3. Keep test descriptions recognizable; do not rename tests for style.
4. Do not leave generic global helper functions (`client()`, `repository()`,
   `service()`). Move reusable helpers to `tests/Support` classes or give them
   unique names.
5. In Pest closures, use `$this->assert*` for PHPUnit assertions.
   `self::assert*` works, but it is not eligible for automated modernization.
6. Preserve multi-message exception assertions. `throws()` accepts one
   message; use `toThrow()` with a callback or keep the current pattern.
7. If a file cannot be converted without behavior risk, leave it as a PHPUnit
   class; Pest still runs it. Record the exception in the phase notes.
8. No test may be deleted or weakened to make the migration pass.

## Phase 1: Pre-requirements and baseline

- [ ] Confirm the `ArrayReader` work is committed and `git status --short` is
      clean.
- [ ] Create a migration branch or worktree and record the rollback point.
- [ ] Confirm PHP 8.4 locally (`php -v`), Composer 2.x, and PHP 8.4 in the CI
      matrix.
- [ ] Run `composer validate --strict` and `composer install`; ensure
      `vendor/` is current.
- [ ] Run `vendor/bin/phpunit` and record total tests, assertions, duration,
      and any warnings. The observed reference is 189 tests / 1,184
      assertions, but re-baseline after `ArrayReader` lands.
- [ ] Record per-file test counts for the 15 `*Test.php` classes.
- [ ] Confirm `composer.json` has no Pest, Laravel, or Mockery packages and
      that no production changes are pending.
- [ ] **Gate:** clean tree; PHPUnit baseline is green; per-file baseline is
      recorded; PHP 8.4 is confirmed.

## Phase 2: Toolchain switch to Pest 5

- [ ] Remove the direct `phpunit/phpunit` dev dependency.
- [ ] Require `pestphp/pest:^5.0` as a dev dependency with
      `--with-all-dependencies`; update `composer.lock`.
- [ ] Run `vendor/bin/pest --migrate-configuration`; review the `phpunit.xml`
      diff and keep the testsuite, source, `failOnRisky`, `failOnWarning`, and
      `cacheDirectory` settings.
- [ ] Update `composer.json` `scripts.test` to `pest`.
- [ ] Update `.github/workflows/ci.yml` to run `vendor/bin/pest`.
- [ ] Do not add `pestphp/pest-plugin-laravel`, `pestphp/pest-plugin-drift`, or
      Laravel test helpers.
- [ ] Run `vendor/bin/pest` on the untouched suite.
- [ ] **Gate:** `vendor/bin/pest` exits 0; the test count matches the Phase 1
      baseline or every difference is documented; `composer validate --strict`
      and `php -l` pass; Pest 5 and PHPUnit 13 versions are confirmed.

## Phase 3: Vanilla Pest bootstrap

- [ ] Create `tests/Pest.php` manually; do not run `pest --init` blindly.
- [ ] Bind
      `pest()->extend(DataEmergencia\EmergencyBff\Tests\TestCase::class)->in('Unit', 'Feature');`.
- [ ] Keep `tests/TestCase.php` and its fixture helpers; do not change their
      namespace or signatures.
- [ ] Add a temporary bootstrap test that asserts `$this` is the custom
      `TestCase` and that `fixture()` resolves a fixture; run it and delete it.
- [ ] Confirm `tests/Pest.php` has no Laravel imports and no stub reference to
      `Tests\TestCase`.
- [ ] Run the full suite.
- [ ] **Gate:** the bootstrap test passes; the full suite is green;
      `tests/Pest.php` loads without errors.

## Phase 4: Conversion wave 1 — stateless unit tests

- [ ] Convert `tests/Unit/ConfigTest.php` to Pest style; preserve test names;
      run the targeted test and the full suite.
- [ ] Convert `tests/Unit/MessageResultTest.php`; run targeted and full suite.
- [ ] Convert `tests/Unit/EndpointsTest.php`; run targeted and full suite.
- [ ] Convert `tests/Unit/ArrayReaderTest.php`; convert
      `profileShapeProvider` to a dataset with named cases; run targeted and
      full suite.
- [ ] Convert `tests/Unit/WhatsAppClientTest.php`; convert
      `failureProvider` to a dataset with named cases; run targeted and full
      suite.
- [ ] Convert `tests/Unit/ProfileAndWhatsAppDataTest.php`; run targeted and
      full suite.
- [ ] Convert `tests/Unit/CreditRepositoryTest.php`; run targeted and full
      suite.
- [ ] Normalize `self::assert*` to `$this->assert*` in every wave-1 test
      closure.
- [ ] **Gate:** all seven wave-1 files run under Pest; targeted tests and the
      full suite are green; per-file test counts match the Phase 1 baseline.

## Phase 5: Conversion wave 2 — pure-helper unit tests

- [ ] Inventory the helper methods and call sites in the five wave-2 files.
- [ ] Move reusable pure helpers to `tests/Support` static factory classes or
      give them unique names; do not create a global `client()` function.
- [ ] Convert `tests/Unit/ApiClientTest.php`; run targeted and full suite.
- [ ] Convert `tests/Unit/ClientMessagesTest.php`; run targeted and full
      suite.
- [ ] Convert `tests/Unit/ClientMessageCoverageTest.php`; run targeted and
      full suite.
- [ ] Convert `tests/Unit/EmergencyRepositoryTest.php`; run targeted and full
      suite.
- [ ] Convert `tests/Unit/EmergencyServiceTest.php`; run targeted and full
      suite.
- [ ] Verify no global function redeclaration and no `$this` usage in
      extracted helpers.
- [ ] **Gate:** all five wave-2 files are green; the full suite is green;
      helper names are unique; no behavior changed.

## Phase 6: Conversion wave 3 — stateful tests

- [ ] Convert `tests/Unit/SessionTest.php`: replace `$store` and `$now`
      instance state with per-test locals or a small state object; preserve
      time progression; convert the `manager()` helper.
- [ ] Convert `tests/Unit/WhatsAppPayloadBuilderTest.php`: replace the
      `$builder` property and `setUp()` with per-test locals or `beforeEach`;
      convert the `contact()` helper.
- [ ] Run the targeted test and the full suite after each file.
- [ ] **Gate:** both files are green; session timing and payload behavior are
      unchanged; the full suite is green.

## Phase 7: Conversion wave 4 — KernelTest

- [ ] Inventory `KernelTest`'s helper methods, three properties, three data
      providers, and assertion usage.
- [ ] Extract static factories and request helpers (`makeKernel`,
      `buildKernel`, `defaultClient`, `json`, `tokenFor`, `startSession`, and
      related helpers) into `tests/Support` classes or unique helpers; remove
      `$this` and `self::` from helpers that become global functions.
- [ ] Convert `KernelTest` test methods to Pest style.
- [ ] Convert `emergencyIdProvider`, `invalidPaginationProvider`, and
      `errorPathProvider` to datasets with named cases.
- [ ] Preserve the `assertResponderPayload` checks as a shared helper.
- [ ] Run the targeted test and the full suite.
- [ ] If conversion is blocked, leave `KernelTest` as a PHPUnit class and
      document why; the full suite must remain green.
- [ ] **Gate:** `KernelTest` is green under Pest, either functional or
      class-based; the full suite is green; feature-flow behavior is
      unchanged.

## Phase 8: Assertion and exception cleanup

- [ ] Record the assertion policy: behavior-preserving (`$this->assert*`) or
      idiomatic (`expect()`).
- [ ] Normalize any remaining `self::assert*` in Pest closures to
      `$this->assert*`.
- [ ] Optionally install `pestphp/pest-plugin-rector:^5.0` and
      `rector/rector`; add a `rector.php` using `PestSetList::CODING_STYLE`;
      run `--dry-run` and review before applying.
- [ ] Manually handle assertions not covered by the automated mapping
      (`assertStringNotContainsString`, `assertNotSame`,
      `assertArrayNotHasKey`, `assertStringContainsString`,
      `assertDoesNotMatchRegularExpression`) or keep them as PHPUnit
      assertions.
- [ ] Convert `expectException()` and `expectExceptionMessage()` to
      `throws()` or `toThrow()` only where every original check is preserved;
      special-case the multi-message assertion in `ConfigTest`.
- [ ] Run the full suite and compare per-file assertion counts and failure
      messages.
- [ ] **Gate:** the full suite is green; no exception assertion is weakened;
      no accidental `self::assert*` remains in Pest closures; the assertion
      policy is recorded.

## Phase 9: Cleanup, documentation, and final verification

- [ ] Remove migration-only packages (Drift if used; Rector if it is not kept).
- [ ] Update `docs/release/releasing.md`, README or contributing docs, and
      any current docs that still reference `vendor/bin/phpunit`; leave
      historical plans untouched.
- [ ] Verify `composer.json` has `pestphp/pest:^5.0`, no direct
      `phpunit/phpunit` constraint, and no Laravel plugin.
- [ ] Verify `tests/Pest.php` binds the custom `TestCase` and that
      `phpunit.xml` uses the PHPUnit 13 schema with the existing options.
- [ ] Run `composer validate --strict`, `composer install` from the lock,
      `vendor/bin/pest`, `php -l` on `src examples public`, and
      `git diff --check`.
- [ ] Review `git diff` for unintended production or fixture changes; confirm
      the test count matches the baseline or every difference is documented.
- [ ] **Gate:** all commands exit 0; `vendor/bin/pest` runs the full suite;
      CI and current docs use Pest; no production or fixture changes; no
      Laravel dependency and no PHPUnit 11 constraint remain.
