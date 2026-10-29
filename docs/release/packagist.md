# Packagist

`dataemergencia/emergency-bff` is published on Packagist from the public split
repository `The-BoxHead-Guy/emergency-bff`. Packagist derives versions from Git
tags; the package has no `version` field in `composer.json`.

## Requirements

The split repository must contain, at its root:

- `composer.json` with `"name": "dataemergencia/emergency-bff"`.
- A `LICENSE` file that matches the `license` field (`MIT`).
- Autoload metadata for `DataEmergencia\EmergencyBff\`.
- At least one tag matching `vX.Y.Z`.
- Public visibility. Packagist cannot index a private repository on the free
  plan.

Do not add a `version` field. If you do, Packagist may report a version that
does not match the tag.

## Register the package

1. Sign in at <https://packagist.org/login/> with GitHub.
2. Open <https://packagist.org/packages/submit>.
3. Submit the repository URL:

   ```text
   https://github.com/The-BoxHead-Guy/emergency-bff
   ```

4. Packagist reads the package name from `composer.json`.
5. Confirm the package page lists the release tag, for example `v0.1.0`.

If submission fails, check that the repository is public, the default branch
contains `composer.json`, and the tag exists.

## Webhook

Packagist needs a push webhook to index future tags automatically.

When Packagist is connected to GitHub, submission normally configures it. In
the split repository, open **Settings → Webhooks** and confirm a payload URL
like:

```text
https://packagist.org/api/github?username=<packagist-username>
```

If it is missing, add it manually:

| Field            | Value                                                            |
| ---------------- | ---------------------------------------------------------------- |
| Payload URL      | `https://packagist.org/api/github?username=<packagist-username>` |
| Content type     | `application/json`                                               |
| Secret           | The API token shown on the Packagist profile                     |
| SSL verification | Enabled                                                          |
| Events           | Push only                                                        |
| Active           | Yes                                                              |

Then click **Update** on the Packagist package page to force the first
refresh.

## Tag mapping

The monorepo tag `php-v0.1.0` becomes the split tag `v0.1.0`. Packagist
normalises the leading `v` and exposes the version as `0.1.0`.

| Monorepo tag | Split tag | Composer constraint |
| ------------ | --------- | ------------------- |
| `php-v0.1.0` | `v0.1.0`  | `^0.1`              |
| `php-v0.1.1` | `v0.1.1`  | `^0.1`              |
| `php-v0.2.0` | `v0.2.0`  | `^0.2`              |

For pre-1.0 releases, `^0.1` allows `0.1.x` but not `0.2.0`. That is the
intended behaviour while the contract is still stabilising.

## Install and verify

```bash
composer require dataemergencia/emergency-bff:^0.1
composer show dataemergencia/emergency-bff
php -r 'require "vendor/autoload.php"; var_dump(class_exists(DataEmergencia\EmergencyBff\Support\Bootstrap::class));'
```

Expected: the resolved version is `0.1.x` and the class check prints
`bool(true)`.

## Publishing a new version

1. Merge the change in the monorepo.
2. Confirm `composer validate --strict` passes.
3. Update `CHANGELOG.md`.
4. Push a monorepo tag, for example `php-v0.1.1`.
5. The split workflow mirrors the package and pushes split tag `v0.1.1`.
6. Packagist indexes the tag through the webhook.
7. Verify with a clean `composer require` in a temporary directory.

If the webhook did not fire, click **Update** on Packagist before assuming the
release failed.

## Troubleshooting

| Symptom                                    | Check                                                                                            |
| ------------------------------------------ | ------------------------------------------------------------------------------------------------ |
| Packagist cannot find `composer.json`      | Repository is public, file is at the root, default branch is `main`.                             |
| No versions listed                         | Split tag `vX.Y.Z` exists and points at the commit containing `composer.json`; click **Update**. |
| Wrong package name                         | Fix `name` in the monorepo, re-tag, let the split overwrite the mirror.                          |
| `composer require` resolves an old version | Run `composer clear-cache`, then `composer show -a dataemergencia/emergency-bff`.                |
| Webhook deliveries fail                    | Payload URL uses the Packagist username and the secret matches the profile token.                |
| Version conflict                           | Never reuse a published version. Publish a new patch.                                            |
| `composer validate --strict` fails         | Fix metadata before tagging; the split CI runs the same command.                                 |

## Related

- [Split repositories](split-repositories.md)
- [Versioning](versioning.md)
- [Release checklist](release-checklist.md)
- [First publish walkthrough](../publishing.md)
