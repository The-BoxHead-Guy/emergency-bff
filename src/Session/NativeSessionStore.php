<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Session;

/**
 * Default session store backed by the native PHP session cookie.
 */
final class NativeSessionStore implements SessionStore
{
    private bool $started = false;

    public function __construct(
        private readonly string $sessionName = 'de_bff_emergency',
        private readonly string $cookiePath = '/',
        private readonly bool $cookieSecure = true,
        private readonly bool $cookieHttpOnly = true,
        private readonly string $cookieSameSite = 'Lax',
    ) {
        $this->start();
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->start();
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->start();
        unset($_SESSION[$key]);
    }

    public function regenerate(): void
    {
        $this->start();
        session_regenerate_id(true);
    }

    public function destroy(): void
    {
        $this->start();
        $_SESSION = [];

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        if (isset($_COOKIE[$this->sessionName])) {
            unset($_COOKIE[$this->sessionName]);
        }

        $this->started = false;
    }

    private function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;

            return;
        }

        if (headers_sent()) {
            return;
        }

        session_name($this->sessionName);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $this->cookiePath,
            'secure' => $this->cookieSecure,
            'httponly' => $this->cookieHttpOnly,
            'samesite' => $this->cookieSameSite,
        ]);

        session_start();
        $this->started = true;
    }
}
