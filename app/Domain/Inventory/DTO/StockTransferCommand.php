<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

use InvalidArgumentException;

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
        if ($this->companyId <= 0) {
            throw new InvalidArgumentException("Company ID must be a positive integer. Given [{$this->companyId}].");
        }

        if (empty($this->lines)) {
            throw new InvalidArgumentException('Stock transfer command must contain at least one line.');
        }

        if ($this->sourceWarehouseId <= 0) {
            throw new InvalidArgumentException("Source warehouse ID must be a positive integer. Given [{$this->sourceWarehouseId}].");
        }

        if ($this->destinationWarehouseId <= 0) {
            throw new InvalidArgumentException("Destination warehouse ID must be a positive integer. Given [{$this->destinationWarehouseId}].");
        }

        if ($this->sourceWarehouseId === $this->destinationWarehouseId) {
            throw new InvalidArgumentException('Source and destination warehouses must be different.');
        }

        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key is required and must not be empty.');
        }

        if (strlen($this->idempotencyKey) > 191) {
            throw new InvalidArgumentException('Idempotency key must be at most 191 characters.');
        }

        // Canonical lowercase source type with schema length (VARCHAR(64))
        if (strlen($this->sourceType) > 64 || ! preg_match('/\A[a-z0-9_-]+\z/', $this->sourceType)) {
            throw new InvalidArgumentException("Invalid source type [{$this->sourceType}]. Must be lowercase alphanumeric with underscores/hyphens and max 64 characters.");
        }

        if ($this->sourceId <= 0) {
            throw new InvalidArgumentException("Source ID must be a positive integer. Given [{$this->sourceId}].");
        }

        if ($this->createdBy <= 0) {
            throw new InvalidArgumentException("Actor (createdBy) must be a positive integer. Given [{$this->createdBy}].");
        }

        // Valid canonical Y-m-d movement date
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $this->movementDate)) {
            throw new InvalidArgumentException("Movement date [{$this->movementDate}] must be in canonical Y-m-d format.");
        }
        [$year, $month, $day] = explode('-', $this->movementDate);
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            throw new InvalidArgumentException("Movement date [{$this->movementDate}] is not a valid calendar date.");
        }

        // Reason length bounded (VARCHAR(512) in schema)
        if ($this->reason !== null && mb_strlen($this->reason, 'UTF-8') > 512) {
            throw new InvalidArgumentException('Reason exceeds maximum allowed length of 512 characters.');
        }
    }
}
