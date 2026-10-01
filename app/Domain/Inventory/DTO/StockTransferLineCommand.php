<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

use App\Domain\Inventory\ValueObjects\Quantity;

final readonly class StockTransferLineCommand
{
    public function __construct(
        public int $productId,
        public Quantity $quantity,
        public ?int $unitId = null,
        public ?int $lotId = null,
    ) {}
}
