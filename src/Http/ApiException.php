<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

use RuntimeException;
use Throwable;

/**
 * Structured upstream API failure.
 */
final class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly string $errorCode = 'api_error',
        private readonly array $details = [],
        private readonly ?ApiResponse $response = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    public static function fromResponse(ApiResponse $response, string $operation = 'API request'): self
    {
        $body = $response->body();
        $errorCode = (string) ($body['code'] ?? $body['error']['code'] ?? 'http_error');
        $message = (string) ($body['message'] ?? $body['error']['message'] ?? sprintf('%s failed with status %d', $operation, $response->status()));
        $details = $body['details'] ?? $body['errors'] ?? [];

        return new self(
            $message,
            $response->status(),
            $errorCode,
            is_array($details) ? $details : ['details' => $details],
            $response,
        );
    }

    public static function transport(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 0, 'transport_error', [], null, $previous);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    public function response(): ?ApiResponse
    {
        return $this->response;
    }
}
