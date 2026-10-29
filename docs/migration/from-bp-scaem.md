# Migrating from the bp.scaem.app flow

This guide maps the current panic-button application (`bp.scaem.app`) to the
split between the emergency BFF and the browser client. It describes behaviour,
not a file-by-file rewrite.

## Current flow

`bp.scaem.app` is a Laravel application with a Vite frontend. In broad terms:

1. A person arrives through `/qr/{code}`. The application validates the code
   against the DataEmergencia API and renders an invalid-code page when it is
   not usable.
2. The browser collects personal and medical information, emergency contacts
   and geolocation.
3. Browser-side services build the WhatsApp payloads, and the application
   calls the API to send messages and create or cancel the emergency.
4. API credentials live in Laravel environment variables and are used by
   server-side services. Some configuration is exposed to the browser through
   a generated config endpoint.
5. Laravel Echo and Pusher provide realtime updates. Session and CSRF are
   Laravel's own.
6. Blade views and Bootstrap provide the interface.

The exact internals are documented in the application README and source. This
page only describes what moves.

## Target split

| Responsibility                      | Before                          | After                                        |
| ----------------------------------- | ------------------------------- | -------------------------------------------- |
| QR entry point and routing          | `bp.scaem.app` `/qr/{code}`     | Host application (unchanged)                 |
| Code format and existence check     | Laravel service calling the API | BFF `POST /session`                          |
| API key and application identifier  | Laravel server-side             | BFF server-side only                         |
| WhatsApp payload construction       | Browser and server services     | BFF (`WhatsAppPayloadBuilder`)               |
| Emergency create / show / delete    | Laravel service                 | BFF routes                                   |
| Geolocation collection              | Browser service                 | Browser client, sent to the BFF              |
| Session and CSRF                    | Laravel session                 | BFF native PHP session plus CSRF token       |
| Realtime                            | Laravel Echo + Pusher           | Public Pusher channels configured by the BFF |
| Panel UI                            | Blade, Bootstrap, custom JS     | `@dataemergencia/emergency` panel            |
| Branding, legal copy, support links | Blade views                     | Host application                             |
| Operator view and dashboards        | Separate dashboard              | Unchanged                                    |

The most important change: **the browser no longer talks to the DataEmergencia
API directly, and no API key is exposed to it.** The browser talks only to the
BFF.

## What moves to the BFF

- Code validation against `GET /v2/fmd/{code}/exists`.
- Profile loading and the activation preconditions: personal information and
  at least one emergency contact.
- The three WhatsApp templates, their parameter order, images, language and
  QR/Google Maps links.
- Emergency creation, lookup, refresh and abort.
- The responder delivery fan-out and partial-failure reporting.
- CSRF, same-origin enforcement, rate limiting and session TTL.
- Public Pusher configuration returned by `GET /config`.

## What stays in the host application

- The public landing page and `/qr/{code}` route. The host decides how a
  person reaches the panel.
- Authentication, accounts, consent screens and privacy notices.
- Branding, typography, layout around the SOS control and the fallback phone
  number.
- Any operator tooling, analytics, audit trail or CRM integration.
- Host-level session storage. The BFF session is separate; do not assume the
  host login session authenticates the BFF. The identity code does.
- The decision to show the panel at all. The client mounts closed and never
  auto-opens.

## Suggested migration sequence

1. **Run the BFF beside the current application.** Deploy it on a path of the
   same origin or on a staging origin. Do not change the current flow yet.
2. **Prove the contract.** Run the curl flow in
   [API contract](../api-contract.md) against a test code and confirm
   `session`, `simulacrum`, `activate`, `current` and `abort`.
3. **Mount the client on one page.** Use a test code and the host's fallback
   phone number. Keep the current panic-button UI reachable.
4. **Compare payloads.** The BFF builds the WhatsApp payload. If the current
   application customises text or parameters, reconcile that difference before
   cutover. The BFF templates are fixed in `0.1.0`.
5. **Move traffic progressively.** Route a small percentage of QR entries to
   the new page. Watch activation success, partial failures and abort success.
6. **Remove duplicate server-side code.** Once traffic is fully migrated,
   delete the old browser-side payload builders and API credentials from the
   client bundle. Keep the operator tooling.
7. **Keep a rollback path.** The old route can stay available for one release
   cycle. Both flows must not activate the same code at the same time.

## Behavioural differences to plan for

| Area               | bp.scaem.app                               | BFF + client                                           |
| ------------------ | ------------------------------------------ | ------------------------------------------------------ |
| API credentials    | Server-side, plus generated browser config | Server-side only                                       |
| Session            | Laravel session and Laravel CSRF           | Separate `de_bff_emergency` session and `X-CSRF-Token` |
| Cross-origin       | Laravel CORS rules                         | Same-origin only                                       |
| Validation errors  | Laravel validation responses               | Nested `error` envelope with stable codes              |
| WhatsApp templates | Built in the application                   | Fixed `_v3`, `_v2`, `_v1` templates                    |
| Realtime           | Laravel Echo, private channels may be used | Public `whatsapp.{phone}` channels, metadata only      |
| UI                 | Blade/Bootstrap screens                    | React panel mounted into a host element                |
| Abort              | Application-specific confirmation          | Two-step confirmation inside the panel                 |
| Simulation         | Application-specific                       | `POST /simulacrum`, never creates an emergency         |
| Idempotency        | Application-specific                       | `Idempotency-Key`, in-memory per process               |

Private Pusher channels are not part of the MVP. If the current application
relies on private channels or presence, keep that channel for operator
features and use the public BFF channel only for end-user delivery metadata.

## Data and session notes

- The BFF does not migrate Laravel sessions. A person who is logged in to the
  host is still anonymous to the BFF until `POST /session` succeeds.
- The BFF stores a snapshot in the PHP session. The upstream API remains the
  source of truth; `GET /current` reconciles after a reload.
- Rate limiting is per BFF session. Multiple tabs share one session and one
  budget.
- One BFF deployment uses one API key and application identifier. A
  multi-tenant migration needs one deployment per trust boundary.

## Rollback

Keep the previous application deployed and reachable while the new path is
evaluated. To roll back:

1. Point the QR entry route back to the old page.
2. Leave the BFF running but stop sending it traffic.
3. Resolve any emergency created through the new flow in the operator tooling.
4. Do not delete the BFF session storage until its TTL has passed.

## Related

- [API contract](../api-contract.md)
- [Security](../bff/security.md)
- [Examples](../examples.md)
- [Release checklist](../release/release-checklist.md)
