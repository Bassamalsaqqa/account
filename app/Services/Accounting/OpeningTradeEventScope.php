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
use Brick\Math\BigDecimal;
use InvalidArgumentException;

/** Request-local canonical authority for Opening Trade Balances. */
final class OpeningTradeEventScope
{
    private ?OpeningTradePostingCapability $activePosting = null;

    private ?OpeningTradeReversalCapability $activeReversal = null;

    /** @var array<string, array{item_id: int, company_id: int, actor_id: int, transition: string, approved_values: array<string, mixed>, item_hash: string}> */
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

        $transition = null;
        $actorId = null;
        $approvedValues = [];

        if ($this->activePosting !== null) {
            // Posting capability only authorizes linking the posting batch
            if (! $item->isDirty('posting_batch_id')) {
                throw new ImmutableRecordException('Active posting capability only authorizes linking the posting batch.');
            }
            if ($item->getOriginal('posting_batch_id') !== null) {
                throw new ImmutableRecordException('Posting batch cannot be reassigned on a posted opening trade item.');
            }
            if ((int) $item->company_id !== $this->activePosting->companyId) {
                throw new ImmutableRecordException('Item company does not match active posting capability.');
            }
            if (! $this->activePosting->isSourceItemAuthorized((int) $itemId)) {
                throw new ImmutableRecordException("Item ID [{$itemId}] is not authorized by the active posting capability.");
            }
            $batchId = (int) $item->posting_batch_id;
            if (! $this->activePosting->isBatchLinkAuthorized((int) $itemId, $batchId)) {
                throw new ImmutableRecordException("Linking posting batch [{$batchId}] to opening trade item [{$itemId}] is not authorized by active capability.");
            }

            // Only posting_batch_id (and updated_at) may be modified
            $dirty = array_diff(array_keys($item->getDirty()), ['posting_batch_id', 'updated_at']);
            if (! empty($dirty)) {
                throw new ImmutableRecordException('Only posting_batch_id can be updated during posting batch link transition.');
            }

            $transition = 'link_posting_batch';
            $actorId = $this->activePosting->actorId;
            $approvedValues = [
                'posting_batch_id' => $batchId,
            ];
        } elseif ($this->activeReversal !== null) {
            // Reversal capability only authorizes the reverse transition
            if (! $item->isDirty('status') || $item->status !== OpeningTradeItem::STATUS_REVERSED) {
                throw new ImmutableRecordException('Active reversal capability only authorizes transition to reversed status.');
            }
            if ($item->getOriginal('status') !== OpeningTradeItem::STATUS_POSTED) {
                throw new ImmutableRecordException('Opening trade item must be in posted status to transition to reversed.');
            }
            if ((int) $item->company_id !== $this->activeReversal->companyId) {
                throw new ImmutableRecordException('Item company does not match active reversal capability.');
            }
            if ((int) $itemId !== $this->activeReversal->itemId) {
                throw new ImmutableRecordException('Item ID does not match active reversal capability.');
            }
            if ((int) $item->reversed_by !== $this->activeReversal->actorId) {
                throw new ImmutableRecordException('Reversal actor does not match active reversal capability.');
            }
            if ((int) $item->posting_batch_id !== $this->activeReversal->originalBatchId) {
                throw new ImmutableRecordException('Original posting batch does not match active reversal capability.');
            }
            $revBatchId = (int) $item->reversal_posting_batch_id;
            if (! $this->activeReversal->isReversalBatchAuthorized($revBatchId)) {
                throw new ImmutableRecordException("Reversal batch [{$revBatchId}] is not authorized for opening trade item [{$itemId}].");
            }

            $allowedDirty = ['status', 'reversal_posting_batch_id', 'reversed_at', 'reversed_by', 'correction_reason', 'updated_at'];
            $disallowedDirty = array_diff(array_keys($item->getDirty()), $allowedDirty);
            if (! empty($disallowedDirty)) {
                throw new ImmutableRecordException('Disallowed fields modified during opening trade item reversal.');
            }

            $transition = 'reverse';
            $actorId = $this->activeReversal->actorId;
            $approvedValues = [
                'status' => OpeningTradeItem::STATUS_REVERSED,
                'reversal_posting_batch_id' => $revBatchId,
                'reversed_at' => (string) $item->reversed_at,
                'reversed_by' => (int) $item->reversed_by,
                'correction_reason' => (string) $item->correction_reason,
            ];
        } else {
            throw new ImmutableRecordException('Cannot authorize model update without an active canonical capability.');
        }

