<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTO;

use Brick\Math\BigDecimal;
use InvalidArgumentException;

final readonly class GoodsReceiptLineCommand
{
    public function __construct(
        public int $purchaseLineId,
        public string $purchaseLinePublicId,
        public BigDecimal $quantity,
        public ?string $lotNumber = null,
        public ?string $expiryDate = null,
    ) {
        if ($this->purchaseLineId <= 0) {
            throw new InvalidArgumentException("Purchase line ID must be a positive integer. Given [{$this->purchaseLineId}].");
        }

        if ($this->quantity->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Goods receipt line quantity must be strictly positive (> 0).');
        }
    }
}
