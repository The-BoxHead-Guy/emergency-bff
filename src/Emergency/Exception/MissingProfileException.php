<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class MissingProfileException extends EmergencyException
{
    public function __construct(string $code)
    {
        parent::__construct(
            sprintf('No medical profile was returned for the code "%s".', $code),
            'missing_profile',
            ['code' => $code],
        );
    }
}
