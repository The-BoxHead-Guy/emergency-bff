<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Feature;

use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Config\Config;
use DataEmergencia\EmergencyBff\Emergency\CodeValidator;
use DataEmergencia\EmergencyBff\Emergency\EmergencyService;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\Kernel;
use DataEmergencia\EmergencyBff\Http\Request;
use DataEmergencia\EmergencyBff\Http\Response;
use DataEmergencia\EmergencyBff\Session\SessionManager;
use DataEmergencia\EmergencyBff\Support\RateLimiter;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\Support\InMemorySessionStore;
use DataEmergencia\EmergencyBff\Tests\TestCase;
use DataEmergencia\EmergencyBff\WhatsApp\ResponderFactory;
use DataEmergencia\EmergencyBff\WhatsApp\WhatsAppPayloadBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

final class KernelTest extends TestCase
{
    private InMemorySessionStore $store;

    private Config $config;

    private ?FakeApiClient $client = null;

    protected function setUp(): void
    {
        $this->store = new InMemorySessionStore();
        $this->config = new Config(
            apiKey: 'api-key',
            applicationIdentifier: 'app-id',
        );
    }

    public function test_the_health_and_config_endpoints_need_no_identity(): void
    {
        $kernel = $this->kernel();

        $health = $kernel->handle(new Request('GET', '/de-bff/emergency/health'));
        self::assertSame(200, $health->status());
        self::assertSame('ok', $health->body()['status']);

        $config = $kernel->handle(new Request('GET', '/de-bff/emergency/config'));
        self::assertSame(200, $config->status());
        self::assertSame('/de-bff/emergency', $config->body()['route_prefix']);
        self::assertSame(64, strlen($config->body()['csrf_token']));
        self::assertArrayNotHasKey('realtime', $config->body());
        self::assertNotNull($config->headers()['X-Request-Id'] ?? null);
        self::assertSame('no-store', $config->headers()['Cache-Control']);
    }

