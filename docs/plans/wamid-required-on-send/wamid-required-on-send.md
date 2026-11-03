# Plan: Require a valid WAMID before marking a responder as sent

## Spec

**Goal.** A responder must never be reported or persisted as `sent = true`
without a usable WhatsApp WAMID. When the API answers 2xx but the envelope
carries no `wamid.`-prefixed id, the BFF records an explicit failure for that
responder (`sent = false`, `failed = true`, `message_id = null`) and never
forwards a null reference to `POST /v2/emergency`. Total and partial failure
semantics stay as they are today.

**Layer responsibilities** (each layer enforces only its own concern):

- `src/WhatsApp/MessageResult.php` — `wamid()` decides what a valid WAMID is:
  a non-empty string starting with `wamid.`. Anything else returns `null`.
- `src/Api/WhatsAppClient.php` — unchanged. It performs the HTTP call and
  returns a faithful `MessageResult`; it does not decide responder state.
- `src/Emergency/EmergencyService.php` — `sendToResponders()` owns the
  responder state: `sent` requires a non-null `messageId()`, otherwise the
  responder is marked `failed`, appended to `failures`, and a warning is logged.

**Assumptions.**

- Meta's WAMID always starts with `wamid.`, and a bare `wamid.` with an empty
  suffix is not valid. A value without the prefix (for example a UUID or an
  internal id) is not usable as a WAMID.
- A 2xx send response without a valid WAMID is a failed send. It is not
  retried: the upstream API may already have delivered the message, so a retry
  risks a duplicate.
- The failure entry keeps the real HTTP status (`200`) as a diagnostic. Its
  message explains the cause.
- The API stays the single source of truth for `sent`, `delivered`, `read` and
  `failed` after activation. This change only stops the BFF from seeding a
  responder as sent without the reference webhooks need.
- Test mode is **deferred**: implementation phases run no tests; Phase 4
  writes the tests and runs every check once.

**Out of scope.**

- API-side validation or logging in `apps/api.dataemergencia.com`.
- Changes to `libs/emergency` types or guards.
- Webhook ordering/buffering (the existing residual risk) and retry of sends.
- Changing the `/current` and `/refresh` `message_id` normalization.
- Simulacrum/abort response-shape changes beyond how a missing WAMID is
  classified.

## Phase 1: Make `MessageResult::wamid()` reject anything that is not a WAMID

- [x] Update the private `wamid()` helper in `src/WhatsApp/MessageResult.php`:
      return the value only when it is a string starting with `wamid.` and longer
      than the prefix (`$value !== 'wamid.'`); return `null` for every other value,
      including numeric ids and non-prefixed strings.
- [x] Update the `messageId()` / `wamid()` docblocks to state the accepted
      format and that the fallback paths are only used when the value is a real
      WAMID.
- [x] Confirm `src/Api/WhatsAppClient.php` needs no change: it still forwards
      `body.data.attributes` when present and returns `MessageResult::sent(...)`.
- [x] **Gate:** deferred → Phase 4

## Phase 2: Enforce `sent` only with a WAMID in `sendToResponders()`

- [x] In `src/Emergency/EmergencyService.php`, replace the success branch so
      `$responder->messageId = $result->messageId()` is computed first; only when
      it is non-null set `sent = true` and `messageId`, then `continue`.
- [x] When the WAMID is `null`, mark the responder as failed and append a
      failure entry with the responder phone, the real HTTP status
      (`$result->status`) and the message
      `The WhatsApp send response did not include a WAMID.` Do not set `sent` or
      `messageId`.
- [x] Log `emergency.wamid_missing` through the optional logger with the phone
      and status before continuing, so the case is observable without changing the
      public contract.
- [x] Verify the shared path covers `activate()`, `simulacrum()` and `abort()`
      (all three call `sendToResponders()`); `allFailed()` then raises
      `WhatsAppFailureException` when every responder is affected.
- [x] **Gate:** deferred → Phase 4

## Phase 3: Document the rule and record the behavior change

- [x] Add the rule to `docs/bff/upstream-api.md`: a 2xx send response without a
      valid `wamid.`-prefixed id is treated as a failed send; the BFF never
      forwards `sent = true` with a null reply reference.
- [x] Add a behavior note to the Activate section of `docs/api-contract.md`:
      a send response without a valid WAMID counts as a failure, so total/partial
      failure rules apply and `failures[].status` keeps the real HTTP status.
- [x] Add the same note to `docs/bff/activation-flow.md` where the dispatch
      summary and failure handling are described.
- [x] Mirror the note in `README.md` where the activation response and its
      failure semantics are documented.
- [x] Add a `Fixed` entry under `## [Unreleased]` in `CHANGELOG.md` describing
      that a responder is no longer marked sent without a valid WAMID.
- [x] **Gate:** deferred → Phase 4

## Phase 4: Verification

- [x] Extend `tests/Unit/MessageResultTest.php`: accept the real
      `wamid.`-prefixed ids; reject a non-prefixed id, a bare `wamid.`, numeric
      ids and numeric strings across the supported envelope paths; keep the
      existing legacy flat `message_id` case with a `wamid.` value.
- [x] Add coverage in `tests/Unit/EmergencyServiceTest.php`:
  - a 200 send response without a valid WAMID, with a single contact, raises
    `WhatsAppFailureException` and issues no `POST /v2/emergency`;
  - with two contacts where only the second lacks a WAMID, the emergency is
    created, the second responder has `sent = false`, `failed = true`,
    `message_id = null`, `failures` has one entry, and the create body carries
    `sent = false` and `failed = true` for it;
  - the failure entry keeps `status = 200` and the WAMID message.
- [x] Extend `tests/Feature/KernelTest.php`: activation where the send endpoint
      returns 200 without a valid WAMID responds `502 whatsapp_delivery_failed` and
      does not call `POST /v2/emergency`.
- [x] Keep the existing full-envelope success tests unchanged as regression
      guards for the normal path.
- [x] Run `vendor/bin/phpunit` from `packages/emergency-bff` and make the suite
      pass.
- [x] Run `php -l` on every changed `src/` file.
- [x] **Gate:** `vendor/bin/phpunit` exits 0 with the new coverage included.
- [x] **Gate:** `php -l` exits 0 for every changed `src/` file.
- [x] **Gate:** `git diff --stat` shows changes only under
      `packages/emergency-bff` (no `libs/emergency` or API edits).
