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

    /**
     * Returns the WhatsApp WAMID from the send response.
     */
    public function messageId(): ?string
    {
        return $this->wamid($this->data);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function wamid(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        $paths = [
            ['attributes', 'details', 'messages', 0, 'id'],
            ['details', 'messages', 0, 'id'],
            ['messages', 0, 'id'],
            ['message_id'],
            ['attributes', 'message_id'],
        ];

        foreach ($paths as $path) {
            $value = $data;

            foreach ($path as $segment) {
                if (!is_array($value) || !array_key_exists($segment, $value)) {
                    continue 2;
                }

                $value = $value[$segment];
            }

            if (is_string($value) && str_starts_with($value, 'wamid.') && $value !== 'wamid.') {
                return $value;
            }
        }

        return null;
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
