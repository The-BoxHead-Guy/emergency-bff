# Plan: Spanish consumer-facing messages at the BFF boundary

## Spec

Make every message the BFF kernel returns to an external consumer Spanish, while
keeping every internal or system-only message English. The audit found a mixed
state, not a uniform English one: the error envelope is mostly English, the
responder `failures[].message` copy is already Spanish, `WhatsAppFailureException`
is already Spanish, and `WhatsAppClient` still leaks English failure copy. The
`502 upstream_error` branch also forwards the upstream `ApiException` message
verbatim, so an English upstream body currently reaches the client.

Translation belongs at the HTTP boundary, in one copy catalog. Internal English
stays where it is: exception `getMessage()` values, configuration errors, log
messages, and upstream diagnostic text are not consumer copy. Machine values
stay English and stable (`error.code`, JSON keys, `details.field`,
`details.allowed`, `details.status`, `details.upstream_code`, `status: "ok"`,
service name, ids). Only `error.message` and `failures[].message` are translated,
because those are the two human-readable fields the consumer renders.

### Audit: strings that reach a client today

| Source                                                              | Field                                         | Current language                     |
| ------------------------------------------------------------------- | --------------------------------------------- | ------------------------------------ |
| `Kernel` route dispatch (`404 not_found`, `405 method_not_allowed`) | `error.message`                               | English                              |
| `Kernel::identity()` (`401 unauthenticated`)                        | `error.message`                               | English                              |
| `Kernel` `POST /session` empty code (`422 validation_failed`)       | `error.message`                               | English                              |
| `Kernel::geolocation()` three validation throws                     | `error.message`                               | English                              |
| `Kernel::reporterPhone()` three validation throws                   | `error.message`                               | English                              |
| `Kernel` unhandled `Throwable` (`500 internal_error`)               | `error.message`                               | English                              |
| `SameOriginMiddleware` (`403 cross_origin_denied`)                  | `error.message`                               | English                              |
| `CsrfMiddleware` (`403 csrf_failed`)                                | `error.message`                               | English                              |
| `RateLimitMiddleware` (`429 rate_limited`)                          | `error.message`                               | English                              |
| `AlreadyActiveEmergencyException`                                   | `error.message`                               | English                              |
| `ForbiddenEmergencyException`                                       | `error.message`                               | English                              |
| `EmergencyNotFoundException`                                        | `error.message`                               | English                              |
| `MissingContactsException`                                          | `error.message`                               | English                              |
| `MissingProfileException`                                           | `error.message`                               | English                              |
| `InvalidCodeException`                                              | `error.message`                               | English                              |
| `InactiveCodeException`                                             | `error.message`                               | English                              |
| `WhatsAppFailureException`                                          | `error.message`                               | Spanish                              |
| `ApiFailureException` (`500 emergency_error`)                       | `error.message` and `details.operation` prose | English                              |
| `ApiException` (`502 upstream_error`)                               | `error.message` forwarded verbatim            | Upstream-owned (English or API text) |
| `WhatsAppClient::send()` exception failure                          | `failures[].message`, `failures[].details`    | English / upstream text              |
| `WhatsAppClient::sendTyped()` exception failure                     | `failures[].message`                          | English / upstream text              |
| `WhatsAppClient::typedResult()` two failure paths                   | `failures[].message`                          | English                              |
| `EmergencyService::recordFailure()` three failure paths             | `failures[].message`                          | Spanish                              |
| `GET /health` `status: "ok"`, service slug                          | values                                        | Machine token, not prose             |

Success payloads (`/session`, `/health`, `/config`, `/current`, `/activate`,
`/simulacrum`, `/{id}/refresh`, `DELETE /{id}`) contain no prose except
`failures[].message`.

### Assumptions

- The package is framework-agnostic PHP; there is no i18n framework, no locale
  negotiation and no `Accept-Language`. Ship one Spanish catalog.
- Consumers render `error.message` and `failures[].message` directly.
  `libs/emergency` forwards the server `message` unchanged, so both fields are in
  scope.
- `GET /current` and `POST /{id}/refresh` return upstream-owned emergency
  attributes (`last_messages`, timestamps, statuses). The BFF does not translate
  upstream data; the API is assumed to emit Spanish user-facing content there.
