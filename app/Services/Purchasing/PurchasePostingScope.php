<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\PostPurchaseAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Models\LandedCostAllocation;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use PDO;

/** Request-local authority for one executing canonical Purchase post. */
final class PurchasePostingScope
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Purchase posting scopes cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Purchase posting scopes cannot be restored.');
    }

    private ?PurchaseReceiptCapability $active = null;

    private ?PostPurchaseAction $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $purchaseId = 0;

    private int $actorId = 0;

    /**
     * @template T
     *
     * @param  Closure(PurchaseReceiptCapability): T  $callback
     * @return T
     */
    public function withinCanonicalPosting(PostPurchaseAction $owner, Purchase $purchase, User $actor, Closure $callback): mixed
    {
        if ($this->active !== null || ! $owner->ownsPostingScope($this)) {
            throw new InvalidInventoryMovementException('Only the executing canonical Purchase action may activate a non-reentrant posting scope.');
        }
        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel());
        if ($connection->transactionLevel() === 0 || $transaction === null || ! $purchase->exists) {
            throw new InvalidInventoryMovementException('Purchase scope requires its active canonical database transaction.');
        }
        $capability = new PurchaseReceiptCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = (int) $purchase->company_id;
        $this->purchaseId = (int) $purchase->id;
        $this->actorId = (int) $actor->id;
        try {
            return $callback($capability);
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->purchaseId = $this->actorId = 0;
        }
    }

    public function isActive(PurchaseReceiptCapability $capability): bool
    {
        if ($this->connection === null || $this->pdo === null) {
            return false;
        }

        return $this->active === $capability && $this->owner?->ownsPostingScope($this) === true
            && $this->connection === DB::connection() && $this->connection->transactionLevel() > 0
            && $this->pdo === $this->connection->getPdo() && $this->pdo->inTransaction()
            && $this->transactions()->getPendingTransactions()->contains(
                fn (DatabaseTransactionRecord $record) => $record === $this->transaction);
    }

    public function assertReceipt(StockMovementCommand $command, ?PurchaseReceiptCapability $capability): void
    {
        $context = app(CompanyContext::class);
        if ($capability === null || ! $this->isActive($capability)
            || $command->movementType !== StockMovement::TYPE_PURCHASE || $command->sourceType !== 'purchase'
            || $command->companyId !== $this->companyId || $command->sourceId !== $this->purchaseId
            || $command->createdBy !== $this->actorId || (int) auth()->id() !== $this->actorId
            || ! $context->hasCompany() || (int) $context->companyId() !== $this->companyId) {
            throw new InvalidInventoryMovementException('Purchase receipt requires the active matching canonical posting capability.');
        }
    }

    public function assertLandedAllocation(LandedCostAllocation $allocation, PurchaseReceiptCapability $capability): void
    {
        if (! $this->isActive($capability) || (int) $allocation->company_id !== $this->companyId
            || (int) $allocation->purchase_id !== $this->purchaseId || $allocation->status !== 'draft'
            || $allocation->isDirty() || (int) auth()->id() !== $this->actorId) {
            throw new InvalidInventoryMovementException('Exact canonical landed allocation required.');
        }
    }

    private function transactions(): DatabaseTransactionsManager
    {
        return app('db.transactions');
    }
}
