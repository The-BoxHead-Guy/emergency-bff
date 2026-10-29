<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Session;

use DataEmergencia\EmergencyBff\Emergency\Identity;
use DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException;

/**
 * Session lifecycle: TTL, identity regeneration and the emergency snapshot.
 */
final class SessionManager
{
    public const IDENTITY_KEY = 'de_bff_identity';
    public const CSRF_KEY = 'de_bff_csrf_token';
    public const EMERGENCY_KEY = 'de_bff_emergency';
    public const STARTED_AT_KEY = 'de_bff_started_at';
    public const LAST_ACTIVITY_KEY = 'de_bff_last_activity';

    public function __construct(
        private readonly SessionStore $store,
        private readonly int $ttlSeconds = 3600,
        private readonly ?\Closure $clock = null,
    ) {
    }

    public function load(): void
    {
        if ($this->isExpired()) {
            $this->destroy();

            return;
        }

        if ($this->store->get(self::STARTED_AT_KEY) !== null) {
            $this->store->set(self::LAST_ACTIVITY_KEY, $this->now());
        }
    }

    public function isExpired(): bool
    {
        $startedAt = $this->store->get(self::STARTED_AT_KEY);

        if (!is_int($startedAt)) {
            return false;
        }

        $lastActivity = $this->store->get(self::LAST_ACTIVITY_KEY, $startedAt);
        $lastActivity = is_int($lastActivity) ? $lastActivity : $startedAt;

        return ($this->now() - $lastActivity) > $this->ttlSeconds;
    }

    public function storeIdentity(Identity $identity, bool $regenerate = true): void
    {
        if ($regenerate) {
            $this->store->regenerate();
        }

        $this->store->set(self::IDENTITY_KEY, $identity->code);
        $this->store->set(self::STARTED_AT_KEY, $this->now());
        $this->store->set(self::LAST_ACTIVITY_KEY, $this->now());
    }

    public function hasIdentity(): bool
    {
        return is_string($this->store->get(self::IDENTITY_KEY));
    }

    public function identity(): Identity
    {
        $code = $this->store->get(self::IDENTITY_KEY);

        if (!is_string($code) || $code === '') {
            throw new InactiveCodeException('');
        }

        return Identity::fromCode($code);
    }

    public function identityCode(): ?string
    {
        $code = $this->store->get(self::IDENTITY_KEY);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function storeEmergency(array $snapshot): void
    {
        $this->store->set(self::EMERGENCY_KEY, $snapshot);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function emergency(): ?array
    {
        $snapshot = $this->store->get(self::EMERGENCY_KEY);

        return is_array($snapshot) ? $snapshot : null;
    }

    public function clearEmergency(): void
    {
        $this->store->remove(self::EMERGENCY_KEY);
    }

    public function store(): SessionStore
    {
        return $this->store;
    }

    public function csrfToken(): string
    {
        return (new CsrfTokenManager($this->store))->token();
    }

    public function rotateCsrf(): string
    {
        return (new CsrfTokenManager($this->store))->rotate();
    }

    public function validateCsrf(?string $token): bool
    {
        return (new CsrfTokenManager($this->store))->validate($token);
    }

    public function destroy(): void
    {
        $this->store->destroy();
    }

    private function now(): int
    {
        if ($this->clock !== null) {
            return (int) ($this->clock)();
        }

        return time();
    }
}
