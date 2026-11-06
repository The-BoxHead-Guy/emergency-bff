<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\Endpoints;
use DataEmergencia\EmergencyBff\WhatsApp\MessageResult;

/**
 * Sends WhatsApp template payloads through the existing API.
 */
final class WhatsAppClient
{
    public function __construct(private readonly ApiClient $client) {}

    /**
     * Sends the legacy payload used by the abort template.
     *
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

        if (is_array($data) && isset($data['attributes']) && is_array($data['attributes'])) {
            $data = $data['attributes'];
        }

        return MessageResult::sent(
            is_array($data) ? $data : $response->body(),
            $response->status(),
        );
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function sendActivation(string $phone, array $variables): MessageResult
    {
        return $this->sendTyped(Endpoints::whatsappEmergencyActivation(), $phone, $variables);
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function sendSimulacrum(string $phone, array $variables): MessageResult
    {
        return $this->sendTyped(Endpoints::whatsappEmergencySimulacrum(), $phone, $variables);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function sendTyped(string $path, string $phone, array $variables): MessageResult
    {
        try {
            $response = $this->client->post($path, [
                'recipients' => [
                    [
                        'phone' => $phone,
                        'variables' => $variables,
                    ],
                ],
            ]);
        } catch (ApiException $exception) {
            return MessageResult::failed(
                [
                    'message' => $exception->getMessage(),
                    'code' => $exception->errorCode(),
                    'details' => $exception->details(),
                ],
                $exception->status()
            );
        }

        return $this->typedResult($response);
    }

    private function typedResult(ApiResponse $response): MessageResult
    {
        $body = $response->body();
        $result = $body['data']['attributes']['data']['results'][0] ?? null;

        if (!is_array($result)) {
            return MessageResult::failed([
                'message' => 'The typed WhatsApp response did not include a recipient result.',
                'details' => $response->body(),
            ], $response->status());
        }

        $wamid = $result['whatsapp_message_id'] ?? null;
        $status = $result['status'] ?? null;

        if ($status === 'sent' && is_string($wamid) && str_starts_with($wamid, 'wamid.') && $wamid !== 'wamid.') {
            return MessageResult::sent($result, $response->status());
        }

        return MessageResult::failed([
            'message' => 'The WhatsApp template was not delivered to the recipient.',
            'status' => $status,
            'details' => $result,
        ], $response->status());
    }
}
