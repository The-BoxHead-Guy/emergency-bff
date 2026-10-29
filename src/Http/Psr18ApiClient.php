<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

use DataEmergencia\EmergencyBff\Config\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use JsonException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * PSR-18 implementation with timeouts, idempotent-read retries and error mapping.
 */
final class Psr18ApiClient implements ApiClient
{
    private const IDEMPOTENT_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private readonly Config $config,
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public static function create(Config $config): self
    {
        $factory = new HttpFactory();

        return new self(
            $config,
            new Client([
                'timeout' => $config->timeout,
                'connect_timeout' => $config->timeout,
                'http_errors' => false,
            ]),
            $factory,
            $factory,
        );
    }

    public function request(string $method, string $path, ?array $body = null, array $headers = []): ApiResponse
    {
        $method = strtoupper($method);
        $url = rtrim($this->config->apiOrigin, '/').'/'.ltrim($path, '/');
        $maxAttempts = in_array($method, self::IDEMPOTENT_METHODS, true)
            ? $this->config->retryCount + 1
            : 1;

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                $response = $this->client->sendRequest($this->buildRequest($method, $url, $body, $headers));
            } catch (ClientExceptionInterface $exception) {
                if ($attempt < $maxAttempts) {
                    usleep(100_000 * $attempt);
                    continue;
                }

                throw ApiException::transport(
                    sprintf('Could not reach the DataEmergencia API: %s', $exception->getMessage()),
                    $exception,
                );
            }

            $status = $response->getStatusCode();

            if ($status >= 500 && $attempt < $maxAttempts) {
                usleep(100_000 * $attempt);
                continue;
            }

            $result = $this->toApiResponse($response);

            if (!$result->isSuccessful()) {
                throw ApiException::fromResponse($result, sprintf('%s %s', $method, $path));
            }

            return $result;
        }
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
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    private function buildRequest(string $method, string $url, ?array $body, array $headers): \Psr\Http\Message\RequestInterface
    {
        $request = $this->requestFactory->createRequest($method, $url)
            ->withHeader('Authorization', 'Bearer '.$this->config->apiKey)
            ->withHeader('X-Application-Identifier', $this->config->applicationIdentifier)
            ->withHeader('Accept', 'application/json');

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($body !== null) {
            try {
                $json = json_encode($body, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw ApiException::transport('Could not encode the request body as JSON.', $exception);
            }

            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream($json));
        }

        return $request;
    }

    private function toApiResponse(ResponseInterface $response): ApiResponse
    {
        $raw = (string) $response->getBody();
        $headers = [];

        foreach ($response->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $body = [];

        if ($raw !== '') {
            $decoded = json_decode($raw, true);

            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        return new ApiResponse($response->getStatusCode(), $body, $raw, $headers);
    }
}
