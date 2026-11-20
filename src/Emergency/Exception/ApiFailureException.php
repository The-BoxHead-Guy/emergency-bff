<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

/**
 * Signals that the upstream API failed during a known operation.
 */
final class ApiFailureException extends EmergencyException
{
    public function __construct(string $operation, private readonly int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('The upstream API failed during the "%s" operation.', $operation),
            'api_failure',
            ['operation' => $operation, 'status' => $status],
            $previous,
        );
    }

    public function status(): int
    {
        return $this->status;
    }
}
