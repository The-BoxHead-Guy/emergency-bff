<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Emergency\Exception\ApiFailureException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingProfileException;
use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\Endpoints;

/**
 * Fetches the medical profile from the existing FMD endpoint.
 */
final class ProfileRepository
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    public function fetch(string $code): Profile
    {
        try {
            $response = $this->client->get(Endpoints::getProfileInfo($code));
        } catch (ApiException $exception) {
            if ($exception->status() === 404) {
                throw new MissingProfileException($code);
            }

            throw new ApiFailureException('fetching the medical profile', $exception->status(), $exception);
        }

        $data = $this->extractData($response->body());

        if ($data === null) {
            throw new MissingProfileException($code);
        }

        return new Profile(
            $code,
            $this->extractPersonalInfo($data),
            $this->extractProductCodes($data),
            $this->extractEmergencyContacts($data),
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>|null
     */
    private function extractData(array $body): ?array
    {
        $candidates = [
            $body['data']['attributes']['data'] ?? null,
            $body['attributes']['data'] ?? null,
            $body['data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function extractPersonalInfo(array $data): array
    {
        if (isset($data['personal_info']) && is_array($data['personal_info'])) {
            return $data['personal_info'];
        }

        if (isset($data['personal']['personal_info']) && is_array($data['personal']['personal_info'])) {
            return $data['personal']['personal_info'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    private function extractProductCodes(array $data): array
    {
        $codes = $data['product_codes'] ?? [];

        return is_array($codes) ? array_filter($codes, 'is_string') : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array<string, mixed>>
     */
    private function extractEmergencyContacts(array $data): array
    {
        $contacts = $data['emergency_info'] ?? $data['emergencia']['emergency_info'] ?? [];

        if (!is_array($contacts)) {
            return [];
        }

        return array_values(array_filter($contacts, 'is_array'));
    }
}
