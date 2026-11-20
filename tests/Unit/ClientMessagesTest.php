<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Http\ClientMessages;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class ClientMessagesTest extends TestCase
{
    private const UNKNOWN_KEY = '__unknown__';

    private const SENTINEL = 'SENTINEL-VALUE';

    public function test_every_catalog_key_resolves_to_its_own_copy(): void
    {
        $generic = ClientMessages::message(self::UNKNOWN_KEY);

        foreach (ClientMessages::keys() as $key) {
            $copy = ClientMessages::message($key);

            self::assertNotSame('', $copy, $key);
            self::assertNotSame($generic, $copy, $key);
            self::assertDoesNotMatchRegularExpression('/:[a-z_]+/', $copy, $key);
        }
    }

    public function test_a_key_ignores_parameters_it_does_not_use(): void
    {
        foreach (ClientMessages::keys() as $key) {
            $copy = ClientMessages::message($key);
            $withParams = ClientMessages::message($key, $this->params(self::SENTINEL));

            self::assertTrue(
                $copy === $withParams || str_contains($withParams, self::SENTINEL),
                $key
            );
        }
    }

    public function test_a_key_with_parameters_falls_back_when_the_value_is_missing_or_empty(): void
    {
        foreach (ClientMessages::keys() as $key) {
            $copy = ClientMessages::message($key);

            if (ClientMessages::message($key, $this->params(self::SENTINEL)) === $copy) {
                continue;
            }

            self::assertSame($copy, ClientMessages::message($key), $key);
            self::assertSame($copy, ClientMessages::message($key, $this->params('')), $key);
        }
    }

    public function test_an_unknown_key_resolves_to_the_generic_copy(): void
    {
        $generic = ClientMessages::message(self::UNKNOWN_KEY);

        self::assertNotSame('', $generic);

        foreach (ClientMessages::keys() as $key) {
            self::assertNotSame($generic, ClientMessages::message($key), $key);
        }
    }

    /**
     * @return array<string, string>
     */
    private function params(string $value): array
    {
        return ['code' => $value, 'emergency_id' => $value];
    }
}
