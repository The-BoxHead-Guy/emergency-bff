# Verification log

This page records the last verification run of the package documentation and
the release it describes. Repeat it before every release and append the new
date.

## Run: 2026-10-29 (0.1.0 documentation)

| Check                | Command                                                                          | Result                                                                                                        |
| -------------------- | -------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------- |
| Composer metadata    | `composer validate --strict`                                                     | Valid `composer.json`                                                                                         |
| PHP syntax           | `find src examples public -name '*.php' -print0 \| xargs -0 -n1 php -l`          | No syntax errors                                                                                              |
| Test suite           | `composer test`                                                                  | 63 tests, 247 assertions passed                                                                               |
| BFF end-to-end smoke | `examples/vanilla-php/smoke.sh` against the bundled mock API                     | Health, config, invalid code, inactive code, session, current, simulacrum, activation, resume, refresh, abort |
| Packagist install    | Clean project: `composer require dataemergencia/emergency-bff:^0.1`              | `0.1.0` resolved; `Bootstrap` autoloaded                                                                      |
| Documentation links  | Manual relative-link review after removing the release and onboarding checklists | No links to removed pages                                                                                     |

## Known gaps

- The Laravel example is a copy-in reference. Its own `check.sh` validates the
  files, but it is not a bootable Laravel application.
- The human walkthrough timing is not recorded; the commands in
  [zero to panel](../onboarding/zero-to-panel.md) were executed end to end.

## Next run

Append a new dated section after the next release verification. Do not rewrite
previous runs.
