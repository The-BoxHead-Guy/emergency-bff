# Versioning

The package follows Semantic Versioning. Versions are derived from Git tags in
the form `vX.Y.Z`; `composer.json` deliberately has no `version` field.

## Semantic versioning

Before `1.0.0`, the package follows the usual pre-1.0 convention:

| Change                                                                | Version                                 |
| --------------------------------------------------------------------- | --------------------------------------- |
| Bug fix, documentation, security hardening without API change         | patch                                   |
| Additive feature, new optional field, new route                       | minor                                   |
| Breaking change to routes, request/response shapes or cookie behavior | minor (pre-1.0) or major (from `1.0.0`) |

Once the package reaches `1.0.0`, a breaking change requires a major version.

Adding a new field to a JSON response is not breaking. Removing a field,
renaming a field, changing a status code, changing an error `code` or changing
the meaning of an existing field is breaking.

## Tags

Release tags use `vX.Y.Z`, for example `v0.1.0`. Packagist normalises the
leading `v` and exposes the version as `0.1.0`.

| Release | Tag      | Composer constraint |
| ------- | -------- | ------------------- |
| `0.1.0` | `v0.1.0` | `^0.1`              |
| `0.1.1` | `v0.1.1` | `^0.1`              |
| `0.2.0` | `v0.2.0` | `^0.2`              |

For pre-1.0 releases, `^0.1` allows `0.1.x` but not `0.2.0`. That is the
intended behaviour while the contract is still stabilising.

## Deprecation

- Announce a deprecation at least one minor release before removal.
- Document the replacement in the changelog and in the affected page.
- Keep the old behaviour working during the deprecation window.
- Remove it in the next minor (pre-1.0) or major release.

Deprecated HTTP fields remain accepted until the announced removal.

## Changelog

`CHANGELOG.md` follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/):

- `Unreleased` collects changes before a release.
- Each release has a dated heading and `Added`, `Changed`, `Deprecated`,
  `Removed`, `Fixed` and `Security` sections as needed.
- The changelog ships with the package.

## Documentation versioning

Documentation ships with the package version it describes. When behaviour
changes, update the docs in the same change. Version-specific statements name
the version, for example "since 0.2.0".

## Related

- [Upgrade guide](../bff/upgrade.md)
- [API contract](../api-contract.md)
