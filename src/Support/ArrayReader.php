<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Support;

/**
 * Reads values from arrays using dot notation.
 */
final class ArrayReader
{
    private function __construct()
    {
    }

    /**
     * A stored null is a hit, not a miss.
     *
     * @param array<string, mixed> $array
     */
    public static function get(array $array, string|int|null $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        [$found, $value] = self::walk($array, (string) $key);

        return $found ? $value : $default;
    }

    /**
     * Tells whether every segment of a dot-notated path exists.
     *
     * A stored null counts as present.
     *
     * @param array<string, mixed> $array
     */
    public static function has(array $array, string|int $key): bool
    {
        if (array_key_exists($key, $array)) {
            return true;
        }

        return self::walk($array, (string) $key)[0];
    }

    /**
     * @param array<string, mixed> $array
     * @return array{0: bool, 1: mixed}
     */
    private static function walk(array $array, string $key): array
    {
        $value = $array;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return [false, null];
            }

            $value = $value[$segment];
        }

        return [true, $value];
    }
}
