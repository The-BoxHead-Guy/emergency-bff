# Releasing the BFF

Applies to: maintainers. This page is the single source of truth for releasing
`dataemergencia/emergency-bff`. Everything else in this directory explains one
part of this flow.

Release the BFF **before** the client when a change touches the HTTP contract.

## One-time setup

- Repository secret `EMERGENCY_BFF_SPLIT_TOKEN`: a fine-grained token with
  **Contents: read and write** and **Workflows: read and write** on
  `The-BoxHead-Guy/emergency-bff`.
- The split repository registered on Packagist, so a new `vX.Y.Z` tag is picked
  up automatically.
- Permission to push tags to `The-BoxHead-Guy/dataemergencia`.

A classic token with only `repo` is not enough: the mirror updates
`.github/workflows/` in the split repository, and GitHub rejects that push
without the `workflow` scope.

## 1. Choose the version

| Change                                                | Version                  |
| ----------------------------------------------------- | ------------------------ |
| Bug fix, docs, security hardening, no contract change | patch                    |
| Additive feature, new optional field, new route       | minor                    |
| Breaking contract change                              | minor (pre-1.0) or major |

The rules in detail: [Versioning](versioning.md).

## 2. Update the changelog

Move the `Unreleased` entries under the new version with today's date:

```markdown
## [0.1.3] - 2026-11-05

### Fixed

- ...
```

## 3. Do not bump composer.json

`composer.json` intentionally has no `version` field; Packagist derives the
version from the Git tag. `scripts/split.sh` refuses to run if a `version` key
appears. The changelog entry is the only versioned file to edit.

## 4. Verify

From `packages/emergency-bff`:

```bash
composer validate --strict
composer install --no-interaction --prefer-dist
find src examples public -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
```

The pull request check `Monorepo Packages - Verify (emergency-bff)` runs the same
steps.

## 5. Tag and push

Tags are project-scoped so several packages can coexist in one repository:

| Monorepo tag           | Split repository tag |
| ---------------------- | -------------------- |
| `emergency-bff-v0.1.3` | `v0.1.3`             |

```bash
git tag -a emergency-bff-v0.1.3 -m "release: 0.1.3"
git push origin emergency-bff-v0.1.3
```

Pushing the tag runs `.github/workflows/split-emergency-bff.yml`:

1. Validates the tag format and the changelog entry.
2. Mirrors `packages/emergency-bff` to `The-BoxHead-Guy/emergency-bff`.
3. Pushes `main` and force-updates `v0.1.3`.

The mirror excludes `vendor/` and `composer.lock`, so consumers resolve their own
dependency set.

## 6. Confirm Packagist

Packagist reads the split repository, not the monorepo. After the workflow
finishes:

1. Open `https://packagist.org/packages/dataemergencia/emergency-bff`.
2. Confirm the new version is listed.
3. If it is missing, check the package's GitHub webhook and trigger a manual
   update on Packagist.

## 7. Verify the release

```bash
composer show dataemergencia/emergency-bff --all | head -5
```

Then install in a clean directory:

```bash
mkdir -p /tmp/emergency-bff-consumer && cd /tmp/emergency-bff-consumer
composer require dataemergencia/emergency-bff:^0.1
```

## Manual fallback

When Actions is unavailable, `scripts/split.sh` performs the same mirror locally:

```bash
cd packages/emergency-bff
bash ./scripts/split.sh 0.1.3          # dry run: mirror and review
bash ./scripts/split.sh 0.1.3 --push   # commit, push main and v0.1.3
```

## Troubleshooting

| Symptom                                                                                             | Cause                                                           | Fix                                                                                       |
| --------------------------------------------------------------------------------------------------- | --------------------------------------------------------------- | ----------------------------------------------------------------------------------------- |
| `Invalid username or token. Password authentication is not supported`                               | Secret empty, expired, revoked, or not scoped to the split repo | Re-create the token, update the secret, re-run the workflow                               |
| `refusing to allow a Personal Access Token to create or update workflow ... without workflow scope` | Token may push contents but not workflow files                  | Add `workflow` to a classic token, or `Workflows: read and write` to a fine-grained token |
| `Tag must look like emergency-bff-vX.Y.Z`                                                           | Wrong tag name                                                  | Delete the local tag and tag again with the project-scoped prefix                         |
| `CHANGELOG.md has no '## [X.Y.Z]' entry`                                                            | Changelog not updated before tagging                            | Add the entry, commit, then tag the commit                                                |
| New tag missing on Packagist                                                                        | Webhook not configured or not delivered                         | Check the Packagist webhook, then trigger a manual update                                 |

## Next step

- [Versioning](versioning.md) for compatibility with the client.
