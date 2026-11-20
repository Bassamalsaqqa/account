<?php

declare(strict_types=1);

namespace App\Services\Accounting;

use App\Actions\Accounting\PostOpeningBalancesAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Posting\DTO\PostingCommand;
use InvalidArgumentException;

/** Request-local canonical authority for Generic Opening Balances. */
final class GenericOpeningPostingScope
{
    private ?int $activeCompanyId = null;

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withPostingCapability(PostOpeningBalancesAction $owner, int $companyId, callable $callback): mixed
    {
        if (! $owner->ownsEventScope($this) || $this->activeCompanyId !== null) {
            throw new ImmutableRecordException('Only the executing canonical opening balances action may activate posting authority.');
        }
        $this->activeCompanyId = $companyId;
        try {
            return $callback();
        } finally {
            $this->activeCompanyId = null;
        }
    }

    public function assertPosting(PostingCommand $command): void
    {
        if ($this->activeCompanyId === null) {
            throw new ImmutableRecordException('Direct GL posting for opening_balance requires canonical PostOpeningBalancesAction authority.');
        }

        if ((int) $command->company->id !== $this->activeCompanyId) {
            throw new InvalidArgumentException('Posting command company does not match authorized generic opening capability.');
        }
    }
}
