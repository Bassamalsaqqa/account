<?php

declare(strict_types=1);

namespace App\Domain\Inventory\DTO;

final readonly class StockMovementCommand
{
    /**
     * @param  list<StockMovementLineCommand>  $lines
     */
    public function __construct(
        public int $companyId,
        public string $movementType,
        public string $movementDate,
        public array $lines,
        public string $sourceType,
        public int $sourceId,
        public string $idempotencyKey,
        public int $createdBy,
        public ?int $sourceLineId = null,
        public ?string $reason = null,
    ) {
        if ($this->companyId <= 0) {
            throw new \InvalidArgumentException("Company ID must be a positive integer. Given [{$this->companyId}].");
        }

        if (empty($this->lines)) {
            throw new \InvalidArgumentException('Stock movement command must contain at least one line.');
        }

        if (trim($this->idempotencyKey) === '') {
            throw new \InvalidArgumentException('Idempotency key is required and must not be empty.');
        }

        if (strlen($this->idempotencyKey) > 191) {
            throw new \InvalidArgumentException('Idempotency key must be at most 191 characters.');
        }

        // Validate movement type is a known Phase3 canonical type only
        $allowed = [
            'opening_balance',
            'transfer_in',
            'transfer_out',
            'adjustment_increase',
            'adjustment_decrease',
            'damage_or_loss',
            'expiry_disposal',
            'sale',
            'sale_return',
            'purchase',
            'purchase_return',
        ];
        if (! in_array($this->movementType, $allowed, true)) {
            throw new \InvalidArgumentException("Movement type [{$this->movementType}] is not a recognized Phase3 canonical type.");
        }

        // Canonical lowercase source type with schema length (VARCHAR(64))
        if (strlen($this->sourceType) > 64 || ! preg_match('/\A[a-z0-9_-]+\z/', $this->sourceType)) {
            throw new \InvalidArgumentException("Invalid source type [{$this->sourceType}]. Must be lowercase alphanumeric with underscores/hyphens and max 64 characters.");
        }

        if ($this->sourceId <= 0) {
            throw new \InvalidArgumentException("Source ID must be a positive integer. Given [{$this->sourceId}].");
        }

        if ($this->sourceLineId !== null && $this->sourceLineId <= 0) {
            throw new \InvalidArgumentException("Source line ID must be a positive integer. Given [{$this->sourceLineId}].");
        }

        if ($this->createdBy <= 0) {
            throw new \InvalidArgumentException("Actor (createdBy) must be a positive integer. Given [{$this->createdBy}].");
        }

        // Valid canonical Y-m-d movement date
        if (! preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $this->movementDate)) {
            throw new \InvalidArgumentException("Movement date [{$this->movementDate}] must be in canonical Y-m-d format.");
        }
        [$year, $month, $day] = explode('-', $this->movementDate);
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            throw new \InvalidArgumentException("Movement date [{$this->movementDate}] is not a valid calendar date.");
        }

        // Reason length bounded (VARCHAR(512) in schema)
        if ($this->reason !== null && mb_strlen($this->reason, 'UTF-8') > 512) {
            throw new \InvalidArgumentException('Reason exceeds maximum allowed length of 512 characters.');
        }
    }
}
