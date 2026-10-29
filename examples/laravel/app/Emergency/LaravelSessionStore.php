<?php

declare(strict_types=1);

namespace App\Emergency;

use DataEmergencia\EmergencyBff\Session\SessionStore;
use Illuminate\Contracts\Session\Session;

/**
 * Adapts Laravel's session store to the BFF contract.
 *
 * Use this only when embedding the kernel inside Laravel. The native store is
 * the default when the BFF runs as its own application.
 */
final class LaravelSessionStore implements SessionStore
{
    public function __construct(private readonly Session $session)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->session->get($key, $default);
    }

    public function set(string $key, mixed $value): void
    {
        $this->session->put($key, $value);
    }

    public function remove(string $key): void
    {
        $this->session->forget($key);
    }

    public function regenerate(): void
    {
        $this->session->regenerate(true);
    }

    public function destroy(): void
    {
        $this->session->invalidate();
        $this->session->regenerateToken();
    }
}
