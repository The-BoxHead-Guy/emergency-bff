<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Emergency\Exception\ApiFailureException;
use DataEmergencia\EmergencyBff\Emergency\Exception\EmergencyNotFoundException;
use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\Endpoints;

/**
 * Proxies the existing emergency state endpoints.
 */
final class EmergencyRepository
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $responders
     * @return array<string, mixed>
     */
    public function create(string $code, array $responders, bool $isActive = true, ?string $reporterPhone = null): array
    {
        try {
            $response = $this->client->post(Endpoints::postState(), [
                'is_active' => $isActive,
                'initiator_id' => $code,
                'reporter_phone' => $reporterPhone,
                'responders' => $responders,
            ]);
        } catch (ApiException $exception) {
            throw new ApiFailureException('creating the emergency', $exception->status(), $exception);
        }

        return $this->normalize($response->body());
    }

    /**
     * Writes the emergency status and responder delivery data back upstream.
     *
     * @param array<int, array<string, mixed>> $responders
     * @return array<string, mixed>
     */
    public function update(string $emergencyId, string $code, array $responders, bool $isActive): array
    {
        try {
            $response = $this->client->request('PUT', Endpoints::update($emergencyId), [
                'is_active' => $isActive,
                'initiator_id' => $code,
                'responders' => $responders,
            ]);
        } catch (ApiException $exception) {
            throw new ApiFailureException('updating the emergency', $exception->status(), $exception);
        }

        return $this->normalize($response->body());
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $emergencyId): array
    {
        try {
            $response = $this->client->get(Endpoints::showState($emergencyId));
        } catch (ApiException $exception) {
            if ($exception->status() === 404) {
                throw new EmergencyNotFoundException($emergencyId);
            }

            throw new ApiFailureException('showing the emergency', $exception->status(), $exception);
        }

        return $this->normalize($response->body());
    }

    public function delete(string $emergencyId): bool
    {
        try {
            $this->client->delete(Endpoints::deleteState($emergencyId));
        } catch (ApiException $exception) {
            if ($exception->status() === 404) {
                throw new EmergencyNotFoundException($emergencyId);
            }

            throw new ApiFailureException('deleting the emergency', $exception->status(), $exception);
        }

        return true;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByCode(string $code): ?array
    {
        try {
            $response = $this->client->get(Endpoints::findActiveByQrCode($code));
        } catch (ApiException $exception) {
            if ($exception->status() === 404) {
                return null;
            }

            throw new ApiFailureException('finding the active emergency', $exception->status(), $exception);
        }

        $normalized = $this->normalize($response->body());

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function normalize(array $body): array
    {
        $attributes = $body['data']['attributes'] ?? $body['attributes'] ?? null;

        if (is_array($attributes)) {
            return $this->normalizeResponders($this->normalizeId($attributes));
        }

        $data = $body['data'] ?? null;

        return $this->normalizeResponders($this->normalizeId(is_array($data) ? $data : $body));
    }

    /**
     * @param array<string, mixed> $shape
     * @return array<string, mixed>
     */
    private function normalizeId(array $shape): array
    {
        foreach (['emergency_id', 'id'] as $key) {
            if (!array_key_exists($key, $shape)) {
                continue;
            }

            $shape[$key] = $this->identifier($shape[$key]);
        }

        return $shape;
    }

    /**
     * @param array<string, mixed> $shape
     * @return array<string, mixed>
     */
    private function normalizeResponders(array $shape): array
    {
        $responders = $shape['responders'] ?? null;

        if (!is_array($responders)) {
            return $shape;
        }

        $shape['responders'] = array_map(function (mixed $responder): mixed {
            if (!is_array($responder)) {
                return $responder;
            }

            $responder['message_id'] = $this->identifier($responder['message_id'] ?? null);

            return $responder;
        }, $responders);

        return $shape;
    }

    private function identifier(mixed $value): ?string
    {
        return match (true) {
            is_int($value) => (string) $value,
            is_string($value) && $value !== '' => $value,
            default => null,
        };
    }
}
