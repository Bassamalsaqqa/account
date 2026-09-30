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

final readonly class ExchangeRate implements Stringable
{
    public const int DEFAULT_SCALE = 10;

    public const string MAX_MAGNITUDE = '9999999999.9999999999';

    private BigDecimal $value;

    private function __construct(BigDecimal $value)
    {
        $this->value = $value->toScale(self::DEFAULT_SCALE, RoundingMode::UNNECESSARY);
    }

    /**
     * Create an ExchangeRate from an exact string, integer, or BigDecimal.
     * Universal convention: base-currency units per 1 transaction-currency unit.
     * Values with precision beyond 10 decimal places require explicit rounding via fromRounded().
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
                if ($clean === '' || ! preg_match('/^\d+(\.\d+)?$/', $clean)) {
                    throw InvalidMoneyException::malformedValue($value);
                }
                $decimal = BigDecimal::of($clean);
            } else {
                throw InvalidMoneyException::malformedValue($value);
            }

            if ($decimal->isNegative() || $decimal->isZero()) {
                throw InvalidMoneyException::negativeRate($value);
            }

            if ($decimal->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
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
     * Explicit rounding constructor for exchange rates.
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
                if ($clean === '' || ! preg_match('/^\d+(\.\d+)?$/', $clean)) {
                    throw InvalidMoneyException::malformedValue($value);
                }
                $decimal = BigDecimal::of($clean);
            } else {
                throw InvalidMoneyException::malformedValue($value);
            }

            if ($decimal->isNegative() || $decimal->isZero()) {
                throw InvalidMoneyException::negativeRate($value);
            }

            $scaled = $decimal->toScale(self::DEFAULT_SCALE, $roundingMode);

            if ($scaled->isNegative() || $scaled->isZero()) {
                throw InvalidMoneyException::negativeRate($value);
            }

            if ($scaled->isGreaterThan(BigDecimal::of(self::MAX_MAGNITUDE))) {
                throw InvalidMoneyException::overflow($value, self::MAX_MAGNITUDE);
            }

            return new self($scaled);
        } catch (MathException) {
            throw InvalidMoneyException::malformedValue($value);
        }
    }

    public static function one(): self
    {
        return new self(BigDecimal::one()->toScale(self::DEFAULT_SCALE));
    }

    /**
     * Converts a transaction-currency amount to base currency.
     * Formula: Base = Transaction * Rate
     * Example: 100 USD @ 3.5000000000 ILS/USD => 350.000000 ILS.
     */
    public function toBase(MoneyAmount $transactionAmount, int $scale = MoneyAmount::DEFAULT_SCALE, RoundingMode $roundingMode = RoundingMode::HALF_UP): MoneyAmount
    {
        return $transactionAmount->multipliedBy($this, $scale, $roundingMode);
    }

    /**
     * Converts a base-currency amount to transaction currency.
     * Formula: Transaction = Base / Rate
     * Example: 350 ILS @ 3.5000000000 ILS/USD => 100.000000 USD.
     */
    public function toTransaction(MoneyAmount $baseAmount, int $scale = MoneyAmount::DEFAULT_SCALE, RoundingMode $roundingMode = RoundingMode::HALF_UP): MoneyAmount
    {
        return $baseAmount->dividedBy($this, $scale, $roundingMode);
    }

    public function getValue(): BigDecimal
    {
        return $this->value;
    }

    public function isOne(): bool
    {
        return $this->value->compareTo(BigDecimal::one()) === 0;
    }

    public function isEqualTo(self|string|int|BigDecimal $other): bool
    {
        $otherDecimal = $other instanceof self ? $other->value : self::from($other)->value;

        return $this->value->compareTo($otherDecimal) === 0;
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

    public function toDecimalString(int $scale = self::DEFAULT_SCALE): string
    {
        return (string) $this->value->toScale($scale, RoundingMode::HALF_UP);
    }

    public function __toString(): string
    {
        return $this->toDecimalString(self::DEFAULT_SCALE);
    }
}
