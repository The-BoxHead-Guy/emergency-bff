<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency\Exception;

/**
 * Signals that the upstream API rejected a billed template send for lack of credits.
 */
final class InsufficientCreditsException extends EmergencyException
{
    public const CODE = 'insufficient_credits';

    public const STATUS = 402;

    public const UPSTREAM_CODE = 'INSUFFICIENT_CREDITS';

    public function __construct(string $code, ?string $upstreamMessage = null)
    {
        parent::__construct(
            $upstreamMessage ?? 'La cuenta no tiene créditos suficientes para enviar mensajes de WhatsApp.',
            self::CODE,
            [
                'code' => $code,
                'upstream_status' => self::STATUS,
                'upstream_code' => self::UPSTREAM_CODE,
            ],
        );
    }
}
