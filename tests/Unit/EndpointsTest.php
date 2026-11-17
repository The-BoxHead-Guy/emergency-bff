<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Http\Endpoints;
use DataEmergencia\EmergencyBff\Tests\TestCase;

final class EndpointsTest extends TestCase
{
    public function test_it_mirrors_the_upstream_endpoint_contract(): void
    {
        self::assertSame('/v2/fmd/DABC12/models/data?model=personal,emergencia', Endpoints::getProfileInfo('DABC12'));
        self::assertSame('/v2/fmd/DABC12/exists', Endpoints::fmdExists('DABC12'));
        self::assertSame('/v2/emergency', Endpoints::postState());
        self::assertSame('/v2/emergency/abc-123', Endpoints::showState('abc-123'));
        self::assertSame('/v2/emergency/abc-123', Endpoints::update('abc-123'));
        self::assertSame('/v2/emergency/abc-123', Endpoints::deleteState('abc-123'));
        self::assertSame('/v2/emergency/qr/DABC12/active', Endpoints::findActiveByQrCode('DABC12'));
        self::assertSame('/v2/whatsapp/templates/send', Endpoints::whatsappTemplates());
        self::assertSame('/v2/whatsapp/templates/emergency/activation', Endpoints::whatsappEmergencyActivation());
        self::assertSame('/v2/whatsapp/templates/emergency/simulacrum', Endpoints::whatsappEmergencySimulacrum());
        self::assertSame('/v2/credits/DABC12', Endpoints::creditSummary('DABC12'));
        self::assertSame('/v2/credits/DABC12/history?per_page=15&page=1', Endpoints::creditHistory('DABC12'));
        self::assertSame('/v2/credits/DABC12/history?per_page=5&page=2', Endpoints::creditHistory('DABC12', 5, 2));
    }

    public function test_it_encodes_path_parameters(): void
    {
        self::assertSame('/v2/emergency/a%2Fb', Endpoints::showState('a/b'));
        self::assertSame('/v2/emergency/a%2Fb', Endpoints::update('a/b'));
        self::assertSame('/v2/fmd/A%20B/exists', Endpoints::fmdExists('A B'));
        self::assertSame('/v2/credits/A%20B', Endpoints::creditSummary('A B'));
        self::assertSame('/v2/credits/a%2Fb/history?per_page=1&page=1', Endpoints::creditHistory('a/b', 1));
    }
}