- No envelope, status-code, field-name or routing change. Only message text
  changes, plus removing the undocumented nested `failures[].details` in the
  legacy abort send path.
- Release: minor bump `0.5.0` is recommended because consumers that match message
  text would observe a change; docs already tell integrators to switch on
  `error.code`. If the next scheduled release is already assigned, use it.
- Test mode: **deferred**. Phases 1–3 build, Phase 4 writes all tests and runs
  every gate once.

### Out of scope

- The status/code drift found during the audit: repositories wrap `ApiException`
  in `ApiFailureException`, so upstream failures return the documented
  `500 emergency_error` (not `502 upstream_error`). Fix separately; this plan
  only makes the message Spanish.
- WhatsApp template language (`WhatsAppPayloadBuilder::DEFAULT_LANGUAGE = 'en'`)
  and template copy, which are owned by the API and Meta.
- English fallbacks generated in the browser client `libs/emergency` (`The
request was cancelled.`, `The emergency service could not be reached.`). They
  are not BFF response bodies; plan separately if they must change.
- Upstream-owned data passed through on `/current` and `/{id}/refresh`.
- Rate limits, CSRF, session lifetime, retries and route behavior.

## Phase 1: Client message catalog and error boundary

- [ ] Add `src/Http/ClientMessages.php`: a final, stateless class whose only job
      is Spanish client copy. Expose
      `public static function message(string $key, array $params = []): string`;
      resolve in this order: keyed template with `:placeholder` interpolation →
      per-key fallback template when a required parameter is missing or empty →
      generic `Se produjo un error inesperado.` for an unknown key. Document in
      the class docblock that English belongs to logs and internal exceptions.
- [ ] Add these error keys and exact copies:
      `not_found` → `La ruta solicitada no existe.`;
      `method_not_allowed` → `El método HTTP no está permitido para esta ruta.`;
      `unauthenticated` → `Inicie una sesión con POST /session antes de continuar.`;
      `cross_origin_denied` → `No se permiten solicitudes desde un origen distinto.`;
      `csrf_failed` → `El token CSRF falta o no es válido.`;
      `rate_limited` → `Demasiadas solicitudes. Vuelva a intentarlo más tarde.`;
      `internal_error` → `Se produjo un error inesperado. Vuelva a intentarlo más tarde.`;
      `upstream_error` → `El servicio de emergencias no respondió correctamente. Vuelva a intentarlo más tarde.`;
      `emergency_error` → `No se pudo completar la operación de emergencia.`;
      `whatsapp_delivery_failed` → `No se pudo enviar ningún mensaje de plantilla de WhatsApp.`
- [ ] Add the parameterized domain copies, with the fallback in parentheses:
      `emergency_already_active` → `El código «:code» ya tiene una emergencia activa.`
      (`El código ya tiene una emergencia activa.`);
      `emergency_forbidden` → `La emergencia «:emergency_id» no pertenece a la identidad validada.`
      (`La emergencia no pertenece a la identidad validada.`);
      `emergency_not_found` → `La emergencia «:emergency_id» no existe o ya no está activa.`
      (`La emergencia no existe o ya no está activa.`);
      `missing_emergency_contacts` → `El código «:code» no tiene contactos de emergencia configurados.`
      (`El código no tiene contactos de emergencia configurados.`);
      `missing_medical_profile` → `No se encontró un perfil médico para el código «:code».`
      (`No se encontró un perfil médico para el código.`);
      `invalid_code` → `El código «:code» no cumple el formato de identidad de 6 caracteres.`
      (`El código no cumple el formato de identidad de 6 caracteres.`);
      `inactive_code` → `El código «:code» no está asociado a un perfil médico activo.`
      (`El código no está asociado a un perfil médico activo.`).
- [ ] Add these `validation_failed` reason keys and copies:
      `identity_code_required` → `Debe indicar un código de identidad de 6 caracteres.`;
      `geolocation_object` → `La geolocalización debe enviarse como un objeto.`;
      `geolocation_coordinates_numeric` → `Las coordenadas de geolocalización deben ser numéricas.`;
      `geolocation_accuracy_numeric` → `La precisión de la geolocalización debe ser numérica.`;
      `reporter_phone_string` → `El teléfono del reportante debe ser texto.`;
      `reporter_phone_max` → `El teléfono del reportante no puede superar los 20 caracteres.`;
      `reporter_phone_e164` → `El teléfono del reportante debe ser un número E.164 válido.`
