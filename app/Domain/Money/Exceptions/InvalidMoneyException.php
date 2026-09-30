<?php

declare(strict_types=1);

namespace App\Domain\Money\Exceptions;

use DomainException;

final class InvalidMoneyException extends DomainException
{
    public static function floatNotAllowed(mixed $value): self
    {
        return new self(sprintf(
            'Floats are strictly prohibited in financial calculations. Value %s must be provided as an exact decimal string or integer.',
            var_export($value, true)
        ));
    }

    public static function malformedValue(mixed $value): self
    {
        return new self(sprintf(
            'Malformed monetary or rate decimal representation: %s.',
            var_export($value, true)
        ));
    }

    public static function negativeRate(mixed $value): self
    {
        return new self(sprintf(
            'Exchange rate must be strictly positive (> 0). Given: %s.',
            var_export($value, true)
        ));
    }

    public static function overPrecision(mixed $value, int $maxScale): self
    {
        return new self(sprintf(
            'Value %s exceeds maximum allowed precision of %d decimal places. Explicit rounding is required.',
            var_export($value, true),
            $maxScale
        ));
    }

    public static function overflow(mixed $value, string $maxRepresentable): self
    {
        return new self(sprintf(
            'Value %s exceeds maximum representable bounds (max magnitude: %s).',
            var_export($value, true),
            $maxRepresentable
        ));
    }
}
