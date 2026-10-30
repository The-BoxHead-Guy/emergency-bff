<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Emergency\EmergencyService;
use DataEmergencia\EmergencyBff\Emergency\Exception\AlreadyActiveEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\ForbiddenEmergencyException;
use DataEmergencia\EmergencyBff\Emergency\Exception\WhatsAppFailureException;
use DataEmergencia\EmergencyBff\Emergency\Identity;
use DataEmergencia\EmergencyBff\Emergency\InMemoryIdempotencyStore;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\TestCase;
use DataEmergencia\EmergencyBff\WhatsApp\ResponderFactory;
use DataEmergencia\EmergencyBff\WhatsApp\WhatsAppPayloadBuilder;

final class EmergencyServiceTest extends TestCase
{
    private const EMERGENCY = [
        'data' => [
            'id' => 'EMG1',
            'type' => 'emergency',
            'attributes' => [
                'emergency_id' => 'EMG1',
                'is_active' => true,
            ],
        ],
    ];

    private const EMERGENCY_NUMERIC = [
        'data' => [
            'id' => 130,
            'type' => 'emergency',
            'attributes' => [
                'emergency_id' => 130,
                'is_active' => true,
            ],
        ],
    ];

    private function service(?FakeApiClient $client = null, ?InMemoryIdempotencyStore $idempotency = null): array
    {
        $client ??= $this->client();
        $service = new EmergencyService(
            profiles: new ProfileRepository($client),
            responders: new ResponderFactory(),
            payloads: new WhatsAppPayloadBuilder(),
            whatsApp: new WhatsAppClient($client),
            emergencies: new EmergencyRepository($client),
            idempotency: $idempotency ?? new InMemoryIdempotencyStore(),
        );

        return [$service, $client];
    }

