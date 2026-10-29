<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

/**
 * Immutable upstream API response.
 */
final class ApiResponse
{
    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function __construct(
        private readonly int $status,
        private readonly array $body = [],
        private readonly string $rawBody = '',
        private readonly array $headers = [],
    ) {
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function body(): array
    {
        return $this->body;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    public function isNoContent(): bool
    {
        return $this->status === 204;
    }

    public function value(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'body' => $this->body,
            'headers' => $this->headers,
        ];
    }
}
