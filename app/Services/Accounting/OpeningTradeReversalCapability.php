<?php

declare(strict_types=1);

namespace App\Services\Accounting;

final class OpeningTradeReversalCapability
{
    private ?int $reversalBatchId = null;

    public function __construct(
        public readonly int $companyId,
        public readonly int $actorId,
        public readonly int $originalBatchId,
        public readonly int $itemId,
    ) {}

    public function authorizeReversalBatch(int $batchId): void
    {
        $this->reversalBatchId = $batchId;
    }

    public function isReversalBatchAuthorized(int $batchId): bool
    {
        return $this->reversalBatchId === $batchId;
    }
}
