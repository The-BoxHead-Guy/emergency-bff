# Security

Applies to: integrators and operators.

## Trust boundaries

The browser is untrusted. It may call the BFF, but it never holds the upstream
API key, the application identifier or the upstream user token. Those live in
the PHP process and are attached by the BFF to outbound requests.

The BFF trusts:

- the upstream API response after schema normalization,
- the per-customer credentials in its environment,
- the PHP session cookie and CSRF token it issued.

It does not trust the identity code as a secret, the request body's ownership
claims, or any header the browser could forge.

## Credentials

- Each customer has its own `EMERGENCY_BFF_API_KEY` and
  `EMERGENCY_BFF_APPLICATION_IDENTIFIER`. There is no shared key.
- Credentials belong in the process environment or a secret manager, never in
  Git, the document root or a front-end bundle.
- Rotate a credential by issuing a new pair in the DataEmergencia console and
  restarting the PHP workers. The old pair stops working when the console
  revokes it.
- Configuration errors never echo the credential value.

## Identity code

The code is an identifier, not a password. It is six characters, the first is a
letter, and it can be guessed or observed on a bracelet. The security properties
come from the session binding and rate limits, not from code secrecy.

Consequences:

- Do not use the code alone to authorize an action from a different origin.
- Do not log the code together with medical data in a shared log sink without a
  retention policy.
- Treat possession of the code as the ability to start a session for that code.
  If a bracelet is lost, revoke the code upstream.

## CSRF

Unsafe methods require `X-CSRF-Token` (or `csrf_token` in the body). The token is
issued by `GET /config`, rotated on `POST /session`, compared in constant time,
and bound to the PHP session. A cross-site request cannot read the token because
of the same-origin policy and `HttpOnly` session cookie.

## Same-origin

`Origin` must match `Host` (including port). Cross-origin requests receive
`403 cross_origin_denied` before the session or domain logic runs. This protects
against a malicious page starting sessions or activating emergencies for a
visitor who is already signed into the BFF.

If the BFF sits behind a reverse proxy, forward the original `Host` and `Origin`
unchanged. Do not rewrite `Host` to the internal upstream name.

## Rate limiting

The session store keeps a fixed-window counter: 60 requests per 60 seconds per
`METHOD:path` bucket. Exceeding it returns `429 rate_limited` with
`details.retry_after`. The limit is intentionally per endpoint so a burst of
refresh calls does not block an activation.

The counter is session-backed. A client that discards cookies gets a new bucket,
so the rate limit is a usability and abuse control, not a replacement for a
network-level WAF.

## Session hardening

- Session ID regenerated when an identity is stored.
- `HttpOnly` and `Secure` cookies by default.
- `SameSite=Lax` by default.
- Sliding TTL (`EMERGENCY_BFF_SESSION_TTL`, default 3600 seconds).
- `Cache-Control: no-store` on every response.
- No session data in URLs.

## Logging

The BFF logs:

- `emergency_bff.session_started` with the code,
- `emergency_bff.unhandled_exception` with the exception class and message,
- upstream and WhatsApp failures through the configured PSR-3 logger.

It does not log request bodies or credentials. Configure your logger to redact
medical data, phone numbers and names if you forward these logs to a third
party. The `X-Request-Id` response header and `error.request_id` field let you
correlate a browser report without logging the payload.

## Delivery and message safety

- WhatsApp sends happen only after upstream profile and contact validation.
- `POST /activate` never creates the upstream emergency when every message
  fails.
- Abort verifies ownership against the active emergency for the session code.
- The frontend should require explicit confirmation before abort and block
  simulation while a real emergency is active.
- The frontend should never claim an emergency was sent when geolocation
  failed; it should show the fallback phone instead.

## Known limitation: refresh ownership

In `0.1.1`, `POST /{id}/refresh` requires a valid session but does not verify
that the requested emergency belongs to the session's identity code. A valid
session could read another emergency's responder names and delivery statuses if
it knows or guesses the emergency ID.

Do not expose a single BFF instance to multiple unrelated customers until this
is fixed. The limitation is scheduled for the next patch release. A frontend
only needs to refresh the ID returned by its own session, but the server-side
ownership check is still required.

## Production checklist

- [ ] HTTPS only; `EMERGENCY_BFF_COOKIE_SECURE=true`.
- [ ] Credentials injected by the environment, not committed.
- [ ] `EMERGENCY_BFF_COOKIE_HTTP_ONLY=true`.
- [ ] `Host`/`Origin` preserved by the proxy.
- [ ] Session storage sized for the expected concurrency.
- [ ] Error logs forwarded with a retention policy.
- [ ] Health check monitored from outside the host.
- [ ] Rate limit reviewed against expected traffic.
- [ ] Pusher secret absent from the BFF environment and the browser bundle.

## Next step

[Pusher and realtime](pusher.md).
