<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

/**
 * JSON response value object.
 */
final class Response
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $status = 200,
        private ?array $body = null,
        private array $headers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public static function json(array $body, int $status = 200, array $headers = []): self
    {
        return new self($status, $body, $headers + ['Content-Type' => 'application/json; charset=utf-8']);
    }

    /**
     * @param array<string, string> $headers
     */
    public static function noContent(array $headers = []): self
    {
        return new self(204, null, $headers);
    }

    public function status(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function body(): ?array
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header(sprintf('%s: %s', $name, $value));
            }
        }

        if ($this->body !== null) {
            echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
    }
}
