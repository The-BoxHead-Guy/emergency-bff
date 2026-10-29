# Upgrade guide

Applies to: integrators upgrading between releases.

## Versioning

The BFF follows Semantic Versioning.

- `0.x` releases may break between minor versions. Read the changelog before
  upgrading.
- `1.0.0` freezes the public HTTP contract and the `Config`/`Bootstrap` entry
  points.
- Patch releases fix behavior without changing request or response shapes.

The package version is published from the Git tag `v<version>`.

## Compatibility

| BFF version | PHP  | Notes                                       |
| ----------- | ---- | ------------------------------------------- |
| `0.1.x`     | 8.4+ | MVP public channels.                        |
| `0.2.x`     | 8.4+ | Reserved for breaking changes before `1.0`. |

## Upgrading a minor release

1. Read `CHANGELOG.md`.
2. Back up your session store if you changed its layout.
3. Run:

   ```bash
   composer update dataemergencia/emergency-bff --with-dependencies
   ```

4. Compare your environment with [Configuration](configuration.md). New optional
   variables get safe defaults; new required variables fail fast at boot.
5. Run your smoke test:

   ```bash
   curl -fsS https://your-host/de-bff/emergency/health
   ```

6. Start a session with a test code and confirm `GET /current` returns
   `active: false`.
7. Activate against a staging code and confirm the WhatsApp payload in a test
   contact.

## Breaking changes

A breaking change is:

- removing or renaming a route, request field or response field,
- changing an error code,
- changing session key semantics or cookie behavior,
- raising the PHP minimum,
- changing the default route prefix.

Breaking changes require a minor bump before `1.0` and a major bump after. The
changelog names the migration step and the affected version.

## Deprecation policy

- A field or route is deprecated in one minor release and removed no earlier
  than the next minor (pre-`1.0`) or the next major (post-`1.0`).
- Deprecated behavior keeps working during the deprecation window.
- Docs mark deprecations with the version in which they were introduced.

## Rollback

1. Reinstall the previous version:
   `composer require dataemergencia/emergency-bff:<previous>`.
2. Restart PHP-FPM so opcache and workers reload.
3. If a session key changed, clear the BFF session cookie so the browser starts
   a fresh session.
4. Confirm `/health` and `/config`.

There is no database migration in the BFF, so rollback is a code and session
operation only.

## Next step

[API contract](../api-contract.md).
