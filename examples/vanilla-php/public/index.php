<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Support\Bootstrap;

/**
 * Minimal .env reader for the demo; the package itself only reads environment
 * variables, as Composer libraries should not depend on a dotenv loader.
 *
 * @return array<string, string>
 */
function demoEnvironment(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    $values = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    foreach ($lines as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = substr($line, 7);
        }

        if (!str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);

        if (preg_match('/^[A-Z][A-Z0-9_]*$/', $key) !== 1) {
            continue;
        }

        $value = trim($value);

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $values[$key] = $value;
    }

    return $values;
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if (PHP_SAPI === 'cli-server' && $path !== '/' && is_file(__DIR__.$path)) {
    return false;
}

$environment = demoEnvironment(__DIR__.'/../.env');

$environment += [
    'EMERGENCY_BFF_API_ORIGIN' => 'http://127.0.0.1:8081',
    'EMERGENCY_BFF_API_KEY' => 'demo-key',
    'EMERGENCY_BFF_APPLICATION_IDENTIFIER' => 'demo-application',
    'EMERGENCY_BFF_COOKIE_SECURE' => 'false',
];

// Real environment variables win over .env, so demo.sh can force the mock.
foreach (array_keys($environment) as $key) {
    $value = getenv($key);

    if ($value !== false && $value !== '') {
        $environment[$key] = $value;
    }
}

if ($path === '/' && strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'GET') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/demo.html');
    exit;
}

Bootstrap::create($environment)->handle(Request::fromGlobals())->send();
