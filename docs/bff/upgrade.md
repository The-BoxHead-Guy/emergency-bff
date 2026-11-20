# Upgrade guide

Applies to: integrators upgrading between releases.

The BFF is a Composer library with no database, migrations or queues. An upgrade
is a dependency update plus a PHP-FPM reload, and a rollback reverses the same
two steps.

## Before upgrading

1. Read `CHANGELOG.md` for the target version.
2. Back up the session store if its layout or backend changed.
3. Check your environment against [Configuration](configuration.md). New
   optional variables have safe defaults; new required variables fail at boot.

## Upgrade

```bash
composer update dataemergencia/emergency-bff --with-dependencies
```

Reload PHP-FPM so opcache and long-lived workers pick up the new code. Then:

1. Call `GET /health` and `GET /config`.
2. Start a session with a test code and confirm `GET /current` returns
   `active: false`.
3. Activate against a staging code and confirm a test contact receives the
   WhatsApp message, then abort it.

## Rollback

```bash
composer require dataemergencia/emergency-bff:<previous-version>
```

Reload PHP-FPM. If the release changed a session key, clear the BFF session
cookie so the browser starts a fresh session. Then repeat the health, session
and activation checks.

## Breaking changes

A change is breaking when it removes or renames a route, request field or
response field, changes an error code, changes session semantics or cookie
behavior, raises the PHP minimum, or changes the default route prefix. Adding a
field is not breaking.

The version policy and the deprecation window are in
[Versioning](../release/versioning.md).
