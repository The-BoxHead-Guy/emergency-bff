<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class InactiveCodeException extends EmergencyException
{
    public function __construct(string $code)
    {
        parent::__construct(
            sprintf('The code "%s" is not associated with an active medical profile.', $code),
            'inactive_code',
            ['code' => $code],
        );
    }
}
