# Plan: ArrayReader support class and array-retrieval refactor

## Spec

Add one framework-free support class, `DataEmergencia\EmergencyBff\Support\ArrayReader`,
whose `get()` mirrors `Illuminate\Support\Arr::get()`: a dot-notated path is
resolved segment by segment, a literal key always wins over path traversal,
numeric segments address list indexes, and a caller-supplied default is
returned when any segment is missing or an intermediate value is not an array.
Then migrate every array **retrieval** in `src/` to
`ArrayReader::get($array, 'a.b.c', $default)` so the package has a single,
predictable lookup primitive instead of ad-hoc `['a']['b'] ?? …` chains.

Assumptions (correct before implementation if wrong):

- The class is named `ArrayReader` and lives at
  `src/Support/ArrayReader.php` in namespace
  `DataEmergencia\EmergencyBff\Support`. Confirmed by the requester; the
  example's `Support\{HelperName}` placeholder resolves to this name.
- Test mode is **deferred**: implementation phases build the change, and
  Phase 5 writes all tests and runs every gate once.
- Refactor scope is **`src/` only**. `examples/`, `tests/`, `public/` and
  `scripts/` are not migrated. Confirmed by the requester.
- The public surface is exactly `get()` and `has()`. No wildcard `*`, no
  escaped `\*`, no `{first}`/`{last}` placeholders, no object/`ArrayAccess`
  support, no closure defaults, no `set()`/`forget()`, no typed accessors.
  Those are `data_get()`/`Arr` extras this package does not need.
- `get()` uses `array_key_exists()` semantics, like Laravel: a key that exists
  with a `null` value is a **hit** and returns `null`, not the default. This
  differs from `$array['key'] ?? $default`, which treats `null` as missing.
  Every migrated call site must preserve the original semantics (see the
  conversion rules below).
- `has()` also uses `array_key_exists()` semantics, so it returns `true` for a
  stored `null`. It is **not** an `isset()` replacement; call sites that need
  "present and non-null" use `ArrayReader::get(...) !== null` instead.
- The root argument is always an array at every call site (upstream body,
  request payload, config array, mapped result). The walker itself type-checks
  each intermediate value, so non-array intermediates degrade to the default
  instead of emitting warnings.
- The package is framework-free (`php: ^8.4`, Guzzle + PSR only), so the class
  is **not** added to `packages/php-shared` (that library requires
  `laravel/framework`). `emergency-bff` is also not an Nx project, so
  verification runs `vendor/bin/phpunit` and `php -l` from
  `packages/emergency-bff`.
- Documentation, README, CHANGELOG and migration guides are out of scope by
  explicit request. No `docs/` page is added or edited except this plan.

Out of scope:

- No behavior, response shape, error mapping, route, session or config change.
- No write-path migration: assignments (`$x['k'] = …`), appends (`$x[] = …`),
  `unset()`, and array literals stay as they are.
- No arithmetic/control arrays: regex match results (`$matches[1]`), the router
  route table, `foreach` key/value mechanics, and list building are not data
  property retrieval and stay untouched.
- No superglobal migration (`$_SERVER`, `$_COOKIE`, `$_SESSION` direct reads),
  except the public `NativeSessionStore::get()` accessor, which reads a
  session-backed data store.

### Conversion rules (apply to every phase)

1. Convert **reads only**. Never touch writes, appends, `unset()`, `foreach`
   destructuring, or array literals.
2. Start from a known array root and use one full dot path:
   `ArrayReader::get($body, 'data.attributes.exists', false)`, not a chain of
   `get()` calls. Use numeric segments for lists:
   `'data.results.0.id'` or the int key form where a single index is read.
3. Preserve `??` semantics. If the original `$x['k'] ?? $default` must fall
   back when the stored value is `null`, write
   `ArrayReader::get($x, 'k') ?? $default`. Use
   `ArrayReader::get($x, 'k', $default)` only when only absence matters.
4. Preserve `isset()` semantics. If the original used `isset($x['k'])`, use
   `ArrayReader::get($x, 'k') !== null` (or `ArrayReader::has($x, 'k')` when a
   stored `null` is acceptable). Never use `has()` where `isset()` was
   guarding behavior against `null`.
5. Preserve defensive `is_array()` / `is_string()` / `(int)` guards. The helper
   retrieves; it does not coerce. A non-array value returned for an expected
   list keeps the existing `is_array(...) ? ... : []` fallback.
6. Keep every literal-key read that may contain dots working: because `get()`
   checks the literal key first, keys such as `127.0.0.1` or `a.b` still match
   directly. Do not split or normalize keys at call sites.
