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

    public function test_it_builds_the_activation_variables_exactly(): void
    {
        $variables = $this->builder->buildActivationVariables('Ada Lovelace', 'EMG1');

        self::assertSame($this->fixture('payloads/emergency.json'), $variables);
    }

    public function test_it_builds_the_simulacrum_variables_exactly(): void
    {
        $variables = $this->builder->buildSimulacrumVariables('Grace Hopper', 'Ada Lovelace', 'EMG1');

        self::assertSame($this->fixture('payloads/simulacrum.json'), $variables);
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

        $variables = $this->builder->buildSimulacrumVariables('  Grace   <b>Hopper</b> ', " Ada\n\nLovelace ", 'EMG1');

        self::assertSame('Grace Hopper', $variables['contact_name']);
        self::assertSame('Ada Lovelace', $variables['user_name']);
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
