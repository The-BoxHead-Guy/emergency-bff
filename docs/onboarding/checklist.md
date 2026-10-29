# First integration checklist

One page to review before a real launch. Print it, or copy it into the change
ticket that authorises production traffic.

## Prerequisites

- [ ] PHP 8.4+ with `curl`, `json`, `mbstring`.
- [ ] Composer 2 and the package installed from Packagist
      (`dataemergencia/emergency-bff:^0.1`).
- [ ] A front controller that calls `Bootstrap::create()` and
      `Request::fromGlobals()`.
- [ ] HTTPS on the public origin. Geolocation and secure cookies depend on it.
- [ ] A DataEmergencia API key and application identifier issued for this
      customer.

## Configuration

- [ ] `EMERGENCY_BFF_API_KEY` is set in the server environment, not committed.
- [ ] `EMERGENCY_BFF_APPLICATION_IDENTIFIER` is set.
- [ ] `EMERGENCY_BFF_API_ORIGIN` points at the intended upstream.
- [ ] `EMERGENCY_BFF_ROUTE_PREFIX` matches the proxy or web-server rewrite.
- [ ] `EMERGENCY_BFF_COOKIE_SECURE=true` in production.
- [ ] `EMERGENCY_BFF_COOKIE_SAME_SITE=Lax` (or `Strict` if the host app allows
      it).
- [ ] Session storage is persistent across PHP-FPM workers if traffic uses
      more than one worker.
- [ ] `EMERGENCY_BFF_PUSHER_KEY` and `EMERGENCY_BFF_PUSHER_CLUSTER` are set if
      realtime delivery updates are wanted.

## Session and security

- [ ] The page and the BFF share an origin, including scheme and port.
- [ ] The host application does not rewrite the `Host` header in a way that
      makes `Origin` and `Host` differ.
- [ ] Cookies for `de_bff_emergency` are present after `GET /config`.
- [ ] Every write request sends `X-CSRF-Token` with the newest token.
- [ ] `POST /session` rotates the token, and the client uses the new value.
- [ ] Session cookies have `Secure`, `HttpOnly` and the intended `SameSite`.
- [ ] Logs redact API keys, full phone numbers and WhatsApp payloads.
- [ ] Rate limiting is understood: 60 requests per 60 seconds per session and
      route bucket.

## Functional smoke test

- [ ] `GET /health` returns `200` and `{"status":"ok"}`.
- [ ] `GET /config` returns the route prefix and a CSRF token.
- [ ] An invalid code returns `422 invalid_code`.
- [ ] An inactive code returns `404 inactive_code`.
- [ ] A valid code returns `201` from `POST /session`.
- [ ] `GET /current` returns `"active": false` before the first activation.
- [ ] `POST /simulacrum` sends test messages and does not create an emergency.
- [ ] `POST /activate` returns `201`, an emergency id and responder statuses.
- [ ] `GET /current` returns the active emergency after activation.
- [ ] `POST /{id}/refresh` returns the upstream emergency.
- [ ] `DELETE /{id}` sends the abort message and returns `"deleted": true`.
- [ ] `GET /current` returns `"active": false` after the abort.
- [ ] Denying location permission shows the fallback phone number, and the
      fallback number is correct for the deployment country.

## Client

- [ ] The client version is pinned and documented.
- [ ] `bffBaseUrl` includes `/de-bff/emergency` and has no trailing slash
      requirement beyond what the client normalises.
- [ ] The trigger element is visible, keyboard reachable and at least 44 px.
- [ ] `destroy()` runs on page unload or component unmount.
- [ ] The panel is readable on a 360 px-wide screen.
- [ ] The panel can be closed with Escape and the close button.
- [ ] Closing the panel does not abort the emergency; the separate abort
      confirmation does.

## Operations

- [ ] Health checks call `GET /de-bff/emergency/health`, not `/`.
- [ ] Alerts exist for `5xx`, `502 upstream_error`, `429 rate_limited` and
      WhatsApp total failure.
- [ ] An on-call owner knows how to reach DataEmergencia support.
- [ ] The rollback procedure is documented and tested.
- [ ] The public support page states what the SOS button does and does not do.

## Sign-off

- [ ] Developer: smoke test completed against the production upstream.
- [ ] Operations: monitoring and rollback reviewed.
- [ ] Product: copy, fallback number and privacy notice approved.

Record the date, package versions, client version and the person who signed
off in the release ticket.
