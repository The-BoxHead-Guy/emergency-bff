<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class ApiFailureException extends EmergencyException
{
    public function __construct(string $operation, private readonly int $status = 0, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('The upstream API failed while %s.', $operation),
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
