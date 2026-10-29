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
            'All WhatsApp template messages failed to send.',
            'whatsapp_failure',
            ['failures' => $failures],
        );
    }
}
