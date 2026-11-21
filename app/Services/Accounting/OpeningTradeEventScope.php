<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\PostingBatch;
use App\Models\User;
use InvalidArgumentException;

/** Request-local canonical authority for Opening Trade Balances. */
final class OpeningTradeEventScope
{
    private ?OpeningTradePostingCapability $activePosting = null;

    private ?OpeningTradeReversalCapability $activeReversal = null;

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

    public function assertPosting(PostingCommand $command): void
    {
        if ($this->activePosting === null) {
            throw new ImmutableRecordException('Direct GL posting for opening_trade_item requires canonical action capability.');
        }

        if ((int) $command->company->id !== $this->activePosting->companyId) {
            throw new InvalidArgumentException('Posting command company does not match authorized capability.');
        }
    }

    public function assertReversal(PostingBatch $batch, User $actor, ?string $postingDate): void
    {
        if ($this->activeReversal === null) {
            throw new ImmutableRecordException('Direct GL reversal for opening_trade_item requires canonical reversal capability.');
        }

        if ((int) $batch->company_id !== $this->activeReversal->companyId || (int) $batch->id !== $this->activeReversal->originalBatchId) {
            throw new InvalidArgumentException('Reversal batch does not match authorized reversal capability.');
        }
    }
}
