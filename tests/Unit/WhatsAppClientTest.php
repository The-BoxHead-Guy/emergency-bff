<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Api\WhatsAppClient;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class WhatsAppClientTest extends TestCase
{
    public function test_it_sends_one_activation_recipient_to_the_typed_endpoint(): void
    {
        $api = new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $this->fixture('responses/typed-send.json')));
        $client = new WhatsAppClient($api);

        $result = $client->sendActivation('573001234567', [
            'user_name' => 'Ada Lovelace',
            'emergency_code' => 'EMG1',
        ]);

        self::assertTrue($result->success);
        self::assertSame('wamid.1', $result->messageId());

        $call = $api->callsTo('POST', 'emergency/activation')[0];

        self::assertSame('/v2/whatsapp/templates/emergency/activation', $call['path']);
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
        ]);

        self::assertTrue($result->success);

        $call = $api->callsTo('POST', 'emergency/simulacrum')[0];

        self::assertSame('/v2/whatsapp/templates/emergency/simulacrum', $call['path']);
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
        ]);

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
        ]);

        self::assertFalse($result->success);
        self::assertNull($result->messageId());
    }
}
