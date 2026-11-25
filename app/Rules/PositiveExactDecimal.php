<?php

declare(strict_types=1);

namespace App\Rules;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class PositiveExactDecimal implements ValidationRule
{
    public function __construct(private readonly int $scale) {}

    public static function parse(mixed $value, int $scale): BigDecimal
    {
        if (! is_string($value) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $value)) {
            throw new \InvalidArgumentException('An exact decimal string is required.');
        }
        $decimal = BigDecimal::of($value)->toScale($scale);
        if (! $decimal->isPositive()) {
            throw new \InvalidArgumentException('A positive decimal is required.');
        }

        return $decimal;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            self::parse($value, $this->scale);
        } catch (\InvalidArgumentException|MathException) {
            $fail(__('accounting.invalid_exact_decimal', ['scale' => $this->scale]));
        }
    }
}
