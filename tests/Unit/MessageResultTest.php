<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Tests\TestCase;
use DataEmergencia\EmergencyBff\WhatsApp\MessageResult;

final class MessageResultTest extends TestCase
{
    public function test_it_reads_the_wamid_from_the_real_send_envelope(): void
    {
        $body = $this->fixture('responses/send-success.json');

        self::assertSame('wamid.1', MessageResult::sent($body['data'])->messageId());
    }

    public function test_it_reads_the_wamid_from_an_attributes_only_payload(): void
    {
        $body = $this->fixture('responses/send-success.json');

        self::assertSame('wamid.1', MessageResult::sent($body['data']['attributes'])->messageId());
    }

    public function test_it_keeps_accepting_the_legacy_flat_message_id(): void
    {
        self::assertSame('wamid.legacy', MessageResult::sent(['message_id' => 'wamid.legacy'])->messageId());
    }

    public function test_it_never_returns_the_synthetic_envelope_or_internal_id(): void
    {
        $result = MessageResult::sent([
            'id' => 'success|42',
            'attributes' => ['id' => 42],
        ]);

        self::assertNull($result->messageId());
    }

    public function test_it_returns_null_when_details_are_missing(): void
    {
        $result = MessageResult::sent([
            'success' => true,
            'id' => 42,
        ]);

        self::assertNull($result->messageId());
    }

    public function test_it_does_not_treat_a_numeric_message_id_as_a_wamid(): void
    {
        self::assertNull(MessageResult::sent(['message_id' => 42])->messageId());
    }

    public function test_it_does_not_treat_a_numeric_string_as_a_wamid(): void
    {
        self::assertNull(MessageResult::sent(['message_id' => '42'])->messageId());
        self::assertNull(MessageResult::sent([
            'details' => ['messages' => [['id' => '42']]],
        ])->messageId());
    }

    public function test_it_accepts_a_real_wamid_across_the_supported_envelopes(): void
    {
        $wamid = 'wamid.HBgMNTg0MTIxMjM0NTY3FQIAERgS';

        self::assertSame($wamid, MessageResult::sent([
            'attributes' => ['details' => ['messages' => [['id' => $wamid]]]],
        ])->messageId());
        self::assertSame($wamid, MessageResult::sent([
            'details' => ['messages' => [['id' => $wamid]]],
        ])->messageId());
        self::assertSame($wamid, MessageResult::sent([
            'messages' => [['id' => $wamid]],
        ])->messageId());
        self::assertSame($wamid, MessageResult::sent([
            'attributes' => ['message_id' => $wamid],
        ])->messageId());
    }

    public function test_it_rejects_a_non_prefixed_id(): void
    {
        self::assertNull(MessageResult::sent(['message_id' => 'internal-id'])->messageId());
        self::assertNull(MessageResult::sent([
            'details' => ['messages' => [['id' => 'a1b2c3d4-0000-0000-0000-000000000000']]],
        ])->messageId());
        self::assertNull(MessageResult::sent([
            'attributes' => ['details' => ['messages' => [['id' => 'success|42']]]],
        ])->messageId());
    }

    public function test_it_rejects_a_bare_wamid_prefix(): void
    {
        self::assertNull(MessageResult::sent(['message_id' => 'wamid.'])->messageId());
        self::assertNull(MessageResult::sent([
            'details' => ['messages' => [['id' => 'wamid.']]],
        ])->messageId());
    }

    public function test_it_rejects_numeric_ids_across_the_supported_envelopes(): void
    {
        self::assertNull(MessageResult::sent([
            'attributes' => ['details' => ['messages' => [['id' => 42]]]],
        ])->messageId());
        self::assertNull(MessageResult::sent([
            'messages' => [['id' => 42]],
        ])->messageId());
        self::assertNull(MessageResult::sent([
            'attributes' => ['message_id' => '42'],
        ])->messageId());
    }
}
