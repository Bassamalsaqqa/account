<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

final readonly class StockTransferCommand
{
    /**
     * @param  list<StockTransferLineCommand>  $lines
     */
    public function __construct(
        public int $companyId,
        public int $sourceWarehouseId,
        public int $destinationWarehouseId,
        public string $movementDate,
        public array $lines,
        public string $idempotencyKey,
        public int $createdBy,
        public string $sourceType = 'warehouse_transfer',
        public int $sourceId = 1,
        public ?string $reason = null,
    ) {
        if (empty($this->lines)) {
            throw new \InvalidArgumentException('Stock transfer command must contain at least one line.');
        }

        if ($this->sourceWarehouseId === $this->destinationWarehouseId) {
            throw new \InvalidArgumentException('Source and destination warehouses must be different.');
        }

        if (trim($this->idempotencyKey) === '') {
            throw new \InvalidArgumentException('Idempotency key is required and must not be empty.');
        }

        if (strlen($this->idempotencyKey) > 191) {
            throw new \InvalidArgumentException('Idempotency key must be at most 191 characters.');
        }

        if (! preg_match('/\A[a-z0-9_-]+\z/', $this->sourceType)) {
            throw new \InvalidArgumentException("Invalid source type [{$this->sourceType}].");
        }

        if ($this->sourceId <= 0) {
            throw new \InvalidArgumentException("Source ID must be a positive integer. Given [{$this->sourceId}].");
        }

        if ($this->createdBy <= 0) {
            throw new \InvalidArgumentException("Actor (createdBy) must be a positive integer. Given [{$this->createdBy}].");
        }
    }
}
