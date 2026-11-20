# Releasing the BFF

Applies to: maintainers. This is the single source of truth for releasing
`dataemergencia/emergency-bff`.

Release the BFF before the client when a change touches the HTTP contract.

## One-time setup

- Repository secret `EMERGENCY_BFF_SPLIT_TOKEN`: a fine-grained token with
  **Contents: read and write** and **Workflows: read and write** on
  `The-BoxHead-Guy/emergency-bff`. A classic token needs the `workflow` scope too,
  because the mirror updates workflow files.
- The split repository registered on Packagist, so a new `vX.Y.Z` tag is picked
  up automatically.
- Permission to push tags to `The-BoxHead-Guy/dataemergencia`.

## 1. Choose the version

Use [Versioning](versioning.md): patch for fixes and docs, minor for additive
features and pre-1.0 breaking changes, major for breaking changes after `1.0.0`.

## 2. Update the changelog

Move the `Unreleased` entries under the new version with today's date:

```markdown
## [0.5.1] - 2026-11-20

### Fixed

- ...
```

Do not add a `version` field to `composer.json`. Packagist derives the version
from the Git tag, and `scripts/split.sh` refuses to run if the field appears.
The changelog entry is the only versioned file to edit.

## 3. Verify

From `packages/emergency-bff`:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
find src examples public -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
```

The pull request check `Monorepo Packages - Verify (emergency-bff)` runs the same
steps.

## 4. Tag and push

Tags are project-scoped so several packages can coexist in one repository:

| Monorepo tag           | Split repository tag |
| ---------------------- | -------------------- |
| `emergency-bff-v0.5.1` | `v0.5.1`             |

```bash
git tag -a emergency-bff-v0.5.1 -m "release: 0.5.1"
git push origin emergency-bff-v0.5.1
```

Pushing the tag runs `.github/workflows/split-emergency-bff.yml`:

1. Validates the tag format and the changelog entry.
2. Mirrors `packages/emergency-bff` to `The-BoxHead-Guy/emergency-bff`.
3. Pushes `main` and force-updates the split tag.

The mirror excludes `vendor/` and `composer.lock`, so consumers resolve their own
dependency set.

## 5. Confirm Packagist

Packagist reads the split repository, not the monorepo. After the workflow
finishes:

1. Open `https://packagist.org/packages/dataemergencia/emergency-bff`.
2. Confirm the new version is listed.
3. If it is missing, check the package's GitHub webhook and trigger a manual
   update on Packagist.

## 6. Verify the release

```bash
composer show dataemergencia/emergency-bff --all | head -5
```

Then install in a clean directory:

```bash
mkdir -p /tmp/emergency-bff-consumer && cd /tmp/emergency-bff-consumer
composer require dataemergencia/emergency-bff:^0.5
```

## Manual fallback

When Actions is unavailable, `scripts/split.sh` performs the same mirror locally:

```bash
cd packages/emergency-bff
bash ./scripts/split.sh 0.5.1          # dry run: mirror and review
bash ./scripts/split.sh 0.5.1 --push   # commit, push main and the split tag
```

## Troubleshooting

| Symptom                                                                                             | Cause                                                           | Fix                                                                                       |
| --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `Invalid username or token. Password authentication is not supported`                               | Secret empty, expired, revoked, or not scoped to the split repo | Re-create the token, update the secret, re-run the workflow                               |
| `refusing to allow a Personal Access Token to create or update workflow ... without workflow scope` | Token may push contents but not workflow files                  | Add `workflow` to a classic token, or `Workflows: read and write` to a fine-grained token |
| `Tag must look like emergency-bff-vX.Y.Z`                                                           | Wrong tag name                                                  | Delete the local tag and tag again with the project-scoped prefix                         |
| `CHANGELOG.md has no '## [X.Y.Z]' entry`                                                            | Changelog not updated before tagging                            | Add the entry, commit, then tag the commit                                                |
| New tag missing on Packagist                                                                        | Webhook not configured or not delivered                         | Check the Packagist webhook, then trigger a manual update                                 |
