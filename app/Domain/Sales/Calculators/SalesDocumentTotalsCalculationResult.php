<?php

declare(strict_types=1);

namespace App\Domain\Sales\Calculators;

use Brick\Math\BigDecimal;

final readonly class SalesDocumentTotalsCalculationResult
{
    public function __construct(
        public BigDecimal $subtotalCurrency,
        public BigDecimal $discountTotalCurrency,
        public BigDecimal $taxTotalCurrency,
        public BigDecimal $grandTotalCurrency,
        public BigDecimal $subtotalBase,
        public BigDecimal $discountTotalBase,
        public BigDecimal $taxTotalBase,
        public BigDecimal $grandTotalBase,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'subtotal_currency' => (string) $this->subtotalCurrency,
            'discount_total_currency' => (string) $this->discountTotalCurrency,
            'tax_total_currency' => (string) $this->taxTotalCurrency,
            'grand_total_currency' => (string) $this->grandTotalCurrency,
            'subtotal_base' => (string) $this->subtotalBase,
            'discount_total_base' => (string) $this->discountTotalBase,
            'tax_total_base' => (string) $this->taxTotalBase,
            'grand_total_base' => (string) $this->grandTotalBase,
        ];
    }
}
