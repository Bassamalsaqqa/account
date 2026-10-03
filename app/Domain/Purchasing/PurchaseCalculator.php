<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Sales\Calculators\SalesLineCalculationInput;
use App\Domain\Sales\Calculators\SalesLineCalculationResult;
use App\Domain\Sales\Calculators\SalesLineCalculator;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class PurchaseCalculator
{
    public function line(mixed $quantity, mixed $cost, ?string $discountType, mixed $discountValue, mixed $taxRate, bool $inclusive, string $currency, mixed $rate): SalesLineCalculationResult
    {
        $qty = Quantity::of($quantity)->toBigDecimal();
        $price = MoneyAmount::from($cost)->getAmount();
        $discount = MoneyAmount::from($discountValue)->getAmount();
        $fx = ExchangeRate::from($rate)->getValue();
        $minor = $currency === 'JOD' ? 3 : 2;
        if (! $qty->isPositive() || $price->isNegative() || $discount->isNegative()
            || ! in_array($discountType, [null, 'none', 'fixed', 'percent'], true)
            || ($discountType === 'percent' && $discount->isGreaterThan(100))
            || (in_array($discountType, [null, 'none'], true) && ! $discount->isZero())
            || ($discountType === 'fixed' && $discount->isGreaterThan($qty->multipliedBy($price)->toScale($minor, RoundingMode::HALF_UP)))) {
            throw new InvalidArgumentException(__('purchasing.invalid_line_amounts'));
        }
        $result = app(SalesLineCalculator::class)->calculate(new SalesLineCalculationInput(
            $qty, $price, $discountType, $discount, $taxRate, $inclusive, $minor, $fx
        ));
        foreach (get_object_vars($result) as $amount) {
            MoneyAmount::from($amount);
        }

        return $result;
    }
}
