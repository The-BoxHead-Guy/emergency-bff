# Security

Applies to: integrators and operators.

## Trust boundaries

The browser is untrusted. It may call the BFF, but it never holds the upstream
API key, the application identifier or the upstream user token. Those live in
the PHP process and are attached by the BFF to outbound requests.

The BFF trusts the upstream response after normalization, the credentials in its
environment, and the session cookie and CSRF token it issued. It does not trust
the identity code as a secret, ownership claims in the request body, or headers
the browser can forge.

## Credentials

- One API key and one application identifier per deployment. There is no shared
  key, no fallback and no hosted instance: each customer deploys the BFF with
  their own pair.
- Keep credentials in the process environment or a secret manager. Never in Git,
  the document root or a front-end bundle.
- Rotate by issuing a new pair in the DataEmergencia console and restarting the
  PHP workers. Existing sessions keep working; new upstream calls use the new
  pair.
- Configuration errors never echo the credential value.

## Identity code

The code is an identifier, not a password. It is six characters, the first is a
letter, and it is printed on a bracelet. Its security comes from session binding
and rate limits, not from secrecy.

Consequences:

- Do not authorize an action with the code alone from another origin.
- Treat possession of the code as the ability to start a session for it. Revoke
  the code upstream if a bracelet is lost.
- Do not log the code next to medical data without a retention policy.

## Request protection

Mechanics are in [Session and CSRF](session-and-csrf.md); this is the intent.

- **Same origin.** Prevents a third-party page from acting on a visitor's
  session. A reverse proxy must preserve `Host` and `Origin`.
- **CSRF token.** Prevents a cross-site form from mutating state. The token is
  issued by `GET /config`, rotated on `POST /session`, bound to the session and
  compared in constant time.
- **Rate limiting.** 60 requests per 60 seconds per `METHOD:path` bucket, per
  session. It is per endpoint so a burst of refreshes cannot block an
  activation. It is abuse control, not a WAF: a client that drops its cookies
  gets a fresh budget.

## Logging

The BFF logs `emergency_bff.session_started`, unhandled exceptions and upstream
or WhatsApp failures through the configured PSR-3 logger. It does not log
request bodies or credentials.

Configure your logger to redact medical data, names and phone numbers before
forwarding logs to a third party. Use `X-Request-Id` and `error.request_id` to
correlate a user report without logging the payload.

## Delivery safety

- WhatsApp messages are sent only after upstream profile and contact validation.
- An activation whose every send fails deletes the emergency it created, so it
  never leaves an active emergency with no alert.
- Abort verifies ownership against the active emergency for the session code.
- The frontend must require explicit confirmation before abort, block simulation
  while a real emergency is active, and never claim an alert was sent when
  geolocation failed — it should show the fallback phone instead.

## Known limitation: refresh ownership

`POST /{id}/refresh` requires a valid session but does not verify that the
requested emergency belongs to that session's identity code. A valid session
could read another emergency's responder names and delivery statuses if it knows
or guesses the emergency ID.

Do not expose a single BFF instance to multiple unrelated customers until this
is fixed. A frontend only needs to refresh the ID returned by its own session,
but the server-side ownership check is still required.

## Production checklist

- [ ] HTTPS terminates at the proxy; HTTP redirects to HTTPS.
- [ ] `EMERGENCY_BFF_COOKIE_SECURE=true` and `EMERGENCY_BFF_COOKIE_HTTP_ONLY=true`.
- [ ] `EMERGENCY_BFF_API_KEY` and `EMERGENCY_BFF_APPLICATION_IDENTIFIER` come
      from the runtime environment or a secret store, not from a committed file.
- [ ] The document root is the directory that contains the front controller;
      `src/`, `vendor/` and `examples/` are not reachable over HTTP.
- [ ] Sessions use shared storage when more than one node serves traffic.
- [ ] PHP-FPM `request_terminate_timeout` exceeds the HTTP timeout plus the
      per-contact fan-out time.
- [ ] The proxy preserves `Host` and `Origin`; no wildcard CORS is added.
- [ ] The health route is monitored from outside the host.
- [ ] Logs redact medical data, names and phone numbers where required.
