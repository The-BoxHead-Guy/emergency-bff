<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Feature;

use DataEmergencia\EmergencyBff\Api\CreditRepository;
use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Config\Config;
use DataEmergencia\EmergencyBff\Emergency\CodeValidator;
use DataEmergencia\EmergencyBff\Emergency\EmergencyService;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\ClientMessages;
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

        $refreshed = $kernel->handle($this->json('POST', "/de-bff/emergency/{$expected}/refresh", [], $token));
        self::assertSame(200, $refreshed->status());
        self::assertSame($expected, $refreshed->body()['emergency']['emergency_id']);
        self::assertIsArray($refreshed->body()['emergency']);
        self::assertArrayNotHasKey('responders', $refreshed->body());
        self::assertIsArray($refreshed->body()['emergency']['responders']);
        $this->assertResponderPayload($refreshed->body()['emergency']['responders'][0], '42');

        $aborted = $kernel->handle($this->json('DELETE', "/de-bff/emergency/{$expected}", [], $token));
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

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
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
        self::assertStringNotContainsString('Unavailable', json_encode($response->body(), JSON_THROW_ON_ERROR));
    }

    public function test_a_total_send_failure_does_not_leak_the_upstream_message(): void
    {
        $client = new FakeApiClient(function (string $method, string $path): ApiResponse {
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
                throw ApiException::fromResponse(new ApiResponse(503, ['message' => 'Unavailable']));
            }

            if ($method === 'DELETE' && str_starts_with($path, '/v2/emergency/')) {
                return new ApiResponse(204);
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });

        $kernel = $this->kernel($client);
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [], $session->body()['csrf_token']));

        self::assertSame(502, $response->status());
        self::assertSame('whatsapp_delivery_failed', $response->body()['error']['code']);
        self::assertStringNotContainsString('Unavailable', json_encode($response->body(), JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{string|int, string}>
     */
    public function test_the_credit_routes_require_a_session(): void
    {
        $kernel = $this->kernel();

        foreach (['/credits', '/credits/history'] as $path) {
            $response = $kernel->handle(new Request('GET', "/de-bff/emergency{$path}"));
            self::assertSame(401, $response->status(), $path);
            self::assertSame('unauthenticated', $response->body()['error']['code']);
        }
    }

    public function test_it_returns_the_credit_summary_for_the_session_code(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/credits'));

        self::assertSame(200, $response->status());
        self::assertSame('DABC12', $response->body()['credit']['code']);
        self::assertSame(9, $response->body()['credit']['balance']);
        self::assertSame(9, $response->body()['credit']['rounds']);
        self::assertSame('9 rounds', $response->body()['credit']['rounds_label']);
        self::assertCount(1, $this->client->callsTo('GET', '/v2/credits/DABC12'));
        self::assertNotEmpty($session->body()['csrf_token']);
    }

    public function test_it_forwards_the_history_pagination(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle(new Request(
            'GET',
            '/de-bff/emergency/credits/history',
            [],
            ['per_page' => '5', 'page' => '2'],
        ));

        self::assertSame(200, $response->status());
        self::assertArrayHasKey('items', $response->body());
        self::assertSame(3, $response->body()['pagination']['total']);
        self::assertSame('/v2/credits/DABC12/history?per_page=5&page=2', $this->client->callsTo('GET', '/v2/credits/DABC12/history')[0]['path']);
    }

    #[DataProvider('invalidPaginationProvider')]
    public function test_it_rejects_invalid_history_pagination_before_the_upstream_call(array $query, string $field): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/credits/history', [], $query));

        self::assertSame(422, $response->status());
        self::assertSame('validation_failed', $response->body()['error']['code']);
        self::assertSame($field, $response->body()['error']['details']['field']);
        self::assertSame([], $this->client->callsTo('GET', '/v2/credits/'));
    }

    public function test_it_maps_a_missing_credit_code_to_inactive_code(): void
    {
        $client = new FakeApiClient(function (string $method, string $path): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, '/v2/credits/')) {
                throw ApiException::fromResponse(new ApiResponse(404));
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });

        $kernel = $this->kernel($client);
        $token = $this->csrfToken($kernel);
        $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/credits'));

        self::assertSame(404, $response->status());
        self::assertSame('inactive_code', $response->body()['error']['code']);
    }

    public function test_it_maps_a_credit_upstream_failure_to_502(): void
    {
        $client = new FakeApiClient(function (string $method, string $path): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, '/v2/credits/')) {
                throw ApiException::fromResponse(new ApiResponse(503, ['message' => 'Unavailable']));
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });

        $kernel = $this->kernel($client);
        $token = $this->csrfToken($kernel);
        $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/credits'));

        self::assertSame(502, $response->status());
        self::assertSame('upstream_error', $response->body()['error']['code']);
        self::assertSame(503, $response->body()['error']['details']['status']);
    }

    public function test_activation_with_insufficient_credits_returns_402_and_cleans_up(): void
    {
        $client = new FakeApiClient(function (string $method, string $path): ApiResponse {
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

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/emergency/activation')) {
                throw ApiException::fromResponse(
                    new ApiResponse(402, $this->fixture('responses/insufficient-credits.json')),
                );
            }

            if ($method === 'DELETE' && str_starts_with($path, '/v2/emergency/')) {
                return new ApiResponse(204);
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });

        $kernel = $this->kernel($client);
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [], $session->body()['csrf_token']));

        self::assertSame(402, $response->status());
        self::assertSame('insufficient_credits', $response->body()['error']['code']);
        self::assertSame('INSUFFICIENT_CREDITS', $response->body()['error']['details']['upstream_code']);
        self::assertCount(1, $this->client->callsTo('POST', '/v2/emergency'));
        self::assertCount(1, $this->client->callsTo('DELETE', '/v2/emergency/EMG1'));
        self::assertCount(0, $this->client->callsTo('PUT', '/v2/emergency/'));
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function invalidPaginationProvider(): array
    {
        return [
            'zero per_page' => [['per_page' => '0'], 'per_page'],
            'too large per_page' => [['per_page' => '101'], 'per_page'],
            'non integer per_page' => [['per_page' => 'abc'], 'per_page'],
            'zero page' => [['page' => '0'], 'page'],
        ];
    }

    #[DataProvider('errorPathProvider')]
    public function test_every_error_path_returns_the_spanish_client_copy(
        callable $kernelFactory,
        callable $request,
        int $status,
        string $code,
        string $key,
    ): void {
        $kernel = $kernelFactory();
        $response = $request($kernel);
        $error = $response->body()['error'];

        self::assertSame($status, $response->status());
        self::assertSame($code, $error['code']);
        self::assertTrue(ClientMessages::has($key), $key);
        self::assertSame(ClientMessages::message($key, $error['details']), $error['message']);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function errorPathProvider(): array
    {
        return [
            'unauthenticated' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(
                    new Request('GET', '/de-bff/emergency/current')
                ),
                'status' => 401,
                'code' => 'unauthenticated',
                'key' => 'unauthenticated',
            ],
            'csrf_failed' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(
                    self::json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'])
                ),
                'status' => 403,
                'code' => 'csrf_failed',
                'key' => 'csrf_failed',
            ],
            'cross_origin_denied' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(new Request(
                    'GET',
                    '/de-bff/emergency/health',
                    ['origin' => 'https://evil.test', 'host' => 'bff.test'],
                )),
                'status' => 403,
                'code' => 'cross_origin_denied',
                'key' => 'cross_origin_denied',
            ],
            'emergency_forbidden' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithActiveEmergency('EMG9')),
                'request' => static function (Kernel $kernel): Response {
                    $token = self::startSession($kernel);

                    return $kernel->handle(self::json('DELETE', '/de-bff/emergency/EMG1', [], $token));
                },
                'status' => 403,
                'code' => 'emergency_forbidden',
                'key' => 'emergency_forbidden',
            ],
            'not_found' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(
                    new Request('GET', '/de-bff/emergency/does/not/exist')
                ),
                'status' => 404,
                'code' => 'not_found',
                'key' => 'not_found',
            ],
            'inactive_code' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/session',
                    ['code' => 'ZZZZZZ'],
                    self::tokenFor($kernel),
                )),
                'status' => 404,
                'code' => 'inactive_code',
                'key' => 'inactive_code',
            ],
            'emergency_not_found' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithoutEmergency()),
                'request' => static function (Kernel $kernel): Response {
                    $token = self::startSession($kernel);

                    return $kernel->handle(self::json('POST', '/de-bff/emergency/EMG1/refresh', [], $token));
                },
                'status' => 404,
                'code' => 'emergency_not_found',
                'key' => 'emergency_not_found',
            ],
            'method_not_allowed' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(
                    new Request('GET', '/de-bff/emergency/session')
                ),
                'status' => 405,
                'code' => 'method_not_allowed',
                'key' => 'method_not_allowed',
            ],
            'emergency_already_active' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithActiveEmergency('EMG9')),
                'request' => static function (Kernel $kernel): Response {
                    $token = self::startSession($kernel);

                    return $kernel->handle(self::json('POST', '/de-bff/emergency/activate', [], $token));
                },
                'status' => 409,
                'code' => 'emergency_already_active',
                'key' => 'emergency_already_active',
            ],
            'identity_code_required' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/session',
                    ['code' => '  '],
                    self::tokenFor($kernel),
                )),
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::IDENTITY_CODE_REQUIRED,
            ],
            'geolocation_object' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    ['geolocation' => 'north'],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::GEOLOCATION_OBJECT,
            ],
            'geolocation_coordinates_numeric' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    ['geolocation' => ['latitude' => 'north', 'longitude' => -74.0721]],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::GEOLOCATION_COORDINATES_NUMERIC,
            ],
            'geolocation_accuracy_numeric' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    ['geolocation' => ['latitude' => 4.711, 'longitude' => -74.0721, 'accuracy' => 'near']],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::GEOLOCATION_ACCURACY_NUMERIC,
            ],
            'reporter_phone_string' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    ['reporter_phone' => 584121301234],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::REPORTER_PHONE_STRING,
            ],
            'reporter_phone_max' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    ['reporter_phone' => '+58412130123456789012'],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::REPORTER_PHONE_MAX,
            ],
            'reporter_phone_e164' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    ['reporter_phone' => 'not-a-phone'],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::REPORTER_PHONE_E164,
            ],
            'query_param_integer' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static function (Kernel $kernel): Response {
                    self::startSession($kernel);

                    return $kernel->handle(new Request(
                        'GET',
                        '/de-bff/emergency/credits/history',
                        [],
                        ['per_page' => 'abc'],
                    ));
                },
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::QUERY_PARAM_INTEGER,
            ],
            'query_param_range' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static function (Kernel $kernel): Response {
                    self::startSession($kernel);

                    return $kernel->handle(new Request(
                        'GET',
                        '/de-bff/emergency/credits/history',
                        [],
                        ['per_page' => '0'],
                    ));
                },
                'status' => 422,
                'code' => 'validation_failed',
                'key' => ClientMessages::QUERY_PARAM_RANGE,
            ],
            'invalid_code' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/session',
                    ['code' => 'bad'],
                    self::tokenFor($kernel),
                )),
                'status' => 422,
                'code' => 'invalid_code',
                'key' => 'invalid_code',
            ],
            'missing_medical_profile' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithProfile([])),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    [],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'missing_medical_profile',
                'key' => 'missing_medical_profile',
            ],
            'missing_emergency_contacts' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(
                    self::clientWithProfile(['full_name' => 'Ada Lovelace'], false)
                ),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    [],
                    self::activateToken($kernel),
                )),
                'status' => 422,
                'code' => 'missing_emergency_contacts',
                'key' => 'missing_emergency_contacts',
            ],
            'rate_limited' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(
                    null,
                    new RateLimiter(new InMemorySessionStore(), limit: 1, windowSeconds: 60),
                ),
                'request' => static function (Kernel $kernel): Response {
                    $kernel->handle(new Request('GET', '/de-bff/emergency/health'));

                    return $kernel->handle(new Request('GET', '/de-bff/emergency/health'));
                },
                'status' => 429,
                'code' => 'rate_limited',
                'key' => 'rate_limited',
            ],
            'internal_error' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithUnexpectedFailure()),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/session',
                    ['code' => 'DABC12'],
                    self::tokenFor($kernel),
                )),
                'status' => 500,
                'code' => 'internal_error',
                'key' => 'internal_error',
            ],
            'emergency_error' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithFailingProfile()),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    [],
                    self::activateToken($kernel),
                )),
                'status' => 500,
                'code' => 'emergency_error',
                'key' => 'emergency_error',
            ],
            'upstream_error' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithFailingExistence()),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/session',
                    ['code' => 'DABC12'],
                    self::tokenFor($kernel),
                )),
                'status' => 502,
                'code' => 'upstream_error',
                'key' => 'upstream_error',
            ],
            'whatsapp_delivery_failed' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithUndeliveredSend()),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    [],
                    self::activateToken($kernel),
                )),
                'status' => 502,
                'code' => 'whatsapp_delivery_failed',
                'key' => 'whatsapp_delivery_failed',
            ],
            'insufficient_credits' => [
                'kernelFactory' => static fn (): Kernel => self::makeKernel(self::clientWithInsufficientCredits()),
                'request' => static fn (Kernel $kernel): Response => $kernel->handle(self::json(
                    'POST',
                    '/de-bff/emergency/activate',
                    [],
                    self::activateToken($kernel),
                )),
                'status' => 402,
                'code' => 'insufficient_credits',
                'key' => 'insufficient_credits',
            ],
        ];
    }

    private static function startSession(Kernel $kernel, string $code = 'DABC12'): string
    {
        $token = self::tokenFor($kernel);
        $response = $kernel->handle(self::json('POST', '/de-bff/emergency/session', ['code' => $code], $token));

        self::assertSame(201, $response->status());

        return (string) $response->body()['csrf_token'];
    }

    private static function activateToken(Kernel $kernel): string
    {
        return self::startSession($kernel);
    }

    private static function clientWithActiveEmergency(string $emergencyId): FakeApiClient
    {
        return new FakeApiClient(function (string $method, string $path) use ($emergencyId): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['emergency_id' => $emergencyId]]]);
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

    private static function clientWithoutEmergency(): FakeApiClient
    {
        return new FakeApiClient(function (string $method, string $path): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_starts_with($path, '/v2/emergency/')) {
                throw ApiException::fromResponse(new ApiResponse(404));
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

    private static function clientWithProfile(array $personalInfo, bool $withContacts = true): FakeApiClient
    {
        $profile = self::fixture('responses/success.json');
        $profile['data']['attributes']['data']['personal_info'] = $personalInfo;

        if (! $withContacts) {
            $profile['data']['attributes']['data']['emergency_info'] = [];
        }

        return new FakeApiClient(function (string $method, string $path) use ($profile): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, $profile);
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                throw ApiException::fromResponse(new ApiResponse(404));
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

    private static function clientWithFailingProfile(): FakeApiClient
    {
        return new FakeApiClient(function (string $method, string $path): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, 'models/data')) {
                throw ApiException::fromResponse(new ApiResponse(503, ['message' => 'Unavailable']));
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                throw ApiException::fromResponse(new ApiResponse(404));
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

    private static function clientWithFailingExistence(): FakeApiClient
    {
        return new FakeApiClient(function (string $method, string $path): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                throw ApiException::fromResponse(new ApiResponse(503, ['message' => 'Unavailable']));
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

    private static function clientWithUnexpectedFailure(): FakeApiClient
    {
        return new FakeApiClient(static function (string $method, string $path): ApiResponse {
            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

    private static function clientWithUndeliveredSend(): FakeApiClient
    {
        $send = self::fixture('responses/typed-send.json');
        $send['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = 42;

        return new FakeApiClient(function (string $method, string $path) use ($send): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, self::fixture('responses/success.json'));
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

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

    private static function clientWithInsufficientCredits(): FakeApiClient
    {
        $credits = self::fixture('responses/insufficient-credits.json');

        return new FakeApiClient(function (string $method, string $path) use ($credits): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]]);
            }

            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, self::fixture('responses/success.json'));
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
                throw ApiException::fromResponse(new ApiResponse(402, $credits));
            }

            if ($method === 'DELETE' && str_starts_with($path, '/v2/emergency/')) {
                return new ApiResponse(204);
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }

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
        return self::tokenFor($kernel);
    }

    private static function tokenFor(Kernel $kernel): string
    {
        $response = $kernel->handle(new Request('GET', '/de-bff/emergency/config'));
        $token = $response->body()['csrf_token'];

        self::assertIsString($token);

        return $token;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function json(string $method, string $path, array $body, ?string $token = null): Request
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
        $client ??= self::defaultClient($emergencyId);

        $this->client = $client;

        return self::buildKernel($this->store, $this->config, $client, $limiter);
    }

    /**
     * Builds a kernel with its own session store, for isolated scenarios.
     */
    private static function makeKernel(
        ?FakeApiClient $client = null,
        ?RateLimiter $limiter = null,
        string|int $emergencyId = 'EMG1',
    ): Kernel {
        $client ??= self::defaultClient($emergencyId);

        return self::buildKernel(
            new InMemorySessionStore(),
            new Config(apiKey: 'api-key', applicationIdentifier: 'app-id'),
            $client,
            $limiter,
        );
    }

    private static function buildKernel(
        InMemorySessionStore $store,
        Config $config,
        FakeApiClient $client,
        ?RateLimiter $limiter = null,
    ): Kernel {
        $session = new SessionManager($store, 3600);
        $service = new EmergencyService(
            profiles: new ProfileRepository($client),
            responders: new ResponderFactory(),
            payloads: new WhatsAppPayloadBuilder(),
            whatsApp: new WhatsAppClient($client),
            emergencies: new EmergencyRepository($client),
        );

        return new Kernel(
            config: $config,
            session: $session,
            validator: new CodeValidator($client),
            emergency: $service,
            credits: new CreditRepository($client),
            limiter: $limiter,
        );
    }

    private static function defaultClient(string|int $emergencyId = 'EMG1'): FakeApiClient
    {
        $active = null;

        return new FakeApiClient(function (string $method, string $path, ?array $body) use (&$active, $emergencyId): ApiResponse {
            if ($method === 'GET' && str_ends_with($path, '/exists')) {
                return str_contains($path, 'DABC12') || str_contains($path, 'A12345')
                    ? new ApiResponse(200, ['data' => ['attributes' => ['exists' => true]]])
                    : throw ApiException::fromResponse(new ApiResponse(404));
            }

            if ($method === 'GET' && str_contains($path, '/credits/') && str_contains($path, '/history')) {
                return new ApiResponse(200, self::fixture('responses/credit-history.json'));
            }

            if ($method === 'GET' && preg_match('#/v2/credits/[^/]+$#', $path) === 1) {
                return new ApiResponse(200, self::fixture('responses/credits.json'));
            }

            if ($method === 'GET' && str_contains($path, 'models/data')) {
                return new ApiResponse(200, self::fixture('responses/success.json'));
            }

            if ($method === 'GET' && str_contains($path, '/active')) {
                return $active === null
                    ? throw ApiException::fromResponse(new ApiResponse(404))
                    : new ApiResponse(200, ['data' => ['attributes' => $active]]);
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/emergency/')) {
                return new ApiResponse(200, self::fixture('responses/typed-send.json'));
            }

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/send')) {
                return new ApiResponse(200, self::fixture('responses/send-success.json'));
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

            if ($method === 'GET' && $path === "/v2/emergency/{$emergencyId}") {
                return new ApiResponse(200, ['data' => ['attributes' => $active ?? ['emergency_id' => $emergencyId]]]);
            }

            if ($method === 'DELETE' && str_starts_with($path, '/v2/emergency/')) {
                $active = null;

                return new ApiResponse(204);
            }

            throw new \RuntimeException("Unexpected request: {$method} {$path}");
        });
    }
}
