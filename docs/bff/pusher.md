# Pusher and realtime

Applies to: integrators enabling live delivery status.

Realtime delivery updates are owned by the DataEmergencia API. The API is the
only publisher of the WhatsApp events; the BFF only proxies session and
emergency reads and does not expose or configure Pusher.

## Ownership

- The API publishes `whatsapp.{recipientId}` events through `WhatsAppEvent`.
- The BFF no longer returns a `realtime` block from `GET /config` or
  `GET /current`, and it reads no Pusher credentials.
- The browser library receives the public Pusher key and cluster through its own
  mount configuration and subscribes directly to the API's channels.

The Pusher secret never leaves the API. The BFF ships no Pusher values at all,
so there is nothing to keep out of `GET /config`.

## Channel naming

For each responder phone, the frontend subscribes to:

```text
whatsapp.<phone without a leading +>
```

Example: `+57 300 123 4567` normalized upstream to `573001234567` becomes
`whatsapp.573001234567`.

Channel names are not secret. Anyone who knows a phone number can subscribe to
its public channel. The MVP therefore treats channel events as **metadata-only
signals**: consumers do not trust their payload. Any event should trigger a
throttled reconciliation call to the BFF, which returns the authoritative
responder statuses.

## Consumer behavior

- Subscribe only when a real emergency is active and responders exist.
- Connect over TLS.
- On reconnect, tab focus, `online` and `visibilitychange`, trigger a throttled
  reconciliation call instead of trusting the event payload.
- Realtime is optional; the state returned by the BFF remains authoritative
  without it.

## Why public channels in the MVP

- No additional auth endpoint to operate.
- No per-user token lifecycle.
- Delivery status is read from the BFF anyway, so the channel carries no
  authority.

The trade-off is privacy: subscriber phone numbers are visible in channel names
to anyone who can observe them. Do not put names, medical data or message
bodies in channel names or event payloads.

## Post-MVP: private channels

Private channels are intentionally out of scope for the MVP. The BFF routes no
`/pusher/auth` endpoint. A future version must:

1. Authenticate the session and verify emergency ownership.
2. Authorize only the responder channels that belong to that emergency.
3. Return a Pusher auth signature using a server-side secret.
4. Keep the secret out of the browser and out of the BFF.

Do not implement a partial version of this endpoint; an auth endpoint that
returns signatures for arbitrary channels is worse than public channels.

## Next step

[Deployment](deployment.md).
