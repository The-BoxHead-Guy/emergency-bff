# Pusher and realtime

Applies to: integrators enabling live delivery status.

The MVP uses **public Pusher channels**. The BFF exposes only the public key and
cluster; the Pusher secret never leaves the server and is not read by this
package.

## Configuration

```dotenv
EMERGENCY_BFF_PUSHER_KEY=public-key
EMERGENCY_BFF_PUSHER_CLUSTER=us2
```

Both values are returned by `GET /config`:

```json
{
  "mode": "public",
  "pusher": {
    "key": "public-key",
    "cluster": "us2",
    "channel_pattern": "whatsapp.{phone}"
  }
}
```

If either value is empty, the client reports `realtimeStatus: "unavailable"` and
keeps working in pull mode through `POST /{id}/refresh`.

## Channel naming

For each responder phone, the client subscribes to:

```text
whatsapp.<phone without a leading +>
```

Example: `+57 300 123 4567` normalized upstream to `573001234567` becomes
`whatsapp.573001234567`.

Channel names are not secret. Anyone who knows a phone number can subscribe to
its public channel. The MVP therefore treats channel events as **metadata-only
signals**: the client does not trust their payload. Any event triggers a
throttled reconciliation call to the BFF, which returns the authoritative
responder statuses.

## Client behavior

- Subscription is created only when a real emergency is active and responders
  exist.
- Pusher connects with `forceTLS: true`.
- Reconnect, tab focus, `online` and `visibilitychange` trigger the same
  throttled refresh.
- Refresh is throttled to one call per 1500 ms.
- `destroy()` unsubscribes and disconnects.
- Realtime is optional; the panel remains correct without it.

## Why public channels in the MVP

- No additional auth endpoint to operate.
- No per-user token lifecycle.
- Delivery status is read from the BFF anyway, so the channel carries no
  authority.

The trade-off is privacy: subscriber phone numbers are visible in channel names
to anyone who can observe them. Do not put names, medical data or message
bodies in channel names or event payloads.

## Post-MVP: private channels

Private channels are intentionally out of scope for the MVP. The reserved
endpoint is:

```text
<route prefix>/pusher/auth
```

It is not routed. A future version must:

1. Authenticate the session and verify emergency ownership.
2. Authorize only the responder channels that belong to that emergency.
3. Return a Pusher auth signature using a server-side secret.
4. Keep the secret out of the browser and out of `GET /config`.

Do not implement a partial version of this endpoint; an auth endpoint that
returns signatures for arbitrary channels is worse than public channels.

## Next step

[Deployment](deployment.md).
