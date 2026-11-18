<?php

declare(strict_types=1);

namespace App\Domain\Purchasing\DTO;

use InvalidArgumentException;

final readonly class RecordGoodsReceiptCommand
{
    /**
     * @param  list<GoodsReceiptLineCommand>  $lines
     */
    public function __construct(
        public int $companyId,
        public int $purchaseId,
        public int $destinationWarehouseId,
        public string $receiptDate,
        public array $lines,
        public string $idempotencyKey,
        public int $createdBy,
        public ?string $notes = null,
    ) {
        if ($this->companyId <= 0) {
            throw new InvalidArgumentException("Company ID must be a positive integer. Given [{$this->companyId}].");
        }

        if ($this->purchaseId <= 0) {
            throw new InvalidArgumentException("Purchase ID must be a positive integer. Given [{$this->purchaseId}].");
        }

        if ($this->destinationWarehouseId <= 0) {
            throw new InvalidArgumentException("Destination warehouse ID must be a positive integer. Given [{$this->destinationWarehouseId}].");
        }

        if (trim($this->idempotencyKey) === '') {
            throw new InvalidArgumentException('Idempotency key is required and must not be empty.');
        }

        if (strlen($this->idempotencyKey) > 191) {
            throw new InvalidArgumentException('Idempotency key must be at most 191 characters.');
        }

        if (empty($this->lines)) {
            throw new InvalidArgumentException('Goods receipt command must contain at least one line.');
        }
    }

    /**
     * Normalized canonical economic payload array for request hashing and conflict detection.
     *
     * @return array<string, mixed>
     */
    public function toPayloadArray(): array
    {
        $normalizedLines = [];
        foreach ($this->lines as $line) {
            $normalizedLines[] = [
                'purchase_line_id' => $line->purchaseLineId,
                'quantity' => (string) $line->quantity,
                'lot_number' => $line->lotNumber !== null && trim($line->lotNumber) !== '' ? trim($line->lotNumber) : null,
                'expiry_date' => $line->expiryDate,
            ];
        }

        usort($normalizedLines, fn ($a, $b) => $a['purchase_line_id'] <=> $b['purchase_line_id']);

        return [
            'destination_warehouse_id' => $this->destinationWarehouseId,
            'receipt_date' => $this->receiptDate,
            'notes' => $this->notes !== null && trim($this->notes) !== '' ? trim($this->notes) : null,
            'lines' => $normalizedLines,
        ];
    }
}
