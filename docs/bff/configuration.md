# Configuration

Applies to: vanilla PHP and Laravel integrators.

The BFF is configured entirely from the process environment or from an explicit
map passed to the bootstrap. Nothing is hardcoded and no `.env` file is loaded
by the package; your framework or process manager exports the values.

## Required credentials

| Variable                               | Purpose                                                |
| -------------------------------------- | ------------------------------------------------------ |
| `EMERGENCY_BFF_API_KEY`                | Per-customer API key, sent as a bearer token upstream. |
| `EMERGENCY_BFF_APPLICATION_IDENTIFIER` | Per-customer application identifier.                   |

Each deployment has its own pair, issued by the DataEmergencia console. There is
no shared key and no fallback. Both values stay server-side: the browser never
sees them. If either is missing or empty, `Bootstrap::create()` throws
`ConfigurationException` before serving a request.

## Optional runtime

| Variable                         | Default                          | Effect                                                           |
| -------------------------------- | -------------------------------- | ---------------------------------------------------------------- |
| `EMERGENCY_BFF_API_ORIGIN`       | `https://api.dataemergencia.com` | Upstream origin. Must be an absolute URL.                        |
| `EMERGENCY_BFF_TIMEOUT`          | `10`                             | Connect and total request timeout in seconds.                    |
| `EMERGENCY_BFF_RETRY_COUNT`      | `1`                              | Extra attempts for `GET`/`HEAD` only. Writes are never retried.  |
| `EMERGENCY_BFF_ROUTE_PREFIX`     | `/de-bff/emergency`              | Path where the BFF is mounted. Must start with `/`.              |
| `EMERGENCY_BFF_SESSION_NAME`     | `de_bff_emergency`               | Session cookie name. Keep it unique per application on the host. |
| `EMERGENCY_BFF_SESSION_TTL`      | `3600`                           | Idle session lifetime in seconds, sliding on activity.           |
| `EMERGENCY_BFF_COOKIE_PATH`      | `/`                              | Session cookie path.                                             |
| `EMERGENCY_BFF_COOKIE_SECURE`    | `true`                           | HTTPS-only cookie. Set `false` for local HTTP only.              |
| `EMERGENCY_BFF_COOKIE_HTTP_ONLY` | `true`                           | Hide the cookie from JavaScript. Keep this `true`.               |
| `EMERGENCY_BFF_COOKIE_SAME_SITE` | `Lax`                            | `Lax`, `Strict` or `None`.                                       |

## Programmatic configuration

`Config::fromArray()` accepts the same settings in camelCase
(`apiOrigin`, `apiKey`, `applicationIdentifier`, `timeout`, `retryCount`,
`routePrefix`, `sessionName`, `sessionTtl`, `cookiePath`, `cookieSecure`,
`cookieHttpOnly`, `cookieSameSite`) for hosts that already own a typed
configuration object. `Bootstrap::create($environment, $logger)` accepts an
explicit map instead of reading the process environment.

Invalid values fail at boot: a missing credential, a non-URL origin, a timeout
below one second, a negative retry count, a prefix without `/`, or an unknown
`SameSite` value. The exception never includes credential values.

## Cookies and origin

The defaults are the production baseline: `Secure`, `HttpOnly`, `SameSite=Lax`.
`SameSite=None` also requires `Secure` and HTTPS on both sides. However, the BFF
rejects cross-origin browser calls regardless of cookie flags, so serving the
panel from another origin is a deliberate front-door change, not a configuration
toggle. See [Session and CSRF](session-and-csrf.md) and
[Security](security.md).

Effective session lifetime is the smaller of `EMERGENCY_BFF_SESSION_TTL` and
PHP's `session.gc_maxlifetime`. Raise the PHP value if the configured BFF TTL
must actually be reached.
