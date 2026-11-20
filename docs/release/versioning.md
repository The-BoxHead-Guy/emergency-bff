# Versioning

The package follows Semantic Versioning. Versions derive from Git tags in the
form `vX.Y.Z`; `composer.json` deliberately has no `version` field.

## What each change means

| Change                                                                | Version                  |
| --------------------------------------------------------------------- | ------------------------ |
| Bug fix, documentation, hardening without a contract change           | patch                    |
| Additive feature, new optional field, new route                       | minor                    |
| Breaking change to routes, request/response shapes or cookie behavior | minor (pre-1.0) or major |

Adding a response field is not breaking. Removing or renaming a field, changing
a status code, changing an error `code` or changing the meaning of an existing
field is breaking.

## Tags

Release tags use `vX.Y.Z`, for example `v0.5.0`. Packagist normalizes the
leading `v` and exposes `0.5.0`.

The tag is created in the monorepo with a project-scoped prefix and mirrored to
the split repository without it:

| Monorepo tag           | Split / Packagist tag |
| ---------------------- | --------------------- |
| `emergency-bff-v0.5.1` | `v0.5.1`              |

Only `emergency-bff-v*` starts the BFF split workflow, which lets several
packages be released from one monorepo. The old `php-v*` prefix is historical
and must not be reused.

For pre-1.0 releases, `^0.5` allows `0.5.x` but not `0.6.0`. That is intended
while the contract is still stabilizing.

## Deprecation

- Announce a deprecation at least one minor release before removal.
- Keep the old behavior working during the window.
- Document the replacement in the changelog and on the affected page.
- Remove it in the next minor (pre-1.0) or major release.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/):
`Unreleased` collects changes, each release has a dated heading, and sections
use `Added`, `Changed`, `Deprecated`, `Removed`, `Fixed` and `Security`.

## Related

- [Releasing the BFF](releasing.md)
- [Upgrade guide](../bff/upgrade.md)
- [Documentation standard](../contributing/documentation.md)
