<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class InvalidCodeException extends EmergencyException
{
    public function __construct(string $code)
    {
        parent::__construct(
            sprintf('The code "%s" does not match the expected 6-character identity format.', $code),
            'invalid_code',
            ['code' => $code],
        );
    }
}
