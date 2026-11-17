<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException;
use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\Endpoints;

/**
 * Normalizes the upstream credit summary and ledger payloads.
 */
final class CreditRepository
{
    public function __construct(private readonly ApiClient $client)
    {
    }

    /**
     * @return array{
     *     code: string,
     *     domain: string,
     *     balance: int,
     *     contacts_count: int,
     *     rounds: int,
     *     extra_alerts: int,
     *     rounds_label: string
     * }
     */
    public function summary(string $code): array
    {
        $response = $this->fetch(Endpoints::creditSummary($code), $code);

        $attributes = $this->firstArray([
            $response->body()['data']['attributes'] ?? null,
            $response->body()['attributes'] ?? null,
            $response->body()['data'] ?? null,
        ]);

        return [
            'code' => $this->string($attributes['code'] ?? null, $code),
            'domain' => $this->string($attributes['domain'] ?? null, ''),
            'balance' => $this->integer($attributes['balance'] ?? null),
            'contacts_count' => $this->integer($attributes['contacts_count'] ?? null),
            'rounds' => $this->integer($attributes['rounds'] ?? null),
            'extra_alerts' => $this->integer($attributes['extra_alerts'] ?? null),
            'rounds_label' => $this->string($attributes['rounds_label'] ?? null, ''),
        ];
    }

    /**
     * @return array{
     *     items: array<int, array<string, mixed>>,
     *     pagination: array{total: int, per_page: int, current_page: int, last_page: int}
     * }
     */
    public function history(string $code, int $perPage, int $page): array
    {
        $response = $this->fetch(Endpoints::creditHistory($code, $perPage, $page), $code);

        $data = $response->body()['data'] ?? null;
        $items = is_array($data) && is_array($data['items'] ?? null) ? $data['items'] : [];
        $pagination = is_array($data) && is_array($data['pagination'] ?? null) ? $data['pagination'] : [];

        return [
            'items' => array_values(array_map(
                fn (mixed $item): array => $this->normalizeItem($item),
                $items,
            )),
            'pagination' => [
                'total' => $this->integer($pagination['total'] ?? null),
                'per_page' => $this->integer($pagination['per_page'] ?? null, $perPage),
                'current_page' => $this->integer($pagination['current_page'] ?? null, 1),
                'last_page' => $this->integer($pagination['last_page'] ?? null, 1),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeItem(mixed $item): array
    {
        if (!is_array($item)) {
            $item = [];
        }

        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : $item;
        $shape = array_merge($item, $attributes);

        return [
            'id' => $this->integer($shape['id'] ?? null),
            'type' => $this->string($shape['type'] ?? null, ''),
            'amount' => $this->integer($shape['amount'] ?? null),
            'balance_after' => $this->integer($shape['balance_after'] ?? null),
            'contacts_count' => $this->integer($shape['contacts_count'] ?? null),
            'rounds' => $this->integer($shape['rounds'] ?? null),
            'extra_alerts' => $this->integer($shape['extra_alerts'] ?? null),
            'emergency_id' => $this->nullableInteger($shape['emergency_id'] ?? null),
            'reference' => $this->nullableString($shape['reference'] ?? null),
            'created_at' => $this->nullableString($shape['created_at'] ?? null),
        ];
    }

    private function fetch(string $path, string $code): ApiResponse
    {
        try {
            return $this->client->get($path);
        } catch (ApiException $exception) {
            if ($exception->status() === 404) {
                throw new InactiveCodeException($code);
            }

            throw $exception;
        }
    }

    /**
     * @param array<int, mixed> $candidates
     * @return array<string, mixed>
     */
    private function firstArray(array $candidates): array
    {
        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                return $candidate;
            }
        }

        return [];
    }

    private function integer(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    private function string(mixed $value, string $default = ''): string
    {
        return is_string($value) ? $value : $default;
    }

    private function nullableInteger(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_numeric($value) => (string) $value,
            default => null,
        };
    }
}
