# DataEmergencia Emergency BFF

Backend for frontend (BFF) for the DataEmergencia emergency and alert flow.

The BFF sits between the browser panel and the DataEmergencia API. It receives a
browser request, validates the identity code, applies the emergency rules and
calls the upstream API with credentials that stay on the server. Its purpose is
the security boundary: the API key, the application identifier and the WhatsApp
flow never reach the browser, and one server-side component owns the emergency
state.

It is not a payload translator and not a full application. Your application owns
the front controller, the web server and the session backend. Realtime delivery
events are published by the DataEmergencia API; this package exposes no Pusher
configuration.

## Requirements

- PHP 8.4 or newer
- Composer 2.x
- One API key and application identifier per deployment, issued by DataEmergencia

The required PHP extensions are declared in `composer.json` and checked by
Composer during installation.

## Install

```bash
composer require dataemergencia/emergency-bff
```

## Documentation

| Read                                                  | When                                                |
| ----------------------------------------------------- | --------------------------------------------------- |
| [Zero to panel](docs/onboarding/zero-to-panel.md)     | First integration, from empty directory to panel    |
| [HTTP contract](docs/bff/routes.md)                   | Calling the BFF from a frontend                     |
| [Activation flow](docs/bff/activation-flow.md)        | Understanding what happens between SOS and WhatsApp |
| [Configuration](docs/bff/configuration.md)            | Wiring credentials and runtime behavior             |
| [Session and CSRF](docs/bff/session-and-csrf.md)      | Hosting the BFF in an existing PHP application      |
| [Security](docs/bff/security.md)                      | Threat model, trust boundaries, known limitations   |
| [Troubleshooting](docs/operations/troubleshooting.md) | Diagnosing a failing deployment                     |
| [Upgrade](docs/bff/upgrade.md)                        | Moving between releases                             |
| [Releasing](docs/release/releasing.md)                | Publishing a version (maintainers)                  |

The full index is [docs/README.md](docs/README.md).

## Browser panel

Use [`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency)
for the SOS panel. It consumes this backend and owns geolocation, the SOS
interaction and the fallback phone number.

## Maintenance

Maintained by Sistemas SCAEM C.A. Source and issues:
[`The-BoxHead-Guy/emergency-bff`](https://github.com/The-BoxHead-Guy/emergency-bff).

## License

MIT. See [LICENSE](LICENSE).
