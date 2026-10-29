# Vanilla PHP example

Dependency-free demo that installs the package through a Composer path
repository and runs the full session → activate → current → simulacrum → abort
flow against a stateful mock of the DataEmergencia API.

## Run everything with one command

```bash
cd examples/vanilla-php
./demo.sh
```

`demo.sh` installs dependencies if needed, resets the mock state, starts the mock
API on `:8081` and the BFF on `:8080`, runs the end-to-end smoke test, and keeps
both servers running until you press Ctrl-C.

Open <http://127.0.0.1:8080> and use the numbered buttons: **Start session** →
**Activate** → **Current** → **Simulacrum** → **Abort**. The demo code `DABC12`
is accepted; `ZZZZZZ` is inactive and `bad` is rejected as malformed.

## Run the servers separately

```bash
# terminal 1 — mock upstream API
cd examples/vanilla-php
php -S 127.0.0.1:8081 mock-api.php

# terminal 2 — demo BFF + page
cd examples/vanilla-php
composer install
php -S 127.0.0.1:8080 -t public public/index.php
```

## API smoke test

With both servers running:

```bash
cd examples/vanilla-php
./smoke.sh
```

Set `BASE_URL` to point at another BFF instance. The script checks health,
CSRF bootstrap, malformed/inactive/valid codes, idle current, simulacrum,
activation, resume, refresh, abort, and the final idle state. It exits non-zero
on the first failed assertion.

## Run against the real or local API

Start the DataEmergencia API first (for example on `http://127.0.0.1:8000`),
then set the example `.env`:

```dotenv
EMERGENCY_BFF_API_ORIGIN=http://127.0.0.1:8000
EMERGENCY_BFF_API_KEY=...
EMERGENCY_BFF_APPLICATION_IDENTIFIER=...
EMERGENCY_BFF_COOKIE_SECURE=false
```

Start the BFF without the mock:

```bash
cd examples/vanilla-php
./serve.sh
```

Open <http://127.0.0.1:8080>. The example's front controller reads `.env`;
real environment variables override it. The package itself does not read
`.env` — a host application supplies the environment variables.

**Warning:** against a real API, **Activate** and **Simulacrum** send real
WhatsApp messages to the profile's emergency contacts, and **Activate** creates
real emergency state. **Start session** and **Current** are read-only. Use the
mock (`demo.sh`) for repeatable testing.

To find a usable local code, query the seeded database for a code with both
personal and emergency data:

```sql
SELECT c.caa
FROM codigos c
JOIN fmd_emergencia e ON e.fmd = c.estado
JOIN fmd_personal p ON p.fmd = c.estado
WHERE e.nombre1 <> '' AND p.nombre <> ''
LIMIT 1;
```

`Start session` only checks that the code exists. The profile is fetched when
you activate (or simulate).

## Demo constraints

The demo uses PHP's built-in server, native `$_SESSION`, and `cookieSecure=false`
because it runs over plain HTTP. A production host must serve HTTPS and keep
`cookieSecure=true`.
