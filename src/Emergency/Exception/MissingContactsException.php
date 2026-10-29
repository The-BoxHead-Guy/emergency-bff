<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class MissingContactsException extends EmergencyException
{
    public function __construct(string $code)
    {
        parent::__construct(
            sprintf('The code "%s" has no emergency contacts configured.', $code),
            'missing_contacts',
            ['code' => $code],
        );
    }
}
