# Changelog

All notable changes to `dataemergencia/emergency-bff` are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.1.0] - 2026-10-28

### Added

- Composer package `dataemergencia/emergency-bff` with a framework-agnostic
  kernel, front-controller bootstrap and PSR-18 upstream client.
- Emergency routes under `/de-bff/emergency`: health, config, session, current,
  activate, simulacrum, refresh and abort.
- Native PHP session store with configurable cookie name, path, TTL, Secure,
  HttpOnly and SameSite flags.
- CSRF tokens, same-origin enforcement and a session-backed fixed-window rate
  limiter.
- Upstream API integration for code validation, medical profiles, emergency
  create/show/delete, active lookup by QR code and WhatsApp template delivery.
- WhatsApp payload builder for activation, simulation and abort, including
  Google Maps and FMD links.
- Public Pusher channel configuration for delivery metadata.
- Vanilla PHP example with a mock upstream API and an end-to-end smoke test.
- Split-repository workflow and Packagist release documentation.

### Known limitations

- `POST /{id}/refresh` checks the session but not emergency ownership.
- Idempotency storage is in-memory and per process.
- Private Pusher channels and `/pusher/auth` are reserved but not implemented.
- The package renders no UI; the browser client is a separate package.
