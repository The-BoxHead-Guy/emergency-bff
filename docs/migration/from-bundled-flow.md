# Migrating from a bundled emergency flow

Applies to: teams replacing a server-side emergency implementation with this
package.

This page describes behavior and sequencing, not a file-by-file rewrite. The
piece that does not change is the host application; the piece that moves is
everything between the browser and the DataEmergencia API.

## Responsibility split

| Responsibility                                | After                                     |
| --------------------------------------------- | ----------------------------------------- |
| QR entry point and routing                    | Host application                          |
| Landing page, branding, legal copy            | Host application                          |
| Accounts, consent, operator tools             | Host application                          |
| Front controller, web server, session storage | Host application                          |
| Code validation and session binding           | BFF `POST /session`                       |
| API credentials                               | BFF, server-side only                     |
| WhatsApp payload construction and fan-out     | BFF                                       |
| Emergency create / read / abort               | BFF routes                                |
| CSRF, same-origin, rate limiting              | BFF                                       |
| Panel UI                                      | Recommended library, or your own frontend |

The browser no longer talks to the DataEmergencia API directly and no credential
is exposed to it. The host's login session does not authenticate the BFF: a
person logged into the host is anonymous to the BFF until `POST /session`
succeeds.

## Behavioral differences to plan for

| Area               | Bundled flow                 | BFF                                                                     |
| ------------------ | ---------------------------- | ----------------------------------------------------------------------- |
| Session            | Application session and CSRF | Separate BFF session and `X-CSRF-Token`                                 |
| Cross-origin       | Application CORS rules       | Same-origin only                                                        |
| Validation errors  | Application responses        | Nested `error` envelope with stable codes                               |
| WhatsApp templates | Built in the application     | Typed endpoints, built by the API                                       |
| Realtime           | Application-specific         | Public channels published by the API                                    |
| Simulation         | Application-specific         | `POST /simulacrum`, pre-creates a simulated emergency the API cleans up |
| Idempotency        | Application-specific         | `Idempotency-Key`, in-memory per process                                |
| Tenancy            | Application-specific         | One API key and identifier per deployment                               |

## Sequence

1. **Run the BFF beside the current application.** Deploy it on a path of the
   same origin or on a staging origin. Leave the current flow untouched.
2. **Prove the contract.** Exercise session, `current`, simulacrum, activate and
   abort with a test code, using the [HTTP contract](../bff/routes.md) or the
   packaged smoke test.
3. **Connect one page.** Serve the panel from the same origin and keep the old
   UI reachable.
4. **Compare payloads.** The API builds the WhatsApp template now. Reconcile
   customized text or parameters before cutover.
5. **Move traffic progressively.** Watch activation success, partial failures
   and abort success.
6. **Remove duplicate server-side code.** Delete the old payload builders and
   API credentials once traffic is migrated. Keep operator tooling.
7. **Keep a rollback path.** Leave the old route available for one release
   cycle. Both flows must never activate the same code at the same time.

## Rollback

1. Point the entry route back to the old page.
2. Stop sending traffic to the BFF, but leave it running.
3. Resolve any emergency created through the new flow in operator tooling.
4. Do not delete BFF session storage until its TTL has passed.

## Related

- [HTTP contract](../bff/routes.md)
- [Session and CSRF](../bff/session-and-csrf.md)
- [Security](../bff/security.md)
