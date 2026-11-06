<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

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
        $responders = array_map(
            static function (array $responder): Responder {
                return new Responder(
                    phone: (string) ($responder['phone'] ?? ''),
                    name: (string) ($responder['name'] ?? ''),
                    sent: (bool) ($responder['sent'] ?? false),
                    delivered: (bool) ($responder['delivered'] ?? false),
                    read: (bool) ($responder['read'] ?? false),
                    failed: (bool) ($responder['failed'] ?? false),
                    messageId: isset($responder['message_id']) ? (string) $responder['message_id'] : null,
                );
            },
            is_array($payload['responders'] ?? null) ? $payload['responders'] : [],
        );

        return new self(
            emergencyId: isset($payload['emergency_id']) ? (string) $payload['emergency_id'] : null,
            responders: $responders,
            failures: is_array($payload['failures'] ?? null) ? $payload['failures'] : [],
            simulated: (bool) ($payload['simulated'] ?? false),
            snapshot: is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : [],
            reporterPhone: isset($payload['reporter_phone']) ? (string) $payload['reporter_phone'] : null,
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
