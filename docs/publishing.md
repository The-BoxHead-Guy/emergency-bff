# Publishing `dataemergencia/emergency-bff`

This guide covers the first public release: publishing the split repository,
registering the package on Packagist, wiring the webhook, and verifying a clean
install. The monorepo remains the source of truth; the split repository is a
generated mirror and must never be edited directly.

## 0. Prerequisites

- Admin access to `The-BoxHead-Guy/emergency-bff` and to the monorepo.
- A public split repository (Packagist cannot index private GitHub repos for
  free).
- A Packagist account. Sign in with GitHub at <https://packagist.org/login/>.
- A fine-grained GitHub token that can write to the split repository, stored as
  the monorepo Actions secret `EMERGENCY_BFF_SPLIT_TOKEN`.
- A green local run of the package checks.

```bash
cd packages/emergency-bff
composer validate --strict
composer install --no-interaction --prefer-dist
find src examples public -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
```

## 1. Commit and push the monorepo

Commit the package and the two root workflows:

- `.github/workflows/emergency-bff.yml` — package CI in the monorepo.
- `.github/workflows/split-emergency-bff.yml` — publishes the split repository
  when a `php-v*` tag is pushed.

Commit atomically and push to the monorepo default branch. The split workflow
must exist in the tagged commit for the tag push to trigger it.

## 2. Create the split token

1. GitHub → **Settings** → **Developer settings** → **Personal access tokens**
   → **Fine-grained tokens** → **Generate new token**.
2. **Resource owner:** `The-BoxHead-Guy`.
3. **Repository access:** only select repositories → `emergency-bff`.
4. **Permissions:** **Contents: Read and write**. Metadata is added
   automatically.
5. Generate, copy the token, and store it in the monorepo under
   **Settings → Secrets and variables → Actions → New repository secret** with
   the exact name `EMERGENCY_BFF_SPLIT_TOKEN`.

`GITHUB_TOKEN` cannot push to another repository, so the workflow requires this
separate token.

## 3. Publish the first split release

Tag the monorepo and push the tag:

```bash
git tag php-v0.1.0
git push origin php-v0.1.0
```

The workflow derives the split tag by replacing `php-` with nothing, so
`php-v0.1.0` becomes `v0.1.0`.

Watch the run under **Actions → Split emergency-bff**. When it finishes:

```bash
git ls-remote --heads --tags git@github.com:The-BoxHead-Guy/emergency-bff.git
```

Expected refs:

- `refs/heads/main`
- `refs/tags/v0.1.0`

If GitHub created the empty repository with a different default branch, open
the split repository → **Settings → Branches** and set **main** as the default
branch before registering the package.

Sanity-check the mirror:

```bash
git clone https://github.com/The-BoxHead-Guy/emergency-bff.git /tmp/emergency-bff-check
head -5 /tmp/emergency-bff-check/composer.json
cd /tmp/emergency-bff-check && git tag --list
```

The repository root must contain `composer.json` with the name
`dataemergencia/emergency-bff` and no `version` field.

### Manual split without GitHub Actions minutes

GitHub Actions is only a convenience. The split repository can be published
from the local machine over SSH with the checked-in script:

```bash
cd packages/emergency-bff
bash scripts/split.sh 0.1.0          # dry run: clone, mirror, show changes
bash scripts/split.sh 0.1.0 --push   # commit, push main, push v0.1.0
```

The script refuses to run when the package has uncommitted or untracked files,
runs `composer validate --strict`, `php -l` and PHPUnit, builds the mirror from
the committed tree with `git archive` (so `vendor/`, `composer.lock` and caches
can never leak), prints the exact changes, and only pushes after `--push` plus
confirmation. It also creates the local monorepo tag `php-v0.1.0` without
pushing it.

The raw equivalent, if the script cannot be used:

```bash
cd /home/jhon-p/monorepos/dataemergencia

TAG=v0.1.0
rm -rf /tmp/emergency-bff-split
git clone git@github.com:The-BoxHead-Guy/emergency-bff.git /tmp/emergency-bff-split

rsync -a --delete \
  --exclude '.git' \
  --exclude 'vendor' \
  --exclude 'composer.lock' \
  --exclude '.phpunit.cache' \
  --exclude 'examples/vanilla-php/vendor' \
  packages/emergency-bff/ /tmp/emergency-bff-split/

cd /tmp/emergency-bff-split
git config user.name "Your Name"
git config user.email "you@example.com"
git add -A
git commit -m "release: $TAG"
git push origin HEAD:main
git tag -f "$TAG"
git push --force origin "$TAG"
```

