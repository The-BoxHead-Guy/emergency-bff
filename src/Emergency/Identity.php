<?php

declare(strict_types=1);

namespace DataEmergencia\EmergencyBff\Emergency;

use DataEmergencia\EmergencyBff\Emergency\Exception\InvalidCodeException;

/**
 * Validated 6-character identity code.
 */
final class Identity
{
    public const PATTERN = '/^[A-Za-z][A-Za-z0-9]{5}$/';

    private function __construct(public readonly string $code)
    {
    }

    public static function fromCode(string $code): self
    {
        if (preg_match(self::PATTERN, $code) !== 1) {
            throw new InvalidCodeException($code);
        }

        return new self($code);
    }

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    public function __toString(): string
    {
        return $this->code;
    }
}
