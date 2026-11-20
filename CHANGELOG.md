# Changelog

All notable changes to `dataemergencia/emergency-bff` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.6.1] - 2026-11-20

### Added

- The abort dispatch now uses the typed, billed
  `POST /v2/whatsapp/templates/emergency/abort` endpoint with a `user_name`
  variable. A `402` or any other send failure is recorded per recipient in
  `failures` and the emergency is still deleted, so `DELETE /{id}` keeps its
  `200` and `deleted: true` contract.

### Deprecated

- `WhatsAppClient::send()`, `WhatsAppPayloadBuilder::buildAbortPayload()`,
  `WhatsAppPayloadBuilder::TEMPLATE_ABORT` and `Endpoints::whatsappTemplates()`
  remain available for older consumers and are deprecated for the next minor.

### Release

- Next release is a pre-1.0 minor (`0.7.0`). The old generic send surface is
  not removed in this release.

## [0.6.0] - 2026-11-20

### Changed

- Internal array reads now go through `Support\ArrayReader`, a framework-free
  dot-path reader with `array_key_exists` semantics. The public HTTP contract,
  response shapes and error mappings are unchanged; opaque idempotency keys
  stay on literal lookups.
- Every consumer-facing message is now Spanish: `error.message` and
  `failures[].message`. The envelope, status codes, error codes, field names
  and `details` keys are unchanged and stay English machine values. Clients
  must branch on `error.code`, never on the message text.
- The `502 upstream_error` envelope no longer forwards the upstream error
  message; it keeps only `details.status` and `details.upstream_code`.
- The `details.operation` value of an upstream failure is now a stable machine
  key (`create_emergency`, `update_emergency`, `show_emergency`,
  `delete_emergency`, `find_active_emergency`, `fetch_medical_profile`)
  instead of an English phrase.
- WhatsApp failures no longer copy the upstream message or upstream details
  into the failure entry; they report the Spanish copy for the failure and keep
  the upstream status. A `402` is read as a credits failure only when the
  upstream error code is the canonical `INSUFFICIENT_CREDITS`.
- `InsufficientCreditsException` no longer accepts an upstream message: it now
  takes the code only, and its message is English.

### Added

- `ClientMessages`, the single catalog of Spanish client copy used by the
  kernel, the WhatsApp client and the emergency service.

### Removed

- The unused `qrBaseHost`, `fallbackOriginUrl` and `local` properties are gone
  from `Config`, along with the `EMERGENCY_BFF_QR_BASE_HOST`,
  `EMERGENCY_BFF_FALLBACK_ORIGIN_URL` and `EMERGENCY_BFF_LOCAL` environment
  variables. Nothing in the package read them; the Laravel reference no longer
  passes them to `WhatsAppPayloadBuilder`.

## [0.5.0] - 2026-11-17

### Added

- `GET /credits` and `GET /credits/history` proxy the upstream credit summary
  and append-only ledger for the session code, with bounded `per_page`/`page`
  pagination and `422 validation_failed` for invalid values.
- `CreditRepository` normalizes the upstream JSON:API credit envelopes and maps
  a credits `404` to `inactive_code`.
- The vanilla demo and mock API cover both credit routes and the zero-credit
  `ZERO12` code.

### Changed

- A `402 INSUFFICIENT_CREDITS` from the typed activation or simulacrum send now
  returns `402 insufficient_credits` (after cleaning up the created emergency)
  instead of `502 whatsapp_delivery_failed`. The unbilled abort template is
  unchanged.
- `Kernel` gained a required `CreditRepository` collaborator; `Bootstrap` wires
  it automatically for hosts that do not build the kernel by hand.

## [0.4.1] - 2026-11-11

### Changed

- `POST /simulacrum` now creates the upstream emergency with `is_active: true`
  (and `is_simulated: true`) instead of leaving it inactive, so the API can
  serve active simulated emergencies through its simulacrum report.

## [0.4.0] - 2026-11-09

### Added

- `POST /simulacrum` now accepts the optional `geolocation` payload, validates
  it and forwards it upstream with `is_simulated: true`.
- Activation and simulacrum now forward the validated, flattened `geolocation`
  to `POST /v2/emergency`, with `is_simulated: false` for real activations. The
  API persists the location through its own domain event; the BFF stores
  nothing.

## [0.3.1] - 2026-11-06

### Added

- `POST /activate` now accepts an optional `reporter_phone` (E.164), forwards
  it to the upstream emergency creation, and returns it on the activation
  result.

## [0.3.0] - 2026-11-06

### Changed

- Activation and simulacrum now call the typed endpoints
  `POST /v2/whatsapp/templates/emergency/activation` and
  `POST /v2/whatsapp/templates/emergency/simulacrum` with recipient phones and
  endpoint variables only. The API builds the Meta payload.
- Activation creates the emergency active before sending, then writes responder
  WAMIDs and statuses back through `PUT /v2/emergency/{id}`.
