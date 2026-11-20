<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\WhatsApp;

use DataEmergencia\EmergencyBff\Support\ArrayReader;

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
        $name = ArrayReader::get($contact, 'name');

        return new self(
            phone: self::phoneFromContact($contact),
            name: is_string($name) ? $name : '',
            messageId: self::messageIdOrNull(ArrayReader::get($contact, 'messageId') ?? ArrayReader::get($contact, 'message_id')),
        );
    }

    /**
     * @param array<string, mixed> $contact
     */
    public static function phoneFromContact(array $contact): string
    {
        if (is_array(ArrayReader::get($contact, 'phones.0'))) {
            $areaCode = (string) ArrayReader::get($contact, 'phones.0.area_code', '');
            $number = (string) ArrayReader::get($contact, 'phones.0.number', '');
            $phone = $areaCode.$number;

            if ($phone !== '') {
                return ltrim($phone, '+');
            }
        }

        foreach (['recipientId', 'recipient_id', 'phone'] as $key) {
            $value = ArrayReader::get($contact, $key);

            if ($value !== null && is_string($value) && $value !== '') {
                return ltrim($value, '+');
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

    private static function messageIdOrNull(mixed $value): ?string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) && $value !== '' => $value,
            default => null,
        };
    }
}
