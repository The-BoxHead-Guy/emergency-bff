# Documentation verification log

This page records the last verification run of both documentation sets. Repeat it
before every release and append the new date.

## Run: 2026-10-28 (0.1.0 documentation)

| Check                      | Command                                                                                                                                              | Result                                                                                                                                |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Markdown lint              | `pnpm docs:lint`                                                                                                                                     | 56 files, 0 issues                                                                                                                    |
| Relative links and anchors | `pnpm docs:links`                                                                                                                                    | 56 files, 20 external URLs not fetched, 0 broken                                                                                      |
| Runnable snippets          | `pnpm docs:snippets`                                                                                                                                 | 4 snippets passed (PHP version, Composer version, extensions, package name)                                                           |
| BFF end-to-end smoke       | `examples/vanilla-php/smoke.sh` against the bundled mock API                                                                                         | 12 checks passed: health, config, invalid code, inactive code, session, current, simulacrum, activation, resume, refresh, abort, idle |
| No-build client (IIFE)     | `examples/vanilla` served statically, mock BFF on `:8787`                                                                                            | Panel opened from the SOS button; simulation rendered both responders and a success status                                            |
| Client clean checkout      | rsync of `libs/emergency` without `dist` or `node_modules`, then `pnpm install --lockfile-only`, `pnpm install --frozen-lockfile`, build, `npm pack` | Build and pack passed; tarball installed in an empty project and both `require()` and `import` exposed `mount`                        |
| BFF clean checkout         | rsync of `packages/emergency-bff` without `vendor` or `composer.lock`, then `composer validate --strict`, install, `php -l`, PHPUnit                 | Composer reported a valid `composer.json`; 63 tests, 247 assertions passed                                                            |
| BFF consumer               | empty project with a Composer path repository, then `composer require`                                                                               | `Bootstrap` autoloaded from the installed package                                                                                     |
| Secrets scan               | Search for credential assignments, bearer tokens and private hosts in both docs                                                                      | No secrets or production credentials found                                                                                            |

The clean-checkout runs reproduce the two split workflows locally. The GitHub
Actions runs themselves require the split repositories and tokens, so they are
verified there after the first tag.

## Known gaps

- Neither package is published yet. The quickstart was exercised against the
  bundled mock API and an installed tarball, not against Packagist or npm.
- The Laravel example is a copy-in reference. It is linted by its own
  `check.sh`, but it is not a bootable Laravel application, so it is not part of
  the automated example run.
- The under-15-minute human walkthrough is pending publication. The commands in
  [zero to panel](../onboarding/zero-to-panel.md) were executed end to end
  against the mock API; the timing must be recorded with a developer who has not
  seen the project before, once `composer require` and `npm install` resolve
  from the public registries.
- Actual `0.1.0` publication is recorded in
  [the release checklist](../release/release-checklist.md) only after both
  registries accept the package.
