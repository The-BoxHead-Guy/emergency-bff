<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Session;

/**
 * Host adapter contract for the session implementation.
 *
 * A framework (Laravel, Symfony, ...) can replace the default native PHP
 * session by implementing this interface. The BFF stores only the validated
 * identity code, the CSRF token and the emergency snapshot.
 */
interface SessionStore
{
    public function get(string $key, mixed $default = null): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;

    public function regenerate(): void;

    public function destroy(): void;
}
