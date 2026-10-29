<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\WhatsApp;

/**
 * Result of a single WhatsApp template send.
 */
final class MessageResult
{
    /**
     * @param array<string, mixed>|null $data
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        public readonly bool $success,
        public readonly ?array $data = null,
        public readonly int $status = 0,
        public readonly ?array $error = null,
    ) {
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public static function sent(?array $data, int $status = 200): self
    {
        return new self(true, $data, $status);
    }

    /**
     * @param array<string, mixed> $error
     */
    public static function failed(array $error, int $status = 0): self
    {
        return new self(false, null, $status, $error);
    }

    public function messageId(): ?string
    {
        $id = $this->data['message_id'] ?? $this->data['id'] ?? $this->data['messages'][0]['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'data' => $this->data,
            'status' => $this->status,
            'error' => $this->error,
        ];
    }
}
