<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * @return array<string, mixed>
     */
    protected static function fixture(string $path): array
    {
        $directory = __DIR__;
        $file = "{$directory}/fixtures/{$path}";
        $contents = file_get_contents($file);

        self::assertIsString($contents, "Missing fixture: {$file}");

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, "Fixture is not valid JSON: {$file}");

        return $decoded;
    }

    protected function fixturePath(string $path): string
    {
        $directory = __DIR__;

        return "{$directory}/fixtures/{$path}";
    }
}
