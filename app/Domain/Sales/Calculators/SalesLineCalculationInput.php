<?php

declare(strict_types=1);

namespace App\Domain\Sales\Calculators;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\MoneyAmount;
use Brick\Math\BigDecimal;

final readonly class SalesLineCalculationInput
{
    public BigDecimal $quantity;

    public BigDecimal $unitPrice;

    public ?string $discountType;

    public BigDecimal $discountValue;

    public ?BigDecimal $taxRate;

    public bool $taxInclusive;

    public int $currencyMinorUnits;

    public BigDecimal $exchangeRate;

    public int $baseCurrencyMinorUnits;

    public function __construct(
        BigDecimal|string|int $quantity,
        BigDecimal|string|int $unitPrice,
        ?string $discountType = null,
        BigDecimal|string|int $discountValue = 0,
        BigDecimal|string|int|null $taxRate = null,
        bool $taxInclusive = false,
        int $currencyMinorUnits = 2,
        BigDecimal|string|int $exchangeRate = 1,
        int $baseCurrencyMinorUnits = 2,
    ) {
        $this->quantity = $quantity instanceof BigDecimal ? $quantity : BigDecimal::of((string) $quantity);
        $this->unitPrice = $unitPrice instanceof BigDecimal ? $unitPrice : BigDecimal::of((string) $unitPrice);
        $this->discountType = $discountType;
        $this->discountValue = $discountValue instanceof BigDecimal ? $discountValue : BigDecimal::of((string) $discountValue);
        $this->taxRate = $taxRate !== null ? ($taxRate instanceof BigDecimal ? $taxRate : BigDecimal::of((string) $taxRate)) : null;
        $this->taxInclusive = $taxInclusive;
        $this->currencyMinorUnits = $currencyMinorUnits;
        $this->exchangeRate = $exchangeRate instanceof BigDecimal ? $exchangeRate : BigDecimal::of((string) $exchangeRate);
        $this->baseCurrencyMinorUnits = $baseCurrencyMinorUnits;
        if ($this->quantity->isNegative() || $this->unitPrice->isNegative() || $this->discountValue->isNegative()
            || ! in_array($discountType, [null, 'none', 'fixed', 'percent'], true)
            || ($discountType === 'percent' && $this->discountValue->isGreaterThan(100))
            || ($this->taxRate !== null && $this->taxRate->isNegative()) || ! $this->exchangeRate->isPositive()) {
            throw new \InvalidArgumentException('Invalid exact Sales calculation inputs.');
        }
        Quantity::of($this->quantity);
        MoneyAmount::from($this->unitPrice);
        MoneyAmount::from($this->discountValue);
    }
}
