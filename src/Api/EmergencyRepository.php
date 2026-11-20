<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Emergency\Exception\ApiFailureException;
use DataEmergencia\EmergencyBff\Emergency\Exception\EmergencyNotFoundException;
use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\Endpoints;
use DataEmergencia\EmergencyBff\Support\ArrayReader;

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
     * @param array<string, mixed>|null $geolocation
     * @return array<string, mixed>
     */
    public function create(
        string $code,
        array $responders,
        bool $isActive = true,
        ?string $reporterPhone = null,
        ?array $geolocation = null,
        bool $isSimulated = false,
    ): array {
        $payload = [
            'is_active' => $isActive,
            'initiator_id' => $code,
            'reporter_phone' => $reporterPhone,
            'responders' => $responders,
            'is_simulated' => $isSimulated,
        ];

        if ($geolocation !== null) {
            $payload['geolocation'] = $geolocation;
        }

        try {
            $response = $this->client->post(Endpoints::postState(), $payload);
        } catch (ApiException $exception) {
            throw new ApiFailureException('create_emergency', $exception->status(), $exception);
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
            throw new ApiFailureException('update_emergency', $exception->status(), $exception);
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

            throw new ApiFailureException('show_emergency', $exception->status(), $exception);
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

            throw new ApiFailureException('delete_emergency', $exception->status(), $exception);
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

            throw new ApiFailureException('find_active_emergency', $exception->status(), $exception);
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
        $attributes = ArrayReader::get($body, 'data.attributes') ?? ArrayReader::get($body, 'attributes');

        if (is_array($attributes)) {
            return $this->normalizeResponders($this->normalizeId($attributes));
        }

        $data = ArrayReader::get($body, 'data');

        return $this->normalizeResponders($this->normalizeId(is_array($data) ? $data : $body));
    }

    /**
     * @param array<string, mixed> $shape
     * @return array<string, mixed>
     */
    private function normalizeId(array $shape): array
    {
        foreach (['emergency_id', 'id'] as $key) {
            if (!ArrayReader::has($shape, $key)) {
                continue;
            }

            $shape[$key] = $this->identifier(ArrayReader::get($shape, $key));
        }

        return $shape;
    }

    /**
     * @param array<string, mixed> $shape
     * @return array<string, mixed>
     */
    private function normalizeResponders(array $shape): array
    {
        $responders = ArrayReader::get($shape, 'responders');

        if (!is_array($responders)) {
            return $shape;
        }

        $shape['responders'] = array_map(function (mixed $responder): mixed {
            if (!is_array($responder)) {
                return $responder;
            }

            $responder['message_id'] = $this->identifier(ArrayReader::get($responder, 'message_id'));

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
