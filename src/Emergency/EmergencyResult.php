<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

use DataEmergencia\EmergencyBff\Support\ArrayReader;
use DataEmergencia\EmergencyBff\WhatsApp\Responder;

/**
 * Result of an activation or simulacrum run.
 */
final class EmergencyResult
{
    /**
     * @param array<int, Responder> $responders
     * @param array<int, array<string, mixed>> $failures
     */
    public function __construct(
        public readonly ?string $emergencyId,
        public readonly array $responders,
        public readonly array $failures = [],
        public readonly bool $simulated = false,
        public readonly array $snapshot = [],
        public readonly ?string $reporterPhone = null,
    ) {
    }

    public function isPartialFailure(): bool
    {
        return $this->failures !== [] && count($this->failures) < count($this->responders);
    }

    public function isTotalFailure(): bool
    {
        return $this->responders !== [] && count($this->failures) === count($this->responders);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromArray(array $payload): self
    {
        $payloadResponders = ArrayReader::get($payload, 'responders');
        $payloadResponders = is_array($payloadResponders) ? $payloadResponders : [];

        $responders = array_map(
            static function (array $responder): Responder {
                $messageId = ArrayReader::get($responder, 'message_id');

                return new Responder(
                    phone: (string) ArrayReader::get($responder, 'phone', ''),
                    name: (string) ArrayReader::get($responder, 'name', ''),
                    sent: (bool) ArrayReader::get($responder, 'sent', false),
                    delivered: (bool) ArrayReader::get($responder, 'delivered', false),
                    read: (bool) ArrayReader::get($responder, 'read', false),
                    failed: (bool) ArrayReader::get($responder, 'failed', false),
                    messageId: $messageId !== null ? (string) $messageId : null,
                );
            },
            $payloadResponders,
        );

        $emergencyId = ArrayReader::get($payload, 'emergency_id');
        $failures = ArrayReader::get($payload, 'failures');
        $snapshot = ArrayReader::get($payload, 'snapshot');
        $reporterPhone = ArrayReader::get($payload, 'reporter_phone');

        return new self(
            emergencyId: $emergencyId !== null ? (string) $emergencyId : null,
            responders: $responders,
            failures: is_array($failures) ? $failures : [],
            simulated: (bool) ArrayReader::get($payload, 'simulated', false),
            snapshot: is_array($snapshot) ? $snapshot : [],
            reporterPhone: $reporterPhone !== null ? (string) $reporterPhone : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'emergency_id' => $this->emergencyId,
            'simulated' => $this->simulated,
            'reporter_phone' => $this->reporterPhone,
            'responders' => array_map(
                static fn (Responder $responder): array => $responder->toArray(),
                $this->responders,
            ),
            'failures' => $this->failures,
            'partial_failure' => $this->isPartialFailure(),
            'total_failure' => $this->isTotalFailure(),
            'snapshot' => $this->snapshot,
        ];
    }
}
