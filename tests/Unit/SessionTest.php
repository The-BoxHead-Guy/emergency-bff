<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Session\CsrfTokenManager;
use DataEmergencia\EmergencyBff\Session\SessionManager;
use DataEmergencia\EmergencyBff\Emergency\Identity;
use DataEmergencia\EmergencyBff\Tests\Support\InMemorySessionStore;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class SessionTest extends TestCase
{
    private InMemorySessionStore $store;

    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionStore();
    }

    private function manager(int $ttl = 3600): SessionManager
    {
        return new SessionManager($this->store, $ttl, fn (): int => $this->now);
    }

    public function test_it_stores_the_identity_and_regenerates_the_session(): void
    {
        $manager = $this->manager();

        $manager->storeIdentity(Identity::fromCode('DABC12'));

        self::assertTrue($manager->hasIdentity());
        self::assertSame('DABC12', $manager->identity()->code);
        self::assertSame('DABC12', $manager->identityCode());
        self::assertSame(1, $this->store->regenerations);
        self::assertIsInt($this->store->data[SessionManager::STARTED_AT_KEY]);
    }

    public function test_it_does_not_regenerate_when_asked_not_to(): void
    {
        $this->manager()->storeIdentity(Identity::fromCode('DABC12'), false);

        self::assertSame(0, $this->store->regenerations);
    }

    public function test_it_expires_and_destroys_an_idle_session(): void
    {
        $manager = $this->manager(ttl: 60);
        $manager->storeIdentity(Identity::fromCode('DABC12'));

        $this->now += 61;
        self::assertTrue($manager->isExpired());
        $manager->load();
        self::assertSame(1, $this->store->destroys);
        self::assertFalse($manager->hasIdentity());
    }

    public function test_activity_keeps_the_session_alive(): void
    {
        $manager = $this->manager(ttl: 60);
        $manager->storeIdentity(Identity::fromCode('DABC12'));

        $this->now += 30;
        $manager->load();
        $this->now += 30;
        $manager->load();

        self::assertFalse($manager->isExpired());
        self::assertSame(0, $this->store->destroys);
    }

    public function test_it_stores_and_clears_the_emergency_snapshot(): void
    {
        $manager = $this->manager();
        $manager->storeEmergency(['emergency_id' => 'EMG1']);

        self::assertSame(['emergency_id' => 'EMG1'], $manager->emergency());

        $manager->clearEmergency();
        self::assertNull($manager->emergency());
    }

    public function test_it_rotates_and_validates_the_csrf_token(): void
    {
        $manager = $this->manager();
        $first = $manager->csrfToken();

        self::assertSame(64, strlen($first));
        self::assertTrue($manager->validateCsrf($first));
        self::assertFalse($manager->validateCsrf('wrong'));
        self::assertFalse($manager->validateCsrf(null));

        $second = $manager->rotateCsrf();
        self::assertNotSame($first, $second);
        self::assertFalse($manager->validateCsrf($first));
        self::assertTrue($manager->validateCsrf($second));
    }

    public function test_the_csrf_manager_is_constant_time_safe(): void
    {
        $tokens = new CsrfTokenManager($this->store);
        $token = $tokens->token();

        self::assertSame($token, $tokens->token());
        self::assertTrue($tokens->validate($token));
        self::assertFalse($tokens->validate(substr($token, 1)));
    }

    public function test_identity_requires_a_stored_code(): void
    {
        $this->expectException(\DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException::class);

        $this->manager()->identity();
    }
}
