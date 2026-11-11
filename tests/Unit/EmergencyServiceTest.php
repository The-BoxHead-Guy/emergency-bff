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
        ?ApiException $createFailure = null,
    ): FakeApiClient {
        $profileBody = $profile === [] ? $this->fixture('responses/success.json') : $profile;
        $emergencyId = $emergency['data']['attributes']['emergency_id'] ?? $emergency['data']['id'] ?? 'EMG1';

        return new FakeApiClient(function (string $method, string $path, ?array $body) use ($whatsApp, $active, $profileBody, $emergency, $emergencyId, $createFailure): ApiResponse {
            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, $profileBody);
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                return $active === null
                    ? throw ApiException::fromResponse(new ApiResponse(404))
                    : new ApiResponse(200, ['data' => ['attributes' => $active]]);
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/emergency/')) {
                return $whatsApp === null
                    ? new ApiResponse(200, $this->fixture('responses/typed-send.json'))
                    : $whatsApp($body);
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/send')) {
                return $whatsApp === null
                    ? new ApiResponse(200, $this->fixture('responses/send-success.json'))
                    : $whatsApp($body);
            }

            if ($method === 'POST' && $path === '/v2/emergency') {
                if ($createFailure !== null) {
                    throw $createFailure;
                }

                return new ApiResponse(201, $emergency);
            }

            if ($method === 'PUT' && $path === '/v2/emergency/'.$emergencyId) {
                return new ApiResponse(200, [
                    'data' => [
                        'attributes' => [
                            'emergency_id' => $emergencyId,
                            'is_active' => $body['is_active'] ?? true,
                            'responders' => $body['responders'] ?? [],
                        ],
                    ],
                ]);
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

        $result = $service->activate(Identity::fromCode('DABC12'));

        self::assertSame('EMG1', $result->emergencyId);
        self::assertTrue($result->responders[0]->sent);
        self::assertSame('wamid.1', $result->responders[0]->messageId);
        self::assertFalse($result->isPartialFailure());

        $methods = array_map(static fn (array $call): string => $call['method'].' '.$call['path'], $client->calls);
        self::assertSame('GET /v2/emergency/qr/DABC12/active', $methods[0]);
        self::assertSame('GET /v2/fmd/DABC12/models/data?model=personal,emergencia', $methods[1]);
        self::assertSame('POST /v2/emergency', $methods[2]);
        self::assertSame('POST /v2/whatsapp/templates/emergency/activation', $methods[3]);
        self::assertSame('PUT /v2/emergency/EMG1', $methods[4]);

        $created = $client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertSame('DABC12', $created['initiator_id']);
        self::assertTrue($created['is_active']);
        self::assertNull($created['responders'][0]['message_id']);

        $activation = $client->callsTo('POST', 'emergency/activation')[0]['body'];
        self::assertSame('EMG1', $activation['recipients'][0]['variables']['emergency_code']);

        $updated = $client->callsTo('PUT', '/v2/emergency/EMG1')[0]['body'];
        self::assertTrue($updated['is_active']);
        self::assertSame('wamid.1', $updated['responders'][0]['message_id']);
    }

    public function test_it_sends_the_typed_wamid_to_the_update_endpoint(): void
    {
        $envelope = $this->fixture('responses/typed-send.json');
        $envelope['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = 'wamid.real';

        [$service, $client] = $this->service($this->client(
            whatsApp: static fn (): ApiResponse => new ApiResponse(200, $envelope),
        ));

        $service->activate(Identity::fromCode('DABC12'));

        $updated = $client->callsTo('PUT', '/v2/emergency/EMG1')[0]['body'];

        self::assertSame('wamid.real', $updated['responders'][0]['message_id']);
    }

    public function test_it_returns_a_numeric_upstream_id_as_a_string(): void
    {
        [$service] = $this->service($this->client(emergency: self::EMERGENCY_NUMERIC));

        $result = $service->activate(Identity::fromCode('DABC12'));

        self::assertSame('130', $result->emergencyId);
        self::assertSame('130', $result->toArray()['emergency_id']);
    }

    public function test_activation_returns_the_reporter_phone(): void
    {
        [$service, $client] = $this->service();

        $result = $service->activate(Identity::fromCode('DABC12'), null, '+584121301234');

        self::assertSame('+584121301234', $result->reporterPhone);
        self::assertSame('+584121301234', $result->toArray()['reporter_phone']);
        self::assertSame('+584121301234', $client->callsTo('POST', '/v2/emergency')[0]['body']['reporter_phone']);
    }

    public function test_the_simulacrum_sends_a_null_reporter_phone(): void
    {
        [$service, $client] = $this->service();

        $result = $service->simulacrum(Identity::fromCode('DABC12'));

        self::assertNull($result->reporterPhone);
        self::assertNull($client->callsTo('POST', '/v2/emergency')[0]['body']['reporter_phone']);
    }

    public function test_it_reports_partial_failures_and_still_creates_the_emergency(): void
    {
        $calls = 0;
        $client = $this->client(
            whatsApp: function () use (&$calls): ApiResponse {
                $calls++;

                return $calls === 1
                    ? new ApiResponse(200, $this->fixture('responses/typed-send.json'))
                    : throw ApiException::fromResponse(new ApiResponse(422, ['message' => 'Invalid number']));
            },
            profile: $this->twoContactProfile(),
        );

        [$service] = $this->service($client);
        $result = $service->activate(Identity::fromCode('DABC12'));

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
            $service->activate(Identity::fromCode('DABC12'));
        } finally {
            self::assertCount(1, $client->callsTo('POST', '/v2/emergency'));
            self::assertCount(1, $client->callsTo('DELETE', '/v2/emergency/EMG1'));
            self::assertCount(0, $client->callsTo('PUT', '/v2/emergency/'));
        }
    }

    public function test_a_send_response_without_a_wamid_counts_as_a_failure(): void
    {
        $client = $this->client(
            whatsApp: fn (): ApiResponse => new ApiResponse(200, $this->sendResponseWithoutWamid()),
        );

        [$service] = $this->service($client);

        $this->expectException(WhatsAppFailureException::class);

        try {
            $service->activate(Identity::fromCode('DABC12'));
        } finally {
            self::assertCount(1, $client->callsTo('POST', '/v2/emergency'));
            self::assertCount(1, $client->callsTo('DELETE', '/v2/emergency/EMG1'));
            self::assertCount(0, $client->callsTo('PUT', '/v2/emergency/'));
        }
    }

    public function test_a_responder_without_a_wamid_is_not_reported_as_sent(): void
    {
        $calls = 0;
        $client = $this->client(
            whatsApp: function () use (&$calls): ApiResponse {
                $calls++;

                return $calls === 1
                    ? new ApiResponse(200, $this->fixture('responses/typed-send.json'))
                    : new ApiResponse(200, $this->sendResponseWithoutWamid());
            },
            profile: $this->twoContactProfile(),
        );

        [$service] = $this->service($client);
        $result = $service->activate(Identity::fromCode('DABC12'));

        self::assertTrue($result->responders[0]->sent);
        self::assertFalse($result->responders[1]->sent);
        self::assertTrue($result->responders[1]->failed);
        self::assertNull($result->responders[1]->messageId);

        self::assertCount(1, $result->failures);
        self::assertSame('447700900123', $result->failures[0]['phone']);
        self::assertSame(200, $result->failures[0]['status']);
        self::assertArrayHasKey('message', $result->failures[0]);

        $updated = $client->callsTo('PUT', '/v2/emergency/EMG1')[0]['body'];
        self::assertFalse($updated['responders'][1]['sent']);
        self::assertTrue($updated['responders'][1]['failed']);
        self::assertNull($updated['responders'][1]['message_id']);
    }

    public function test_it_maps_a_race_conflict_on_creation_to_already_active(): void
    {
        [$service] = $this->service($this->client(
            createFailure: ApiException::fromResponse(new ApiResponse(409, ['message' => 'Conflict'])),
        ));

        $this->expectException(AlreadyActiveEmergencyException::class);

        $service->activate(Identity::fromCode('DABC12'));
    }

    public function test_it_refuses_to_activate_when_an_emergency_is_already_active(): void
    {
        [$service, $client] = $this->service($this->client(active: ['emergency_id' => 'EMG9']));

        $this->expectException(AlreadyActiveEmergencyException::class);

        try {
            $service->activate(Identity::fromCode('DABC12'));
        } finally {
            self::assertCount(0, $client->callsTo('GET', 'models/data'));
        }
    }

    public function test_the_simulacrum_pre_creates_an_active_emergency(): void
    {
        [$service, $client] = $this->service();

        $result = $service->simulacrum(Identity::fromCode('DABC12'));

        self::assertTrue($result->simulated);
        self::assertNull($result->emergencyId);

        $created = $client->callsTo('POST', '/v2/emergency');
        self::assertCount(1, $created);
        self::assertTrue($created[0]['body']['is_active']);
        self::assertTrue($created[0]['body']['is_simulated']);

        $payload = $client->callsTo('POST', 'emergency/simulacrum')[0]['body'];
        self::assertSame('EMG1', $payload['recipients'][0]['variables']['emergency_code']);
        self::assertSame('Grace Hopper', $payload['recipients'][0]['variables']['contact_name']);
        self::assertCount(0, $client->callsTo('PUT', '/v2/emergency/'));
    }

    public function test_the_simulacrum_deletes_the_created_emergency_when_every_send_fails(): void
    {
        $client = $this->client(
            whatsApp: static function (): never {
                throw ApiException::fromResponse(new ApiResponse(500));
            },
        );

        [$service] = $this->service($client);

        $this->expectException(WhatsAppFailureException::class);

        try {
            $service->simulacrum(Identity::fromCode('DABC12'));
        } finally {
            self::assertCount(1, $client->callsTo('POST', '/v2/emergency'));
            self::assertCount(1, $client->callsTo('DELETE', '/v2/emergency/EMG1'));
        }
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

        $first = $service->activate(Identity::fromCode('DABC12'), 'key-1');
        $calls = count($client->calls);
        $second = $service->activate(Identity::fromCode('DABC12'), 'key-1');

        self::assertSame($first->emergencyId, $second->emergencyId);
        self::assertCount($calls, $client->calls);
    }

    public function test_it_requires_contacts_and_a_profile(): void
    {
        $profile = $this->fixture('responses/success.json');
        $profile['data']['attributes']['data']['emergency_info'] = [];

        [$service] = $this->service($this->client(profile: $profile));

        $this->expectException(\DataEmergencia\EmergencyBff\Emergency\Exception\MissingContactsException::class);

        $service->activate(Identity::fromCode('DABC12'));
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

    /**
     * A 2xx send envelope whose only message id is internal, never a WAMID.
     *
     * @return array<string, mixed>
     */
    private function sendResponseWithoutWamid(): array
    {
        $body = $this->fixture('responses/typed-send.json');
        $body['data']['attributes']['data']['results'][0]['status'] = 'sent';
        $body['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = 42;

        return $body;
    }
}
