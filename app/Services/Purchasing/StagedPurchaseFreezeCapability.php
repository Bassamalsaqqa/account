<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\RecordStagedPurchaseBillAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Models\Purchase;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One-shot transaction-bound capability token owned by RecordStagedPurchaseBillAction
 * strictly required to freeze the response snapshot on a staged Purchase.
 */
final class StagedPurchaseFreezeCapability
{
    private bool $consumed = false;

    private readonly Connection $connection;

    private readonly ?DatabaseTransactionRecord $transaction;

    private readonly int $transactionLevel;

    public function __construct(
        private readonly RecordStagedPurchaseBillAction $action,
        private readonly int $companyId,
        private readonly int $actorId,
        private readonly int $purchaseId
    ) {
        $this->connection = DB::connection();
        $this->transactionLevel = $this->connection->transactionLevel();
        if ($this->transactionLevel === 0) {
            throw new ImmutableRecordException('Staged purchase snapshot freeze requires an active transaction.');
        }

        /** @var DatabaseTransactionsManager $transactions */
        $transactions = app('db.transactions');
        $this->transaction = $transactions->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $this->connection->getName()
                && $record->level === $this->transactionLevel
        );
    }

    private function __clone() {}

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Freeze capabilities cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Freeze capabilities cannot be restored.');
    }

    public function isConsumed(): bool
    {
        return $this->consumed;
    }

    public function consume(): void
    {
        $this->consumed = true;
    }

    public function assertValidFor(Purchase $purchase): void
    {
        if ($this->consumed) {
            throw new ImmutableRecordException('Purchase response snapshot capability has already been consumed.');
        }

        if (! $this->action->ownsFreezeCapability($this)) {
            throw new ImmutableRecordException('Only the executing canonical staged purchase bill action may freeze response snapshots.');
        }

        if ($this->connection->transactionLevel() === 0
            || $this->connection->transactionLevel() !== $this->transactionLevel
            || $this->connection !== DB::connection()
            || ($this->transaction !== null && ! app('db.transactions')->getPendingTransactions()->contains($this->transaction))) {
            throw new ImmutableRecordException('Purchase snapshot freeze must occur within its original active transaction.');
        }

        if (! $purchase->exists) {
            throw new ImmutableRecordException('Purchase must be a persisted row to freeze its response snapshot.');
        }

        if ((int) $purchase->id !== $this->purchaseId) {
            throw new ImmutableRecordException('Freeze capability is not bound to this purchase instance.');
        }

        if ((int) $purchase->company_id !== $this->companyId) {
            throw new ImmutableRecordException('Purchase company identity does not match the freeze capability.');
        }

        if ((int) $purchase->created_by !== $this->actorId) {
            throw new ImmutableRecordException('Purchase actor identity does not match the freeze capability.');
        }
    }
}
