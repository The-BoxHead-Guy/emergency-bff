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
        self::assertSame('/v2/emergency/abc-123', Endpoints::deleteState('abc-123'));
        self::assertSame('/v2/emergency/qr/DABC12/active', Endpoints::findActiveByQrCode('DABC12'));
        self::assertSame('/v2/whatsapp/templates/send', Endpoints::whatsappTemplates());
    }

    public function test_it_encodes_path_parameters(): void
    {
        self::assertSame('/v2/emergency/a%2Fb', Endpoints::showState('a/b'));
        self::assertSame('/v2/fmd/A%20B/exists', Endpoints::fmdExists('A B'));
    }
}
