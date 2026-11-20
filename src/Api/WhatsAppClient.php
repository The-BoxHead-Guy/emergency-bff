<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Emergency\Exception\InsufficientCreditsException;
use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\ClientMessages;
use DataEmergencia\EmergencyBff\Http\Endpoints;
use DataEmergencia\EmergencyBff\Support\ArrayReader;
use DataEmergencia\EmergencyBff\WhatsApp\MessageResult;

/**
 * Sends WhatsApp template payloads through the existing API.
 *
 * Failure messages are resolved from the Spanish `ClientMessages` catalog.
 */
final class WhatsAppClient
{
    public function __construct(private readonly ApiClient $client) {}

    /**
     * Sends the abort template payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public function send(array $payload): MessageResult
    {
        try {
            $response = $this->client->post(Endpoints::whatsappTemplates(), $payload);
        } catch (ApiException $exception) {
            return MessageResult::failed([
                'message' => ClientMessages::message($this->failureKey($exception)),
                'code' => $exception->errorCode(),
            ], $exception->status());
        }

        $body = $response->body();
        $data = ArrayReader::get($body, 'data');
        $attributes = ArrayReader::get($body, 'data.attributes');

        if (is_array($attributes)) {
            $data = $attributes;
        }

        return MessageResult::sent(
            is_array($data) ? $data : $body,
            $response->status(),
        );
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function sendActivation(string $phone, array $variables, string $emergencyId): MessageResult
    {
        return $this->sendTyped(Endpoints::whatsappEmergencyActivation(), $phone, $variables, $emergencyId);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    public function sendSimulacrum(string $phone, array $variables, string $emergencyId): MessageResult
    {
        return $this->sendTyped(Endpoints::whatsappEmergencySimulacrum(), $phone, $variables, $emergencyId);
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function sendTyped(string $path, string $phone, array $variables, string $emergencyId): MessageResult
    {
        try {
            $response = $this->client->post($path, [
                'emergency_id' => $emergencyId,
                'recipients' => [
                    [
                        'phone' => $phone,
                        'variables' => $variables,
                    ],
                ],
            ]);
        } catch (ApiException $exception) {
            if ($this->isInsufficientCredits($exception)) {
                return MessageResult::insufficientCredits(
                    ClientMessages::message(ClientMessages::FAILURE_INSUFFICIENT_CREDITS),
                );
            }

            return MessageResult::failed(
                [
                    'message' => ClientMessages::message($this->failureKey($exception)),
                    'code' => $exception->errorCode(),
                ],
                $exception->status()
            );
        }

        return $this->typedResult($response);
    }

    private function typedResult(ApiResponse $response): MessageResult
    {
        $body = $response->body();
        $result = ArrayReader::get($body, 'data.attributes.data.results.0');

        if (! is_array($result)) {
            return MessageResult::failed([
                'message' => ClientMessages::message(ClientMessages::FAILURE_RESULT_MISSING),
                'status' => $response->status(),
            ], $response->status());
        }

        $wamid = ArrayReader::get($result, 'whatsapp_message_id');
        $status = ArrayReader::get($result, 'status');

        if ($status === 'sent' && is_string($wamid) && str_starts_with($wamid, 'wamid.') && $wamid !== 'wamid.') {
            return MessageResult::sent($result, $response->status());
        }

        return MessageResult::failed([
            'message' => ClientMessages::message(ClientMessages::FAILURE_NOT_DELIVERED),
            'status' => $status,
        ], $response->status());
    }

    /**
     * Maps an upstream failure to the catalog key of its client copy.
     */
    private function failureKey(ApiException $exception): string
    {
        return match (true) {
            $this->isInsufficientCredits($exception) => ClientMessages::FAILURE_INSUFFICIENT_CREDITS,
            $exception->status() === 429 => ClientMessages::FAILURE_RATE_LIMITED,
            $exception->status() === 0 => ClientMessages::FAILURE_TRANSPORT,
            default => ClientMessages::FAILURE_SEND_FAILED,
        };
    }

    /**
     * Tells whether the upstream rejected a billed send for lack of credits.
     */
    private function isInsufficientCredits(ApiException $exception): bool
    {
        return $exception->status() === InsufficientCreditsException::STATUS
            && $exception->errorCode() === InsufficientCreditsException::UPSTREAM_CODE;
    }
}
