<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Money\MoneyValues;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

final class OpeningTradeAmount implements ValidationRule
{
    public function __construct(private readonly int $companyId, private readonly string $currency) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        try {
            $scale = app(CurrencyMinorUnitsResolver::class)->resolveFreshForCompany($this->companyId, $this->currency, lockForUpdate: false);
        } catch (Throwable) {
            $fail(__('accounting.opening_currency_unavailable'));

            return;
        }
        try {
            PositiveExactDecimal::parse($value, $scale);
            MoneyValues::amount($value, $this->currency);
        } catch (Throwable) {
            $fail(__('accounting.invalid_exact_decimal', ['scale' => $scale]));
        }
    }
}
