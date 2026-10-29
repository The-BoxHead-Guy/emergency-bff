<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Tests\Unit;

use DataEmergencia\EmergencyBff\Tests\TestCase;
use DataEmergencia\EmergencyBff\WhatsApp\WhatsAppPayloadBuilder;

final class WhatsAppPayloadBuilderTest extends TestCase
{
    private WhatsAppPayloadBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new WhatsAppPayloadBuilder();
    }

    public function test_it_builds_the_emergency_payload_exactly(): void
    {
        $payload = $this->builder->buildEmergencyPayload(
            $this->contact(),
            ['latitude' => '4.7110', 'longitude' => '-74.0721'],
            ['full_name' => 'Ada Lovelace'],
            'DABC12',
            new \DateTimeImmutable('2026-10-28 12:35:00'),
        );

        self::assertSame($this->fixture('payloads/emergency.json'), $payload);
    }

    public function test_it_builds_the_simulacrum_payload_exactly(): void
    {
        $payload = $this->builder->buildSimulacrumPayload(
            $this->contact(),
            'DABC12',
            ['full_name' => 'Ada Lovelace'],
        );

        self::assertSame($this->fixture('payloads/simulacrum.json'), $payload);
    }

    public function test_it_builds_the_abort_payload_exactly(): void
    {
        $payload = $this->builder->buildAbortPayload(
            $this->contact(),
            ['full_name' => 'Ada Lovelace'],
        );

        self::assertSame($this->fixture('payloads/abort.json'), $payload);
    }

    public function test_it_sanitizes_html_and_whitespace_in_names(): void
    {
        $payload = $this->builder->buildAbortPayload(
            $this->contact('  Grace   <b>Hopper</b> '),
            ['full_name' => " Ada\n\nLovelace "],
        );

        self::assertSame('user_name', $payload['components'][0]['parameters'][0]['parameter_name']);
        self::assertSame('Ada Lovelace', $payload['components'][0]['parameters'][0]['text']);
    }

    public function test_it_formats_the_spanish_activation_date(): void
    {
        self::assertSame(
            '28 de Octubre de 2026, 12:35pm',
            $this->builder->formatActivationDate(new \DateTimeImmutable('2026-10-28 12:35:00')),
        );
        self::assertSame(
            '1 de Enero de 2026, 12:05am',
            $this->builder->formatActivationDate(new \DateTimeImmutable('2026-01-01 00:05:00')),
        );
        self::assertSame(
            '9 de Diciembre de 2025, 1:09pm',
            $this->builder->formatActivationDate(new \DateTimeImmutable('2025-12-09 13:09:00')),
        );
    }

    public function test_it_builds_local_qr_urls_when_configured(): void
    {
        $builder = new WhatsAppPayloadBuilder(local: true);
        $payload = $builder->buildSimulacrumPayload($this->contact(), 'DABC12', ['full_name' => 'Ada']);

        self::assertSame(
            'http://localhost/zur.dataemergencia.com/qr/DABC12',
            $payload['components'][1]['parameters'][2]['text'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function contact(string $name = 'Grace Hopper'): array
    {
        return [
            'name' => $name,
            'phones' => [['area_code' => '+57', 'number' => '3001234567']],
        ];
    }
}
