<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\User;
use InvalidArgumentException;

/** Request-local canonical authority for Opening Trade Balances. */
final class OpeningTradeEventScope
{
    private ?OpeningTradePostingCapability $activePosting = null;

    private ?OpeningTradeReversalCapability $activeReversal = null;

    /** @var array<string, array{item_id: int, company_id: int, item_hash: string}> */
    private array $activeUpdateTokens = [];

    /** @var array<string, array{company_id: int, actor_id: int, item_hash: string}> */
    private array $activeInsertTokens = [];

    public function hasActiveCapability(): bool
    {
        return $this->activePosting !== null || $this->activeReversal !== null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withPostingCapability(PostOpeningTradeItemAction $owner, OpeningTradePostingCapability $capability, callable $callback): mixed
    {
        if (! $owner->ownsEventScope($this) || $this->activePosting !== null) {
            throw new ImmutableRecordException('Only the executing canonical opening action may activate posting authority.');
        }
        $previous = $this->activePosting;
        $this->activePosting = $capability;
        try {
            return $callback();
        } finally {
            $this->activePosting = $previous;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withReversalCapability(ReverseOpeningTradeItemAction $owner, OpeningTradeReversalCapability $capability, callable $callback): mixed
    {
        if (! $owner->ownsEventScope($this) || $this->activeReversal !== null) {
            throw new ImmutableRecordException('Only the executing canonical reversal action may activate reversal authority.');
        }
        $previous = $this->activeReversal;
        $this->activeReversal = $capability;
        try {
            return $callback();
        } finally {
            $this->activeReversal = $previous;
        }
    }

    public function authorizeModelUpdate(OpeningTradeItem $item): string
    {
        if (! $this->hasActiveCapability()) {
            throw new ImmutableRecordException('Cannot authorize model update without an active canonical capability.');
        }

        $itemId = $item->getKey();
        if ($itemId === null) {
            throw new ImmutableRecordException('Cannot authorize model update for unpersisted item.');
        }

        $token = bin2hex(random_bytes(16));
        $this->activeUpdateTokens[$token] = [
            'item_id' => (int) $itemId,
            'company_id' => (int) $item->company_id,
            'item_hash' => spl_object_hash($item),
        ];

        return $token;
    }

    public function verifyModelUpdateToken(OpeningTradeItem $item, string $token): void
    {
        if (! isset($this->activeUpdateTokens[$token])) {
            throw new ImmutableRecordException('Invalid or expired model update token.');
        }

        $info = $this->activeUpdateTokens[$token];
        if ($info['item_id'] !== (int) $item->getKey()
            || $info['company_id'] !== (int) $item->company_id
            || $info['item_hash'] !== spl_object_hash($item)) {
            throw new ImmutableRecordException('Model update token does not match target item.');
        }
    }

    public function consumeModelUpdateToken(string $token): void
    {
        unset($this->activeUpdateTokens[$token]);
    }

    public function authorizeModelInsert(OpeningTradeItem $item): string
    {
        if ($this->activePosting === null) {
            throw new ImmutableRecordException('Cannot authorize model insert without an active canonical posting capability.');
        }

        if ((int) $item->company_id !== $this->activePosting->companyId || (int) $item->created_by !== $this->activePosting->actorId) {
            throw new ImmutableRecordException('Item company or actor does not match active posting capability.');
        }

        $token = bin2hex(random_bytes(16));
        $this->activeInsertTokens[$token] = [
            'company_id' => (int) $item->company_id,
            'actor_id' => (int) $item->created_by,
            'item_hash' => spl_object_hash($item),
        ];

        return $token;
    }

    public function verifyModelInsertToken(OpeningTradeItem $item, string $token): void
    {
        if (! isset($this->activeInsertTokens[$token])) {
            throw new ImmutableRecordException('Invalid or expired model insert token.');
        }

        $info = $this->activeInsertTokens[$token];
        if ($info['company_id'] !== (int) $item->company_id
            || $info['actor_id'] !== (int) $item->created_by
            || $info['item_hash'] !== spl_object_hash($item)) {
            throw new ImmutableRecordException('Model insert token does not match item identity.');
        }
    }

    public function consumeModelInsertToken(string $token): void
    {
        unset($this->activeInsertTokens[$token]);
    }

    public function assertPosting(PostingCommand $command): void
    {
        if ($this->activePosting === null) {
            throw new ImmutableRecordException('Direct GL posting for opening_trade_item requires canonical action capability.');
        }

        if ($command->sourceType !== 'opening_trade_item') {
            throw new InvalidArgumentException("Posting command sourceType [{$command->sourceType}] must be 'opening_trade_item'.");
        }

        if ((int) $command->company->id !== $this->activePosting->companyId) {
            throw new InvalidArgumentException('Posting command company does not match authorized capability.');
        }

        if ($command->postedBy === null || (int) $command->postedBy->id !== $this->activePosting->actorId) {
            throw new ImmutableRecordException('Posting command actor does not match authorized posting capability.');
        }

        if (! $this->activePosting->isSourceItemAuthorized($command->sourceId)) {
            throw new ImmutableRecordException("Posting command source item ID [{$command->sourceId}] is not authorized by the active posting capability.");
        }
    }

    public function assertReversal(PostingBatch $batch, User $actor, ?string $postingDate): void
    {
        if ($this->activeReversal === null) {
            throw new ImmutableRecordException('Direct GL reversal for opening_trade_item requires canonical reversal capability.');
        }

        if ((int) $batch->company_id !== $this->activeReversal->companyId) {
            throw new InvalidArgumentException('Reversal batch company does not match authorized reversal capability.');
        }

        if ((int) $batch->id !== $this->activeReversal->originalBatchId) {
            throw new InvalidArgumentException('Reversal batch does not match authorized reversal capability.');
        }

        if ($batch->source_type !== 'opening_trade_item') {
            throw new InvalidArgumentException("Reversal batch sourceType [{$batch->source_type}] must be 'opening_trade_item'.");
        }

        if ((int) $batch->source_id !== $this->activeReversal->itemId) {
            throw new InvalidArgumentException('Reversal batch source item ID does not match authorized reversal capability.');
        }

        if ((int) $actor->id !== $this->activeReversal->actorId) {
            throw new ImmutableRecordException('Reversal actor does not match authorized reversal capability.');
        }
    }

    public function assertCanCreateItem(OpeningTradeItem $item): void
    {
        if ($this->activePosting === null) {
            throw new ImmutableRecordException('Direct creation of posted opening trade item is prohibited outside canonical posting action.');
        }

        if ((int) $item->company_id !== $this->activePosting->companyId || $item->side !== $this->activePosting->side) {
            throw new InvalidArgumentException('Opening trade item does not match authorized posting capability.');
        }

        $partyId = (int) ($item->customer_id ?? $item->vendor_id ?? 0);
        if ($partyId !== $this->activePosting->partyId) {
            throw new InvalidArgumentException('Opening trade item party does not match authorized posting capability.');
        }

        if (! $this->activePosting->isItemAuthorized($item)) {
            throw new ImmutableRecordException('Opening trade item is not authorized by the active posting capability.');
        }
    }

    public function assertCanLinkPostingBatch(OpeningTradeItem $item, int $batchId): void
    {
        if ($this->activePosting === null) {
            throw new ImmutableRecordException('Linking posting batch to opening trade item requires canonical posting capability.');
        }

        if ((int) $item->company_id !== $this->activePosting->companyId) {
            throw new InvalidArgumentException('Opening trade item company does not match authorized posting capability.');
        }

        if (! $this->activePosting->isBatchLinkAuthorized((int) $item->id, $batchId)) {
            throw new ImmutableRecordException("Linking posting batch [{$batchId}] to opening trade item [{$item->id}] is not authorized by active capability.");
        }
    }

    public function assertCanReverseItem(OpeningTradeItem $item): void
    {
        if ($this->activeReversal === null) {
            throw new ImmutableRecordException('Direct status transition to reversed is prohibited outside canonical reversal action.');
        }

        if ((int) $item->company_id !== $this->activeReversal->companyId || (int) $item->posting_batch_id !== $this->activeReversal->originalBatchId) {
            throw new InvalidArgumentException('Opening trade item does not match authorized reversal capability.');
        }

        if ((int) $item->id !== $this->activeReversal->itemId) {
            throw new InvalidArgumentException('Opening trade item ID does not match authorized reversal capability.');
        }

        if ($item->reversal_posting_batch_id !== null && ! $this->activeReversal->isReversalBatchAuthorized((int) $item->reversal_posting_batch_id)) {
            throw new ImmutableRecordException("Reversal batch [{$item->reversal_posting_batch_id}] is not authorized for opening trade item [{$item->id}].");
        }
    }
}
