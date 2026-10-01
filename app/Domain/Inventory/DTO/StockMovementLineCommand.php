<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use Brick\Math\BigDecimal;

final readonly class StockMovementLineCommand
{
    public function __construct(
        public int $productId,
        public int $warehouseId,
        public Quantity $quantity,
        public ?int $unitId = null,
        public ?string $unitCostBase = null,
        public ?int $lotId = null,
        public ?string $lotNumber = null,
        public ?string $expiryDate = null,
    ) {
        if ($this->unitCostBase !== null) {
            if (! is_numeric($this->unitCostBase)) {
                throw new InvalidInventoryMovementException("Inbound unit cost must be numeric. Given [{$this->unitCostBase}].");
            }
            $bd = BigDecimal::of($this->unitCostBase);
            if ($bd->isNegative()) {
                throw new InvalidInventoryMovementException("Inbound unit cost cannot be negative. Given [{$this->unitCostBase}].");
            }
            if ($bd->stripTrailingZeros()->getScale() > 6) {
                throw new InvalidInventoryMovementException("Inbound unit cost [{$this->unitCostBase}] exceeds maximum precision of 6 decimal places.");
            }
            $max = BigDecimal::of('99999999999999.999999');
            if ($bd->isGreaterThan($max)) {
                throw new InvalidInventoryMovementException("Inbound unit cost [{$this->unitCostBase}] exceeds DECIMAL(20,6) boundary.");
            }
        }
    }
}
