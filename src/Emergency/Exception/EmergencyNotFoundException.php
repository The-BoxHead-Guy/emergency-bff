<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class EmergencyNotFoundException extends EmergencyException
{
    public function __construct(string $emergencyId)
    {
        parent::__construct(
            sprintf('The emergency "%s" does not exist or is no longer active.', $emergencyId),
            'emergency_not_found',
            ['emergency_id' => $emergencyId],
        );
    }
}
