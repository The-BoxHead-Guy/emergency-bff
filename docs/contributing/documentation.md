# Documentation standards

This page records the identities, reader personas, information architecture and
versioning policy for the package documentation.

## Identities

| Item                 | Value                             |
| -------------------- | --------------------------------- |
| Composer package     | `dataemergencia/emergency-bff`    |
| Repository           | `The-BoxHead-Guy/emergency-bff`   |
| Initial version      | `0.1.0`                           |
| BFF route prefix     | `/de-bff/emergency`               |
| Identity code format | Six characters, first is a letter |

## Reader personas

1. **PHP integrator (primary).** Owns a PHP application with a front controller
   and native sessions. Needs Composer, environment variables and a front
   controller recipe.
2. **Framework integrator (secondary).** Owns a Laravel or similar application.
   Needs the session/CSRF adapter contract, route registration and deployment
   guidance.
3. **API integrator (secondary).** Calls the JSON API from a frontend. Needs the
   route contract and error envelope; the recommended browser library is
   documented in its own repository.

## Information architecture

1. Overview
2. Quickstart
3. Configuration
4. API contract
5. Examples
6. Operations
7. Migration
8. Upgrade and versioning
9. Troubleshooting

Every page starts with the persona and prerequisite it applies to. The
recommended browser library is linked, not duplicated: its own repository is
the source of truth for its documentation.

## Tooling

Documentation is plain Markdown. No static-site generator is used. Before
publishing a release, verify relative links and anchors with your Markdown
tooling of choice; there is no package-level generator to run.

## Versioning policy

- Docs are versioned with the package release they describe.
- The quickstart is tested against the latest published tag before each release.
- A breaking API change requires a matching docs change in the same release.
- Deprecations are announced one minor version before removal.
- Version-specific statements name the version, for example "since 0.2.0".

## Language

Documentation is written in English. Product UI copy may be Spanish; document
quoted UI strings exactly as they appear.
