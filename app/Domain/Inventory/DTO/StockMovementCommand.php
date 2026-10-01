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
        ];
        if (! in_array($this->movementType, $allowed, true)) {
            throw new \InvalidArgumentException("Movement type [{$this->movementType}] is not a recognized Phase3 canonical type.");
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