- [ ] Add these failure keys and copies:
      `failure_contact_unusable` → `El contacto de emergencia no tiene un número de teléfono utilizable.`;
      `failure_send_failed` → `No se pudo enviar el mensaje de WhatsApp.`;
      `failure_wamid_missing` → `La respuesta de envío de WhatsApp no incluyó un WAMID.`;
      `failure_insufficient_credits` → `No hay créditos suficientes para enviar la alerta.`;
      `failure_rate_limited` → `El servicio de WhatsApp está limitando los envíos. Vuelva a intentarlo más tarde.`;
      `failure_transport` → `No se pudo conectar con el servicio de WhatsApp.`;
      `failure_result_missing` → `La respuesta de WhatsApp no incluyó el resultado del destinatario.`;
      `failure_not_delivered` → `WhatsApp no confirmó la entrega del mensaje al destinatario.`
- [ ] Extend `src/Http/HttpError.php` with an optional last constructor parameter
      `?string $messageKey = null` and a `messageKey(): ?string` getter. Existing
      throw sites keep compiling; `message` stays the English internal value.
- [ ] Rework `Kernel::error()` so the message is resolved internally:
      `private function error(int $status, string $code, array $details = [], ?string $key = null): Response`
      builds `ClientMessages::message($key ?? $code, $details)`. Delete the
      `$message` parameter and every `$exception->getMessage()` argument in
      `Kernel::handle()`.
- [ ] Pass the reason key at the seven `validation_failed` throw sites:
      the `/session` code check, the three geolocation checks and the three
      reporter-phone checks. Pass the matching machine key in middleware throws
      (`cross_origin_denied`, `csrf_failed`, `rate_limited`) and route
      `not_found`, `method_not_allowed`, `unauthenticated` through the catalog.
- [ ] Change the `ApiException` catch to emit the fixed `upstream_error` copy
      with only machine details (`status`, `upstream_code`). Remove the upstream
      `getMessage()` from the envelope entirely.
- [ ] Change the `ApiFailureException` path to emit the fixed `emergency_error`
      copy. In `src/Emergency/Exception/ApiFailureException.php`, replace the
      prose operation string with a stable machine key (`create_emergency`,
      `update_emergency`, `show_emergency`, `delete_emergency`,
      `find_active_emergency`, `fetch_medical_profile`) and update the six call
      sites in `src/Api/EmergencyRepository.php`, `src/Api/ProfileRepository.php`
      and `src/Emergency/EmergencyService.php`. Keep `details.status`; never
      interpolate the key into client copy.
- [ ] In `Kernel::mapEmergencyException()`, keep the status and code mapping
      unchanged so the catalog lookup is exhaustive for every mapped code.
- [ ] **Gate:** deferred → Phase 4

## Phase 2: Failure paths and internal English boundary

- [ ] Keep every `EmergencyException::getMessage()` English. Change
      `src/Emergency/Exception/WhatsAppFailureException.php` back to an English
      internal message (`No WhatsApp template message could be delivered.`);
      its Spanish copy now comes from the catalog.
- [ ] In `src/Emergency/EmergencyService.php::sendToResponders()` and
      `recordFailure()`, replace the three Spanish literals with
      `ClientMessages::message()` lookups (`failure_contact_unusable`,
      `failure_send_failed`, `failure_wamid_missing`). Keep the public failure
      shape `{ phone, message, status? }`; `status` is a machine value and stays
      untranslated.
- [ ] In `src/Api/WhatsAppClient.php::send()` and `sendTyped()`, translate the
      caught `ApiException` into a catalog key using only machine signals:
      status `402` with `insufficient_credits` → `failure_insufficient_credits`,
      status `429` → `failure_rate_limited`, status `0` → `failure_transport`,
      otherwise `failure_send_failed`. Set `message` to the catalog copy, keep
      `code` and `status`, and stop copying the upstream `message` and upstream
      `details` into the failure entry.
- [ ] In `WhatsAppClient::typedResult()`, replace the two English literals with
      `failure_result_missing` and `failure_not_delivered`; keep the machine
      `status` and the raw result out of client-visible prose.
