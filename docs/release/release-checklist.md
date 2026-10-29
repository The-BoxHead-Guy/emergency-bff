# Release checklist

This is the maintainer checklist for a coordinated release. Release the BFF
first, then the client. Every command runs from the monorepo root unless stated
otherwise.

Never paste tokens, API keys or registry credentials into a ticket, a commit or
this document. Use the secret names, not their values.

## 0. Preconditions

- [ ] `git status --short` is clean on the release commit.
- [ ] The version is recorded in both `CHANGELOG.md` files if both packages
      change.
- [ ] The docs for the released behaviour are merged.
- [ ] No known release-blocking issue is open for the package.
- [ ] The split repository is public and its default branch is `main`.
- [ ] `EMERGENCY_BFF_SPLIT_TOKEN` and `EMERGENCY_CLIENT_SPLIT_TOKEN` are valid.

## 1. Verify the BFF from source

```bash
cd packages/emergency-bff
composer validate --strict
composer install --no-interaction --prefer-dist
find src examples public -name '*.php' -print0 | xargs -0 -n1 php -l
vendor/bin/phpunit
```

- [ ] All commands pass.
- [ ] `composer.json` has no `version` field.
- [ ] The package name, license and support URLs are correct.

## 2. Verify the client from source

Run from the monorepo root:

```bash
cd libs/emergency
pnpm install --frozen-lockfile
pnpm run typecheck
pnpm run test
pnpm run build
npm pack --dry-run
```

- [ ] Typecheck, tests and build pass.
- [ ] The tarball contains `dist/index.js`, `dist/index.cjs`, declarations,
      `dist/styles.css` and `dist/iife/emergency.iife.global.js`.
- [ ] The IIFE gzip size is at most 150 KiB.
- [ ] `package.json` version matches the release version.
- [ ] The no-build example still works with the built IIFE.

## 3. Build and inspect the client tarball

```bash
cd libs/emergency
npm pack --dry-run
```

Inspect the file list. The package must not contain `node_modules`, source
tests outside `dist`, local paths or unpublished examples that reference the
monorepo.

For the first `0.1.0` release, publish manually with npm 2FA:

```bash
bash ./scripts/release.sh 0.1.0 --publish
```

For later releases, use Trusted Publishing from the split repository. See
[npm](https://github.com/The-BoxHead-Guy/emergency/blob/v0.1.0/docs/release/npm.md).

## 4. Tag the BFF

```bash
git tag php-v0.1.0
git push origin php-v0.1.0
```

- [ ] The **Split emergency-bff** workflow succeeds.
- [ ] The split repository contains tag `v0.1.0`.
- [ ] The split repository has no `vendor/` directory and no `composer.lock`.
- [ ] Packagist lists `0.1.0`.

## 5. Verify the BFF from a clean consumer

```bash
mkdir -p /tmp/emergency-bff-consumer && cd /tmp/emergency-bff-consumer
composer init --no-interaction --name=example/consumer
composer require dataemergencia/emergency-bff:^0.1
php -r 'require "vendor/autoload.php"; var_dump(class_exists(DataEmergencia\EmergencyBff\Support\Bootstrap::class));'
```

- [ ] Composer resolves the published version.
- [ ] The autoloader finds `Bootstrap`.
- [ ] `composer show` reports the expected version and license.

## 6. Tag the client

```bash
git tag npm-v0.1.0
git push origin npm-v0.1.0
```

- [ ] The **Split emergency client** workflow succeeds.
- [ ] The split repository contains tag `v0.1.0`.
- [ ] The split repository has a standalone `pnpm-lock.yaml`.
- [ ] The split repository does not contain `dist/`, `node_modules/` or the
      copied example bundle.

## 7. Verify the client from a clean consumer

```bash
mkdir -p /tmp/emergency-client-consumer && cd /tmp/emergency-client-consumer
npm init -y
npm install @dataemergencia/emergency@0.1.0
node -e "const cjs = require('@dataemergencia/emergency'); console.log(typeof cjs.mount);"
```

- [ ] The install succeeds.
- [ ] `mount` is a function.
- [ ] `@dataemergencia/emergency/styles.css` resolves.
- [ ] The IIFE file exists and is reachable through the package `unpkg` field.

## 8. Verify the split CI

- [ ] BFF split CI passes a clean checkout: `composer validate --strict`,
      install, lint, PHPUnit.
- [ ] Client split CI passes a clean checkout: frozen install, typecheck, test,
      build, `npm pack --dry-run`.
- [ ] Neither workflow depends on files outside the split repository.

## 9. Update the docs

- [ ] The pinned version links in both documentation sets point at the new
      tags.
- [ ] The compatibility matrix is current.
- [ ] The changelogs are published in the split repositories.
- [ ] The onboarding quickstart was re-run against the new version.

## 10. Announce and monitor

- [ ] Release notes state the version, breaking changes and minimum
      compatible counterpart.
- [ ] A clean consumer install was tested after publication, not before.
- [ ] Registry pages show the expected version and provenance (npm).
- [ ] Support has the request IDs and error codes for the release.

## Rollback

Registry versions cannot be overwritten.

- **Bad BFF release:** publish a patch with the fix. If the issue is severe,
  deprecate the affected version on Packagist or advise a pinned previous
  version in the release notes.
- **Bad client release:** publish a patch, or `npm deprecate` the affected
  version with a message pointing at the fixed one.
- **Bad split mirror:** fix the monorepo and re-run the split for the same
  monorepo tag. The split tag is force-updated, but an already published
  registry version is not.
- **Operational rollback:** deploy the previous package version and keep the
  session storage intact until its TTL expires.

## Related

- [Split repositories](split-repositories.md)
- [Versioning](versioning.md)
- [Packagist](packagist.md)
- [Client release checklist](https://github.com/The-BoxHead-Guy/emergency/blob/v0.1.0/docs/release/release-checklist.md)
