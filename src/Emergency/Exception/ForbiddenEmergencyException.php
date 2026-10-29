<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class ForbiddenEmergencyException extends EmergencyException
{
    public function __construct(string $emergencyId, string $code)
    {
        parent::__construct(
            sprintf('The emergency "%s" does not belong to the validated identity.', $emergencyId),
            'forbidden',
            ['emergency_id' => $emergencyId, 'code' => $code],
        );
    }
}
