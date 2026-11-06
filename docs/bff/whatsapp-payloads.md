# WhatsApp payloads

Applies to: maintainers and integrators diagnosing message content.

The BFF sends only the recipient phone and the endpoint-specific `variables`.
The upstream API owns the Meta template name, language, header image, date
formatting, QR links and map data.

## Endpoints

| Scenario   | Endpoint                                           | Variables                                          |
| ---------- | -------------------------------------------------- | -------------------------------------------------- |
| Emergency  | `POST /v2/whatsapp/templates/emergency/activation` | `user_name`, `emergency_code`                      |
| Simulation | `POST /v2/whatsapp/templates/emergency/simulacrum` | `contact_name`, `user_name`, `emergency_code`      |
| Abort      | `POST /v2/whatsapp/templates/send`                 | Legacy payload with `emergency_status_aborting_v1` |

Activation and simulacrum no longer send a template name, language, components
or `google_maps`. Abort keeps the legacy payload because no typed abort endpoint
exists.

## Emergency request

```json
{
  "recipients": [
    {
      "phone": "573001234567",
      "variables": {
        "user_name": "Ada Lovelace",
        "emergency_code": "EMG123456"
      }
    }
  ]
}
```

`emergency_code` is the upstream emergency id. The API uses it as the URL button
suffix, so the emergency must exist before the message is sent.

## Simulation request

```json
{
  "recipients": [
    {
      "phone": "573001234567",
      "variables": {
        "contact_name": "Grace Hopper",
        "user_name": "Ada Lovelace",
        "emergency_code": "EMG123456"
      }
    }
  ]
}
```

The simulation pre-creates an inactive emergency only to obtain a valid
`emergency_code` for its `/test` link. It stays inactive and the BFF does not
update it.

## Typed response

Both typed endpoints answer `200` even when an individual recipient fails:

```json
{
  "data": {
    "type": "whatsapp_template",
    "id": "resource|…",
    "attributes": {
      "data": {
        "event_type": "activation",
        "results": [
          {
            "phone": "573001234567",
            "status": "sent",
            "whatsapp_message_id": "wamid.HBgMNTg0MTIxMjM0NTY3FQIAERgS…"
          }
        ],
        "summary": { "total": 1, "sent": 1, "failed": 0 }
      }
    }
  }
}
```

A send succeeds only when `data.attributes.data.results[0].status` is `sent` and
`whatsapp_message_id` is a valid `wamid.`-prefixed value. A `200` with
`status: failed` becomes a failed `MessageResult`, never a success.

## Abort payload

```json
{
  "to": "573001234567",
  "template": "emergency_status_aborting_v1",
  "language": "en",
  "components": [
    {
      "type": "body",
      "parameters": [
        {
          "type": "text",
          "parameter_name": "user_name",
          "text": "Ada Lovelace"
        }
      ]
    }
  ]
}
```

## Phone formatting

`Responder::phoneFromContact()` chooses the number in this order:

1. The first entry in `phones[]`, concatenating `area_code` and `number`.
2. `recipientId`.
3. `recipient_id`.
4. `phone`.

A leading `+` is stripped. No other normalization is applied: country code and
digits must already be present. An empty result produces an empty `phone`, which
the upstream API rejects; the BFF records that contact as failed.

## Names

`user_name` and `contact_name` are stripped of HTML tags and normalized to single
spaces. Blank names become an empty string.

## Fan-out

Messages are sent one contact at a time. A failure for one contact does not stop
the rest. The BFF aggregates results into `responders` and `failures` and only
treats the whole operation as failed when every send failed.

## Next step

[Security](security.md).
