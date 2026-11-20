<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

use DataEmergencia\EmergencyBff\Support\ArrayReader;
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
        $firstError = ArrayReader::get($body, 'errors.0');
        $firstError = is_array($firstError) ? $firstError : [];
        $errorCode = (string) (
            ArrayReader::get($body, 'code')
            ?? ArrayReader::get($body, 'error.code')
            ?? ArrayReader::get($firstError, 'code')
            ?? 'http_error'
        );
        $message = (string) (
            ArrayReader::get($body, 'message')
            ?? ArrayReader::get($body, 'error.message')
            ?? ArrayReader::get($firstError, 'detail')
            ?? sprintf('%s failed with status %d', $operation, $response->status())
        );
        $details = ArrayReader::get($body, 'details')
            ?? ArrayReader::get($body, 'errors')
            ?? [];

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
