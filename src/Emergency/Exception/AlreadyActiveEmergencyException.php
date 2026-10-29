<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class AlreadyActiveEmergencyException extends EmergencyException
{
    public function __construct(string $code, string $emergencyId)
    {
        parent::__construct(
            sprintf('The code "%s" already has an active emergency.', $code),
            'already_active',
            ['code' => $code, 'emergency_id' => $emergencyId],
        );
    }
}