- [ ] Assert by inspection that no `getMessage()` value flows into a response
      payload: `grep -rn "getMessage()" src/Http src/Api` must show only internal
      logging/wrapping, never `error.message` or `failures[].message`.
- [ ] Document the language boundary in the class docblocks of `ClientMessages`,
      `Kernel` and `WhatsAppClient`: internal/English, client/Spanish.
- [ ] **Gate:** deferred → Phase 4

## Phase 3: Contract docs, changelog and release note

- [ ] In `docs/api-contract.md`, add a "Message language" subsection under the
      error envelope: `error.message` and `failures[].message` are Spanish;
      `error.code`, field names, enum values, ids and `details` keys are stable
      English machine values; clients must branch on `error.code`, never on the
      message text; upstream-owned data in `/current` and `/{id}/refresh` is not
      translated by the BFF.
- [ ] Update the quoted examples in `docs/api-contract.md` (generic error
      envelope and rate-limit block) and `docs/bff/routes.md` (error envelope)
      and `docs/bff/session-and-csrf.md` (CSRF example) to the exact Spanish
      copies from Phase 1.
- [ ] Note in `docs/operations/troubleshooting.md` and `docs/operations/faq.md`
      that message text is Spanish while codes stay English, so log searches and
      client checks must use codes.
- [ ] Keep documentation prose in English and quote the Spanish strings exactly,
      per `docs/contributing/documentation.md`.
- [ ] Add an `Unreleased → Changed` entry to `CHANGELOG.md` and bump the version
      to `0.5.0` (or the next scheduled version): all consumer-facing error and
      failure messages are Spanish; shape and codes unchanged. Add an upgrade
      note in `docs/bff/upgrade.md` telling integrators to switch on
      `error.code` if they matched message text.
- [ ] Grep the docs for stale quoted English client messages
      (`Too many requests`, `The CSRF token`, `An unexpected error`,
      `Human-readable message`) and confirm only intentional English diagnostic
      prose remains.
- [ ] **Gate:** deferred → Phase 4

## Phase 4: Verification

- [ ] Write `tests/Unit/ClientMessagesTest.php`: a table-driven assertion that
      every catalog key returns the exact Spanish copy; interpolation replaces
      `:code` and `:emergency_id`; a missing or empty parameter selects the
      fallback; an unknown key returns the generic Spanish message; no resolved
      value contains a `:placeholder`.
- [ ] Extend `tests/Feature/KernelTest.php` with a table-driven test over every
      kernel error path: `401 unauthenticated`; `403 csrf_failed`,
      `cross_origin_denied`, `emergency_forbidden`; `404 not_found`,
      `inactive_code`, `emergency_not_found`; `405 method_not_allowed`;
      `409 emergency_already_active`; each `422 validation_failed` reason,
      `invalid_code`, `missing_medical_profile`, `missing_emergency_contacts`;
      `429 rate_limited`; `500 internal_error`, `emergency_error`;
      `502 upstream_error`, `whatsapp_delivery_failed`. Assert the Spanish
      `error.message`, the unchanged English `error.code`, and the unchanged
      status.
- [ ] Add a leak assertion to the upstream-failure and total-failure tests: the
      upstream fixture text (for example `Unavailable`) must not appear anywhere
      in the serialized response body, including `details` and `failures`.
- [ ] Extend `tests/Unit/WhatsAppClientTest.php` and
      `tests/Unit/EmergencyServiceTest.php`: each failure path returns Spanish
      `message` copy, preserves `status`, and never carries the upstream
      `message` key; internal `ApiException` and `EmergencyException`
      `getMessage()` values stay English (include `WhatsAppFailureException`).
- [ ] Add a completeness check that every code returned by
      `Kernel::mapEmergencyException()` and every `HttpError` code used in `src/`
      has a `ClientMessages` entry, so a future error code cannot ship an English
      or empty client message.
- [ ] **Gate:** `vendor/bin/phpunit` in `packages/emergency-bff` exits `0` with
      the full suite green.
- [ ] **Gate:** `php -l` passes for every changed PHP file, and
      `grep -rn "getMessage()" src/Http src/Api` shows no path into a client
      payload.
- [ ] **Gate:** docs contain the exact Spanish copies and no stale quoted English
      client messages; the CHANGELOG and version reflect the change.