    #[DataProvider('emergencyIdProvider')]
    public function test_it_runs_the_full_session_to_abort_flow(string|int $upstreamId, string $expected): void
    {
        $kernel = $this->kernel(emergencyId: $upstreamId);
        $token = $this->csrfToken($kernel);

        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));
        self::assertSame(201, $session->status());
        self::assertSame('DABC12', $session->body()['code']);
        self::assertSame(3600, $session->body()['expires_in']);

        $token = $session->body()['csrf_token'];

        $current = $kernel->handle(new Request('GET', '/de-bff/emergency/current'));
        self::assertSame(200, $current->status());
        self::assertFalse($current->body()['active']);
        self::assertArrayNotHasKey('realtime', $current->body());

        $activated = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'geolocation' => ['coords' => ['latitude' => '4.7110', 'longitude' => '-74.0721']],
        ], $token));
        self::assertSame(201, $activated->status());
        self::assertArrayHasKey('emergency_id', $activated->body());
        self::assertIsString($activated->body()['emergency_id']);
        self::assertSame($expected, $activated->body()['emergency_id']);
        self::assertSame('wamid.1', $activated->body()['responders'][0]['message_id']);

        $current = $kernel->handle(new Request('GET', '/de-bff/emergency/current'));
        self::assertTrue($current->body()['active']);
        self::assertSame($expected, $current->body()['emergency']['emergency_id']);
        self::assertArrayNotHasKey('realtime', $current->body());
        self::assertIsArray($current->body()['emergency']['responders']);
        $this->assertResponderPayload($current->body()['emergency']['responders'][0], '42');

        $refreshed = $kernel->handle($this->json('POST', '/de-bff/emergency/'.$expected.'/refresh', [], $token));
        self::assertSame(200, $refreshed->status());
        self::assertSame($expected, $refreshed->body()['emergency']['emergency_id']);
        self::assertIsArray($refreshed->body()['emergency']);
        self::assertArrayNotHasKey('responders', $refreshed->body());
        self::assertIsArray($refreshed->body()['emergency']['responders']);
        $this->assertResponderPayload($refreshed->body()['emergency']['responders'][0], '42');

        $aborted = $kernel->handle($this->json('DELETE', '/de-bff/emergency/'.$expected, [], $token));
        self::assertSame(200, $aborted->status());
        self::assertSame($expected, $aborted->body()['emergency_id']);
        self::assertTrue($aborted->body()['deleted']);

        $current = $kernel->handle(new Request('GET', '/de-bff/emergency/current'));
        self::assertFalse($current->body()['active']);
    }

    public function test_the_simulacrum_route_creates_an_active_emergency(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/simulacrum', [], $session->body()['csrf_token']));

        self::assertSame(200, $response->status());
        self::assertTrue($response->body()['simulated']);
        self::assertCount(1, $this->client->callsTo('POST', '/v2/emergency'));
        self::assertTrue($this->client->callsTo('POST', '/v2/emergency')[0]['body']['is_active']);
        self::assertCount(1, $this->client->callsTo('POST', 'emergency/simulacrum'));
    }

    public function test_activation_without_a_wamid_returns_502_and_deletes_the_created_emergency(): void
    {
        $send = $this->fixture('responses/typed-send.json');
        $send['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = 42;

        $client = new FakeApiClient(function (string $method, string $path) use ($send): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, $this->fixture('responses/success.json'));
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                throw ApiException::fromResponse(new ApiResponse(404));
            }

            if ($method === 'POST' && $path === '/v2/emergency') {
                return new ApiResponse(201, [
                    'data' => ['attributes' => ['emergency_id' => 'EMG1', 'is_active' => true]],
                ]);
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/emergency/')) {
                return new ApiResponse(200, $send);
            }

            if ($method === 'DELETE' && str_starts_with($path, '/v2/emergency/')) {
                return new ApiResponse(204);
            }

            throw new \RuntimeException('Unexpected request: '.$method.' '.$path);
        });

        $kernel = $this->kernel($client);
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [], $session->body()['csrf_token']));

        self::assertSame(502, $response->status());
        self::assertSame('whatsapp_delivery_failed', $response->body()['error']['code']);
        self::assertCount(1, $this->client->callsTo('POST', '/v2/emergency'));
        self::assertCount(1, $this->client->callsTo('DELETE', '/v2/emergency/EMG1'));
    }

    public function test_activation_forwards_the_reporter_phone_upstream(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'geolocation' => ['coords' => ['latitude' => 4.711, 'longitude' => -74.0721]],
            'reporter_phone' => '+584121301234',
        ], $session->body()['csrf_token']));

        self::assertSame(201, $response->status());

        $created = $this->client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertSame('+584121301234', $created['reporter_phone']);
    }

    public function test_activation_succeeds_without_a_reporter_phone(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'geolocation' => ['coords' => ['latitude' => 4.711, 'longitude' => -74.0721]],
        ], $session->body()['csrf_token']));

        self::assertSame(201, $response->status());

        $created = $this->client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertNull($created['reporter_phone']);
    }

    public function test_activation_rejects_a_malformed_reporter_phone_before_the_upstream_call(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'reporter_phone' => 'not-a-phone',
        ], $session->body()['csrf_token']));

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', $response->body()['error']['code']);
        self::assertCount(0, $this->client->callsTo('POST', '/v2/emergency'));
    }

    public function test_activation_forwards_a_flat_geolocation_payload(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'geolocation' => ['latitude' => 4.711, 'longitude' => -74.0721, 'accuracy' => 12.5],
        ], $session->body()['csrf_token']));

        self::assertSame(201, $response->status());

        $created = $this->client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertSame(['latitude' => 4.711, 'longitude' => -74.0721, 'accuracy' => 12.5], $created['geolocation']);
        self::assertFalse($created['is_simulated']);
    }

    public function test_activation_flattens_and_forwards_a_nested_geolocation_payload(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'geolocation' => ['coords' => ['latitude' => '4.7110', 'longitude' => '-74.0721']],
        ], $session->body()['csrf_token']));

        self::assertSame(201, $response->status());

        $created = $this->client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertSame(['latitude' => 4.711, 'longitude' => -74.0721], $created['geolocation']);
    }

    public function test_activation_omits_geolocation_when_absent(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [], $session->body()['csrf_token']));

        self::assertSame(201, $response->status());

        $created = $this->client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertArrayNotHasKey('geolocation', $created);
    }

    public function test_activation_rejects_invalid_geolocation_before_the_upstream_call(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'geolocation' => ['coords' => ['latitude' => 'north', 'longitude' => -74.0721]],
        ], $session->body()['csrf_token']));

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', $response->body()['error']['code']);
        self::assertCount(0, $this->client->callsTo('POST', '/v2/emergency'));
    }

    public function test_the_simulacrum_forwards_geolocation_and_marks_the_emergency_as_simulated(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/simulacrum', [
            'geolocation' => ['latitude' => 4.711, 'longitude' => -74.0721],
        ], $session->body()['csrf_token']));

        self::assertSame(200, $response->status());
        self::assertTrue($response->body()['simulated']);

        $created = $this->client->callsTo('POST', '/v2/emergency')[0]['body'];
        self::assertTrue($created['is_active']);
        self::assertTrue($created['is_simulated']);
        self::assertSame(['latitude' => 4.711, 'longitude' => -74.0721], $created['geolocation']);
    }

    public function test_the_simulacrum_rejects_invalid_geolocation_before_the_upstream_call(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/simulacrum', [
            'geolocation' => ['latitude' => 'north', 'longitude' => -74.0721],
        ], $session->body()['csrf_token']));

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', $response->body()['error']['code']);
        self::assertCount(0, $this->client->callsTo('POST', '/v2/emergency'));
    }

    public function test_it_rejects_mutating_requests_without_a_csrf_token(): void
    {
        $kernel = $this->kernel();
        $this->csrfToken($kernel);

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12']));

        self::assertSame(403, $response->status());
        self::assertSame('csrf_failed', $response->body()['error']['code']);
    }

    public function test_it_accepts_the_csrf_token_in_the_body(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/session', [
            'code' => 'DABC12',
            'csrf_token' => $token,
        ]));

        self::assertSame(201, $response->status());
    }

    public function test_it_rejects_cross_origin_requests(): void
    {
        $kernel = $this->kernel();

        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/health', [
            'origin' => 'https://evil.test',
            'host' => 'bff.test',
        ]));

        self::assertSame(403, $response->status());
        self::assertSame('cross_origin_denied', $response->body()['error']['code']);
    }

    public function test_it_allows_the_same_origin_and_echoes_cors_headers(): void
    {
        $kernel = $this->kernel();

        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/health', [
            'origin' => 'https://bff.test',
            'host' => 'bff.test',
        ]));

        self::assertSame(200, $response->status());
        self::assertSame('https://bff.test', $response->headers()['Access-Control-Allow-Origin']);
        self::assertSame('Origin', $response->headers()['Vary']);
    }

    public function test_it_requires_a_session_before_protected_routes(): void
    {
        $kernel = $this->kernel();

        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/current'));

        self::assertSame(401, $response->status());
        self::assertSame('unauthenticated', $response->body()['error']['code']);
        self::assertSame($response->headers()['X-Request-Id'], $response->body()['error']['request_id']);
    }

    public function test_it_maps_invalid_and_inactive_codes(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);

        $invalid = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'bad'], $token));
        self::assertSame(422, $invalid->status());
        self::assertSame('invalid_code', $invalid->body()['error']['code']);

        $inactive = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'ZZZZZZ'], $token));
        self::assertSame(404, $inactive->status());
        self::assertSame('inactive_code', $inactive->body()['error']['code']);
    }

    public function test_it_maps_unknown_routes_and_methods(): void
    {
        $kernel = $this->kernel();

        $notFound = $kernel->handle(new Request('GET', '/de-bff/emergency/does/not/exist'));
        self::assertSame(404, $notFound->status());
        self::assertSame('not_found', $notFound->body()['error']['code']);

        $wrongMethod = $kernel->handle(new Request('GET', '/de-bff/emergency/session'));
        self::assertSame(405, $wrongMethod->status());
        self::assertSame('method_not_allowed', $wrongMethod->body()['error']['code']);
        self::assertContains('POST', $wrongMethod->body()['error']['details']['allowed']);
    }

    public function test_it_rate_limits_requests(): void
    {
        $limiter = new RateLimiter($this->store, limit: 1, windowSeconds: 60);
        $kernel = $this->kernel(limiter: $limiter);

        self::assertSame(200, $kernel->handle(new Request('GET', '/de-bff/emergency/health'))->status());
        $limited = $kernel->handle(new Request('GET', '/de-bff/emergency/health'));

        self::assertSame(429, $limited->status());
        self::assertSame('rate_limited', $limited->body()['error']['code']);
        self::assertSame(60, $limited->body()['error']['details']['retry_after']);
    }

    public function test_it_returns_a_json_error_envelope_for_upstream_failures(): void
    {
        $this->client = new FakeApiClient(static function (): never {
            throw ApiException::fromResponse(new ApiResponse(503, ['message' => 'Unavailable']));
        });
        $kernel = $this->kernel($this->client);
        $token = $this->csrfToken($kernel);

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        self::assertSame(502, $response->status());
        self::assertSame('upstream_error', $response->body()['error']['code']);
        self::assertSame(503, $response->body()['error']['details']['status']);
        self::assertSame('application/json; charset=utf-8', $response->headers()['Content-Type']);
    }

    /**
     * @return array<string, array{string|int, string}>
     */
    public static function emergencyIdProvider(): array
    {
        return [
            'string id' => ['EMG1', 'EMG1'],
            'numeric id' => [130, '130'],
        ];
    }

    /**
     * @param array<string, mixed> $responder
     */
    private function assertResponderPayload(array $responder, ?string $expectedMessageId = null): void
    {
        foreach (['phone', 'name', 'sent', 'delivered', 'read', 'failed', 'message_id'] as $key) {
            self::assertArrayHasKey($key, $responder);
        }

        self::assertIsString($responder['phone']);
        self::assertIsString($responder['name']);
        self::assertIsBool($responder['sent']);
        self::assertIsBool($responder['delivered']);
        self::assertIsBool($responder['read']);
        self::assertIsBool($responder['failed']);
        self::assertTrue($responder['message_id'] === null || is_string($responder['message_id']));

        if ($expectedMessageId !== null) {
            self::assertSame($expectedMessageId, $responder['message_id']);
        }
    }

    private function csrfToken(Kernel $kernel): string
    {
        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/config'));
        $token = $response->body()['csrf_token'];

        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function json(string $method, string $path, array $body, ?string $token = null): Request
    {
        $headers = ['content-type' => 'application/json'];

        if ($token !== null) {
            $headers['x-csrf-token'] = $token;
        }

        return new Request($method, $path, $headers, [], $body, json_encode($body) ?: '');
    }

    private function kernel(
        ?FakeApiClient $client = null,
        ?RateLimiter $limiter = null,
        string|int $emergencyId = 'EMG1',
    ): Kernel {
        $active = null;
        $client ??= new FakeApiClient(function (string $method, string $path, ?array $body) use (&$active, $emergencyId): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return str_contains($path, 'DABC12') || str_contains($path, 'A12345')
                    ? new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]])
                    : throw ApiException::fromResponse(new ApiResponse(404));
            }

            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, $this->fixture('responses/success.json'));
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                return $active === null
                    ? throw ApiException::fromResponse(new ApiResponse(404))
                    : new ApiResponse(200, ['data' => ['attributes' => $active]]);
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/emergency/')) {
                return new ApiResponse(200, $this->fixture('responses/typed-send.json'));
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/send')) {
                return new ApiResponse(200, $this->fixture('responses/send-success.json'));
            }

            if ($method === 'POST' && $path === '/v2/emergency') {
                $snapshot = [
                    'emergency_id' => $emergencyId,
                    'is_active' => $body['is_active'] ?? true,
                    'responders' => [
                        [
                            'id' => 5011,
                            'emergency_id' => $emergencyId,
                            'message_id' => 42,
                            'name' => 'Grace Hopper',
                            'phone' => '573001234567',
                            'sent' => true,
                            'delivered' => false,
                            'read' => false,
                            'failed' => false,
                        ],
                    ],
                ];

                if (($body['is_active'] ?? true) === true) {
                    $active = $snapshot;
                }

                return new ApiResponse(201, ['data' => ['attributes' => $snapshot]]);
            }

            if ($method === 'PUT' && str_starts_with($path, '/v2/emergency/')) {
                $active = [
                    'emergency_id' => $emergencyId,
                    'is_active' => $body['is_active'] ?? true,
                    'responders' => [
                        [
                            'id' => 5011,
                            'emergency_id' => $emergencyId,
                            'message_id' => 42,
                            'name' => 'Grace Hopper',
                            'phone' => '573001234567',
                            'sent' => true,
                            'delivered' => false,
                            'read' => false,
                            'failed' => false,
                        ],
                    ],
                ];

                return new ApiResponse(200, ['data' => ['attributes' => $active]]);
            }

            if ($method === 'GET' && $path === '/v2/emergency/'.$emergencyId) {
                return new ApiResponse(200, ['data' => ['attributes' => $active ?? ['emergency_id' => $emergencyId]]]);
            }

            if ($method === 'DELETE' && str_starts_with($path, '/v2/emergency/')) {
                $active = null;

                return new ApiResponse(204);
            }

            throw new \RuntimeException('Unexpected request: '.$method.' '.$path);
        });

        $this->client = $client;

        $session = new SessionManager($this->store, 3600);
        $service = new EmergencyService(
            profiles: new ProfileRepository($client),
            responders: new ResponderFactory(),
            payloads: new WhatsAppPayloadBuilder(),
            whatsApp: new WhatsAppClient($client),
            emergencies: new EmergencyRepository($client),
        );

        return new Kernel(
            config: $this->config,
            session: $session,
            validator: new CodeValidator($client),
            emergency: $service,
            limiter: $limiter,
        );
    }
}
