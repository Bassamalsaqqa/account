<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

use App\Domain\Inventory\ValueObjects\Quantity;
use InvalidArgumentException;

final readonly class StockTransferLineCommand
{
    public function __construct(
        public int $productId,
        public Quantity $quantity,
        public ?int $unitId = null,
        public ?int $lotId = null,
    ) {
        if ($this->productId <= 0) {
            throw new InvalidArgumentException("Product ID must be a positive integer. Given [{$this->productId}].");
        }

        if ($this->quantity->toBigDecimal()->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Stock transfer line quantity must be strictly positive (> 0).');
        }

        if ($this->unitId !== null && $this->unitId <= 0) {
            throw new InvalidArgumentException("Unit ID must be a positive integer. Given [{$this->unitId}].");
        }

        if ($this->lotId !== null && $this->lotId <= 0) {
            throw new InvalidArgumentException("Lot ID must be a positive integer. Given [{$this->lotId}].");
        }
    }
}
