<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\Endpoints;
use DataEmergencia\EmergencyBff\WhatsApp\MessageResult;

/**
 * Sends WhatsApp template payloads through the existing API.
 */
final class WhatsAppClient
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function send(array $payload): MessageResult
    {
        try {
            $response = $this->client->post(Endpoints::whatsappTemplates(), $payload);
        } catch (ApiException $exception) {
            return MessageResult::failed([
                'message' => $exception->getMessage(),
                'code' => $exception->errorCode(),
                'details' => $exception->details(),
            ], $exception->status());
        }

        $data = $response->body()['data'] ?? null;

        return MessageResult::sent(
            is_array($data) ? $data : $response->body(),
            $response->status(),
        );
    }
}
