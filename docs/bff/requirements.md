# Requirements

Applies to: vanilla PHP and Laravel integrators.

## Runtime

| Requirement  | Minimum | Notes                                                                    |
| ------------ | ------- | ------------------------------------------------------------------------ |
| PHP          | 8.4     | The package uses constructor property promotion and readonly properties. |
| Composer     | 2.x     | Composer 1 is not supported.                                             |
| ext-curl     | any     | Outbound HTTP to the DataEmergencia API.                                 |
| ext-json     | any     | Request and response bodies.                                             |
| ext-mbstring | any     | Name normalization in WhatsApp payloads.                                 |

Verify the runtime before installing:

```bash runnable
php -v
```

```bash runnable
composer --version
```

```bash runnable
php -m
```

The last command must list `curl`, `json` and `mbstring`.

## Web server

The BFF is a front-controller application. Every request below the route prefix
must reach `public/index.php`.

- Apache 2.4 with `mod_rewrite`, or
- Nginx with `fastcgi_pass` to PHP-FPM, or
- PHP's built-in server for local demos only.

The package itself is framework-agnostic. It does not ship a router file for
Laravel, Symfony or Slim; you mount the provided `Kernel` or forward requests to
`public/index.php`.

## Network and credentials

- Outbound HTTPS to your configured `EMERGENCY_BFF_API_ORIGIN`
  (`https://api.dataemergencia.com` by default).
- A per-customer API key and application identifier, issued by the
  DataEmergencia console. There is no shared key.
- A public Pusher key and cluster if you want realtime delivery status. The
  Pusher secret is never needed by this package.

## Browser requirements (for the client)

The BFF works with any HTTP client. The reference browser client requires:

- A browser with ES2022 support and native `fetch`.
- `navigator.geolocation` for the normal activation path. If geolocation is
  unavailable, the client shows the configured fallback phone number.

See [Browser support](https://github.com/The-BoxHead-Guy/emergency/blob/main/docs/client/browser-support.md)
in the client documentation.

## Next step

[Install the BFF](installation.md).
