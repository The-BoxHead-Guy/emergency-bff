# WhatsApp payloads

Applies to: maintainers and integrators diagnosing message delivery.

The upstream API owns the Meta template: name, language, header image, date
formatting, QR links and map data. The BFF only sends the recipient, the
emergency id and the template variables.

## Which endpoint is used

| Scenario   | Upstream endpoint                                  | Variables                                     |
| ---------- | -------------------------------------------------- | --------------------------------------------- |
| Emergency  | `POST /v2/whatsapp/templates/emergency/activation` | `user_name`, `emergency_code`                 |
| Simulation | `POST /v2/whatsapp/templates/emergency/simulacrum` | `contact_name`, `user_name`, `emergency_code` |
| Abort      | `POST /v2/whatsapp/templates/emergency/abort`      | `user_name`                                   |

Activation, simulation and abort are billed per recipient. The abort dispatch
does not surface upstream billing failures: the `DELETE /{id}` route keeps its
`200` contract, so a `402 INSUFFICIENT_CREDITS` becomes a `failures` entry and
the emergency is still deleted.

A blank `user_name` (after sanitization) skips the request and records a
`failures` entry. The API rejects that payload with `422`, and the abort still
deletes the emergency.

`emergency_code` is the upstream emergency id used as the URL button suffix, so
the emergency must exist before the message is sent. The API resolves the
`initiator_id` from that record and charges it.

## Response

Both typed endpoints answer `200` even when an individual recipient fails:

```json
{
  "data": {
    "attributes": {
      "data": {
        "results": [
          {
            "phone": "573001234567",
            "status": "sent",
            "whatsapp_message_id": "wamid.HBgMNTg0MTIxMjM0NTY3FQIAERgS…"
          }
        ]
      }
    }
  }
}
```

For all typed endpoints, a send succeeds only when `status` is `sent` and
`whatsapp_message_id` is a non-empty string starting with `wamid.`. A numeric
id, a bare `wamid.` or any other value resolves to `null`, and the BFF records
the responder as failed. The HTTP status alone never proves delivery.

The generic `POST /v2/whatsapp/templates/send` remains available for older
consumers and is treated as delivered on a `2xx`, but the BFF no longer uses it
for abort.

## Recipient data

- The phone is the first entry in `phones[]`, concatenating `area_code` and
  `number`, falling back to `recipientId`, `recipient_id` or `phone`.
- A leading `+` is stripped. Nothing else is normalized: the country code and
  digits must already be present. An empty phone is rejected upstream and
  recorded as a failed contact.
- `user_name` and `contact_name` are stripped of HTML tags and collapsed to
  single spaces.

The BFF fans out sequentially. One contact failing does not stop the rest; the
whole operation fails only when every send fails.
