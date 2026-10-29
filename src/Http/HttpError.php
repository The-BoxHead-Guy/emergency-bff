<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

use RuntimeException;

/**
 * HTTP-layer failure translated into the public error envelope.
 */
final class HttpError extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
