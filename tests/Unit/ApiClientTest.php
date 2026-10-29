<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Config\Config;
use DataEmergencia\EmergencyBff\Emergency\CodeValidator;
use DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException;
use DataEmergencia\EmergencyBff\Emergency\Exception\InvalidCodeException;
use DataEmergencia\EmergencyBff\Emergency\Identity;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\Psr18ApiClient;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;

final class ApiClientTest extends TestCase
{
    private function client(MockHandler $handler): Psr18ApiClient
    {
        $factory = new HttpFactory();
        $guzzle = new Client(['handler' => HandlerStack::create($handler)]);

        return new Psr18ApiClient(
            new Config(apiKey: 'api-key', applicationIdentifier: 'app-id', retryCount: 1),
            $guzzle,
            $factory,
            $factory,
        );
    }

    public function test_it_sends_credentials_and_decodes_json(): void
    {
        $handler = new MockHandler([new Response(200, [], '{"ok":true}')]);
        $response = $this->client($handler)->get('/v2/emergency/abc');

        self::assertSame(200, $response->status());
        self::assertSame(['ok' => true], $response->body());
        self::assertCount(0, $handler);
    }

    public function test_it_throws_a_mapped_exception_for_http_errors(): void
    {
        $handler = new MockHandler([
            new Response(422, [], '{"error":{"code":"invalid_code","message":"Bad code"}}'),
        ]);

        try {
            $this->client($handler)->get('/v2/emergency');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame(422, $exception->status());
            self::assertSame('invalid_code', $exception->errorCode());
            self::assertSame('Bad code', $exception->getMessage());
        }
    }

    public function test_it_retries_idempotent_reads_on_server_errors(): void
    {
        $handler = new MockHandler([
            new Response(500, [], '{"message":"boom"}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $response = $this->client($handler)->get('/v2/emergency/abc');

        self::assertSame(['ok' => true], $response->body());
        self::assertCount(0, $handler);
    }

    public function test_it_does_not_retry_mutations(): void
    {
        $handler = new MockHandler([
            new Response(500, [], '{"message":"boom"}'),
            new Response(200, [], '{"ok":true}'),
        ]);

        $this->expectException(ApiException::class);

        try {
            $this->client($handler)->post('/v2/emergency', ['x' => 1]);
        } finally {
            self::assertCount(1, $handler);
        }
    }

    public function test_it_maps_transport_failures(): void
    {
        $handler = new MockHandler([
            new ConnectException('timeout', new GuzzleRequest('GET', '/')),
            new ConnectException('timeout', new GuzzleRequest('GET', '/')),
        ]);

        try {
            $this->client($handler)->get('/v2/emergency');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame('transport_error', $exception->errorCode());
        }
    }

    public function test_the_validator_uses_the_existence_endpoint_and_never_fetches_the_profile(): void
    {
        $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(
            200,
            ['data' => ['attributes' => ['exists' => true]]],
        ));
        $validator = new CodeValidator($client);

        self::assertTrue($validator->isValid('DABC12'));
        self::assertCount(1, $client->calls);
        self::assertSame('GET', $client->calls[0]['method']);
        self::assertSame('/v2/fmd/DABC12/exists', $client->calls[0]['path']);
        self::assertSame([], $client->callsTo('GET', 'models/data'));
    }

    public function test_the_validator_rejects_an_existing_code_without_data(): void
    {
        $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(
            200,
            ['data' => ['attributes' => ['exists' => false]]],
        ));

        self::assertFalse((new CodeValidator($client))->isValid('DABC12'));
    }

    public function test_the_validator_returns_false_for_a_404(): void
    {
        $client = new FakeApiClient(static fn (): ApiException => ApiException::fromResponse(
            new ApiResponse(404, ['message' => 'Not found']),
        ));

        self::assertFalse((new CodeValidator($client))->isValid('DABC12'));
    }

    public function test_the_validator_rejects_a_malformed_code_without_calling_the_api(): void
    {
        $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200));

        $this->expectException(InvalidCodeException::class);

        try {
            (new CodeValidator($client))->validate('bad');
        } finally {
            self::assertSame([], $client->calls);
        }
    }

    public function test_the_validator_raises_an_inactive_code_exception(): void
    {
        $client = new FakeApiClient(fn (): ApiException => ApiException::fromResponse(new ApiResponse(404)));

        $this->expectException(InactiveCodeException::class);

        (new CodeValidator($client))->validate('DABC12');
    }

    public function test_identity_accepts_known_prefixes_and_all_letters(): void
    {
        self::assertSame('P12345', Identity::fromCode('P12345')->code);
        self::assertSame('AABC12', Identity::fromCode('AABC12')->code);
        self::assertSame('Z9Z9Z9', Identity::fromCode('Z9Z9Z9')->code);
    }

    public function test_identity_rejects_invalid_shapes(): void
    {
        foreach (['DABC1', '1ABC12', 'DAB-12', '', 'DABC123'] as $invalid) {
            self::assertFalse(Identity::isValid($invalid), $invalid);
        }

        $this->expectException(InvalidCodeException::class);
        Identity::fromCode('1ABC12');
    }
}
