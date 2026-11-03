<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Api\EmergencyRepository;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class EmergencyRepositoryTest extends TestCase
{
    public function test_it_normalizes_responder_message_ids_on_show(): void
    {
        $body = $this->fixture('responses/emergency-with-responders.json');
        $first = $body['data']['attributes']['responders'][0];

        $body['data']['attributes']['responders'] = [
            $first,
            $this->responder(5012, 'wamid.existing'),
            $this->responder(5013, null),
            $this->responder(5014, null, false),
            $this->responder(5015, ['42']),
        ];

        $normalized = $this->repository($body)->show('987');

        self::assertSame('987', $normalized['emergency_id']);
        self::assertSame('42', $normalized['responders'][0]['message_id']);
        self::assertSame('wamid.existing', $normalized['responders'][1]['message_id']);
        self::assertNull($normalized['responders'][2]['message_id']);
        self::assertArrayHasKey('message_id', $normalized['responders'][3]);
        self::assertNull($normalized['responders'][3]['message_id']);
        self::assertNull($normalized['responders'][4]['message_id']);
    }

    public function test_it_leaves_other_responder_fields_untouched(): void
    {
        $body = $this->fixture('responses/emergency-with-responders.json');

        $responder = $this->repository($body)->show('987')['responders'][0];

        self::assertSame(5011, $responder['id']);
        self::assertSame(987, $responder['emergency_id']);
        self::assertSame('María Pérez', $responder['name']);
        self::assertSame('584121234567', $responder['phone']);
        self::assertTrue($responder['sent']);
        self::assertFalse($responder['delivered']);
        self::assertFalse($responder['read']);
        self::assertFalse($responder['failed']);
        self::assertNull($responder['last_messages']);
    }

    public function test_it_normalizes_responder_message_ids_on_the_active_lookup(): void
    {
        $body = $this->fixture('responses/emergency-with-responders.json');

        $normalized = $this->repository($body)->findActiveByCode('DABC12');

        self::assertIsArray($normalized);
        self::assertSame('42', $normalized['responders'][0]['message_id']);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function repository(array $body): EmergencyRepository
    {
        return new EmergencyRepository(new FakeApiClient(
            static fn (): ApiResponse => new ApiResponse(200, $body),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function responder(int $id, mixed $messageId = null, bool $withMessageId = true): array
    {
        $responder = [
            'id' => $id,
            'emergency_id' => 987,
            'name' => 'Responder '.$id,
            'phone' => '584121234567',
            'sent' => true,
            'delivered' => false,
            'read' => false,
            'failed' => false,
        ];

        if ($withMessageId) {
            $responder['message_id'] = $messageId;
        }

        return $responder;
    }
}
