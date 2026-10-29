<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

/**
 * Framework-agnostic HTTP request.
 */
final class Request
{
    /**
     * @param array<string, string> $headers Lower-cased header names
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $headers = [],
        private readonly array $query = [],
        private readonly array $body = [],
        private readonly string $rawBody = '',
        private readonly string $clientIp = '',
        private array $attributes = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        $rawBody = (string) file_get_contents('php://input');
        $body = [];

        if ($rawBody !== '') {
            $decoded = json_decode($rawBody, true);
            $body = is_array($decoded) ? $decoded : [];
        }

        return new self(
            method: $method,
            path: is_string($path) && $path !== '' ? $path : '/',
            headers: self::collectHeaders(),
            query: $_GET,
            body: $body,
            rawBody: $rawBody,
            clientIp: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * @return array<string, mixed>
     */
    public function query(): array
    {
        return $this->query;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function clientIp(): string
    {
        return $this->clientIp;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    private static function collectHeaders(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }
}
