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
use DataEmergencia\EmergencyBff\Realtime\RealtimeConfig;
use DataEmergencia\EmergencyBff\Session\SessionManager;
use DataEmergencia\EmergencyBff\Support\RateLimiter;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\Support\InMemorySessionStore;
use DataEmergencia\EmergencyBff\Tests\TestCase;
use DataEmergencia\EmergencyBff\WhatsApp\ResponderFactory;
use DataEmergencia\EmergencyBff\WhatsApp\WhatsAppPayloadBuilder;

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
            pusherKey: 'public-key',
            pusherCluster: 'eu',
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
        self::assertSame('public', $config->body()['realtime']['mode']);
        self::assertSame('public-key', $config->body()['realtime']['pusher']['key']);
        self::assertSame(64, strlen($config->body()['csrf_token']));
        self::assertArrayNotHasKey('secret', $config->body()['realtime']['pusher']);
        self::assertNotNull($config->headers()['X-Request-Id'] ?? null);
        self::assertSame('no-store', $config->headers()['Cache-Control']);
    }

    public function test_it_runs_the_full_session_to_abort_flow(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);

        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));
        self::assertSame(201, $session->status());
        self::assertSame('DABC12', $session->body()['code']);
        self::assertSame(3600, $session->body()['expires_in']);

        $token = $session->body()['csrf_token'];

        $current = $kernel->handle(new Request('GET', '/de-bff/emergency/current'));
        self::assertSame(200, $current->status());
        self::assertFalse($current->body()['active']);

        $activated = $kernel->handle($this->json('POST', '/de-bff/emergency/activate', [
            'geolocation' => ['coords' => ['latitude' => '4.7110', 'longitude' => '-74.0721']],
        ], $token));
        self::assertSame(201, $activated->status());
        self::assertSame('EMG1', $activated->body()['emergency_id']);
        self::assertSame('wamid.1', $activated->body()['responders'][0]['message_id']);

        $current = $kernel->handle(new Request('GET', '/de-bff/emergency/current'));
        self::assertTrue($current->body()['active']);
        self::assertSame('EMG1', $current->body()['emergency']['emergency_id']);

        $refreshed = $kernel->handle($this->json('POST', '/de-bff/emergency/EMG1/refresh', [], $token));
        self::assertSame(200, $refreshed->status());
        self::assertSame('EMG1', $refreshed->body()['emergency']['emergency_id']);

        $aborted = $kernel->handle($this->json('DELETE', '/de-bff/emergency/EMG1', [], $token));
        self::assertSame(200, $aborted->status());
        self::assertSame('EMG1', $aborted->body()['emergency_id']);
        self::assertTrue($aborted->body()['deleted']);

        $current = $kernel->handle(new Request('GET', '/de-bff/emergency/current'));
        self::assertFalse($current->body()['active']);
    }

    public function test_the_simulacrum_route_does_not_create_an_emergency(): void
    {
        $kernel = $this->kernel();
        $token = $this->csrfToken($kernel);
        $session = $kernel->handle($this->json('POST', '/de-bff/emergency/session', ['code' => 'DABC12'], $token));

        $response = $kernel->handle($this->json('POST', '/de-bff/emergency/simulacrum', [], $session->body()['csrf_token']));

        self::assertSame(200, $response->status());
        self::assertTrue($response->body()['simulated']);
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

    private function kernel(?FakeApiClient $client = null, ?RateLimiter $limiter = null): Kernel
    {
        $active = null;
        $client ??= new FakeApiClient(function (string $method, string $path, ?array $body) use (&$active): ApiResponse {
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

            if ($method === 'POST' && str_contains($path, 'whatsapp/templates/send')) {
                return new ApiResponse(200, ['data' => ['message_id' => 'wamid.1']]);
            }

            if ($method === 'POST' && $path === '/v2/emergency') {
                $active = ['emergency_id' => 'EMG1', 'is_active' => true];

                return new ApiResponse(201, ['data' => ['attributes' => $active]]);
            }

            if ($method === 'GET' && $path === '/v2/emergency/EMG1') {
                return new ApiResponse(200, ['data' => ['attributes' => $active ?? ['emergency_id' => 'EMG1']]]);
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
            realtime: new RealtimeConfig($this->config),
            limiter: $limiter,
        );
    }
}
