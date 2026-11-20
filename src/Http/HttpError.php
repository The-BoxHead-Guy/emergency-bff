<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

use RuntimeException;

/**
 * HTTP-layer failure translated into the public error envelope.
 *
 * The client copy is resolved from the `ClientMessages` catalog by the message
 * key.
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
        private readonly ?string $messageKey = null,
    ) {
        parent::__construct($message);
    }

    /**
     * Returns the catalog key that resolves the client copy.
     */
    public function messageKey(): ?string
    {
        return $this->messageKey;
    }
}
