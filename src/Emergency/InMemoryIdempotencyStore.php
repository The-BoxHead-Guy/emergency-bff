<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

/**
 * In-memory idempotency store used when the host does not provide one.
 */
final class InMemoryIdempotencyStore implements IdempotencyStore
{
    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }
}
