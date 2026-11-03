# Deployment

Applies to: operators deploying the BFF.

## Document root

Expose only `public/`. The package source, tests, examples and `vendor/` must not
be reachable over HTTP.

```text
/var/www/emergency/
├── public/
│   ├── index.php
│   └── .htaccess
├── src/
├── vendor/
└── composer.json
```

## Apache

The reference `.htaccess` in `public/`:

```apache
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

A virtual host example:

```apache
<VirtualHost *:443>
    ServerName emergency.example.com
    DocumentRoot /var/www/emergency/public

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/emergency.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/emergency.example.com/privkey.pem

    <Directory /var/www/emergency/public>
        AllowOverride All
        Require all granted
        FallbackResource /index.php
    </Directory>

    SetEnv EMERGENCY_BFF_API_KEY "replace-me"
    SetEnv EMERGENCY_BFF_APPLICATION_IDENTIFIER "replace-me"
    SetEnv EMERGENCY_BFF_COOKIE_SECURE "true"
</VirtualHost>
```

Prefer your process manager or secret store over `SetEnv` in a world-readable
configuration file.

## Nginx and PHP-FPM

```nginx
server {
    listen 443 ssl;
    server_name emergency.example.com;
    root /var/www/emergency/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_PROXY "";
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

Pass the environment through PHP-FPM (`clear_env = no` or an explicit `env[...]`
list) or through the application's own configuration layer. `Bootstrap::create()`
reads `getenv()`.

Preserve the original `Host` header:

```nginx
proxy_set_header Host $host;
proxy_set_header X-Forwarded-Proto $scheme;
```

If an upstream load balancer rewrites `Host` to an internal name, same-origin
checks will reject browser requests. Fix the proxy, not the middleware.

## PHP-FPM sizing

The BFF is I/O-bound on the upstream API and WhatsApp sends. Size workers for
the expected concurrent activations, not for CPU. Each activation performs one
profile fetch plus one WhatsApp request per contact.

Set `request_terminate_timeout` above the sum of the upstream timeout and the
fan-out time. With the default 10-second timeout and several contacts, 60
seconds is a reasonable starting point.

## Sessions

The default `NativeSessionStore` uses PHP's file session handler. For multiple
application servers, configure a shared handler (`redis`, `memcached`, or a
database session handler) so a request can land on any node.

The session cookie name is `EMERGENCY_BFF_SESSION_NAME` (default
`de_bff_emergency`). Keep it distinct from other applications on the same host.

Session files contain the identity code and the last emergency snapshot. Protect
the session directory with the same controls as the rest of the application
data, and set a retention policy.

## Health check

```bash
curl -fsS https://emergency.example.com/de-bff/emergency/health
```

Use this route for the load balancer. It does not call upstream, so it only
proves that PHP and the routing layer are alive. Add a separate synthetic check
for `/config` if you need to verify session storage.

## Production checklist

- [ ] HTTPS terminates at the proxy; HTTP redirects to HTTPS.
- [ ] `EMERGENCY_BFF_COOKIE_SECURE=true`.
- [ ] Required credentials are present in the runtime environment.
- [ ] Document root is `public/`; `src/`, `vendor/` and `examples/` are not
      reachable.
- [ ] Sessions use shared storage if more than one node serves traffic.
- [ ] PHP-FPM `request_terminate_timeout` exceeds the upstream timeout budget.
- [ ] Health check is monitored and alerts on failure.
- [ ] Logs redact medical data, names and phone numbers where required.
- [ ] Reverse proxy preserves `Host` and `Origin`.
- [ ] CORS is not enabled; the BFF is same-origin only.

## Next step

[Upgrade guide](upgrade.md).
