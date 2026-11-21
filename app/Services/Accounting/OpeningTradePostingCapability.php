<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Models\OpeningTradeItem;

final class OpeningTradePostingCapability
{
    /** @var array<string, bool> */
    private array $authorizedItems = [];

    /** @var array<int, int> */
    private array $authorizedBatches = [];

    public function __construct(
        public readonly int $companyId,
        public readonly int $actorId,
        public readonly string $side,
        public readonly int $partyId,
    ) {}

    public function authorizeItem(OpeningTradeItem $item): void
    {
        $this->authorizedItems[spl_object_hash($item)] = true;
        if (! empty($item->public_id)) {
            $this->authorizedItems[$item->public_id] = true;
        }
    }

    public function authorizeBatchLink(int $itemId, int $batchId): void
    {
        $this->authorizedBatches[$itemId] = $batchId;
    }

    public function isItemAuthorized(OpeningTradeItem $item): bool
    {
        return isset($this->authorizedItems[spl_object_hash($item)])
            || (! empty($item->public_id) && isset($this->authorizedItems[$item->public_id]));
    }

    public function isBatchLinkAuthorized(int $itemId, int $batchId): bool
    {
        return isset($this->authorizedBatches[$itemId]) && $this->authorizedBatches[$itemId] === $batchId;
    }
}