Cloning an empty repository succeeds and leaves an unborn branch; the first
commit followed by `git push origin HEAD:main` creates `main`. If the split
repository already exists, the clone contains its history and this replaces the
working tree with the current package.

This consumes no Actions minutes. The split repository's own CI workflow may
still run on the push; if the quota is exhausted, disable Actions in the split
repository under **Settings → Actions → General** and run the same checks
locally (the script already runs the BFF checks before pushing). Then continue
with Packagist registration.

## 4. Register the package on Packagist

1. Sign in at <https://packagist.org/login/> using GitHub.
2. Open <https://packagist.org/packages/submit>.
3. Enter the exact repository URL:

   ```text
   https://github.com/The-BoxHead-Guy/emergency-bff
   ```

4. Submit. Packagist reads the name from `composer.json`; the resulting package
   URL is <https://packagist.org/packages/dataemergencia/emergency-bff>.
5. Confirm the page lists `v0.1.0` under versions.

If Packagist reports that it cannot find `composer.json`, verify the repository
is public, the file is at the repository root on the default branch, and the
tag has been pushed.

## 5. Configure the update webhook

Packagist needs the webhook to index future tags automatically.

Preferred path: when Packagist is connected to GitHub, submitting the package
configures the webhook via the GitHub API. Confirm it exists in the split
repository under **Settings → Webhooks**; the payload URL looks like:

```text
https://packagist.org/api/github?username=<your-packagist-username>
```

If it is missing, add it manually:

1. Split repository → **Settings → Webhooks → Add webhook**.
2. **Payload URL:** `https://packagist.org/api/github?username=<your-packagist-username>`
   — use your Packagist username, not the GitHub organization.
3. **Content type:** `application/json`.
4. **Secret:** the API token / webhook secret shown on your Packagist profile.
5. **SSL verification:** enabled.
6. **Events:** just the push event.
7. **Active:** checked.

Then open the Packagist package page and click **Update** (or **Check for
updates**) to force the first refresh. Future `v*` tags are indexed on push.

## 6. Verify a clean install

In a fresh directory, require the release from Packagist:

```bash
mkdir -p /tmp/emergency-bff-consumer && cd /tmp/emergency-bff-consumer
composer init --no-interaction --name=example/consumer
composer require dataemergencia/emergency-bff:^0.1
composer show dataemergencia/emergency-bff
```

Expected: the resolved version is `0.1.0`, sourced from the split repository.
Confirm the autoloader works:

```bash
php -r 'require "vendor/autoload.php"; var_dump(class_exists(DataEmergencia\EmergencyBff\Support\Bootstrap::class));'
```

Expected: `bool(true)`.

## 7. Enable CI in the split repository

The first split publishes `.github/workflows/ci.yml`. In the split repository,
open **Actions**, enable workflows if GitHub prompts for it, and confirm the CI
run is green. That workflow validates Composer metadata, installs dependencies,
runs `php -l`, and executes PHPUnit.

## 8. Future releases

1. Merge the change in the monorepo.
2. Push a new monorepo tag, for example `php-v0.1.1`.
3. The split workflow mirrors `main` and pushes `v0.1.1`.
4. The Packagist webhook indexes the tag; no manual action is normally needed.

Never edit the split repository directly: the next run uses `rsync --delete`
and overwrites it. Never add a `version` field to `composer.json`; Packagist
derives versions from Git tags.

## Troubleshooting

| Symptom                                                    | Check                                                                                                                           |
| ---------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------- |
| Split workflow fails with `403` or authentication error    | `EMERGENCY_BFF_SPLIT_TOKEN` exists, is not expired, and has **Contents: Read and write** on the split repository.               |
| Split repository has no `main` branch                      | The first workflow run failed before pushing; re-run it and check the logs.                                                     |
| Packagist page has no versions                             | Tag `v0.1.0` exists in the split repo, the default branch contains `composer.json`, then click **Update** on Packagist.         |
| Webhook deliveries fail                                    | Payload URL uses the Packagist username and the secret matches the profile token.                                               |
| `composer require` finds the package but no `^0.1` version | The split tag is missing or malformed; tags must look like `v0.1.0`.                                                            |
| Packagist shows the wrong package name                     | The `name` field in the split repository's `composer.json` is authoritative; fix it in the monorepo source before the next tag. |
