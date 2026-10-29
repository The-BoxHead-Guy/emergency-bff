<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Api;

/**
 * Medical profile snapshot fetched at activation time.
 */
final class Profile
{
    /**
     * @param array<string, mixed> $personalInfo
     * @param array<string, string> $productCodes
     * @param array<int, array<string, mixed>> $emergencyContacts
     */
    public function __construct(
        public readonly string $code,
        public readonly array $personalInfo,
        public readonly array $productCodes,
        public readonly array $emergencyContacts,
    ) {
    }

    public function hasPersonalInfo(): bool
    {
        return $this->personalInfo !== [];
    }

    public function hasContacts(): bool
    {
        return $this->emergencyContacts !== [];
    }

    public function fullName(): string
    {
        $name = $this->personalInfo['full_name'] ?? '';

        return is_string($name) ? $name : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'personal_info' => $this->personalInfo,
            'product_codes' => $this->productCodes,
            'emergency_contacts' => $this->emergencyContacts,
        ];
    }
}
