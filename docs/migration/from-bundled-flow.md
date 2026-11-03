# Migrating from a bundled emergency flow

This guide maps an application that validates codes, builds WhatsApp payloads
and manages emergencies in one codebase to a deployment where this package owns
the server-side emergency flow. It describes behaviour, not a file-by-file
rewrite.

## Current flow

A typical bundled implementation:

1. A person arrives through a QR or identity-code route.
2. The application validates the code against the DataEmergencia API.
3. The frontend or backend builds the WhatsApp payloads.
4. The application sends the messages and creates or cancels the emergency.
5. API credentials live in the server environment.
6. The application provides session and CSRF behaviour; the browser library
   supplies the public Pusher settings for realtime.

## Target responsibilities

| Responsibility                     | After                                     |
| ---------------------------------- | ----------------------------------------- |
| QR entry point and routing         | Host application                          |
| Code format and existence check    | BFF `POST /session`                       |
| API key and application identifier | BFF server-side only                      |
| WhatsApp payload construction      | BFF                                       |
| Emergency create / show / delete   | BFF routes                                |
| Session and CSRF                   | BFF native session plus CSRF token        |
| Realtime                           | Public channels owned by the API          |
| Panel UI                           | Recommended library, or your own frontend |
| Branding, legal copy, support      | Host application                          |
| Operator view and dashboards       | Unchanged                                 |

The browser no longer talks to the DataEmergencia API directly, and no API key
is exposed to it. The browser talks only to the BFF.

## What moves to the BFF

- Code validation against the upstream API.
- Profile loading and activation preconditions.
- The WhatsApp templates, their parameters, links and language.
- Emergency creation, lookup, refresh and abort.
- Responder delivery fan-out and partial-failure reporting.
- CSRF, same-origin enforcement, rate limiting and session TTL.

## What stays in the host application

- The public landing page and the QR route. The host decides how a person
  reaches the panel.
- Authentication, accounts, consent screens and privacy notices.
- Branding, layout around the SOS control and the fallback phone number.
- Operator tooling, analytics, audit trail or CRM integration.
- Host-level session storage. The BFF session is separate; the host login
  session does not authenticate the BFF.
- The decision to show the panel at all.

## Suggested migration sequence

1. **Run the BFF beside the current application.** Deploy it on a path of the
   same origin or on a staging origin. Do not change the current flow yet.
2. **Prove the contract.** Run the flow in [API contract](../api-contract.md)
   against a test code and confirm `session`, `simulacrum`, `activate`,
   `current` and abort.
3. **Connect one page.** Use a test code and the host's fallback phone number.
   Keep the current UI reachable.
4. **Compare payloads.** The BFF builds the WhatsApp payload. Reconcile
   customised text or parameters before cutover.
5. **Move traffic progressively.** Route a small percentage of entries to the
   new page. Watch activation success, partial failures and abort success.
6. **Remove duplicate server-side code.** Once traffic is migrated, delete the
   old payload builders and API credentials. Keep the operator tooling.
7. **Keep a rollback path.** The old route can stay available for one release
   cycle. Both flows must not activate the same code at the same time.

## Behavioural differences to plan for

| Area               | Bundled flow                 | BFF                                            |
| ------------------ | ---------------------------- | ---------------------------------------------- |
| API credentials    | Server-side                  | Server-side only                               |
| Session            | Application session and CSRF | Separate BFF session and `X-CSRF-Token`        |
| Cross-origin       | Application CORS rules       | Same-origin only                               |
| Validation errors  | Application responses        | Nested `error` envelope with stable codes      |
| WhatsApp templates | Built in the application     | Fixed templates                                |
| Realtime           | Application-specific         | Public channels published by the API           |
| Simulation         | Application-specific         | `POST /simulacrum`, never creates an emergency |
| Idempotency        | Application-specific         | `Idempotency-Key`, in-memory per process       |

## Data and session notes

- The BFF does not migrate host sessions. A person logged in to the host is
  still anonymous to the BFF until `POST /session` succeeds.
- The BFF stores a snapshot in the PHP session. The upstream API remains the
  source of truth; `GET /current` reconciles after a reload.
- Rate limiting is per BFF session. Multiple tabs share one session and one
  budget.
- One BFF deployment uses one API key and application identifier. A
  multi-tenant migration needs one deployment per trust boundary.

## Rollback

Keep the previous application deployed and reachable while the new path is
evaluated. To roll back:

1. Point the entry route back to the old page.
2. Leave the BFF running but stop sending it traffic.
3. Resolve any emergency created through the new flow in the operator tooling.
4. Do not delete the BFF session storage until its TTL has passed.

## Related

- [API contract](../api-contract.md)
- [Security](../bff/security.md)
- [Examples](../examples.md)
