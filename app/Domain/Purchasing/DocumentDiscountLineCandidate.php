<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use Brick\Math\BigDecimal;

final readonly class DocumentDiscountLineCandidate
{
    public function __construct(
        public int $lineNumber,
        public BigDecimal $unitCost,
        public BigDecimal $quantity,
        public BigDecimal $subtotal,
        public bool $taxInclusive = false,
        public ?BigDecimal $taxRate = null,
        public mixed $identity = null,
    ) {}

    public function isEligible(): bool
    {
        return $this->unitCost->isGreaterThan(0)
            && ! $this->taxInclusive;
    }
}
