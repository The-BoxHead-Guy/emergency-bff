<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\WhatsApp;

/**
 * Builds the abort payload and the variable maps for the typed emergency endpoints.
 *
 * Activation and simulacrum templates are owned by the API; the BFF only sends
 * the recipient phone and the endpoint-specific variables.
 */
final class WhatsAppPayloadBuilder
{
    public const TEMPLATE_ABORT = 'emergency_status_aborting_v1';

    public const DEFAULT_LANGUAGE = 'en';

    /**
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

    /**
     * @return array{user_name: string, emergency_code: string}
     */
    public function buildActivationVariables(string $userName, string $emergencyCode): array
    {
        return [
            'user_name' => $this->sanitizeName($userName),
            'emergency_code' => $emergencyCode,
        ];
    }

    /**
     * @return array{contact_name: string, user_name: string, emergency_code: string}
     */
    public function buildSimulacrumVariables(string $contactName, string $userName, string $emergencyCode): array
    {
        return [
            'contact_name' => $this->sanitizeName($contactName),
            'user_name' => $this->sanitizeName($userName),
            'emergency_code' => $emergencyCode,
        ];
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
    private function textParameter(string $name, string $text): array
    {
        return [
            'type' => 'text',
            'parameter_name' => $name,
            'text' => $text,
        ];
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
