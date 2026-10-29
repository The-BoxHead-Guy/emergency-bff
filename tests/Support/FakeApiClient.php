<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Support;

use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use Throwable;

/**
 * Scripted API client for unit and HTTP tests.
 */
final class FakeApiClient implements ApiClient
{
    /**
     * @var array<int, array{method: string, path: string, body: ?array, headers: array}>>
     */
    public array $calls = [];

    /**
     * @param callable(string, string, ?array): (ApiResponse|Throwable) $handler
     */
    public function __construct(private $handler)
    {
    }

    public function request(string $method, string $path, ?array $body = null, array $headers = []): ApiResponse
    {
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => $body, 'headers' => $headers];
        $result = ($this->handler)($method, $path, $body);

        if ($result instanceof Throwable) {
            throw $result;
        }

        return $result;
    }

    public function get(string $path, array $headers = []): ApiResponse
    {
        return $this->request('GET', $path, null, $headers);
    }

    public function head(string $path, array $headers = []): ApiResponse
    {
        return $this->request('HEAD', $path, null, $headers);
    }

    public function post(string $path, array $body = [], array $headers = []): ApiResponse
    {
        return $this->request('POST', $path, $body, $headers);
    }

    public function delete(string $path, array $headers = []): ApiResponse
    {
        return $this->request('DELETE', $path, null, $headers);
    }

    /**
     * @return array<int, array{method: string, path: string, body: ?array, headers: array}>
     */
    public function callsTo(string $method, string $pathContains): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method && str_contains($call['path'], $pathContains),
        ));
    }
}
