# Installation

Applies to: vanilla PHP and Laravel integrators.

## Install with Composer

```bash
composer require dataemergencia/emergency-bff
```

The package is published on Packagist from the public split repository
[`The-BoxHead-Guy/emergency-bff`](https://github.com/The-BoxHead-Guy/emergency-bff).
Composer installs the library under `vendor/dataemergencia/emergency-bff`.

The package is backend-only. It ships no JavaScript, CSS, images or public
assets, and it does not write to your document root.

## Create the front controller

Point your web server at `public/` and forward every BFF route to
`public/index.php`. A minimal front controller is:

```php
<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Support\Bootstrap;

$kernel = Bootstrap::create();
$kernel->handle(Request::fromGlobals())->send();
```

`Bootstrap::create()` reads the environment variables documented in
[Configuration](configuration.md). It throws
`DataEmergencia\EmergencyBff\Support\ConfigurationException` when a required
credential is missing or a value is invalid.

## Confirm the installation

Start the service and call the health route:

```bash
curl -i http://localhost/de-bff/emergency/health
```

Expected response:

```http
HTTP/1.1 200 OK
Content-Type: application/json
Cache-Control: no-store
X-Request-Id: 4f6c2a1b8e3d9a70

{"status":"ok","service":"dataemergencia/emergency-bff"}
```

If the route prefix is different, use your configured value.

## Local development without Packagist

Inside a monorepo or during package development, use a path repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../emergency-bff",
      "options": { "symlink": true }
    }
  ],
  "require": {
    "dataemergencia/emergency-bff": "@dev"
  }
}
```

Then run `composer update dataemergencia/emergency-bff`.

## Framework hosts

### Laravel

Register the BFF routes outside `VerifyCsrfToken` and give the BFF its own
session cookie, or forward the matching requests to `public/index.php`. Do not
mix Laravel's session middleware with the BFF's native session store unless you
implement the `SessionStore` adapter described in
[Session and CSRF](session-and-csrf.md).

### Apache and Nginx

The required rewrite rules are in [Deployment](deployment.md).

## Package contents

```text
src/          PHP source (PSR-4: DataEmergencia\EmergencyBff\)
public/       reference front controller and .htaccess
examples/     vanilla PHP example (not part of the runtime API)
tests/        PHPUnit suite
docs/         this documentation
```

## Next step

[Configure the BFF](configuration.md).
