<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\WhatsApp;

use DataEmergencia\EmergencyBff\Support\ArrayReader;

/**
 * Selects the product code using the same rules as `QRProductCodeService`.
 */
final class ProductCodeSelector
{
    /**
     * @var array<string, string>
     */
    private const PRODUCT_CODE_MAP = [
        'p' => 'panic_button',
        'a' => 'dte',
        'm' => 'pet',
        'q' => 'carnet',
        'd' => 'dte_d',
        't' => 'dte_t',
    ];

    /**
     * @param array<string, mixed> $productCodes
     */
    public function findValidProductCode(array $productCodes, bool $excludePanicButton = true): ?string
    {
        $found = null;

        foreach (self::PRODUCT_CODE_MAP as $key) {
            $value = ArrayReader::get($productCodes, $key);

            if (is_string($value) && $value !== '' && (!$excludePanicButton || $key !== 'panic_button')) {
                $found = $value;
            }
        }

        if ($found === null) {
            $fallback = ArrayReader::get($productCodes, 'dte');
            $found = is_string($fallback) && $fallback !== '' ? $fallback : null;
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    public function productCodeMap(): array
    {
        return self::PRODUCT_CODE_MAP;
    }
}
