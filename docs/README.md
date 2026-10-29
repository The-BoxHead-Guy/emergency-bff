# Emergency BFF documentation

`dataemergencia/emergency-bff` is a framework-agnostic PHP backend-for-frontend
for the DataEmergencia emergency flow. It validates the identity code, builds
the WhatsApp templates server-side and keeps the emergency state in sync with
the upstream API.

The browser panel is a separate package:
[`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency). It
is the recommended frontend for this backend.

## Start here

| If you are…                           | Read                                                                                                              |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Installing the BFF for the first time | [Requirements](bff/requirements.md) → [Installation](bff/installation.md) → [Configuration](bff/configuration.md) |
| Building the whole flow               | [Zero to panel](onboarding/zero-to-panel.md)                                                                      |
| Calling the JSON API directly         | [Routes](bff/routes.md) and [API contract](api-contract.md)                                                       |
| Integrating Laravel                   | [Session and CSRF](bff/session-and-csrf.md)                                                                       |
| Operating the service                 | [Deployment](bff/deployment.md) and [Operations FAQ](operations/faq.md)                                           |
| Upgrading a release                   | [Upgrade guide](bff/upgrade.md) and [Versioning](release/versioning.md)                                           |

## Documentation map

### Overview and quickstart

- [Requirements](bff/requirements.md)
- [Installation](bff/installation.md)
- [Zero to panel](onboarding/zero-to-panel.md)

### Configuration and integration

- [Configuration reference](bff/configuration.md)
- [Session and CSRF](bff/session-and-csrf.md)
- [Routes](bff/routes.md)
- [Upstream API mapping](bff/upstream-api.md)
- [Activation flow](bff/activation-flow.md)
- [WhatsApp payloads](bff/whatsapp-payloads.md)
- [Security](bff/security.md)
- [Pusher and realtime](bff/pusher.md)
- [Deployment](bff/deployment.md)
- [Upgrade guide](bff/upgrade.md)

### API contract and examples

- [API contract](api-contract.md)
- [Examples](examples.md)

### Operations and migration

- [FAQ](operations/faq.md)
- [Troubleshooting](operations/troubleshooting.md)
- [Migration from a bundled flow](migration/from-bundled-flow.md)

### Releases

- [Versioning](release/versioning.md)

### Contributor documentation

- [Documentation standards](contributing/documentation.md)
- [Verification log](contributing/verification.md)

## Recommended library

The browser panel is documented in its own repository:

- [Client documentation](https://github.com/The-BoxHead-Guy/emergency#readme)
- [Client quickstart](https://github.com/The-BoxHead-Guy/emergency/blob/main/docs/client/quickstart.md)

## Version

These docs describe `0.1.1` and are versioned with the package. The quickstart
is tested against the latest published tag.
