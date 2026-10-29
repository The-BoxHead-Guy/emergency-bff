# WhatsApp payloads

Applies to: maintainers and integrators diagnosing message content.

The BFF builds the payloads in PHP and sends them through
`POST /v2/whatsapp/templates/send`. The browser never builds a WhatsApp payload.

## Templates

| Scenario   | Template                                      | Image                                                                              |
| ---------- | --------------------------------------------- | ---------------------------------------------------------------------------------- |
| Emergency  | `emergency_message_template_v3`               | `https://api.dataemergencia.com/public/images/panic-button/emergency-template.png` |
| Simulation | `simulacrum_support_contacts_confirmation_v2` | `https://api.dataemergencia.com/public/images/logo_scaem.png`                      |
| Abort      | `emergency_status_aborting_v1`                | none                                                                               |

All three use `"language": "en"`, which is the template language registered
upstream. The rendered activation date is Spanish.

## Emergency payload

```json
{
  "to": "573001234567",
  "template": "emergency_message_template_v3",
  "language": "en",
  "google_maps": {
    "latitude": "4.711",
    "longitude": "-74.0721",
    "zoom": 17
  },
  "components": [
    {
      "type": "header",
      "parameters": [
        {
          "type": "image",
          "image": {
            "link": "https://api.dataemergencia.com/public/images/panic-button/emergency-template.png"
          }
        }
      ]
    },
    {
      "type": "body",
      "parameters": [
        {
          "type": "text",
          "parameter_name": "contact_name",
          "text": "Grace Hopper"
        },
        {
          "type": "text",
          "parameter_name": "user_name",
          "text": "Ada Lovelace"
        },
        {
          "type": "text",
          "parameter_name": "full_date",
          "text": "3 de Octubre de 2026, 7:05pm"
        },
        {
          "type": "text",
          "parameter_name": "fmd_url",
          "text": "https://zur.dataemergencia.com/qr/DABC12"
        }
      ]
    }
  ]
}
```

Body parameters, in order:

1. `contact_name` — the responder's name from the profile.
2. `user_name` — the FMD holder's full name, sanitized.
3. `full_date` — Spanish date, for example `3 de Octubre de 2026, 7:05pm`.
4. `fmd_url` — QR URL for the product code or identity code.

`google_maps` is a top-level field, not a template component. Latitude and
longitude are stringified; `zoom` is the number `17`. Missing coordinates become
empty strings.

## Simulation payload

```json
{
  "to": "573001234567",
  "template": "simulacrum_support_contacts_confirmation_v2",
  "language": "en",
  "google_maps": null,
  "components": [
    {
      "type": "header",
      "parameters": [
        {
          "type": "image",
          "image": {
            "link": "https://api.dataemergencia.com/public/images/logo_scaem.png"
          }
        }
      ]
    },
    {
      "type": "body",
      "parameters": [
        {
          "type": "text",
          "parameter_name": "contact_name",
          "text": "Grace Hopper"
        },
        {
          "type": "text",
          "parameter_name": "user_name",
          "text": "Ada Lovelace"
        },
        {
          "type": "text",
          "parameter_name": "medical_profile_url",
          "text": "https://zur.dataemergencia.com/qr/DABC12"
        }
      ]
    }
  ]
}
```

The simulation sends no map data and never creates an emergency.

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
digits must already be present. An empty result produces an empty `to`, which
the upstream API rejects; the BFF records that contact as failed.

## Names

`user_name` and contact names are stripped of HTML tags and normalized to single
spaces. Blank names become an empty string. Contact names are taken as-is from
the profile apart from that normalization.

## QR links

- Host: `EMERGENCY_BFF_QR_BASE_HOST` (default `zur.dataemergencia.com`).
- Local mode: `EMERGENCY_BFF_LOCAL=true` builds
  `http://localhost/<qr host>/qr/<code>`.
- The code is URL-encoded with `rawurlencode`.
- An empty code produces an empty URL.

`EMERGENCY_BFF_FALLBACK_ORIGIN_URL` is reserved for future fallback behavior; the
current builder does not use it.

## Idempotency and fan-out

Messages are sent one contact at a time. A failure for one contact does not stop
the rest. The BFF aggregates results into `responders` and `failures` and only
treats the whole operation as failed when every send failed.

## Next step

[Security](security.md).
