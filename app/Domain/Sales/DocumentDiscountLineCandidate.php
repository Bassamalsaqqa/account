<?php

declare(strict_types=1);

namespace App\Domain\Sales;

use Brick\Math\BigDecimal;

final readonly class DocumentDiscountLineCandidate
{
    public BigDecimal $unitPrice;

    public BigDecimal $quantity;

    public BigDecimal $subtotal;

    public bool $taxInclusive;

    public ?BigDecimal $taxRate;

    public function __construct(
        public int $lineNumber,
        BigDecimal|string|int|float $unitPrice,
        BigDecimal|string|int|float $quantity,
        BigDecimal|string|int|float $subtotal,
        bool $taxInclusive = false,
        BigDecimal|string|int|float|null $taxRate = null,
        public mixed $identity = null,
        ?bool $isTaxInclusive = null,
    ) {
        $this->unitPrice = $unitPrice instanceof BigDecimal ? $unitPrice : BigDecimal::of((string) $unitPrice);
        $this->quantity = $quantity instanceof BigDecimal ? $quantity : BigDecimal::of((string) $quantity);
        $this->subtotal = $subtotal instanceof BigDecimal ? $subtotal : BigDecimal::of((string) $subtotal);
        $this->taxInclusive = $isTaxInclusive ?? $taxInclusive;
        $this->taxRate = $taxRate === null ? null : ($taxRate instanceof BigDecimal ? $taxRate : BigDecimal::of((string) $taxRate));
    }

    public function isEligible(): bool
    {
        return $this->unitPrice->isGreaterThan(0)
            && ! $this->taxInclusive;
    }
}
