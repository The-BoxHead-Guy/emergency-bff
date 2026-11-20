<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Config;

use DataEmergencia\EmergencyBff\Support\ArrayReader;
use DataEmergencia\EmergencyBff\Support\ConfigurationException;

/**
 * Runtime configuration for the emergency BFF.
 *
 * Secrets are always supplied by the host application; nothing is hardcoded.
 */
final class Config
{
    public function __construct(
        public readonly string $apiOrigin = 'https://api.dataemergencia.com',
        public readonly string $apiKey = '',
        public readonly string $applicationIdentifier = '',
        public readonly int $timeout = 10,
        public readonly int $retryCount = 1,
        public readonly string $routePrefix = '/de-bff/emergency',
        public readonly string $sessionName = 'de_bff_emergency',
        public readonly int $sessionTtl = 3600,
        public readonly string $cookiePath = '/',
        public readonly bool $cookieSecure = true,
        public readonly bool $cookieHttpOnly = true,
        public readonly string $cookieSameSite = 'Lax',
    ) {
        $this->validate();
    }

    /**
     * Build the configuration from environment variables.
     *
     * @param array<string, string|false|null>|null $env
     */
    public static function fromEnvironment(?array $env = null): self
    {
        $read = static function (string $key, ?string $default = null) use ($env): ?string {
            if ($env !== null) {
                $value = ArrayReader::get($env, $key, false);
            } else {
                $value = getenv($key);
            }

            if ($value === false || $value === null || $value === '') {
                return $default;
            }

            return (string) $value;
        };

        $apiKey = $read('EMERGENCY_BFF_API_KEY');
        $applicationIdentifier = $read('EMERGENCY_BFF_APPLICATION_IDENTIFIER');

        $missing = [];
        if ($apiKey === null) {
            $missing[] = 'EMERGENCY_BFF_API_KEY';
        }
        if ($applicationIdentifier === null) {
            $missing[] = 'EMERGENCY_BFF_APPLICATION_IDENTIFIER';
        }

        if ($missing !== []) {
            throw new ConfigurationException(sprintf(
                'Missing required DataEmergencia credentials: %s. Each customer must provide its own API key and application identifier.',
                implode(', ', $missing),
            ));
        }

        return new self(
            apiOrigin: (string) $read('EMERGENCY_BFF_API_ORIGIN', 'https://api.dataemergencia.com'),
            apiKey: (string) $apiKey,
            applicationIdentifier: (string) $applicationIdentifier,
            timeout: (int) $read('EMERGENCY_BFF_TIMEOUT', '10'),
            retryCount: (int) $read('EMERGENCY_BFF_RETRY_COUNT', '1'),
            routePrefix: (string) $read('EMERGENCY_BFF_ROUTE_PREFIX', '/de-bff/emergency'),
            sessionName: (string) $read('EMERGENCY_BFF_SESSION_NAME', 'de_bff_emergency'),
            sessionTtl: (int) $read('EMERGENCY_BFF_SESSION_TTL', '3600'),
            cookiePath: (string) $read('EMERGENCY_BFF_COOKIE_PATH', '/'),
            cookieSecure: self::toBool($read('EMERGENCY_BFF_COOKIE_SECURE', 'true')),
            cookieHttpOnly: self::toBool($read('EMERGENCY_BFF_COOKIE_HTTP_ONLY', 'true')),
            cookieSameSite: (string) $read('EMERGENCY_BFF_COOKIE_SAME_SITE', 'Lax'),
        );
    }

    /**
     * @param array<string, mixed> $values
     */
    public static function fromArray(array $values): self
    {
        if (
            ArrayReader::get($values, 'apiKey') === null
            || ArrayReader::get($values, 'applicationIdentifier') === null
        ) {
            throw new ConfigurationException('Both apiKey and applicationIdentifier are required.');
        }

        return new self(
            apiOrigin: (string) (ArrayReader::get($values, 'apiOrigin') ?? 'https://api.dataemergencia.com'),
            apiKey: (string) ArrayReader::get($values, 'apiKey'),
            applicationIdentifier: (string) ArrayReader::get($values, 'applicationIdentifier'),
            timeout: (int) (ArrayReader::get($values, 'timeout') ?? 10),
            retryCount: (int) (ArrayReader::get($values, 'retryCount') ?? 1),
            routePrefix: (string) (ArrayReader::get($values, 'routePrefix') ?? '/de-bff/emergency'),
            sessionName: (string) (ArrayReader::get($values, 'sessionName') ?? 'de_bff_emergency'),
            sessionTtl: (int) (ArrayReader::get($values, 'sessionTtl') ?? 3600),
            cookiePath: (string) (ArrayReader::get($values, 'cookiePath') ?? '/'),
            cookieSecure: (bool) (ArrayReader::get($values, 'cookieSecure') ?? true),
            cookieHttpOnly: (bool) (ArrayReader::get($values, 'cookieHttpOnly') ?? true),
            cookieSameSite: (string) (ArrayReader::get($values, 'cookieSameSite') ?? 'Lax'),
        );
    }

    private function validate(): void
    {
        if (trim($this->apiKey) === '') {
            throw new ConfigurationException('The per-customer API key is required.');
        }

        if (trim($this->applicationIdentifier) === '') {
            throw new ConfigurationException('The per-customer application identifier is required.');
        }

        if (!filter_var($this->apiOrigin, FILTER_VALIDATE_URL)) {
            throw new ConfigurationException(sprintf('Invalid API origin "%s".', $this->apiOrigin));
        }

        if ($this->timeout < 1) {
            throw new ConfigurationException('The request timeout must be at least 1 second.');
        }

        if ($this->retryCount < 0) {
            throw new ConfigurationException('The retry count cannot be negative.');
        }

        if ($this->sessionTtl < 1) {
            throw new ConfigurationException('The session TTL must be at least 1 second.');
        }

        if (!str_starts_with($this->routePrefix, '/')) {
            throw new ConfigurationException('The route prefix must start with "/".');
        }

        if (!in_array($this->cookieSameSite, ['Lax', 'Strict', 'None'], true)) {
            throw new ConfigurationException('The cookie SameSite value must be Lax, Strict or None.');
        }
    }

    private static function toBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
