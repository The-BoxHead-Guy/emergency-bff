<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

use DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException;
use DataEmergencia\EmergencyBff\Http\ApiClient;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\Endpoints;
use DataEmergencia\EmergencyBff\Support\ArrayReader;

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
        $body = $response->body();

        $attributes = $this->firstArray([
            ArrayReader::get($body, 'data.attributes'),
            ArrayReader::get($body, 'attributes'),
            ArrayReader::get($body, 'data'),
        ]);

        return [
            'code' => $this->string(ArrayReader::get($attributes, 'code'), $code),
            'domain' => $this->string(ArrayReader::get($attributes, 'domain'), ''),
            'balance' => $this->integer(ArrayReader::get($attributes, 'balance')),
            'contacts_count' => $this->integer(ArrayReader::get($attributes, 'contacts_count')),
            'rounds' => $this->integer(ArrayReader::get($attributes, 'rounds')),
            'extra_alerts' => $this->integer(ArrayReader::get($attributes, 'extra_alerts')),
            'rounds_label' => $this->string(ArrayReader::get($attributes, 'rounds_label'), ''),
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
        $body = $response->body();

        $data = ArrayReader::get($body, 'data');
        $data = is_array($data) ? $data : [];

        $items = ArrayReader::get($data, 'items');
        $items = is_array($items) ? $items : [];
        $pagination = ArrayReader::get($data, 'pagination');
        $pagination = is_array($pagination) ? $pagination : [];

        return [
            'items' => array_values(array_map(
                fn (mixed $item): array => $this->normalizeItem($item),
                $items,
            )),
            'pagination' => [
                'total' => $this->integer(ArrayReader::get($pagination, 'total')),
                'per_page' => $this->integer(ArrayReader::get($pagination, 'per_page'), $perPage),
                'current_page' => $this->integer(ArrayReader::get($pagination, 'current_page'), 1),
                'last_page' => $this->integer(ArrayReader::get($pagination, 'last_page'), 1),
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

        $attributes = ArrayReader::get($item, 'attributes');
        $attributes = is_array($attributes) ? $attributes : $item;
        $shape = array_merge($item, $attributes);

        return [
            'id' => $this->integer(ArrayReader::get($shape, 'id')),
            'type' => $this->string(ArrayReader::get($shape, 'type'), ''),
            'amount' => $this->integer(ArrayReader::get($shape, 'amount')),
            'balance_after' => $this->integer(ArrayReader::get($shape, 'balance_after')),
            'contacts_count' => $this->integer(ArrayReader::get($shape, 'contacts_count')),
            'rounds' => $this->integer(ArrayReader::get($shape, 'rounds')),
            'extra_alerts' => $this->integer(ArrayReader::get($shape, 'extra_alerts')),
            'emergency_id' => $this->nullableInteger(ArrayReader::get($shape, 'emergency_id')),
            'reference' => $this->nullableString(ArrayReader::get($shape, 'reference')),
            'created_at' => $this->nullableString(ArrayReader::get($shape, 'created_at')),
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
