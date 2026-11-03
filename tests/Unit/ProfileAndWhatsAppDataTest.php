<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Api\ProfileRepository;
use DataEmergencia\EmergencyBff\Emergency\Exception\ApiFailureException;
use DataEmergencia\EmergencyBff\Emergency\Exception\MissingProfileException;
use DataEmergencia\EmergencyBff\Http\ApiException;
use DataEmergencia\EmergencyBff\Http\ApiResponse;
use DataEmergencia\EmergencyBff\Tests\Support\FakeApiClient;
use DataEmergencia\EmergencyBff\Tests\TestCase;
use DataEmergencia\EmergencyBff\WhatsApp\ProductCodeSelector;
use DataEmergencia\EmergencyBff\WhatsApp\Responder;
use DataEmergencia\EmergencyBff\WhatsApp\ResponderFactory;

final class ProfileAndWhatsAppDataTest extends TestCase
{
    public function test_it_parses_the_profile_envelope(): void
    {
        $repository = new ProfileRepository(new FakeApiClient(
            fn (): ApiResponse => new ApiResponse(200, $this->fixture('responses/success.json')),
        ));

        $profile = $repository->fetch('DABC12');

        self::assertSame('DABC12', $profile->code);
        self::assertSame('Ada Lovelace', $profile->fullName());
        self::assertSame('DABC12', $profile->productCodes['dte_d']);
        self::assertCount(1, $profile->emergencyContacts);
        self::assertTrue($profile->hasPersonalInfo());
        self::assertTrue($profile->hasContacts());
    }

    public function test_it_tolerates_the_flattened_model_shape(): void
    {
        $body = [
            'data' => [
                'attributes' => [
                    'data' => [
                        'product_codes' => ['dte' => 'A12345'],
                        'personal' => ['personal_info' => ['full_name' => 'Ada Lovelace']],
                        'emergencia' => ['emergency_info' => [['name' => 'Grace Hopper']]],
                    ],
                ],
            ],
        ];

        $profile = (new ProfileRepository(new FakeApiClient(fn (): ApiResponse => new ApiResponse(200, $body))))
            ->fetch('A12345');

        self::assertSame('Ada Lovelace', $profile->fullName());
        self::assertSame('A12345', $profile->productCodes['dte']);
        self::assertCount(1, $profile->emergencyContacts);
    }

    public function test_it_maps_a_missing_profile(): void
    {
        $repository = new ProfileRepository(new FakeApiClient(
            fn (): ApiException => ApiException::fromResponse(new ApiResponse(404)),
        ));

        $this->expectException(MissingProfileException::class);

        $repository->fetch('DABC12');
    }

    public function test_it_maps_upstream_failures(): void
    {
        $repository = new ProfileRepository(new FakeApiClient(
            fn (): ApiException => ApiException::fromResponse(new ApiResponse(500)),
        ));

        $this->expectException(ApiFailureException::class);

        $repository->fetch('DABC12');
    }

    public function test_the_product_selector_matches_the_frontend_rules(): void
    {
        $selector = new ProductCodeSelector();

        self::assertSame('DABC12', $selector->findValidProductCode([
            'panic_button' => 'PABC12',
            'dte_d' => 'DABC12',
        ]));

        self::assertSame('PABC12', $selector->findValidProductCode([
            'panic_button' => 'PABC12',
        ], false));

        self::assertSame('DABC12', $selector->findValidProductCode(['dte' => 'DABC12']));
        self::assertSame('DXYZ12', $selector->findValidProductCode([
            'dte' => 'DABC12',
            'carnet' => 'QABC12',
            'dte_d' => 'DXYZ12',
        ]));
        self::assertNull($selector->findValidProductCode([]));
    }

    public function test_the_responder_factory_normalizes_phones(): void
    {
        $responders = (new ResponderFactory())->createFromContacts([
            [
                'name' => 'Grace Hopper',
                'phones' => [['area_code' => '+57', 'number' => '3001234567']],
                'messageId' => 'wamid.1',
            ],
            ['name' => 'No phone'],
        ]);

        self::assertCount(2, $responders);
        self::assertSame('573001234567', $responders[0]->phone);
        self::assertSame('wamid.1', $responders[0]->messageId);
        self::assertSame('', $responders[1]->phone);
        self::assertSame([
            'phone' => '573001234567',
            'name' => 'Grace Hopper',
            'sent' => false,
            'delivered' => false,
            'read' => false,
            'failed' => false,
            'message_id' => 'wamid.1',
        ], $responders[0]->toArray());
    }

    public function test_it_coerces_numeric_contact_message_ids(): void
    {
        $responders = (new ResponderFactory())->createFromContacts([
            [
                'name' => 'Numeric',
                'phones' => [['area_code' => '+57', 'number' => '3001234567']],
                'messageId' => 42,
            ],
            [
                'name' => 'Numeric snake case',
                'phones' => [['area_code' => '+57', 'number' => '3001234568']],
                'message_id' => 43,
            ],
            [
                'name' => 'Non scalar',
                'phones' => [['area_code' => '+57', 'number' => '3001234569']],
                'messageId' => ['42'],
            ],
        ]);

        self::assertSame('42', $responders[0]->messageId);
        self::assertSame('43', $responders[1]->messageId);
        self::assertNull($responders[2]->messageId);
    }

    public function test_it_returns_an_empty_responder_for_an_empty_contact(): void
    {
        $responder = Responder::fromContact([]);

        self::assertSame('', $responder->phone);
        self::assertSame('', $responder->name);
    }
}
