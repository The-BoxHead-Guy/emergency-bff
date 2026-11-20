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

    /**
     * Canonical upstream error code for a rejected billed send.
     */
    public const UPSTREAM_CODE = 'INSUFFICIENT_CREDITS';

    public function __construct(string $code)
    {
        parent::__construct(
            'The account does not have enough credits to send WhatsApp messages.',
            self::CODE,
            [
                'code' => $code,
                'upstream_status' => self::STATUS,
                'upstream_code' => self::UPSTREAM_CODE,
            ],
        );
    }
}
