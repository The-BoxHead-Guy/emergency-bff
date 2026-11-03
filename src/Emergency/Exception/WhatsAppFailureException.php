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
            'No se pudo enviar ningún mensaje de plantilla de WhatsApp.',
            'whatsapp_failure',
            ['failures' => $failures],
        );
    }
}
