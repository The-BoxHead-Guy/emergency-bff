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
| Abort      | `POST /v2/whatsapp/templates/send`                 | `emergency_status_aborting_v1` template       |

Activation and simulation are billed per recipient. Abort uses the generic,
unbilled endpoint because no typed abort endpoint exists. This is why a `402`
blocks activation and simulation but never blocks abort.

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

For both typed endpoints, a send succeeds only when `status` is `sent` and
`whatsapp_message_id` is a non-empty string starting with `wamid.`. A numeric
id, a bare `wamid.` or any other value resolves to `null`, and the BFF records
the responder as failed. The HTTP status alone never proves delivery.

The abort endpoint is the generic send and returns no typed result; a `2xx`
there is treated as delivered.

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
