<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\WhatsApp;

use DateTimeInterface;

/**
 * Port of the application `PayloadBuilderService` for the three MVP templates.
 */
final class WhatsAppPayloadBuilder
{
    public const TEMPLATE_EMERGENCY = 'emergency_message_template_v3';
    public const TEMPLATE_SIMULACRUM = 'simulacrum_support_contacts_confirmation_v2';
    public const TEMPLATE_ABORT = 'emergency_status_aborting_v1';

    public const IMAGE_EMERGENCY = 'https://api.dataemergencia.com/public/images/panic-button/emergency-template.png';
    public const IMAGE_SIMULACRUM = 'https://api.dataemergencia.com/public/images/logo_scaem.png';

    public const DEFAULT_LANGUAGE = 'en';

    private const SPANISH_MONTHS = [
        'Enero',
        'Febrero',
        'Marzo',
        'Abril',
        'Mayo',
        'Junio',
        'Julio',
        'Agosto',
        'Septiembre',
        'Octubre',
        'Noviembre',
        'Diciembre',
    ];

    public function __construct(
        private readonly string $qrBaseHost = 'zur.dataemergencia.com',
        private readonly string $fallbackOriginUrl = 'https://dataemergencia.com',
        private readonly bool $local = false,
    ) {
    }

    /**
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $geolocation
     * @param array<string, mixed> $personalInfo
     * @return array<string, mixed>
     */
    public function buildEmergencyPayload(array $contact, array $geolocation, array $personalInfo, string $qrCode, ?DateTimeInterface $now = null): array
    {
        return array_merge($this->basePayload($contact, self::TEMPLATE_EMERGENCY), [
            'google_maps' => [
                'latitude' => (string) (($geolocation['coords'] ?? $geolocation)['latitude'] ?? ''),
                'longitude' => (string) (($geolocation['coords'] ?? $geolocation)['longitude'] ?? ''),
                'zoom' => 17,
            ],
            'components' => [
                $this->imageHeader(self::IMAGE_EMERGENCY),
                [
                    'type' => 'body',
                    'parameters' => [
                        $this->textParameter('contact_name', (string) ($contact['name'] ?? '')),
                        $this->textParameter('user_name', $this->sanitizeName($personalInfo['full_name'] ?? '')),
                        $this->textParameter('full_date', $this->formatActivationDate($now)),
                        $this->textParameter('fmd_url', $this->buildQrUrl($qrCode)),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $personalInfo
     * @return array<string, mixed>
     */
    public function buildSimulacrumPayload(array $contact, string $digitalQrCode, array $personalInfo): array
    {
        return array_merge($this->basePayload($contact, self::TEMPLATE_SIMULACRUM), [
            'google_maps' => null,
            'components' => [
                $this->imageHeader(self::IMAGE_SIMULACRUM),
                [
                    'type' => 'body',
                    'parameters' => [
                        $this->textParameter('contact_name', (string) ($contact['name'] ?? '')),
                        $this->textParameter('user_name', $this->sanitizeName($personalInfo['full_name'] ?? '')),
                        $this->textParameter('medical_profile_url', $this->buildQrUrl($digitalQrCode)),
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $contact
     * @param array<string, mixed> $personalInfo
     * @return array<string, mixed>
     */
    public function buildAbortPayload(array $contact, array $personalInfo): array
    {
        return array_merge($this->basePayload($contact, self::TEMPLATE_ABORT), [
            'components' => [
                [
                    'type' => 'body',
                    'parameters' => [
                        $this->textParameter('user_name', $this->sanitizeName($personalInfo['full_name'] ?? '')),
                    ],
                ],
            ],
        ]);
    }

    public function formatActivationDate(?DateTimeInterface $now = null): string
    {
        $now ??= new \DateTimeImmutable();

        $day = (int) $now->format('j');
        $month = self::SPANISH_MONTHS[(int) $now->format('n') - 1];
        $year = $now->format('Y');
        $minutes = $now->format('i');
        $hours24 = (int) $now->format('G');
        $hours = $hours24 % 12 ?: 12;
        $period = $hours24 >= 12 ? 'pm' : 'am';

        return sprintf('%d de %s de %s, %d:%s%s', $day, $month, $year, $hours, $minutes, $period);
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    private function basePayload(array $contact, string $template): array
    {
        return [
            'to' => Responder::phoneFromContact($contact),
            'template' => $template,
            'language' => self::DEFAULT_LANGUAGE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function imageHeader(string $imageUrl): array
    {
        return [
            'type' => 'header',
            'parameters' => [
                [
                    'type' => 'image',
                    'image' => ['link' => $imageUrl],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function textParameter(string $name, string $text): array
    {
        return [
            'type' => 'text',
            'parameter_name' => $name,
            'text' => $text,
        ];
    }

    private function buildQrUrl(string $qrCodeProduct): string
    {
        if ($qrCodeProduct === '') {
            return '';
        }

        $base = $this->local
            ? sprintf('http://localhost/%s', $this->qrBaseHost)
            : sprintf('https://%s', $this->qrBaseHost);

        return sprintf('%s/qr/%s', $base, rawurlencode($qrCodeProduct));
    }

    private function sanitizeName(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '') {
            return '';
        }

        $withoutTags = strip_tags($name);
        $normalized = preg_replace('/\s+/u', ' ', $withoutTags) ?? $withoutTags;

        return trim($normalized);
    }
}
