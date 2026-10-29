<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Session;

/**
 * Session-backed CSRF token generation and validation.
 */
final class CsrfTokenManager
{
    public function __construct(private readonly SessionStore $store)
    {
    }

    public function token(): string
    {
        $token = $this->store->get(SessionManager::CSRF_KEY);

        if (!is_string($token) || strlen($token) < 32) {
            $token = $this->rotate();
        }

        return $token;
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->store->set(SessionManager::CSRF_KEY, $token);

        return $token;
    }

    public function validate(?string $token): bool
    {
        $expected = $this->store->get(SessionManager::CSRF_KEY);

        if (!is_string($expected) || $expected === '' || $token === null || $token === '') {
            return false;
        }

        return hash_equals($expected, $token);
    }
}