7. Add `use DataEmergencia\EmergencyBff\Support\ArrayReader;` to each touched
   file, keeping the existing alphabetical import order.
8. If a conversion cannot be proven behavior-identical, leave the original
   code and record the reason in the Phase 4 audit.

## Phase 1: ArrayReader core

- [x] Create `src/Support/ArrayReader.php`: `declare(strict_types=1)`, namespace
      `DataEmergencia\EmergencyBff\Support`, `final class ArrayReader`, private
      constructor, no dependencies, no state.
- [x] Implement
      `public static function get(array $array, string|int|null $key, mixed $default = null): mixed`
      with Laravel `Arr::get` order of operations: `null` key returns the whole
      array; a literal `array_key_exists($key, $array)` hit returns the value
      immediately; otherwise split `(string) $key` on `.` and walk, returning
      `$default` as soon as the current value is not an array or the segment is
      missing; return the final value.
- [x] Implement
      `public static function has(array $array, string|int $key): bool`
      using the same literal-key-first, segment-walking algorithm, returning
      `true` only when every segment exists, including a stored `null`.
- [x] Document only the non-obvious contract (a stored `null` is a hit); do
      not narrate the walk. No history, no rationale.
- [x] Add the class docblock `Reads values from arrays using dot notation.`
      and `@param array<string, mixed> $array` annotations consistent with the
      package's PHPDoc style.
- [x] **Gate:** deferred → Phase 5

## Phase 2: Upstream envelope and error normalization

- [x] `src/Api/CreditRepository.php`
  - Replace the three `summary()` candidates with
    `ArrayReader::get($response->body(), 'data.attributes')`,
    `…'attributes'` and `…'data'`, keeping `firstArray()`.
  - Replace every `$attributes[...]` and `$shape[...]` field read with
    `ArrayReader::get(...)`; originals used `?? null`, so the default-less form
    is identical.
  - In `history()`, read `data`, `data.items`, `data.pagination` and the four
    pagination fields with `ArrayReader::get($response->body(), 'data.…')`,
    keeping the `is_array()` guards before mapping items.
  - In `normalizeItem()`, read `attributes` with
    `ArrayReader::get($item, 'attributes')` before the `is_array()` fallback to
    `$item`, and read all shape fields with `ArrayReader::get()`.
  - Do not change `firstArray()`, the private coercers, or the returned shapes.
- [x] `src/Api/ProfileRepository.php`
  - Replace the `extractData()` candidates with
    `ArrayReader::get($body, 'data.attributes.data')`,
    `…'attributes.data'` and `…'data'`; keep the non-empty-array filter.
  - `extractPersonalInfo()`: read `personal_info` and
    `personal.personal_info` with `ArrayReader::get()` + `is_array()` instead
    of `isset()` + bracket reads.
  - `extractProductCodes()`: read `product_codes` with a `[]` default and keep
    the `is_array()` filter.
  - `extractEmergencyContacts()`: read `emergency_info` with
    `ArrayReader::get($data, 'emergency_info') ?? ArrayReader::get($data, 'emergencia.emergency_info', [])`
    to preserve the `??` chain.
- [x] `src/Api/EmergencyRepository.php`
  - `normalize()`: read the `data.attributes` → `attributes` → `data` shapes
    with `ArrayReader::get()` preserving the `??` fallback order.
  - `normalizeId()`: replace `array_key_exists($key, $shape)` with
    `ArrayReader::has($shape, $key)` and the read with
    `ArrayReader::get($shape, $key)`; the assignment stays.
  - `normalizeResponders()`: read `responders` with `ArrayReader::get()`;
    inside the map, read `message_id` with `ArrayReader::get($responder, 'message_id')`
    while the assignment to `$responder['message_id']` stays untouched.
  - Leave the `$payload['geolocation']` write in `create()` alone.
- [x] `src/Api/WhatsAppClient.php`
  - `send()`: read `data` from the body with `ArrayReader::get()`; replace the
    `isset($data['attributes']) && is_array(...)` block with
    `$attributes = ArrayReader::get($response->body(), 'data.attributes')`
    followed by the existing `is_array()` reassignment. Read from the body
    root, not from the already-extracted `$data`, because `get()` takes an
    array and the intermediate is mixed.
  - `typedResult()`: read the recipient with
    `ArrayReader::get($body, 'data.attributes.data.results.0')` and read
    `whatsapp_message_id` / `status` with `ArrayReader::get()`.
