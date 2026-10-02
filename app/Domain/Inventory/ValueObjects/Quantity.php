<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Models\Unit;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Stringable;

final class Quantity implements Stringable
{
    public const DEFAULT_SCALE = 6;

    private BigDecimal $amount;

    private function __construct(BigDecimal $amount)
    {
        if ($amount->strippedOfTrailingZeros()->getScale() > self::DEFAULT_SCALE) {
            throw InvalidQuantityException::excessiveScale((string) $amount, self::DEFAULT_SCALE);
        }

        $max = BigDecimal::of('99999999999999.999999');
        $min = BigDecimal::of('-99999999999999.999999');
        if ($amount->isGreaterThan($max) || $amount->isLessThan($min)) {
            throw new InvalidQuantityException("Quantity [{$amount}] exceeds DECIMAL(20,6) boundaries.");
        }

        $this->amount = $amount;
    }

    public static function of(mixed $value): self
    {
        if (is_float($value)) {
            throw InvalidQuantityException::nonNumeric((string) $value);
        }

        if (! is_string($value) && ! is_int($value) && ! ($value instanceof BigDecimal)) {
            throw InvalidQuantityException::nonNumeric(is_scalar($value) ? (string) $value : gettype($value));
        }

        try {
            $amount = $value instanceof BigDecimal ? $value : BigDecimal::of((string) $value);
        } catch (MathException) {
            throw InvalidQuantityException::nonNumeric((string) $value);
        }

        return new self($amount);
    }

    public static function zero(): self
    {
        return new self(BigDecimal::zero());
    }

    public function toBigDecimal(): BigDecimal
    {
        return $this->amount;
    }

    public function toScale(int $scale = self::DEFAULT_SCALE, RoundingMode $mode = RoundingMode::HALF_UP): string
    {
        return (string) $this->amount->toScale($scale, $mode);
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

    public function isEqualTo(self|string|int|BigDecimal $other): bool
    {
        $otherBd = $other instanceof self
            ? $other->amount
            : ($other instanceof BigDecimal ? $other : BigDecimal::of((string) $other));

        return $this->amount->isEqualTo($otherBd);
    }

    public function isGreaterThan(self|string|int|BigDecimal $other): bool
    {
        $otherBd = $other instanceof self
            ? $other->amount
            : ($other instanceof BigDecimal ? $other : BigDecimal::of((string) $other));

        return $this->amount->isGreaterThan($otherBd);
    }

    public function isGreaterThanOrEqualTo(self|string|int|BigDecimal $other): bool
    {
        $otherBd = $other instanceof self
            ? $other->amount
            : ($other instanceof BigDecimal ? $other : BigDecimal::of((string) $other));

        return $this->amount->isGreaterThanOrEqualTo($otherBd);
    }

    public function isLessThan(self|string|int|BigDecimal $other): bool
    {
        $otherBd = $other instanceof self
            ? $other->amount
            : ($other instanceof BigDecimal ? $other : BigDecimal::of((string) $other));

        return $this->amount->isLessThan($otherBd);
    }

    public function isLessThanOrEqualTo(self|string|int|BigDecimal $other): bool
    {
        $otherBd = $other instanceof self
            ? $other->amount
            : ($other instanceof BigDecimal ? $other : BigDecimal::of((string) $other));

        return $this->amount->isLessThanOrEqualTo($otherBd);
    }

    public function add(self|string|int|BigDecimal $other): self
    {
        $otherBd = $other instanceof self
            ? $other->amount
            : ($other instanceof BigDecimal ? $other : BigDecimal::of((string) $other));

        return new self($this->amount->plus($otherBd));
    }

    public function subtract(self|string|int|BigDecimal $other): self
    {
        $otherBd = $other instanceof self
            ? $other->amount
            : ($other instanceof BigDecimal ? $other : BigDecimal::of((string) $other));

        return new self($this->amount->minus($otherBd));
    }

    public function multiply(self|string|int|BigDecimal $factor, RoundingMode $roundingMode = RoundingMode::HALF_UP): self
    {
        $factorBd = $factor instanceof self
            ? $factor->amount
            : ($factor instanceof BigDecimal ? $factor : BigDecimal::of((string) $factor));

        $res = $this->amount->multipliedBy($factorBd);
        if ($res->strippedOfTrailingZeros()->getScale() > self::DEFAULT_SCALE) {
            $res = $res->toScale(self::DEFAULT_SCALE, $roundingMode);
        }

        return new self($res);
    }

    public function divide(self|string|int|BigDecimal $divisor, int $scale = self::DEFAULT_SCALE, RoundingMode $mode = RoundingMode::HALF_UP): self
    {
        $divisorBd = $divisor instanceof self
            ? $divisor->amount
            : ($divisor instanceof BigDecimal ? $divisor : BigDecimal::of((string) $divisor));

        if ($divisorBd->isZero()) {
            throw new \DivisionByZeroError('Cannot divide quantity by zero.');
        }

        return new self($this->amount->dividedBy($divisorBd, $scale, $mode));
    }

    public function abs(): self
    {
        return new self($this->amount->abs());
    }

    public function negate(): self
    {
        return new self($this->amount->negated());
    }

    public function validateUnitConstraints(Unit $unit): void
    {
        if (! $unit->allows_fraction) {
            if ($this->amount->hasNonZeroFractionalPart()) {
                throw InvalidQuantityException::fractionDisallowed($unit->name_ar, (string) $this->amount);
            }
        }

        $allowedDecimals = (int) $unit->decimal_places;
        if ($this->amount->strippedOfTrailingZeros()->getScale() > $allowedDecimals) {
            throw InvalidQuantityException::excessiveScale((string) $this->amount, $allowedDecimals);
        }
    }

    public function format(int $decimals = 2): string
    {
        $scaled = (string) $this->amount->toScale($decimals, RoundingMode::HALF_UP);
        $parts = explode('.', $scaled);
        $intStr = $parts[0];
        $formattedInt = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $intStr);

        return isset($parts[1]) && $decimals > 0 ? "{$formattedInt}.{$parts[1]}" : (string) $formattedInt;
    }

    public function __toString(): string
    {
        return $this->toScale();
    }
}
