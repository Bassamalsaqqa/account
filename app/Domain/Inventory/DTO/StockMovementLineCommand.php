<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

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
        if ($this->productId <= 0) {
            throw new InvalidArgumentException("Product ID must be a positive integer. Given [{$this->productId}].");
        }

        if ($this->warehouseId <= 0) {
            throw new InvalidArgumentException("Warehouse ID must be a positive integer. Given [{$this->warehouseId}].");
        }

        if ($this->quantity->toBigDecimal()->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException('Stock movement line quantity must be strictly positive (> 0).');
        }

        if ($this->unitId !== null && $this->unitId <= 0) {
            throw new InvalidArgumentException("Unit ID must be a positive integer. Given [{$this->unitId}].");
        }

        if ($this->lotId !== null && $this->lotId <= 0) {
            throw new InvalidArgumentException("Lot ID must be a positive integer. Given [{$this->lotId}].");
        }

        if ($this->lotNumber !== null && mb_strlen($this->lotNumber, 'UTF-8') > 128) {
            throw new InvalidArgumentException('Lot number exceeds maximum allowed length of 128 characters.');
        }

        if ($this->expiryDate !== null) {
            if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $this->expiryDate)) {
                throw new InvalidArgumentException("Expiry date [{$this->expiryDate}] must be in canonical Y-m-d format.");
            }
            [$year, $month, $day] = explode('-', $this->expiryDate);
            if (! checkdate((int) $month, (int) $day, (int) $year)) {
                throw new InvalidArgumentException("Expiry date [{$this->expiryDate}] is not a valid calendar date.");
            }
        }

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
