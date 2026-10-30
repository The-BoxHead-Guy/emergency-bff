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
    public function create(string $code, array $responders, bool $isActive = true): array
    {
        try {
            $response = $this->client->post(Endpoints::postState(), [
                'is_active' => $isActive,
                'initiator_id' => $code,
                'responders' => $responders,
            ]);
        } catch (ApiException $exception) {
            throw new ApiFailureException('creating the emergency', $exception->status(), $exception);
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
            return $this->normalizeId($attributes);
        }

        $data = $body['data'] ?? null;

        return $this->normalizeId(is_array($data) ? $data : $body);
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

            $value = $shape[$key];

            $shape[$key] = match (true) {
                is_int($value) => (string) $value,
                is_string($value) && $value !== '' => $value,
                default => null,
            };
        }

        return $shape;
    }
}
