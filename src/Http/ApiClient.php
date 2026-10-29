<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Http;

/**
 * Minimal HTTP client contract used by the BFF.
 *
 * Implementations map transport failures and non-2xx responses to
 * {@see ApiException} so callers only deal with successful responses.
 */
interface ApiClient
{
    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     */
    public function request(string $method, string $path, ?array $body = null, array $headers = []): ApiResponse;

    /**
     * @param array<string, string> $headers
     */
    public function get(string $path, array $headers = []): ApiResponse;

    /**
     * @param array<string, string> $headers
     */
    public function head(string $path, array $headers = []): ApiResponse;

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    public function post(string $path, array $body = [], array $headers = []): ApiResponse;

    /**
     * @param array<string, string> $headers
     */
    public function delete(string $path, array $headers = []): ApiResponse;
}
