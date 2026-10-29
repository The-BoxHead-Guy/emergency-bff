# API collection

Use these requests to exercise a running BFF. Replace `BASE_URL` with the full
prefix, including `/de-bff/emergency`.

```bash
BASE_URL="http://127.0.0.1:8080/de-bff/emergency"
```

Every write request needs the CSRF token from the most recent `GET /config` or
`POST /session`. Keep the cookie jar: without the session cookie the BFF
returns `401 unauthenticated`.

## 1. Health

```bash
curl -sS "$BASE_URL/health"
```

## 2. Config and CSRF token

```bash
curl -sS -c cookies.txt "$BASE_URL/config"
```

Copy `csrf_token` from the response.

## 3. Start a session

```bash
curl -sS -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"code":"DABC12"}' \
  "$BASE_URL/session"
```

The response contains a new `csrf_token`. Export it before the next write:

```bash
CSRF="<csrf_token from the session response>"
```

## 4. Current state

```bash
curl -sS -b cookies.txt "$BASE_URL/current"
```

## 5. Simulation

```bash
curl -sS -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF" \
  -d '{}' \
  "$BASE_URL/simulacrum"
```

Simulation sends test WhatsApp messages and never creates an emergency.

## 6. Activate

```bash
curl -sS -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF" \
  -d '{"geolocation":{"latitude":4.711,"longitude":-74.0721}}' \
  "$BASE_URL/activate"
```

Copy `emergency_id` from the response. Activation sends real messages when the
BFF uses the real upstream.

## 7. Refresh

```bash
curl -sS -b cookies.txt -c cookies.txt \
  -H "Content-Type: application/json" \
  -H "X-CSRF-Token: $CSRF" \
  -d '{}' \
  "$BASE_URL/$EMERGENCY_ID/refresh"
```

Refresh is a `POST`; it requires the session and the CSRF token.

## 8. Abort

```bash
curl -sS -b cookies.txt -c cookies.txt \
  -H "X-CSRF-Token: $CSRF" \
  -X DELETE \
  "$BASE_URL/$EMERGENCY_ID"
```

Expected: `"deleted": true` and a final `GET /current` with
`"active": false`.

## Postman

Import [`emergency-bff.postman_collection.json`](emergency-bff.postman_collection.json).
The collection defines:

- `baseUrl` — full BFF prefix.
- `csrfToken` — captured automatically from `/config` and `/session`.
- `emergencyId` — captured automatically from activation.

Postman keeps cookies per collection run. Run the requests in order. If you
change the code or restart the BFF, start again from **Config**.

## Automated curl flow

The vanilla PHP example runs the same sequence with assertions:

```bash
cd packages/emergency-bff/examples/vanilla-php
./demo.sh
```

`smoke.sh` is the authoritative end-to-end check.
