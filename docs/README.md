# Emergency BFF documentation

`dataemergencia/emergency-bff` is a framework-agnostic PHP backend-for-frontend
for the DataEmergencia emergency module. It exposes the
`/de-bff/emergency/*` JSON API, validates the 6-character identity code, builds
the WhatsApp template payloads server-side and proxies the existing
DataEmergencia API.

The BFF ships backend PHP only. The browser panel lives in
[`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency).

## Start here

| If you are…                           | Read                                                                                                              |
| ------------------------------------- | ----------------------------------------------------------------------------------------------------------------- |
| Installing the BFF for the first time | [Requirements](bff/requirements.md) → [Installation](bff/installation.md) → [Configuration](bff/configuration.md) |
| Building the whole flow               | [Zero to panel](onboarding/zero-to-panel.md)                                                                      |
| Calling the JSON API directly         | [Routes](bff/routes.md) and [API contract](api-contract.md)                                                       |
| Integrating Laravel                   | [Session and CSRF](bff/session-and-csrf.md)                                                                       |
| Operating the service                 | [Deployment](bff/deployment.md) and [Operations FAQ](operations/faq.md)                                           |
| Publishing a release                  | [Release and deployment](release/release-checklist.md)                                                            |

## Documentation map

The information architecture is: overview, quickstart, BFF configuration,
client configuration, API contract, examples, operations, migration, release and
deployment, and troubleshooting.

### Overview and quickstart

- [Requirements](bff/requirements.md)
- [Installation](bff/installation.md)
- [Zero to panel](onboarding/zero-to-panel.md)
- [Onboarding checklist](onboarding/checklist.md)

### BFF configuration

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

### Operations, migration and release

- [FAQ](operations/faq.md)
- [Troubleshooting](operations/troubleshooting.md)
- [Migration from bp.scaem.app](migration/from-bp-scaem.md)
- [Split repositories](release/split-repositories.md)
- [Versioning](release/versioning.md)
- [Packagist publication](release/packagist.md)
- [Release checklist](release/release-checklist.md)

### Contributor documentation

- [Documentation standards](contributing/documentation.md)
- [Verification log](contributing/verification.md)

## Client documentation

The browser client is documented in its own repository:

- [Client documentation](https://github.com/The-BoxHead-Guy/emergency#readme)
- [Client quickstart](https://github.com/The-BoxHead-Guy/emergency/blob/main/docs/client/quickstart.md)

## Version

These docs describe `0.1.0` and are versioned with the package. The quickstart
is tested against the latest published tag.
