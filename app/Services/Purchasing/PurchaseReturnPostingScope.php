<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Models\InventoryOperation;
use App\Models\Product;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use PDO;

/** Request-local authority for one executing canonical Purchase Return post. */
final class PurchaseReturnPostingScope
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Purchase return posting scopes cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Purchase return posting scopes cannot be restored.');
    }

    private ?PurchaseReturnIssueCapability $active = null;

    private ?PostPurchaseReturnAction $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $returnId = 0;

    private int $purchaseId = 0;

    private int $actorId = 0;

    private ?string $allocatedNumber = null;

    /**
     * @template T
     *
     * @param  Closure(PurchaseReturnIssueCapability): T  $callback
     * @return T
     */
    public function withinCanonicalReturnPosting(PostPurchaseReturnAction $owner, PurchaseReturn $return, User $actor, Closure $callback): mixed
    {
        if ($this->active !== null || ! $owner->ownsPostingScope($this)) {
            throw new InvalidInventoryMovementException('Only the executing canonical Purchase Return action may activate a non-reentrant posting scope.');
        }

        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel()
        );

        if ($connection->transactionLevel() === 0 || $transaction === null || ! $return->exists) {
            throw new InvalidInventoryMovementException('Purchase return scope requires its active canonical database transaction.');
        }

        $capability = new PurchaseReturnIssueCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = (int) $return->company_id;
        $this->returnId = (int) $return->id;
        $this->purchaseId = (int) $return->purchase_id;
        $this->actorId = (int) $actor->id;
        $this->allocatedNumber = null;

        try {
            return $callback($capability);
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->allocatedNumber = null;
            $this->companyId = $this->returnId = $this->purchaseId = $this->actorId = 0;
        }
    }

    public function bindAllocatedNumber(string $number): void
    {
        if ($this->active === null) {
            throw new InvalidInventoryMovementException('Purchase return scope is not active.');
        }

        if ($this->allocatedNumber !== null) {
            throw new InvalidInventoryMovementException('Posting scope already has an allocated number.');
        }

        if (trim($number) === '') {
            throw new InvalidInventoryMovementException('Allocated return number cannot be empty.');
        }

        $this->allocatedNumber = $number;
    }

    public function allocatedNumber(): ?string
    {
        return $this->allocatedNumber;
    }

    public function assertAllocatedNumber(string $number): void
    {
        if ($this->allocatedNumber === null) {
            throw new InvalidInventoryMovementException('No sequence number has been allocated for this posting scope.');
        }

        if ($this->allocatedNumber !== $number) {
            throw new InvalidInventoryMovementException("Supplied number [{$number}] does not match allocated sequence number [{$this->allocatedNumber}].");
        }
    }

    public function isActive(PurchaseReturnIssueCapability $capability): bool
    {
        if ($this->connection === null || $this->pdo === null) {
            return false;
        }

        return $this->active === $capability
            && $this->owner?->ownsPostingScope($this) === true
            && $this->connection === DB::connection()
            && $this->connection->transactionLevel() > 0
            && $this->pdo === $this->connection->getPdo()
            && $this->pdo->inTransaction()
            && $this->transactions()->getPendingTransactions()->contains(
                fn (DatabaseTransactionRecord $record) => $record === $this->transaction
            );
    }

    public function assertScope(int $companyId, int $returnId, ?User $actor, ?PurchaseReturnIssueCapability $capability): void
    {
        $context = app(CompanyContext::class);

        if ($capability === null || ! $this->isActive($capability)
            || $companyId !== $this->companyId
            || $returnId !== $this->returnId
            || $actor === null
            || (int) $actor->id !== $this->actorId
            || (int) auth()->id() !== $this->actorId
            || ! $context->hasCompany() || (int) $context->companyId() !== $this->companyId
            || (int) PurchaseReturn::withoutGlobalScopes()->where('company_id', $this->companyId)->where('id', $this->returnId)->value('purchase_id') !== $this->purchaseId) {
            throw new InvalidInventoryMovementException('Purchase return action requires the active matching canonical posting capability.');
        }
    }

    public function assertIssue(StockMovementCommand $command, ?PurchaseReturnIssueCapability $capability): void
    {
        if ((int) $command->createdBy !== $this->actorId) {
            throw new InvalidInventoryMovementException('Purchase return action requires the active matching canonical posting capability.');
        }

        $this->assertScope($command->companyId, $command->sourceId, auth()->user(), $capability);

        if ($command->movementType !== StockMovement::TYPE_PURCHASE_RETURN
            || $command->sourceType !== 'purchase_return'
            || $command->sourceLineId === null) {
            throw new InvalidInventoryMovementException('Purchase return issue requires valid source line.');
        }

        $isExistingOperation = InventoryOperation::where('company_id', $this->companyId)
            ->where('idempotency_key', $command->idempotencyKey)
            ->exists()
            || StockMovement::where('company_id', $this->companyId)
                ->where('idempotency_key', $command->idempotencyKey)
                ->exists();

        if ($isExistingOperation) {
            return;
        }

        /** @var PurchaseReturn|null $return */
        $return = PurchaseReturn::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('id', $this->returnId)
            ->first();

        if ($return === null || ! $return->isDraft()) {
            throw new InvalidInventoryMovementException('Purchase return issue requires an active draft return.');
        }

        if ($command->movementDate !== $return->return_date->format('Y-m-d')) {
            throw new InvalidInventoryMovementException('Purchase return movement date does not match return date.');
        }

        /** @var PurchaseReturnLine|null $line */
        $line = PurchaseReturnLine::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('purchase_return_id', $return->id)
            ->where('id', $command->sourceLineId)
            ->first();

        if ($line === null) {
            throw new InvalidInventoryMovementException('Purchase return issue line does not exist.');
        }

        /** @var PurchaseLine|null $originalPurchaseLine */
        $originalPurchaseLine = PurchaseLine::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->where('purchase_id', $this->purchaseId)
            ->find($line->purchase_line_id);

        if ($originalPurchaseLine === null) {
            throw new InvalidInventoryMovementException('Original purchase line does not exist.');
        }

        /** @var Product|null $product */
        $product = Product::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->find($line->product_id);

        if ($product === null) {
            throw new InvalidInventoryMovementException('Purchase return issue product does not exist.');
        }

        $allocations = $line->allocations()->withoutGlobalScopes()->orderBy('id')->get();
        if ($allocations->isEmpty() || count($command->lines) !== $allocations->count()) {
            throw new InvalidInventoryMovementException('Command lines count does not match persisted allocations count.');
        }

        $sumCommandQty = BigDecimal::zero();

        foreach ($command->lines as $idx => $cmdLine) {
            if ($cmdLine->unitCostBase !== null || $cmdLine->valueDeltaBase !== null) {
                throw new InvalidInventoryMovementException('Purchase return issue command cannot specify unit cost or value delta.');
            }

            if ((int) $cmdLine->productId !== (int) $line->product_id) {
                throw new InvalidInventoryMovementException('Command line product does not match return line.');
            }

            if ((int) $cmdLine->warehouseId !== (int) $return->warehouse_id) {
                throw new InvalidInventoryMovementException('Command line warehouse does not match return warehouse.');
            }

            if ((int) $cmdLine->unitId !== (int) $product->base_unit_id) {
                throw new InvalidInventoryMovementException('Command line unit must be product base unit.');
            }

            $alloc = $allocations[$idx];

            try {
                PurchaseReturnStockProvenance::validateAllocationLotChain(
                    $this->companyId,
                    (int) $return->warehouse_id,
                    $this->purchaseId,
                    $originalPurchaseLine,
                    $alloc
                );
            } catch (\Throwable $e) {
                throw new InvalidInventoryMovementException($e->getMessage(), previous: $e);
            }

            if ((int) $cmdLine->originalMovementId !== (int) $alloc->original_stock_movement_id) {
                throw new InvalidInventoryMovementException('Command line original movement does not match allocation.');
            }

            if ($alloc->inventory_lot_id !== null) {
                if ((int) $cmdLine->lotId !== (int) $alloc->inventory_lot_id) {
                    throw new InvalidInventoryMovementException('Command line lot does not match allocation lot.');
                }
            } else {
                if ($cmdLine->lotId !== null) {
                    throw new InvalidInventoryMovementException('Command line lot must be null for non-expiry allocation.');
                }
            }

            $cmdQty = $cmdLine->quantity->toBigDecimal();
            if (! $cmdQty->isEqualTo(BigDecimal::of((string) $alloc->quantity_base))) {
                throw new InvalidInventoryMovementException('Command line quantity does not match persisted allocation quantity.');
            }

            $sumCommandQty = $sumCommandQty->plus($cmdQty);
        }

        if (! $sumCommandQty->isEqualTo(BigDecimal::of((string) $line->quantity_base))) {
            throw new InvalidInventoryMovementException('Command total quantity does not match return line base quantity.');
        }
    }

    public function purchaseId(): int
    {
        return $this->purchaseId;
    }

    private function transactions(): DatabaseTransactionsManager
    {
        return app('db.transactions');
    }
}
