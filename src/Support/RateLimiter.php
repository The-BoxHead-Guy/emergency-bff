<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Support;

use DataEmergencia\EmergencyBff\Session\SessionStore;

/**
 * Session-backed fixed-window rate limiter.
 */
final class RateLimiter
{
    private const STORAGE_KEY = 'de_bff_rate_limits';

    public function __construct(
        private readonly SessionStore $store,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    public function allow(string $bucket): bool
    {
        $now = $this->now();
        $all = $this->store->get(self::STORAGE_KEY, []);
        $all = is_array($all) ? $all : [];
        $entries = $all[$bucket] ?? [];
        $entries = is_array($entries) ? $entries : [];

        $entries = array_values(array_filter(
            $entries,
            fn (mixed $timestamp): bool => is_int($timestamp) && ($now - $timestamp) < $this->windowSeconds,
        ));

        if (count($entries) >= $this->limit) {
            $all[$bucket] = $entries;
            $this->store->set(self::STORAGE_KEY, $all);

            return false;
        }

        $entries[] = $now;
        $all[$bucket] = $entries;
        $this->store->set(self::STORAGE_KEY, $all);

        return true;
    }

    private function now(): int
    {
        return $this->clock !== null ? (int) ($this->clock)() : time();
    }
}
