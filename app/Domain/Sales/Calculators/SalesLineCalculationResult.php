<?php

declare(strict_types=1);

namespace App\Domain\Sales\Calculators;

use Brick\Math\BigDecimal;

final readonly class SalesLineCalculationResult
{
    public function __construct(
        public BigDecimal $subtotal,
        public BigDecimal $discount,
        public BigDecimal $netBeforeTax,
        public BigDecimal $tax,
        public BigDecimal $total,
        public BigDecimal $subtotalBase,
        public BigDecimal $discountBase,
        public BigDecimal $taxBase,
        public BigDecimal $totalBase,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'subtotal' => (string) $this->subtotal,
            'discount' => (string) $this->discount,
            'net_before_tax' => (string) $this->netBeforeTax,
            'tax' => (string) $this->tax,
            'total' => (string) $this->total,
            'subtotal_base' => (string) $this->subtotalBase,
            'discount_base' => (string) $this->discountBase,
            'tax_base' => (string) $this->taxBase,
            'total_base' => (string) $this->totalBase,
        ];
    }
}
