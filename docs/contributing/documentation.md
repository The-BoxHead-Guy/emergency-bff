# Documentation standards

This page records the fixed identities, reader personas, information
architecture, tooling and versioning policy for the emergency module docs. It is
the source of truth for both documentation sets.

## Fixed identities

| Item                 | Value                             |
| -------------------- | --------------------------------- |
| npm package          | `@dataemergencia/emergency`       |
| Composer package     | `dataemergencia/emergency-bff`    |
| Monorepo client path | `libs/emergency`                  |
| Monorepo BFF path    | `packages/emergency-bff`          |
| Public client repo   | `The-BoxHead-Guy/emergency`       |
| Public BFF repo      | `The-BoxHead-Guy/emergency-bff`   |
| Initial version      | `0.1.0`                           |
| BFF route prefix     | `/de-bff/emergency`               |
| Identity code format | Six characters, first is a letter |

Both packages are public and version themselves independently. The monorepo is
the documentation source; each public split repository mirrors its package
directory to the repository root, so `packages/emergency-bff/docs` becomes
`docs/` in `The-BoxHead-Guy/emergency-bff` and `libs/emergency/docs` becomes
`docs/` in `The-BoxHead-Guy/emergency`.

## Reader personas

1. **Vanilla PHP integrator (primary).** Owns a classic PHP application with a
   front controller and native sessions. Needs Composer, environment variables,
   Apache/Nginx guidance, and a no-build browser example.
2. **Laravel integrator (secondary).** Owns a Laravel application. Needs the
   session/CSRF adapter contract, route registration and the npm/ESM client.
3. **JavaScript/React integrator (secondary).** Owns a React application that
   consumes the BFF over HTTP. Needs the npm client, mount options, typed events,
   theming, accessibility and browser support.

## Information architecture

The approved order is:

1. Overview
2. Quickstart
3. BFF configuration
4. Client configuration
5. API contract
6. Examples
7. Operations
8. Migration
9. Release and deployment
10. Troubleshooting

Every page starts with the persona and prerequisite it applies to. Shared
material (API contract, zero-to-panel, migration) is authored once in the BFF
docs and linked from the client docs through its public GitHub URL.

## Tooling

Documentation is plain Markdown. No static-site generator is used in the MVP.

| Purpose           | Command              |
| ----------------- | -------------------- |
| Markdown lint     | `pnpm docs:lint`     |
| Link check        | `pnpm docs:links`    |
| Runnable snippets | `pnpm docs:snippets` |
| Everything        | `pnpm docs:verify`   |

`markdownlint-cli2` is configured by `.markdownlint-cli2.jsonc` at the monorepo
root. The link checker validates relative links, anchors and image paths; it
does not depend on network access. The snippet runner executes fenced blocks
whose info string ends with `runnable`, for example:

````text
```bash runnable
php -v
```
````

Runnable snippets must be self-contained, must not require production
credentials, and must finish quickly. A snippet that cannot run unattended is a
snippet that must not be marked `runnable`.

## Versioning policy

- Docs are versioned with the package release they describe.
- The quickstart is tested against the latest published tag before each release.
- A breaking API change requires a matching docs change in the same release.
- Deprecations are announced one minor version before removal.
- Version-specific statements name the version, for example "since 0.2.0".

## Language

Documentation is written in English. Product UI copy may be Spanish, because
the MVP ships Spanish copy; document quoted UI strings exactly as they appear.
