# Split repositories

Both emergency packages are developed in this monorepo and published to a
public GitHub repository. The monorepo is the single source of truth; the
split repository is a generated mirror and must never be edited by hand.

## Mapping

| Item   | Monorepo source          | Public split repository                            |
| ------ | ------------------------ | -------------------------------------------------- |
| BFF    | `packages/emergency-bff` | `https://github.com/The-BoxHead-Guy/emergency-bff` |
| Client | `libs/emergency`         | `https://github.com/The-BoxHead-Guy/emergency`     |

The split repository root is the package root. That is why `composer.json` and
`package.json` sit at the root of their respective public repositories, and
why the documentation directories mirror to `docs/`.

## How the mirror is produced

The monorepo workflows:

- `.github/workflows/split-emergency-bff.yml`
- `.github/workflows/split-emergency-client.yml`

Each workflow:

1. Clones the split repository with a fine-grained token stored as a monorepo
   secret (`EMERGENCY_BFF_SPLIT_TOKEN` or
   `EMERGENCY_CLIENT_SPLIT_TOKEN`).
2. Runs `rsync -a --delete` from the package directory to the split checkout.
3. Regenerates a standalone lockfile for the client.
4. Commits the mirror on `main`.
5. Pushes the split tag.

`--delete` is intentional. A file removed from the package disappears from
the split repository on the next release.

## What is copied

BFF exclusions:

- `.git`
- `vendor`
- `composer.lock`
- `examples/vanilla-php/vendor`

Client exclusions:

- `.git`
- `node_modules`
- `dist`
- `examples/vanilla/emergency.global.js`

Everything else is copied, including `src/`, `tests/`, `examples/`, `docs/`,
`.github/workflows/`, `README.md`, `CHANGELOG.md`, `LICENSE` and package
metadata.

The client split regenerates `pnpm-lock.yaml` with `pnpm install
--lockfile-only` because the monorepo lockfile covers the whole workspace and
is meaningless in a standalone repository. The BFF deliberately does not ship
`composer.lock`; libraries should let consumers resolve dependencies.

## Tag conventions

| Package | Monorepo tag | Split tag |
| ------- | ------------ | --------- |
| BFF     | `php-vX.Y.Z` | `vX.Y.Z`  |
| Client  | `npm-vX.Y.Z` | `vX.Y.Z`  |

The split workflow derives the split tag by removing the package prefix. Tags
are created in both places so the monorepo records what was released and the
public repository has the tag Packagist and npm expect.

Pushing a tag is the release trigger. Do not create the split tag manually
unless the workflow cannot run and you are following the manual fallback.

## Read-only policy

- Never commit to the split repository.
- Never create a release branch there.
- Never edit `composer.json`, `package.json`, workflows or docs there.
- Fix the monorepo source, then tag again.

Because tags are force-updated by the workflow (`git tag -f`), a mistagged
release can be corrected by re-running the split for the same monorepo tag.
Registries do not allow overwriting an existing published version, so a bad
registry artifact needs a new patch version, not a moved tag.

## Before tagging

1. The package works in the monorepo and its example passes.
2. `CHANGELOG.md` has an entry for the version.
3. Package metadata is correct: name, license, repository, keywords, `files`
   for npm, autoload for Composer.
4. No secrets, local paths, vendor directories or build output are committed.
5. The split workflow file exists in the tagged commit.
6. The split repository is public and its default branch is `main`.

## Clean-checkout verification

A mirror is not proven until it builds from a clean clone.

BFF:

```bash
git clone https://github.com/The-BoxHead-Guy/emergency-bff.git /tmp/emergency-bff-check
cd /tmp/emergency-bff-check
composer validate --strict
composer install --no-interaction --prefer-dist
vendor/bin/phpunit
```

Client:

```bash
git clone https://github.com/The-BoxHead-Guy/emergency.git /tmp/emergency-client-check
cd /tmp/emergency-client-check
pnpm install --frozen-lockfile
pnpm run typecheck
pnpm run test
pnpm run build
npm pack --dry-run
```

Run the commands in a clean environment, not in the monorepo with its shared
`node_modules`.

## Permissions

The split tokens are fine-grained GitHub personal access tokens:

- Resource owner: the account that owns the split repositories.
- Repository access: only the corresponding split repository.
- Permission: **Contents: Read and write**.
- Stored as an Actions secret in the monorepo, never in a file.

`GITHUB_TOKEN` cannot push to a different repository, so a separate token is
required. Rotate the token before it expires and update the secret.

## Related

- [Versioning](versioning.md)
- [Packagist](packagist.md)
- [npm](https://github.com/The-BoxHead-Guy/emergency/blob/v0.1.0/docs/release/npm.md)
- [Release checklist](release-checklist.md)
- [First publish walkthrough](../publishing.md)
