<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\ExecutePhysicalPurchaseReturnAction;
use App\Actions\Purchasing\RecordPurchaseGoodsReceiptAction;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Warehouse;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Request-local authority for executing canonical goods receipt stock transfers involving in-transit warehouses.
 */
final class GoodsReceiptScope
{
    private function __clone() {}

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('Goods receipt scopes cannot be serialized.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Goods receipt scopes cannot be restored.');
    }

    private ?GoodsReceiptCapability $active = null;

    private ?object $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $purchaseId = 0;

    private int $sourceWarehouseId = 0;

    private int $destinationWarehouseId = 0;

    private int $actorId = 0;

    private ?string $allocatedNumber = null;

    private bool $transferAsserted = false;

    public function getPurchaseId(): int
    {
        return $this->purchaseId;
    }

    public function getActorId(): int
    {
        return $this->actorId;
    }

    private function transactions(): DatabaseTransactionsManager
    {
        return app('db.transactions');
    }

    /**
     * @template T
     *
     * @param  Closure(GoodsReceiptCapability): T  $callback
     * @return T
     */
    public function withinGoodsReceipt(
        RecordPurchaseGoodsReceiptAction $owner,
        Purchase $purchase,
        Warehouse $sourceWarehouse,
        Warehouse $destinationWarehouse,
        User $actor,
        Closure $callback
    ): mixed {
        if ($this->active !== null || ! $owner->ownsGoodsReceiptScope($this)) {
            throw new InvalidArgumentException('Only the executing canonical GRN action may activate a non-reentrant receipt scope.');
        }

        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel()
        );

        if ($connection->transactionLevel() === 0 || $transaction === null || ! $purchase->exists) {
            throw new InvalidArgumentException('Goods receipt scope requires its active canonical database transaction.');
        }

        $capability = new GoodsReceiptCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = (int) $purchase->company_id;
        $this->purchaseId = (int) $purchase->id;
        $this->sourceWarehouseId = (int) $sourceWarehouse->id;
        $this->destinationWarehouseId = (int) $destinationWarehouse->id;
        $this->actorId = (int) $actor->id;
        $this->allocatedNumber = null;
        $this->transferAsserted = false;

        try {
            $result = $callback($capability);
            if (! $this->transferAsserted) {
                throw new InvalidArgumentException('Goods receipt requires verified canonical stock transfer completion.');
            }

            return $result;
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->purchaseId = $this->sourceWarehouseId = $this->destinationWarehouseId = $this->actorId = 0;
            $this->allocatedNumber = null;
            $this->transferAsserted = false;
        }
    }

    /**
     * Scope for returning physically received goods back to transit before canonical return posting.
     *
     * @template T
     *
     * @param  Closure(GoodsReceiptCapability): T  $callback
     * @return T
     */
    public function withinReturnTransfer(
        ExecutePhysicalPurchaseReturnAction $owner,
        Purchase $purchase,
        Warehouse $sourcePhysicalWarehouse,
        Warehouse $destTransitWarehouse,
        User $actor,
        Closure $callback
    ): mixed {
        if ($this->active !== null || ! $owner->ownsGoodsReceiptScope($this)) {
            throw new InvalidArgumentException('Only the executing canonical physical return action may activate a non-reentrant return transfer scope.');
        }

        if (! $actor->exists || $actor->id <= 0) {
            throw new InvalidArgumentException('Return transfer scope requires a valid persisted actor.');
        }

        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel()
        );

        if ($connection->transactionLevel() === 0 || $transaction === null || ! $purchase->exists) {
            throw new InvalidArgumentException('Return transfer scope requires its active canonical database transaction.');
        }

        $capability = new GoodsReceiptCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = (int) $purchase->company_id;
        $this->purchaseId = (int) $purchase->id;
        $this->sourceWarehouseId = (int) $sourcePhysicalWarehouse->id;
        $this->destinationWarehouseId = (int) $destTransitWarehouse->id;
        $this->actorId = (int) $actor->id;
        $this->allocatedNumber = null;
        $this->transferAsserted = false;

        try {
            $result = $callback($capability);
            if (! $this->transferAsserted) {
                throw new InvalidArgumentException('Return transfer requires verified canonical stock transfer completion.');
            }

            return $result;
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->purchaseId = $this->sourceWarehouseId = $this->destinationWarehouseId = $this->actorId = 0;
            $this->allocatedNumber = null;
            $this->transferAsserted = false;
        }
    }

    public function recordAllocatedNumber(RecordPurchaseGoodsReceiptAction $owner, string $number): void
    {
        if ($this->owner !== $owner || ! $owner->ownsGoodsReceiptScope($this) || ! $this->isActive($this->active)
            || trim($number) === '' || $this->allocatedNumber !== null) {
            throw new InvalidArgumentException('Only the active canonical GRN action may bind its allocated number.');
        }
        $this->allocatedNumber = $number;
    }

    public function recordTransferCompleted(?GoodsReceiptCapability $capability): void
    {
        if (! $this->isActive($capability)) {
            throw new InvalidArgumentException('Transfer completion confirmation requires active GoodsReceiptCapability.');
        }
        $this->transferAsserted = true;
    }

    public function isActive(?GoodsReceiptCapability $capability): bool
    {
        if ($capability === null || $this->connection === null || $this->pdo === null) {
            return false;
        }

        return $this->active === $capability
            && $this->connection === DB::connection()
            && $this->connection->transactionLevel() > 0
            && $this->connection->transactionLevel() >= $this->transaction?->level
            && $this->pdo === $this->connection->getPdo()
            && $this->pdo->inTransaction()
            && $this->transactions()->getPendingTransactions()->contains(
                fn (DatabaseTransactionRecord $record) => $record === $this->transaction
            );
    }

    public function assertTransferAuthorized(
        int $companyId,
        Warehouse $sourceWarehouse,
        Warehouse $destWarehouse,
        ?GoodsReceiptCapability $capability
    ): void {
        if (! $this->isActive($capability)) {
            throw new InvalidArgumentException('Stock transfer involving in-transit warehouse requires active GoodsReceiptCapability.');
        }

        if ($companyId !== $this->companyId
            || (int) $sourceWarehouse->id !== $this->sourceWarehouseId
            || (int) $destWarehouse->id !== $this->destinationWarehouseId) {
            throw new InvalidArgumentException('Active GoodsReceiptCapability scope mismatch for transfer warehouses or company.');
        }
    }
}
