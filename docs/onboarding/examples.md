# Examples

Applies to: integrators choosing a starting point.

Each example lives in its own directory with its own README. That README is the
source of truth for its commands; this page only helps you pick one.

| Situation                            | Use                    |
| ------------------------------------ | ---------------------- |
| Plain PHP site, no framework         | `examples/vanilla-php` |
| Laravel site, BFF on the same origin | `examples/laravel`     |
| Testing the HTTP contract by hand    | `examples/api`         |

## Vanilla PHP (primary)

`examples/vanilla-php` is a complete host: front controller, demo page and a
mock upstream API. It proves the full lifecycle without credentials and without
sending real messages.

```bash
cd examples/vanilla-php
./demo.sh
```

The script installs dependencies, starts the mock API and the BFF, runs
`smoke.sh` and leaves a browser demo running. `serve.sh` starts the same BFF
against a real upstream from the example `.env`; it sends real messages.

See [`examples/vanilla-php/README.md`](../../examples/vanilla-php/README.md) for
the demo codes, the mock state and the reset instructions.

## Laravel (secondary)

`examples/laravel` is a copy-in reference, not a bootable application. The
recommended topology is a Laravel page on the same origin as a separately
deployed BFF. It includes the Blade view, the ESM entry point, a controller and
route, and an optional `SessionStore` adapter for hosts that embed the kernel.

See [`examples/laravel/README.md`](../../examples/laravel/README.md).

## API collection

`examples/api` contains a Postman collection and a curl walkthrough for session,
current, activate, simulation, refresh and abort. Use it against a deployed BFF
without a browser panel.

## Mock upstream

`examples/vanilla-php/mock-api.php` mocks the upstream DataEmergencia API so the
real BFF can run offline. It validates the BFF behavior and sends no real
messages.
