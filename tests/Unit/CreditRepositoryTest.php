<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Api\CreditRepository;
use DataEmergencia\EmergencyBff\Emergency\Exception\InactiveCodeException;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class CreditRepositoryTest extends TestCase
{
    public function test_it_normalizes_the_credit_summary_and_calls_the_summary_endpoint(): void
    {
        $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $this->fixture('responses/credits.json')));

        $summary = (new CreditRepository($client))->summary('DABC12');

        self::assertSame([
            'code' => 'DABC12',
            'domain' => 'emergency',
            'balance' => 9,
            'contacts_count' => 1,
            'rounds' => 9,
            'extra_alerts' => 0,
            'rounds_label' => '9 rounds',
        ], $summary);
        self::assertCount(1, $client->calls);
        self::assertSame('GET', $client->calls[0]['method']);
        self::assertSame('/v2/credits/DABC12', $client->calls[0]['path']);
    }

    public function test_it_reads_the_summary_from_legacy_envelope_shapes(): void
    {
        $attributes = [
            'code' => 'DABC12',
            'domain' => 'emergency',
            'balance' => 3,
            'contacts_count' => 2,
            'rounds' => 3,
            'extra_alerts' => 1,
            'rounds_label' => '3 rounds',
        ];

        foreach ([
            ['attributes' => $attributes],
            ['data' => $attributes],
        ] as $body) {
            $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $body));

            self::assertSame($attributes, (new CreditRepository($client))->summary('DABC12'));
        }
    }

    public function test_it_normalizes_the_history_items_and_pagination(): void
    {
        $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $this->fixture('responses/credit-history.json')));

        $history = (new CreditRepository($client))->history('DABC12', 2, 1);

        self::assertSame(['total' => 3, 'per_page' => 15, 'current_page' => 1, 'last_page' => 1], $history['pagination']);
        self::assertCount(3, $history['items']);
        self::assertSame([
            'id' => 3,
            'type' => 'refund',
            'amount' => 1,
            'balance_after' => 10,
            'contacts_count' => 1,
            'rounds' => 10,
            'extra_alerts' => 0,
            'emergency_id' => 501,
            'reference' => 'refund:EMG000501',
            'created_at' => '2026-11-16T09:30:00+00:00',
        ], $history['items'][0]);
        self::assertNull($history['items'][2]['emergency_id']);
        self::assertSame('/v2/credits/DABC12/history?per_page=2&page=1', $client->calls[0]['path']);
    }

    public function test_it_accepts_flat_history_items_and_coerces_types(): void
    {
        $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, [
            'data' => [
                'items' => [
                    [
                        'id' => '7',
                        'type' => 'emergency',
                        'amount' => '-1',
                        'balance_after' => '4',
                        'contacts_count' => 1,
                        'rounds' => 4,
                        'extra_alerts' => 0,
                        'emergency_id' => '900',
                        'reference' => 1234,
                        'created_at' => null,
                    ],
                    [
                        'id' => 8,
                        'type' => 'recharge',
                        'amount' => 5,
                        'balance_after' => 5,
                        'emergency_id' => null,
                    ],
                ],
            ],
        ]));

        $history = (new CreditRepository($client))->history('DABC12', 15, 1);

        self::assertSame(7, $history['items'][0]['id']);
        self::assertSame(-1, $history['items'][0]['amount']);
        self::assertSame(900, $history['items'][0]['emergency_id']);
        self::assertSame('1234', $history['items'][0]['reference']);
        self::assertNull($history['items'][0]['created_at']);
        self::assertNull($history['items'][1]['emergency_id']);
        self::assertSame(['total' => 0, 'per_page' => 15, 'current_page' => 1, 'last_page' => 1], $history['pagination']);
    }

    public function test_it_degrades_empty_payloads_instead_of_throwing(): void
    {
        $client = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200));

        self::assertSame([
            'code' => 'DABC12',
            'domain' => '',
            'balance' => 0,
            'contacts_count' => 0,
            'rounds' => 0,
            'extra_alerts' => 0,
            'rounds_label' => '',
        ], (new CreditRepository($client))->summary('DABC12'));

        self::assertSame([
            'items' => [],
            'pagination' => ['total' => 0, 'per_page' => 5, 'current_page' => 1, 'last_page' => 1],
        ], (new CreditRepository($client))->history('DABC12', 5, 2));
    }

    public function test_a_404_becomes_an_inactive_code_exception(): void
    {
        $client = new FakeApiClient(fn (): ApiException => ApiException::fromResponse(new ApiResponse(404)));

        $this->expectException(InactiveCodeException::class);

        (new CreditRepository($client))->summary('DABC12');
    }

    public function test_a_history_404_becomes_an_inactive_code_exception(): void
    {
        $client = new FakeApiClient(fn (): ApiException => ApiException::fromResponse(new ApiResponse(404)));

        $this->expectException(InactiveCodeException::class);

        (new CreditRepository($client))->history('DABC12', 15, 1);
    }

    public function test_it_rethrows_non_404_api_failures_unchanged(): void
    {
        $client = new FakeApiClient(fn (): ApiException => ApiException::fromResponse(new ApiResponse(503, [
            'message' => 'Unavailable',
        ])));

        try {
            (new CreditRepository($client))->summary('DABC12');
            self::fail('Expected an API exception.');
        } catch (ApiException $exception) {
            self::assertSame(503, $exception->status());
        }
    }
}
