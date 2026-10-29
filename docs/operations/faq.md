# FAQ

## Is the BFF a full application?

No. `dataemergencia/emergency-bff` is a Composer library. It provides routing,
session handling, CSRF, the emergency lifecycle and the upstream API client.
Your application supplies a front controller and the web server.

## Does the package render the SOS panel?

No. It provides the API. Use the recommended library,
[`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency), for
the browser panel.

## Can the frontend call the BFF on another domain?

No, not by default. The BFF rejects requests whose `Origin` host or port does
not match `Host`. Serve the frontend and the BFF from the same origin, or expose
the BFF through a same-origin reverse proxy.

## Why is there a PHP session if the panel already has a code?

The code identifies the person; the session proves that the same browser
started the flow. It also holds the CSRF token, the rate-limit counters and a
snapshot of the active emergency. Without it, anyone who knew a code could
read or abort an emergency.

## Does the BFF store emergency data?

It stores a snapshot in the PHP session for resume and display. The source of
truth is the DataEmergencia API. No database, migrations or queues are
required. Session storage must survive across requests and workers.

## Which upstream endpoints does it call?

`GET /v2/fmd/{code}/exists`, `GET /v2/fmd/{code}/models/data`,
`POST /v2/emergency`, `GET /v2/emergency/{id}`, `DELETE /v2/emergency/{id}`,
`GET /v2/emergency/qr/{code}/active` and
`POST /v2/whatsapp/templates/send`. See
[Upstream API](../bff/upstream-api.md).

## Can I test without sending WhatsApp messages?

Yes. Run the bundled mock API and BFF from `examples/vanilla-php` with
`./demo.sh`, or implement a mock upstream. The package ships a mock upstream
for local development.

## Is realtime required?

No. Pusher updates are an enhancement. Consumers should call `GET /current` on
mount and reconcile on focus, reconnect and after activation, so delivery
status still converges without Pusher.

## Can a person have more than one active emergency?

The upstream API enforces one active emergency per code. A second activation
returns `409 emergency_already_active`; the frontend should then load the active
emergency instead of creating a duplicate.

## What happens if WhatsApp delivery fails for every contact?

The BFF returns `502 whatsapp_delivery_failed` and does not create the
emergency. The frontend should show the failure and keep the SOS action
available so the person can retry or use the fallback phone number.

## Does the BFF work behind a load balancer?

Yes, if sessions are shared between instances. Use a shared session store,
sticky sessions or a single instance. Otherwise a request can land on a worker
that does not know the session and return `401 unauthenticated`.

## How do I rotate the upstream credentials?

Change `EMERGENCY_BFF_API_KEY` in the process environment and restart or
reload PHP-FPM. Existing sessions keep working; new upstream calls use the new
key. The BFF does not cache the key.

## Is there a hosted BFF I can point at?

No. Each customer deploys the BFF with their own credentials. This keeps the
API key server-side and lets the host control sessions, logging and the
network boundary.

## Can I use the BFF with multiple tenants?

Not in `0.1.1`. One deployment uses one API key and one application identifier.
For multiple customers, deploy one BFF instance per trust boundary, and see the
ownership limitation in [Security](../bff/security.md#known-limitation-refresh-ownership).

## Where can I report a security issue?

Follow the security policy in the package repository. Do not open a public
issue with credentials, phone numbers or an exploitable request.