    private function client(
        ?callable $whatsApp = null,
        ?array $active = null,
        array $profile = [],
        array $emergency = self::EMERGENCY,
    ): FakeApiClient {
        $profileBody = $profile === [] ? $this->fixture('responses/success.json') : $profile;
        $emergencyId = $emergency['data']['attributes']['emergency_id'] ?? $emergency['data']['id'] ?? 'EMG1';

        return new FakeApiClient(function (string $method, string $path, ?array $body) use ($whatsApp, $active, $profileBody, $emergency, $emergencyId): ApiResponse {
            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, $profileBody);
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                return $active === null
                    ? throw ApiException::fromResponse(new ApiResponse(404))
                    : new ApiResponse(200, ['data' => ['attributes' => $active]]);
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/send')) {
                return $whatsApp === null
                    ? new ApiResponse(200, ['success' => true, 'data' => ['message_id' => 'wamid.1']])
                    : $whatsApp($body);
            }

            if ($method === 'POST' && $path === '/v2/emergency') {
                return new ApiResponse(201, $emergency);
            }

            if ($method === 'GET' && $path === '/v2/emergency/'.$emergencyId) {
                return new ApiResponse(200, $emergency);
            }

            if ($method === 'DELETE' && str_starts_with($path, '/v2/emergency/')) {
                return new ApiResponse(204);
            }

            throw new \RuntimeException('Unexpected request: '.$method.' '.$path);
        });
    }

    public function test_it_activates_in_order_and_persists_the_emergency(): void
    {
        [$service, $client] = $this->service();

        $result = $service->activate(
            Identity::fromCode('DABC12'),
            ['coords' => ['latitude' => '4.7110', 'longitude' => '-74.0721']],
        );

        self::assertSame('EMG1', $result->emergencyId);
        self::assertTrue($result->responders[0]->sent);
        self::assertSame('wamid.1', $result->responders[0]->messageId);
        self::assertFalse($result->isPartialFailure());

        $methods = array_map(static fn (array $call): string => $call['method'].' '.$call['path'], $client->calls);
        self::assertSame('GET /v2/emergency/qr/DABC12/active', $methods[0]);
        self::assertSame('GET /v2/fmd/DABC12/models/data?model=personal,emergencia', $methods[1]);
        self::assertSame('POST /v2/whatsapp/templates/send', $methods[2]);
        self::assertSame('POST /v2/emergency', $methods[3]);

        $created = $client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertSame('DABC12', $created['initiator_id']);
        self::assertTrue($created['is_active']);
        self::assertSame('wamid.1', $created['responders'][0]['message_id']);
    }

    public function test_it_returns_a_numeric_upstream_id_as_a_string(): void
    {
        [$service] = $this->service($this->client(emergency: self::EMERGENCY_NUMERIC));

        $result = $service->activate(Identity::fromCode('DABC12'), []);

        self::assertSame('130', $result->emergencyId);
        self::assertSame('130', $result->toArray()['emergency_id']);
    }

    public function test_it_reports_partial_failures_and_still_creates_the_emergency(): void
    {
        $calls = 0;
        $client = $this->client(
            whatsApp: function () use (&$calls): ApiResponse {
                $calls++;

                return $calls === 1
                    ? new ApiResponse(200, ['data' => ['message_id' => 'wamid.1']])
                    : throw ApiException::fromResponse(new ApiResponse(422, ['message' => 'Invalid number']));
            },
            profile: $this->twoContactProfile(),
        );

        [$service] = $this->service($client);
        $result = $service->activate(Identity::fromCode('DABC12'), []);

        self::assertTrue($result->isPartialFailure());
        self::assertCount(1, $result->failures);
        self::assertSame('447700900123', $result->failures[0]['phone']);
        self::assertCount(1, $client->callsTo('POST', '/v2/emergency'));
    }

    public function test_it_fails_when_every_message_fails(): void
    {
        $client = $this->client(
            whatsApp: static function (): never {
                throw ApiException::fromResponse(new ApiResponse(500));
            },
        );

        [$service] = $this->service($client);

        $this->expectException(WhatsAppFailureException::class);

        try {
            $service->activate(Identity::fromCode('DABC12'), []);
        } finally {
            self::assertCount(0, $client->callsTo('POST', '/v2/emergency'));
        }
    }

    public function test_it_refuses_to_activate_when_an_emergency_is_already_active(): void
    {
        [$service, $client] = $this->service($this->client(active: ['emergency_id' => 'EMG9']));

        $this->expectException(AlreadyActiveEmergencyException::class);

        try {
            $service->activate(Identity::fromCode('DABC12'), []);
        } finally {
            self::assertCount(0, $client->callsTo('GET', 'models/data'));
        }
    }

    public function test_the_simulacrum_never_creates_an_emergency(): void
    {
        [$service, $client] = $this->service();

        $result = $service->simulacrum(Identity::fromCode('DABC12'));

        self::assertTrue($result->simulated);
        self::assertNull($result->emergencyId);
        self::assertCount(0, $client->callsTo('POST', '/v2/emergency'));
        $payload = $client->callsTo('POST', 'whatsapp/templates/send')[0]['body'];
        self::assertSame('simulacrum_support_contacts_confirmation_v2', $payload['template']);
    }

    public function test_it_aborts_only_the_callers_emergency(): void
    {
        [$service, $client] = $this->service($this->client(active: ['emergency_id' => 'EMG2']));

        $this->expectException(ForbiddenEmergencyException::class);

        try {
            $service->abort(Identity::fromCode('DABC12'), 'EMG1');
        } finally {
            self::assertCount(0, $client->callsTo('DELETE', '/v2/emergency/'));
        }
    }

    public function test_it_sends_abort_messages_and_deletes_the_emergency(): void
    {
        [$service, $client] = $this->service($this->client(active: ['emergency_id' => 'EMG1']));

        $result = $service->abort(Identity::fromCode('DABC12'), 'EMG1');

        self::assertSame(['deleted' => true], $result->snapshot);
        $payload = $client->callsTo('POST', 'whatsapp/templates/send')[0]['body'];
        self::assertSame('emergency_status_aborting_v1', $payload['template']);
        self::assertCount(1, $client->callsTo('DELETE', '/v2/emergency/EMG1'));
    }

    public function test_it_refreshes_and_resumes(): void
    {
        [$service] = $this->service($this->client(active: ['emergency_id' => 'EMG1']));

        self::assertSame('EMG1', $service->refresh('EMG1')['emergency_id']);
        self::assertSame('EMG1', $service->resume(Identity::fromCode('DABC12'))['emergency_id']);
    }

    public function test_the_idempotency_key_reuses_the_activation_result(): void
    {
        [$service, $client] = $this->service();

        $first = $service->activate(Identity::fromCode('DABC12'), [], 'key-1');
        $calls = count($client->calls);
        $second = $service->activate(Identity::fromCode('DABC12'), [], 'key-1');

        self::assertSame($first->emergencyId, $second->emergencyId);
        self::assertCount($calls, $client->calls);
    }

    public function test_it_requires_contacts_and_a_profile(): void
    {
        $profile = $this->fixture('responses/success.json');
        $profile['data']['attributes']['data']['emergency_info'] = [];

        [$service] = $this->service($this->client(profile: $profile));

        $this->expectException(\DataEmergencia\EmergencyBff\Emergency\Exception\MissingContactsException::class);

        $service->activate(Identity::fromCode('DABC12'), []);
    }

    /**
     * @return array<string, mixed>
     */
    private function twoContactProfile(): array
    {
        $profile = $this->fixture('responses/success.json');
        $profile['data']['attributes']['data']['emergency_info'][] = [
            'name' => 'Alan Turing',
            'phones' => [['area_code' => '+44', 'number' => '7700900123']],
        ];

        return $profile;
    }
}
