<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

/**
 * Idempotency cache for activation requests.
 */
interface IdempotencyStore
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;
}
