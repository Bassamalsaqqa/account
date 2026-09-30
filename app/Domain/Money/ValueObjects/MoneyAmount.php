<?php

declare(strict_types=1);

namespace App\Domain\Money\ValueObjects;

use App\Domain\Money\Exceptions\InvalidMoneyException;
use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use Stringable;

final readonly class MoneyAmount implements Stringable
{
    public const int DEFAULT_SCALE = 6;

    public const string MAX_MAGNITUDE = '99999999999999.999999';

    private BigDecimal $amount;

    private function __construct(BigDecimal $amount)
    {
        $this->amount = $amount->toScale(self::DEFAULT_SCALE, RoundingMode::UNNECESSARY);
    }

    /**
     * Create a MoneyAmount from an exact string, integer, BigInteger, or BigDecimal.
     * Floating point numbers are strictly rejected.
     * Values with precision beyond 6 decimal places require explicit rounding via fromRounded().
     */
    public static function from(mixed $value): self
    {
        if (is_float($value)) {
            throw InvalidMoneyException::floatNotAllowed($value);
        }

        if ($value instanceof self) {
            return $value;
        }

        try {
            if ($value instanceof BigDecimal) {
                $decimal = $value;
            } elseif ($value instanceof BigInteger || is_int($value)) {
                $decimal = BigDecimal::of($value);
            } elseif (is_string($value)) {
                $clean = trim($value);
                if ($clean === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
                    throw InvalidMoneyException::malformedValue($value);
                }
                $decimal = BigDecimal::of($clean);
            } else {
                throw InvalidMoneyException::malformedValue($value);
            }

            if ($decimal->abs()->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
                throw InvalidMoneyException::overflow($value, self::MAX_MAGNITUDE);
            }

            return new self($decimal->toScale(self::DEFAULT_SCALE, RoundingMode::UNNECESSARY));
        } catch (RoundingNecessaryException) {
            throw InvalidMoneyException::overPrecision($value, self::DEFAULT_SCALE);
        } catch (MathException) {
            throw InvalidMoneyException::malformedValue($value);
        }
    }

    /**
     * Explicit rounding constructor for monetary amounts.
     */
    public static function fromRounded(mixed $value, RoundingMode $roundingMode = RoundingMode::HALF_UP): self
    {
        if (is_float($value)) {
            throw InvalidMoneyException::floatNotAllowed($value);
        }

        if ($value instanceof self) {
            return $value;
        }

        try {
            if ($value instanceof BigDecimal) {
                $decimal = $value;
            } elseif ($value instanceof BigInteger || is_int($value)) {
                $decimal = BigDecimal::of($value);
            } elseif (is_string($value)) {
                $clean = trim($value);
                if ($clean === '' || ! preg_match('/^-?\d+(\.\d+)?$/', $clean)) {
                    throw InvalidMoneyException::malformedValue($value);
                }
                $decimal = BigDecimal::of($clean);
            } else {
                throw InvalidMoneyException::malformedValue($value);
            }

            $scaled = $decimal->toScale(self::DEFAULT_SCALE, $roundingMode);

            if ($scaled->abs()->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
                throw InvalidMoneyException::overflow($value, self::MAX_MAGNITUDE);
            }

            return new self($scaled);
        } catch (MathException) {
            throw InvalidMoneyException::malformedValue($value);
        }
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero()->toScale(self::DEFAULT_SCALE));
    }

    public function plus(self|string|int|BigDecimal $other): self
    {
        $otherDecimal = $other instanceof self ? $other->amount : self::from($other)->amount;
        $result = $this->amount->plus($otherDecimal);

        if ($result->abs()->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
            throw InvalidMoneyException::overflow((string) $result, self::MAX_MAGNITUDE);
        }

        return new self($result);
    }

    public function minus(self|string|int|BigDecimal $other): self
    {
        $otherDecimal = $other instanceof self ? $other->amount : self::from($other)->amount;
        $result = $this->amount->minus($otherDecimal);

        if ($result->abs()->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
            throw InvalidMoneyException::overflow((string) $result, self::MAX_MAGNITUDE);
        }

        return new self($result);
    }

    public function multipliedBy(mixed $multiplier, int $scale = self::DEFAULT_SCALE, RoundingMode $roundingMode = RoundingMode::HALF_UP): self
    {
        if (is_float($multiplier)) {
            throw InvalidMoneyException::floatNotAllowed($multiplier);
        }

        if ($multiplier instanceof self) {
            $factor = $multiplier->amount;
        } elseif ($multiplier instanceof ExchangeRate) {
            $factor = $multiplier->getValue();
        } elseif ($multiplier instanceof BigDecimal) {
            $factor = $multiplier;
        } else {
            $factor = BigDecimal::of((string) $multiplier);
        }

        $result = $this->amount->multipliedBy($factor)->toScale($scale, $roundingMode);

        if ($result->abs()->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
            throw InvalidMoneyException::overflow((string) $result, self::MAX_MAGNITUDE);
        }

        return new self($result);
    }

    public function dividedBy(mixed $divisor, int $scale = self::DEFAULT_SCALE, RoundingMode $roundingMode = RoundingMode::HALF_UP): self
    {
        if (is_float($divisor)) {
            throw InvalidMoneyException::floatNotAllowed($divisor);
        }

        if ($divisor instanceof self) {
            $factor = $divisor->amount;
        } elseif ($divisor instanceof ExchangeRate) {
            $factor = $divisor->getValue();
        } elseif ($divisor instanceof BigDecimal) {
            $factor = $divisor;
        } else {
            $factor = BigDecimal::of((string) $divisor);
        }

        if ($factor->isZero()) {
            throw new InvalidMoneyException('Division by zero is not allowed.');
        }

        $result = $this->amount->dividedBy($factor, $scale, $roundingMode);

        if ($result->abs()->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
            throw InvalidMoneyException::overflow((string) $result, self::MAX_MAGNITUDE);
        }

        return new self($result);
    }

    public function negated(): self
    {
        return new self($this->amount->negated());
    }

    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isPositive(): bool
    {
        return $this->amount->isPositive();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function compareTo(self|string|int|BigDecimal $other): int
    {
        $otherDecimal = $other instanceof self ? $other->amount : self::from($other)->amount;

        return $this->amount->compareTo($otherDecimal);
    }

    public function isEqualTo(self|string|int|BigDecimal $other): bool
    {
        return $this->compareTo($other) === 0;
    }

    public function equals(mixed $other): bool
    {
        if ($other instanceof self) {
            return $this->isEqualTo($other);
        }

        try {
            return $this->isEqualTo(self::from($other));
        } catch (\Throwable) {
            return false;
        }
    }

    public function isGreaterThan(self|string|int|BigDecimal $other): bool
    {
        return $this->compareTo($other) > 0;
    }

    public function isGreaterThanOrEqualTo(self|string|int|BigDecimal $other): bool
    {
        return $this->compareTo($other) >= 0;
    }

    public function isLessThan(self|string|int|BigDecimal $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function isLessThanOrEqualTo(self|string|int|BigDecimal $other): bool
    {
        return $this->compareTo($other) <= 0;
    }

    public function getAmount(): BigDecimal
    {
        return $this->amount;
    }

    public function toScale(int $scale, RoundingMode $roundingMode = RoundingMode::HALF_UP): string
    {
        return (string) $this->amount->toScale($scale, $roundingMode);
    }

    public function toDecimalString(int $scale = self::DEFAULT_SCALE): string
    {
        return (string) $this->amount->toScale($scale, RoundingMode::HALF_UP);
    }

    /**
     * Format for presentation using standard minor units (ILS: 2, USD: 2, JOD: 3, EUR: 2).
     */
    public function formatForCurrency(string $currencyCode, RoundingMode $roundingMode = RoundingMode::HALF_UP): string
    {
        $decimals = match (strtoupper($currencyCode)) {
            'JOD', 'KWD', 'BHD', 'OMR' => 3,
            default => 2,
        };

        return (string) $this->amount->toScale($decimals, $roundingMode);
    }

    public function __toString(): string
    {
        return $this->toDecimalString(self::DEFAULT_SCALE);
    }
}