- Simulacrum pre-creates an inactive emergency to obtain a valid
  `emergency_code` and leaves it inactive.
- The abort flow keeps the legacy `POST /v2/whatsapp/templates/send` payload.

### Added

- `EmergencyRepository::update()` and `Endpoints::updateState()` for
  `PUT /v2/emergency/{id}`.
- Typed response parsing for `data.attributes.data.results[0].status` and
  `whatsapp_message_id`, including the `200` plus `status: failed` case.

### Fixed

- A total activation or simulacrum send failure now deletes the emergency the
  BFF created instead of leaving an orphan record.
- An upstream `409` during creation maps to `emergency_already_active` so a race
  returns the domain error instead of a generic upstream failure.

## [0.2.0] - 2026-11-03

### Removed

- The `realtime` block from the `GET /config` and `GET /current` responses.
- `DataEmergencia\EmergencyBff\Realtime\RealtimeConfig` and its wiring.
- The `EMERGENCY_BFF_PUSHER_KEY` and `EMERGENCY_BFF_PUSHER_CLUSTER` environment
  variables, along with `Config::$pusherKey` and `Config::$pusherCluster`.

### Changed

- Realtime ownership now sits with the API and the browser library:
  documentation points at the API broadcaster and the library's `realtime` mount
  option instead of BFF-provided Pusher credentials.

## [0.1.5] - 2026-11-03

### Removed

- Top-level `responders` from the `POST /{id}/refresh` response. Responders
  remain available at `emergency.responders`.

## [0.1.4] - 2026-11-03

### Fixed

- A responder is no longer marked as sent when the WhatsApp send response lacks
  a valid `wamid.`-prefixed id; it is reported as failed with the real HTTP
  status instead of seeding a null reply reference.

## [0.1.3] - 2016-11-03

### Changed

- The manual split script no longer repeats composer validation, PHP syntax
  checks or PHPUnit; CI owns verification so releases mirror and push faster.

### Fixed

- Read the WhatsApp WAMID from `data.attributes.details.messages[0].id`
  instead of the synthetic envelope id, so `POST /v2/emergency` can resolve the
  message reference.
- Normalized proxied responder `message_id` values from the upstream internal
  integer to a decimal string, so `/current` and `/refresh` no longer fail the
  client's `string | null` contract.

## [0.1.2] - 2026-10-30

### Changed

- Improved `scripts/split.sh` output with numbered progress, status icons and
  clearer failure messages.
- Removed the clean-install reminder from the split script's final output.

### Fixed

- Coerced a numeric upstream `emergency_id` to its decimal string in the
  repository, so client payloads no longer receive `null`. This restores the
  `refresh()` and `abort()` guards that no-op when the id is missing.

## [0.1.1] - 2026-10-29

Documentation-only release. No runtime behavior changed.

### Added

- Guarded manual release script `scripts/split.sh`, which validates the package,
  mirrors the committed tree and can publish the release tag without CI minutes.

### Changed

- Rewrote the README for a standalone package audience: the recommended browser
  library comes first, only required configuration is listed, the HTTP API is
  presented as an ordered route/response walkthrough, and host integration no
  longer includes web-server recipes.
- Rewrote the onboarding, versioning, examples, FAQ, Pusher, requirements,
  upgrade and documentation-standard pages to describe this package only.
- Replaced the legacy application migration page with a generic bundled-flow
  migration guide.

### Removed

- Publishing, Packagist, release-checklist and onboarding-checklist guides that
  are no longer part of the package documentation.
- Split-repository and internal review documents.
- Curl smoke-test and Apache/Nginx sections from the README.

### Fixed

- Documentation links that pointed at removed pages or at repository-internal
  paths.

## [0.1.0] - 2026-10-28

### Added

- Composer package `dataemergencia/emergency-bff` with a framework-agnostic
  kernel, front-controller bootstrap and PSR-18 upstream client.
- Emergency routes under `/de-bff/emergency`: health, config, session, current,
  activate, simulacrum, refresh and abort.
- Native PHP session store with configurable cookie name, path, TTL, Secure,
  HttpOnly and SameSite flags.
- CSRF tokens, same-origin enforcement and a session-backed fixed-window rate
  limiter.
- Upstream API integration for code validation, medical profiles, emergency
  create/show/delete, active lookup by QR code and WhatsApp template delivery.
- WhatsApp payload builder for activation, simulation and abort, including
  Google Maps and FMD links.
- Public Pusher channel configuration for delivery metadata.
- Vanilla PHP example with a mock upstream API and an end-to-end smoke test.
- Split-repository workflow and Packagist release documentation.

### Known limitations

- `POST /{id}/refresh` checks the session but not emergency ownership.
- Idempotency storage is in-memory and per process.
- Private Pusher channels and `/pusher/auth` are reserved but not implemented.
- The package renders no UI; the browser client is a separate package.
