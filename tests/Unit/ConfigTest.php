<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Config\Config;
use DataEmergencia\EmergencyBff\Support\ConfigurationException;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class ConfigTest extends TestCase
{
    public function test_it_builds_configuration_from_environment(): void
    {
        $config = Config::fromEnvironment([
            'EMERGENCY_BFF_API_ORIGIN' => 'https://example.test',
            'EMERGENCY_BFF_API_KEY' => 'key-123',
            'EMERGENCY_BFF_APPLICATION_IDENTIFIER' => 'app-456',
            'EMERGENCY_BFF_TIMEOUT' => '3',
            'EMERGENCY_BFF_RETRY_COUNT' => '2',
            'EMERGENCY_BFF_ROUTE_PREFIX' => '/custom/emergency',
            'EMERGENCY_BFF_SESSION_NAME' => 'custom_session',
            'EMERGENCY_BFF_SESSION_TTL' => '120',
            'EMERGENCY_BFF_COOKIE_SECURE' => 'false',
            'EMERGENCY_BFF_COOKIE_SAME_SITE' => 'Strict',
            'EMERGENCY_BFF_PUSHER_KEY' => 'public-key',
            'EMERGENCY_BFF_PUSHER_CLUSTER' => 'eu',
        ]);

        self::assertSame('https://example.test', $config->apiOrigin);
        self::assertSame('key-123', $config->apiKey);
        self::assertSame('app-456', $config->applicationIdentifier);
        self::assertSame(3, $config->timeout);
        self::assertSame(2, $config->retryCount);
        self::assertSame('/custom/emergency', $config->routePrefix);
        self::assertSame('custom_session', $config->sessionName);
        self::assertSame(120, $config->sessionTtl);
        self::assertFalse($config->cookieSecure);
        self::assertSame('Strict', $config->cookieSameSite);
        self::assertSame('public-key', $config->pusherKey);
        self::assertSame('eu', $config->pusherCluster);
    }

    public function test_it_fails_fast_when_credentials_are_missing(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('EMERGENCY_BFF_API_KEY');
        $this->expectExceptionMessage('EMERGENCY_BFF_APPLICATION_IDENTIFIER');

        Config::fromEnvironment([]);
    }

    public function test_it_fails_fast_when_only_one_credential_is_missing(): void
    {
        try {
            Config::fromEnvironment(['EMERGENCY_BFF_API_KEY' => 'key']);
            self::fail('Expected a configuration exception.');
        } catch (ConfigurationException $exception) {
            self::assertStringContainsString('EMERGENCY_BFF_APPLICATION_IDENTIFIER', $exception->getMessage());
            self::assertStringNotContainsString('EMERGENCY_BFF_API_KEY,', $exception->getMessage());
        }
    }

    public function test_it_rejects_invalid_values(): void
    {
        $this->expectException(ConfigurationException::class);

        new Config(apiKey: 'key', applicationIdentifier: 'app', apiOrigin: 'not-a-url');
    }

    public function test_it_rejects_an_unsupported_same_site_value(): void
    {
        $this->expectException(ConfigurationException::class);

        new Config(apiKey: 'key', applicationIdentifier: 'app', cookieSameSite: 'Sometimes');
    }

    public function test_it_accepts_the_secure_defaults(): void
    {
        $config = new Config(apiKey: 'key', applicationIdentifier: 'app');

        self::assertTrue($config->cookieSecure);
        self::assertTrue($config->cookieHttpOnly);
        self::assertSame('Lax', $config->cookieSameSite);
        self::assertSame('/de-bff/emergency', $config->routePrefix);
    }
}
