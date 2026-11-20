<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Emergency\Exception\WhatsAppFailureException;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Http\ClientMessages;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class WhatsAppClientTest extends TestCase
{
    public function test_it_sends_one_activation_recipient_to_the_typed_endpoint(): void
    {
        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $this->fixture('responses/typed-send.json')));
        $client = new WhatsAppClient($api);

        $result = $client->sendActivation('573001234567', [
            'user_name' => 'Ada Lovelace',
            'emergency_code' => 'EMG1',
        ], 'EMG1');

        self::assertTrue($result->success);
        self::assertSame('wamid.1', $result->messageId());

        $call = $api->callsTo('POST', 'emergency/activation')[0];

        self::assertSame('/v2/whatsapp/templates/emergency/activation', $call['path']);
        self::assertSame('EMG1', $call['body']['emergency_id']);
        self::assertSame([
            [
                'phone' => '573001234567',
                'variables' => [
                    'user_name' => 'Ada Lovelace',
                    'emergency_code' => 'EMG1',
                ],
            ],
        ], $call['body']['recipients']);
    }

    public function test_it_sends_one_simulacrum_recipient_to_the_typed_endpoint(): void
    {
        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $this->fixture('responses/typed-send.json')));
        $client = new WhatsAppClient($api);

        $result = $client->sendSimulacrum('573001234567', [
            'contact_name' => 'Grace Hopper',
            'user_name' => 'Ada Lovelace',
            'emergency_code' => 'EMG1',
        ], 'EMG1');

        self::assertTrue($result->success);

        $call = $api->callsTo('POST', 'emergency/simulacrum')[0];

        self::assertSame('/v2/whatsapp/templates/emergency/simulacrum', $call['path']);
        self::assertSame('EMG1', $call['body']['emergency_id']);
        self::assertSame([
            [
                'phone' => '573001234567',
                'variables' => [
                    'contact_name' => 'Grace Hopper',
                    'user_name' => 'Ada Lovelace',
                    'emergency_code' => 'EMG1',
                ],
            ],
        ], $call['body']['recipients']);
    }

    public function test_it_sends_one_abort_recipient_to_the_typed_endpoint(): void
    {
        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $this->fixture('responses/typed-abort.json')));
        $client = new WhatsAppClient($api);

        $result = $client->sendAbort('573001234567', ['user_name' => 'Ada Lovelace'], 'EMG1');

        self::assertTrue($result->success);
        self::assertSame('wamid.abort1', $result->messageId());

        $call = $api->callsTo('POST', 'emergency/abort')[0];

        self::assertSame('/v2/whatsapp/templates/emergency/abort', $call['path']);
        self::assertSame('EMG1', $call['body']['emergency_id']);
        self::assertSame([
            [
                'phone' => '573001234567',
                'variables' => ['user_name' => 'Ada Lovelace'],
            ],
        ], $call['body']['recipients']);
    }

    public function test_a_200_with_a_failed_abort_result_is_a_failed_message(): void
    {
        $body = $this->fixture('responses/typed-abort.json');
        $body['data']['attributes']['data']['results'][0]['status'] = 'failed';
        $body['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = null;

        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $body));
        $client = new WhatsAppClient($api);

        $result = $client->sendAbort('573001234567', ['user_name' => 'Ada Lovelace'], 'EMG1');

        self::assertFalse($result->success);
        self::assertSame('failed', $result->error['status']);
        self::assertSame(
            ClientMessages::message(ClientMessages::FAILURE_NOT_DELIVERED),
            $result->error['message']
        );
    }

    public function test_a_200_without_a_nested_abort_result_is_a_failed_message(): void
    {
        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, ['data' => ['attributes' => []]]));
        $client = new WhatsAppClient($api);

        $result = $client->sendAbort('573001234567', ['user_name' => 'Ada Lovelace'], 'EMG1');

        self::assertFalse($result->success);
        self::assertSame(
            ClientMessages::message(ClientMessages::FAILURE_RESULT_MISSING),
            $result->error['message']
        );
    }

    public function test_a_sent_abort_without_a_wamid_is_a_failed_message(): void
    {
        $body = $this->fixture('responses/typed-abort.json');
        $body['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = null;

        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $body));
        $client = new WhatsAppClient($api);

        $result = $client->sendAbort('573001234567', ['user_name' => 'Ada Lovelace'], 'EMG1');

        self::assertFalse($result->success);
        self::assertNull($result->messageId());
        self::assertSame(
            ClientMessages::message(ClientMessages::FAILURE_NOT_DELIVERED),
            $result->error['message']
        );
    }

    public function test_an_abort_without_credits_reports_the_catalog_copy(): void
    {
        $api = new FakeApiClient(static fn (): ApiResponse => throw ApiException::fromResponse(
            new ApiResponse(402, ['errors' => [['code' => 'INSUFFICIENT_CREDITS']]]),
        ));
        $client = new WhatsAppClient($api);

        $result = $client->sendAbort('573001234567', ['user_name' => 'Ada Lovelace'], 'EMG1');

        self::assertFalse($result->success);
        self::assertTrue($result->insufficientCredits);
        self::assertSame(
            ClientMessages::message(ClientMessages::FAILURE_INSUFFICIENT_CREDITS),
            $result->error['message']
        );
    }

    public function test_a_200_with_a_failed_result_is_a_failed_message(): void
    {
        $body = $this->fixture('responses/typed-send.json');
        $body['data']['attributes']['data']['results'][0]['status'] = 'failed';
        $body['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = null;

        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $body));
        $client = new WhatsAppClient($api);

        $result = $client->sendActivation('573001234567', [
            'user_name' => 'Ada Lovelace',
            'emergency_code' => 'EMG1',
        ], 'EMG1');

        self::assertFalse($result->success);
        self::assertNull($result->messageId());
        self::assertSame(200, $result->status);
        self::assertSame('failed', $result->error['status']);
    }

    public function test_a_200_without_a_nested_result_is_a_failed_message(): void
    {
        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, ['data' => ['attributes' => []]]));
        $client = new WhatsAppClient($api);

        $result = $client->sendActivation('573001234567', [
            'user_name' => 'Ada Lovelace',
            'emergency_code' => 'EMG1',
        ], 'EMG1');

        self::assertFalse($result->success);
        self::assertNull($result->messageId());
        self::assertSame(
            ClientMessages::message(ClientMessages::FAILURE_RESULT_MISSING),
            $result->error['message']
        );
    }

    public function test_a_failed_result_reports_the_delivery_copy(): void
    {
        $body = $this->fixture('responses/typed-send.json');
        $body['data']['attributes']['data']['results'][0]['status'] = 'failed';
        $body['data']['attributes']['data']['results'][0]['whatsapp_message_id'] = null;

        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $body));
        $client = new WhatsAppClient($api);

        $result = $client->sendActivation('573001234567', [
            'user_name' => 'Ada Lovelace',
            'emergency_code' => 'EMG1',
        ], 'EMG1');

        self::assertFalse($result->success);
        self::assertSame(
            ClientMessages::message(ClientMessages::FAILURE_NOT_DELIVERED),
            $result->error['message']
        );
        self::assertSame('failed', $result->error['status']);
    }

    #[DataProvider('failureProvider')]
    public function test_an_upstream_failure_reports_the_catalog_copy(
        ApiException $exception,
        string $expectedKey,
        int $expectedStatus
    ): void {
        $api = new FakeApiClient(static fn (): ApiResponse => throw $exception);
        $client = new WhatsAppClient($api);

        $result = $client->sendActivation('573001234567', [
            'user_name' => 'Ada Lovelace',
            'emergency_code' => 'EMG1',
        ], 'EMG1');

        self::assertFalse($result->success);
        self::assertTrue(ClientMessages::has($expectedKey));
        self::assertSame(ClientMessages::message($expectedKey), $result->error['message']);
        self::assertSame($expectedStatus, $result->status);
        self::assertStringNotContainsString('Unavailable', json_encode($result->error, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('cURL error', json_encode($result->error, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('failureProvider')]
    public function test_an_upstream_failure_reports_the_catalog_copy_on_the_legacy_path(
        ApiException $exception,
        string $expectedKey,
        int $expectedStatus
    ): void {
        $api = new FakeApiClient(static fn (): ApiResponse => throw $exception);
        $client = new WhatsAppClient($api);

        $result = $client->send(['template' => 'emergency_status_aborting_v1']);

        self::assertFalse($result->success);
        self::assertTrue(ClientMessages::has($expectedKey));
        self::assertSame(ClientMessages::message($expectedKey), $result->error['message']);
        self::assertSame($expectedStatus, $result->status);
        self::assertArrayNotHasKey('details', $result->error);
    }

    public function test_the_internal_exception_messages_stay_in_english(): void
    {
        $apiException = ApiException::fromResponse(new ApiResponse(503, ['message' => 'Unavailable']));

        self::assertSame('Unavailable', $apiException->getMessage());

        $failure = new WhatsAppFailureException([]);

        self::assertSame('No WhatsApp template message could be delivered.', $failure->getMessage());
    }

    /**
     * @return array<string, array{ApiException, string, int}>
     */
    public static function failureProvider(): array
    {
        return [
            'insufficient credits' => [
                ApiException::fromResponse(new ApiResponse(402, [
                    'errors' => [['code' => 'INSUFFICIENT_CREDITS', 'detail' => 'Unavailable']],
                ])),
                ClientMessages::FAILURE_INSUFFICIENT_CREDITS,
                402,
            ],
            'rate limited' => [
                ApiException::fromResponse(new ApiResponse(429, ['message' => 'Unavailable'])),
                ClientMessages::FAILURE_RATE_LIMITED,
                429,
            ],
            'transport' => [
                ApiException::transport('cURL error 28: Operation timed out'),
                ClientMessages::FAILURE_TRANSPORT,
                0,
            ],
            'payment required without the canonical upstream code' => [
                ApiException::fromResponse(new ApiResponse(402, ['code' => 'PAYMENT_REQUIRED', 'message' => 'Unavailable'])),
                ClientMessages::FAILURE_SEND_FAILED,
                402,
            ],
            'server error' => [
                ApiException::fromResponse(new ApiResponse(500, ['message' => 'Unavailable'])),
                ClientMessages::FAILURE_SEND_FAILED,
                500,
            ],
        ];
    }
}