- [x] `src/Emergency/CodeValidator.php`
  - Replace the `exists` fallback chain with
    `ArrayReader::get($body, 'data.attributes.exists') ?? ArrayReader::get($body, 'data.exists') ?? ArrayReader::get($body, 'exists', false)`
    so a stored `false` still short-circuits the chain.
- [x] `src/Http/ApiException.php`
  - Read the first error with `ArrayReader::get($body, 'errors.0')` and keep
    the `is_array()` guard.
  - Rebuild the `errorCode` and `message` chains with `ArrayReader::get()`
    calls joined by `??`, preserving the original order and the final literal
    fallbacks.
  - Read `details` as
    `ArrayReader::get($body, 'details') ?? ArrayReader::get($body, 'errors', [])`.
- [x] **Gate:** deferred → Phase 5

## Phase 3: Result, domain and infrastructure reads (sub-agent sweep)

- [x] Dispatch one `general` sub-agent with the universal prompt below. Scope
      is exactly the file list in this phase; do not let the sub-agent edit
      files from Phase 2 or any file outside the list.
- [x] Universal prompt (paste verbatim, replacing only the file list if a file
      is added):

```text
Repository: packages/emergency-bff (PHP 8.4, framework-free, no Laravel).
Task: replace direct array reads with the new support helper
DataEmergencia\EmergencyBff\Support\ArrayReader. The helper already exists at
src/Support/ArrayReader.php with:
  ArrayReader::get(array $array, string|int|null $key, mixed $default = null): mixed
  ArrayReader::has(array $array, string|int $key): bool
It supports dot paths ('data.items'), numeric segments ('results.0.id'),
literal dotted keys (a literal key always wins), and returns the default when
a segment is missing or an intermediate value is not an array. It uses
array_key_exists semantics: a stored null is a hit, unlike ??.

Files (edit only these):
  src/Emergency/EmergencyResult.php
  src/Emergency/EmergencyService.php
  src/WhatsApp/MessageResult.php
  src/WhatsApp/Responder.php
  src/WhatsApp/WhatsAppPayloadBuilder.php
  src/WhatsApp/ProductCodeSelector.php
  src/Api/Profile.php
  src/Config/Config.php
  src/Http/Kernel.php
  src/Http/Request.php
  src/Http/ApiResponse.php
  src/Session/NativeSessionStore.php
  src/Support/RateLimiter.php
  src/Emergency/InMemoryIdempotencyStore.php

Rules:
1. Convert reads only. Never touch writes ($x['k'] = ...), appends ($x[] = ...),
   unset(), foreach mechanics, array literals, or constructor property lists.
2. Use one full dot path from a known array root; do not chain get() calls for
   nested paths. Example: ArrayReader::get($payload, 'responders', []).
3. Preserve ?? semantics: if the original default must also apply when the
   value is stored as null, write ArrayReader::get($x, 'k') ?? $default.
4. Preserve isset() semantics: replace isset($x['k']) with
   ArrayReader::get($x, 'k') !== null. Use ArrayReader::has() only when a
   stored null counts as present.
5. Keep every defensive is_array()/is_string()/(int)/(bool) guard and every
   returned payload shape exactly as it is.
6. Leave superglobal reads ($_SERVER, $_COOKIE) and regex/route-table arrays
   alone. NativeSessionStore::get() is the one session-store read to convert.
7. Add use DataEmergencia\EmergencyBff\Support\ArrayReader; in alphabetical
   import order. Do not reformat unrelated lines.
8. If a conversion cannot be behavior-identical, skip it and record the reason.

Specific expected conversions (not exhaustive):
- EmergencyResult::fromArray(): responder fields, message_id via
  get() !== null (or has), payload responders/failures/snapshot with
  is_array() guards, emergency_id/reporter_phone via get() !== null.
- EmergencyService: active id chains ('emergency_id' ?? 'id'), personalInfo
  'full_name', contact 'name', contacts[$index] -> get($contacts, $index, []),
  $result->error reads via $error = $result->error ?? []; then get($error, 'message'),
  and emergencyId()'s two-key chain.
- MessageResult::wamid(): replace the manual segment loop with
  ArrayReader::get($data, implode('.', $path)) inside the existing $paths loop.
- Responder: name, messageId/message_id chain, phones.0.area_code and
  phones.0.number read from the $contact root (the phones intermediate is
  mixed), and the recipientId/recipient_id/phone loop reads. The phone
  concatenation and ltrim results must stay identical.
- WhatsAppPayloadBuilder: personalInfo['full_name'].
- ProductCodeSelector: $productCodes[$key] and $productCodes['dte'] reads.
- Profile: personalInfo['full_name'].
- Config: $env[$key] in fromEnvironment(); fromArray() required check via
  get(...) !== null (not has), and every scalar default with “get(...) ?? default”
  to keep null-coalescing parity.
- Kernel: $params['id'] reads, geolocation coords/field/accuracy/source/
  recorded_at reads, and intQuery()'s query read. Leave unset() and literals.
- Request: input() and attribute() accessors and the lowercased header lookup.
  Leave $_SERVER/$_COOKIE reads.
- ApiResponse::value(): ArrayReader::get($this->body, $key) ?? $default.
- NativeSessionStore::get(): ArrayReader::get($_SESSION, $key) ?? $default.
- RateLimiter: $all[$bucket] ?? [] read.
- InMemoryIdempotencyStore::get(): storage read.

Verify with php -l on every changed file, then report per file:
  path | before -> after | skipped reads and why.
Do not run the test suite; the parent does that in Phase 5.
```

