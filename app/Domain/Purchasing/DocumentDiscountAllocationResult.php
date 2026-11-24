<?php

declare(strict_types=1);

namespace App\Domain\Purchasing;

use Brick\Math\BigDecimal;

final readonly class DocumentDiscountAllocationResult
{
    /**
     * @param  array<int, BigDecimal>  $allocatedDiscountsByLineNumber  LineNumber (1-based) => allocated discount (scale 6)
     * @param  BigDecimal  $totalAllocated  Total allocated discount in currency (scale 6)
     * @param  BigDecimal  $totalBasis  Total eligible basis in currency (scale 6)
     */
    public function __construct(
        public array $allocatedDiscountsByLineNumber,
        public BigDecimal $totalAllocated,
        public BigDecimal $totalBasis,
    ) {}

    public function forLine(int $lineNumber): BigDecimal
    {
        return $this->allocatedDiscountsByLineNumber[$lineNumber] ?? BigDecimal::zero()->toScale(6);
    }
}