        $token = bin2hex(random_bytes(16));
        $this->activeUpdateTokens[$token] = [
            'item_id' => (int) $itemId,
            'company_id' => (int) $item->company_id,
            'actor_id' => $actorId,
            'transition' => $transition,
            'approved_values' => $approvedValues,
            'item_hash' => spl_object_hash($item),
        ];

        return $token;
    }

    /**
     * @return array{item_id: int, company_id: int, actor_id: int, transition: string, approved_values: array<string, mixed>, item_hash: string}
     */
    public function getUpdateTokenInfo(string $token): array
    {
        if (! isset($this->activeUpdateTokens[$token])) {
            throw new ImmutableRecordException('Invalid or expired model update token.');
        }

        return $this->activeUpdateTokens[$token];
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

        if ($command->sourceId <= 0) {
            throw new ImmutableRecordException('Posting command source item ID must be a strictly positive integer.');
        }

        if (! $this->activePosting->isSourceItemAuthorized($command->sourceId)) {
            throw new ImmutableRecordException("Posting command source item ID [{$command->sourceId}] is not authorized by the active posting capability.");
        }

        if ($this->activePosting->hasJournalBeenPosted($command->sourceId)) {
            throw new ImmutableRecordException("A journal command has already been posted for opening trade item [{$command->sourceId}].");
        }

        $item = $this->activePosting->getAuthorizedItem($command->sourceId);
        if ($item !== null) {
            // Verify date matches
            $commandDate = $command->postingDate->format('Y-m-d');
            $itemDate = $item->opening_date->format('Y-m-d');
            if ($commandDate !== $itemDate) {
                throw new ImmutableRecordException('Posting command date does not match authorized opening trade item.');
            }

            // Verify currency matches
            if ($command->transactionCurrencyCode !== $item->currency_code) {
                throw new ImmutableRecordException('Posting command currency does not match authorized opening trade item.');
            }

            // Verify exchange rate matches
            if (! $command->exchangeRate->getValue()->isEqualTo(BigDecimal::of((string) $item->exchange_rate))) {
                throw new ImmutableRecordException('Posting command exchange rate does not match authorized opening trade item.');
            }

            // Verify lines: must be exactly 2 lines matching the economic event
            if (count($command->lines) !== 2) {
                throw new ImmutableRecordException('Posting command lines must contain exactly two lines for opening trade item.');
            }

            $line1 = $command->lines[0];
            $line2 = $command->lines[1];

            $expectedAmount = BigDecimal::of((string) $item->amount_currency);
            $expectedCarryingBase = BigDecimal::of((string) $item->carrying_value_base);

            // One line debit, one line credit
            $line1Debit = $line1->debitBase->isPositive();
            $line2Debit = $line2->debitBase->isPositive();
            $hasDebit = ($line1Debit && ! $line2Debit) || (! $line1Debit && $line2Debit);
            if (! $hasDebit) {
                throw new ImmutableRecordException('Posting command must have one debit line and one credit line.');
            }

            $debitLine = $line1Debit ? $line1 : $line2;
            $creditLine = $line1Debit ? $line2 : $line1;

            if (! $debitLine->debitBase->getAmount()->isEqualTo($expectedCarryingBase)
                || ! $creditLine->creditBase->getAmount()->isEqualTo($expectedCarryingBase)) {
                throw new ImmutableRecordException('Posting command amounts do not match authorized opening trade item.');
            }

            if ($debitLine->transactionAmount !== null && ! $debitLine->transactionAmount->getAmount()->isEqualTo($expectedAmount)) {
                throw new ImmutableRecordException('Posting command amounts do not match authorized opening trade item.');
            }

            if ($creditLine->transactionAmount !== null && ! $creditLine->transactionAmount->getAmount()->isEqualTo($expectedAmount)) {
                throw new ImmutableRecordException('Posting command amounts do not match authorized opening trade item.');
            }

            // Mark journal as posted for this item
            $this->activePosting->markJournalPosted((int) $command->sourceId);
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
