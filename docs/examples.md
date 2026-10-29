# Examples

The BFF package ships runnable examples and reference integrations. Start with
the vanilla PHP example: it is the only one that needs no JavaScript build
step.

## Vanilla PHP (primary)

`examples/vanilla-php` is a complete host: a front controller, a demo page and
a mock upstream API. It proves the full lifecycle without credentials and
without sending real messages.

```bash
cd packages/emergency-bff/examples/vanilla-php
./demo.sh
```

The script installs dependencies, starts the mock API on `:8081` and the BFF on
`:8080`, runs the smoke test, and leaves both servers running for the browser
demo at <http://127.0.0.1:8080>.

Useful commands:

| Command                              | Purpose                                                       |
| ------------------------------------ | ------------------------------------------------------------- |
| `./demo.sh`                          | Mock upstream, BFF, smoke test and demo page.                 |
| `./serve.sh`                         | BFF against the real upstream in `.env`. Sends real messages. |
| `./smoke.sh`                         | End-to-end curl flow against a running BFF.                   |
| `php -S 127.0.0.1:8081 mock-api.php` | Mock upstream only.                                           |

Read the example README for the demo codes (`DABC12` valid, `ZZZZZZ` inactive,
`bad` malformed), local API configuration and reset instructions.

## Laravel (secondary)

`examples/laravel` is a copy-in reference for a Laravel host. The recommended
topology is a Laravel page on the same origin as a separately deployed BFF,
with the client imported through Vite. The example includes the Blade view, the
ESM entry point, a controller and route, and an optional `SessionStore` adapter
for hosts that embed the kernel instead of deploying it separately.

It is not a standalone Laravel application. Copy the files into an existing
application, or use them as the shape of your integration. See
[`examples/laravel/README.md`](../examples/laravel/README.md).

## API collection

`examples/api` contains a Postman collection and a curl walkthrough for
session, current, activate, simulation, refresh and abort. Use it to exercise a
deployed BFF without the panel.

## Mock BFF mode

There are two independent mocks:

- `packages/emergency-bff/examples/vanilla-php/mock-api.php` mocks the
  **upstream DataEmergencia API** so the real BFF can run offline.
- `libs/emergency/examples/vanilla/mock-bff.mjs` mocks the **BFF** so the
  client can run without PHP.

Do not confuse them. The first validates BFF behaviour; the second validates
client behaviour.

## Which example should I copy?

| Situation                            | Use                                                                                                     |
| ------------------------------------ | ------------------------------------------------------------------------------------------------------- |
| Plain PHP site, no framework         | `examples/vanilla-php`                                                                                  |
| Laravel site, BFF on the same origin | `examples/laravel`                                                                                      |
| React or Vite frontend               | [Client React guide](https://github.com/The-BoxHead-Guy/emergency/blob/v0.1.0/docs/client/react.md)     |
| No build step, static HTML           | [Vanilla client guide](https://github.com/The-BoxHead-Guy/emergency/blob/v0.1.0/docs/client/vanilla.md) |
| Testing the HTTP contract by hand    | `examples/api`                                                                                          |
