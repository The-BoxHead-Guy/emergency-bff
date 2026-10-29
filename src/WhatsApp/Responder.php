<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\WhatsApp;

/**
 * A single emergency responder and the delivery state of its message.
 */
final class Responder
{
    public function __construct(
        public string $phone,
        public string $name,
        public bool $sent = false,
        public bool $delivered = false,
        public bool $read = false,
        public bool $failed = false,
        public ?string $messageId = null,
    ) {
    }

    /**
     * @param array<string, mixed> $contact
     */
    public static function fromContact(array $contact): self
    {
        return new self(
            phone: self::phoneFromContact($contact),
            name: is_string($contact['name'] ?? null) ? $contact['name'] : '',
            messageId: self::stringOrNull($contact['messageId'] ?? $contact['message_id'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $contact
     */
    public static function phoneFromContact(array $contact): string
    {
        $phones = $contact['phones'] ?? null;

        if (is_array($phones) && isset($phones[0]) && is_array($phones[0])) {
            $areaCode = (string) ($phones[0]['area_code'] ?? '');
            $number = (string) ($phones[0]['number'] ?? '');
            $phone = $areaCode.$number;

            if ($phone !== '') {
                return ltrim($phone, '+');
            }
        }

        foreach (['recipientId', 'recipient_id', 'phone'] as $key) {
            if (isset($contact[$key]) && is_string($contact[$key]) && $contact[$key] !== '') {
                return ltrim($contact[$key], '+');
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'phone' => $this->phone,
            'name' => $this->name,
            'sent' => $this->sent,
            'delivered' => $this->delivered,
            'read' => $this->read,
            'failed' => $this->failed,
            'message_id' => $this->messageId,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
