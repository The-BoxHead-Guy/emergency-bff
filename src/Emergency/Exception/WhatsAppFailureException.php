<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

final class WhatsAppFailureException extends EmergencyException
{
    /**
     * @param array<int, array<string, mixed>> $failures
     */
    public function __construct(array $failures)
    {
        parent::__construct(
            'No WhatsApp template message could be delivered.',
            'whatsapp_failure',
            ['failures' => $failures],
        );
    }
}
