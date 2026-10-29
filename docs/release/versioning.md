# Versioning

The BFF (`dataemergencia/emergency-bff`) and the client
(`@dataemergencia/emergency`) are versioned independently. Both start at
`0.1.0`. A release of one package does not require a release of the other, but
the HTTP contract is the compatibility boundary between them.

## Semantic versioning

Before `1.0.0`, the packages follow the usual pre-1.0 convention:

| Change                                                                             | Version                                 |
| ---------------------------------------------------------------------------------- | --------------------------------------- |
| Bug fix, documentation, security hardening without API change                      | patch                                   |
| Additive feature, new optional field, new route                                    | minor                                   |
| Breaking change to routes, request/response shapes, mount options or CSS variables | minor (pre-1.0) or major (from `1.0.0`) |

Once a package reaches `1.0.0`, a breaking change requires a major version.

Adding a new field to a JSON response is not breaking. Removing a field,
renaming a field, changing a status code, changing an error `code` or changing
the meaning of an existing field is breaking.

## Compatibility matrix

| BFF     | Client  | Status                                                |
| ------- | ------- | ----------------------------------------------------- |
| `0.1.x` | `0.1.x` | Supported                                             |
| `0.1.x` | `0.2.x` | Evaluate per release; contract changes are documented |
| `0.2.x` | `0.1.x` | Not supported unless the release notes say otherwise  |

The contract is documented in [API contract](../api-contract.md). A client
release that requires a newer BFF must say so in its changelog and release
notes.

## Tags

| Event          | Monorepo tag | Split tag | Registry  |
| -------------- | ------------ | --------- | --------- |
| BFF release    | `php-vX.Y.Z` | `vX.Y.Z`  | Packagist |
| Client release | `npm-vX.Y.Z` | `vX.Y.Z`  | npm       |

The prefix identifies which split workflow runs. The split tag drops the
prefix because each public repository has its own namespace.

## Deprecation

- Announce a deprecation at least one minor release before removal.
- Document the replacement in the changelog and in the affected page.
- Keep the old behaviour working during the deprecation window.
- Remove it in the next minor (pre-1.0) or major release.

Deprecated HTTP fields remain accepted by the BFF until the announced removal.
Deprecated client options remain accepted and emit a console warning when
practical.

## Changelogs

Both packages keep `CHANGELOG.md` in [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
format:

- `Unreleased` collects changes before a release.
- Each release has a dated heading and `Added`, `Changed`, `Deprecated`,
  `Removed`, `Fixed` and `Security` sections as needed.
- The changelog is part of the split repository and is published with the
  package.

The BFF changelog is `packages/emergency-bff/CHANGELOG.md`. The client changelog
is `libs/emergency/CHANGELOG.md`.

## Documentation versioning

Documentation ships with the package version it describes. The public
repository tag `vX.Y.Z` must contain the docs for that release, including the
quickstart that was tested against it. When behaviour changes, update the docs
in the same pull request.

Links in the documentation that point at the other package should pin the
matching release tag, for example
`https://github.com/The-BoxHead-Guy/emergency/blob/v0.1.0/docs/client/quickstart.md`,
so a reader on an old tag does not land on incompatible instructions.

## Release order

For a coordinated contract change:

1. Release the BFF first.
2. Verify the new BFF against the previous client when the change is
   backwards compatible.
3. Release the client.
4. Update the compatibility matrix.

If the change is breaking for clients, the client release notes must state the
minimum BFF version.

## Related

- [Split repositories](split-repositories.md)
- [Release checklist](release-checklist.md)
- [Packagist](packagist.md)
