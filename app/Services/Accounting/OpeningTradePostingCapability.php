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

    /** @var array<int, bool> */
    private array $authorizedSourceItemIds = [];

    /** @var array<int, OpeningTradeItem> */
    private array $authorizedItemsBySourceId = [];

    /** @var array<int, bool> */
    private array $postedSourceItemIds = [];

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
        if ($item->exists) {
            $this->authorizedSourceItemIds[(int) $item->id] = true;
            $this->authorizedItemsBySourceId[(int) $item->id] = $item;
        }
    }

    public function authorizeSourceItemId(int $itemId, ?OpeningTradeItem $item = null): void
    {
        if ($itemId <= 0) {
            throw new \InvalidArgumentException('Source item ID must be a strictly positive integer.');
        }
        $this->authorizedSourceItemIds[$itemId] = true;
        if ($item !== null) {
            $this->authorizedItemsBySourceId[$itemId] = $item;
        }
    }

    public function getAuthorizedItem(int $sourceId): ?OpeningTradeItem
    {
        return $this->authorizedItemsBySourceId[$sourceId] ?? null;
    }

    public function hasJournalBeenPosted(int $sourceId): bool
    {
        return isset($this->postedSourceItemIds[$sourceId]);
    }

    public function markJournalPosted(int $sourceId): void
    {
        $this->postedSourceItemIds[$sourceId] = true;
    }

    public function isSourceItemAuthorized(int $sourceId): bool
    {
        return isset($this->authorizedSourceItemIds[$sourceId]);
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