- [x] While the sub-agent runs, do not edit the same files in the parent
      session; review the returned report for skipped conversions.
- [x] **Gate:** deferred → Phase 5

## Phase 4: Audit and cleanup

- [x] Re-read every file changed in Phases 2–3 and confirm no write, append,
      `unset()`, constructor property, or array literal was altered. Use
      `git diff -- src/` as the source of truth.
- [x] Run the inventory
      `grep -rnE '\$[A-Za-z_][A-Za-z0-9_]*(\[[^]]+\])+' src --include='*.php'`
      and classify every remaining bracket read as one of: already converted
      through `ArrayReader`, intentional exclusion (superglobal, regex match,
      route table, write), or missed conversion.
- [x] Confirm the documented exclusions are the only ones left:
      `src/Http/Router.php` (route table and `preg_match` results),
      `src/Http/Psr18ApiClient.php` header assembly write,
      `src/Http/Endpoints.php` query builder, exception-array literals,
      `src/Http/Kernel.php` router match table (`$match['handler']` /
      `$match['params']`), `src/Http/ClientMessages.php` `preg_match_all`
      results, and `$_SERVER`/`$_COOKIE` reads in `src/Http/Request.php` and
      `src/Session/NativeSessionStore.php`. Opaque idempotency keys stay on a
      literal read in `src/Emergency/InMemoryIdempotencyStore.php`, because
      dot traversal would let a client-controlled key resolve a sibling entry.
- [x] Confirm every touched file imports
      `DataEmergencia\EmergencyBff\Support\ArrayReader` in alphabetical order
      and no unused import remains.
- [x] Check for accidental semantic drift at `??`/`isset` sites: a stored
      `null` must still behave as it did before the change.
- [x] **Gate:** deferred → Phase 5

## Phase 5: Verification

- [x] Create `tests/Unit/ArrayReaderTest.php` covering:
      `null` key returns the whole array; top-level read; nested dot path;
      missing top-level key returns the default; missing intermediate segment
      returns the default; non-array intermediate returns the default; numeric
      list index through `'results.0.id'` and through an int key; literal
      dotted key wins over traversal; a stored `null` returns `null` rather
      than the default; empty-string key; and `has()` for nested paths, numeric
      indexes, missing segments, and a stored `null`.
- [x] Pin the nested shape from the request (`profile.social.github` →
      `octocat`, missing nested key → `N/A`) in the regular unit tests; the
      separate data-provider case was removed as redundant.
- [x] Run `php -l` on every changed `src/` file and the new test file; all must
      report `No syntax errors detected`.
- [x] Run `vendor/bin/phpunit` from `packages/emergency-bff`; the full existing
      suite plus the new test must pass with exit code 0.
- [x] Re-run `grep -rn "ArrayReader" src --include='*.php'` and confirm every
      Phase 2–4 target file appears except `InMemoryIdempotencyStore` (opaque
      keys intentionally stay literal); re-run the Phase 4 inventory and
      confirm no missed read remains.
- [x] Confirm `git diff --stat` touches only `src/Support/ArrayReader.php`,
      the migrated `src/` files, and `tests/Unit/ArrayReaderTest.php` (plus
      this plan file).
- [x] **Gate:** `vendor/bin/phpunit` exits 0 and `php -l` exits 0 for every
      changed PHP file.
- [x] **Gate:** the audit grep shows no unclassified array read in `src/`, and
      `git diff` contains no write-path, formatting-only, or out-of-scope
      change.
