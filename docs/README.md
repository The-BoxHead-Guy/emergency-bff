# Emergency BFF documentation

`dataemergencia/emergency-bff` is a framework-agnostic PHP backend for frontend
for the DataEmergencia emergency and alert flow. It validates the identity code,
binds it to a server-side session, applies the emergency rules and calls the
DataEmergencia API with credentials the browser never sees.

The browser panel is a separate package:
[`@dataemergencia/emergency`](https://github.com/The-BoxHead-Guy/emergency).

## If you are…

| …then read                                          |
| --------------------------------------------------- |
| Integrating for the first time                      | [Zero to panel](onboarding/zero-to-panel.md) → [Examples](onboarding/examples.md)         |
| Calling the BFF from a frontend                     | [HTTP contract](bff/routes.md)                                                            |
| Understanding what happens between SOS and WhatsApp | [Activation flow](bff/activation-flow.md) → [WhatsApp payloads](bff/whatsapp-payloads.md) |
| Configuring credentials and runtime behavior        | [Configuration](bff/configuration.md)                                                     |
| Hosting the BFF inside an existing PHP application  | [Session and CSRF](bff/session-and-csrf.md)                                               |
| Reviewing the threat model or the known limitations | [Security](bff/security.md)                                                               |
| Operating a deployment                              | [Troubleshooting](operations/troubleshooting.md)                                          |
| Replacing a bundled emergency implementation        | [Migration](migration/from-bundled-flow.md)                                               |
| Upgrading                                           | [Upgrade guide](bff/upgrade.md) → [Versioning](release/versioning.md)                     |
| Publishing a release                                | [Releasing](release/releasing.md)                                                         |
| Writing documentation                               | [Documentation standard](contributing/documentation.md)                                   |

## Structure

```text
docs/
├── bff/            contract, behavior and integration rules
├── onboarding/     getting started and examples
├── operations/     diagnosing a deployment
├── migration/      moving from a bundled flow
├── release/        semver policy and the release runbook
├── contributing/   documentation standard
└── plans/          implementation plans, not reference documentation
```

## Related

- [Package README](../README.md)
- [Changelog](../CHANGELOG.md)
- [Client documentation](https://github.com/The-BoxHead-Guy/emergency#readme)
