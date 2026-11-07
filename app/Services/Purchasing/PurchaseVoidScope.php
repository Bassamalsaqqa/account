<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Actions\Purchasing\VoidPurchaseAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;
use PDO;

/** Request-local authority for one executing canonical Purchase void. */
final class PurchaseVoidScope
{
    private function __clone() {}

    /** @return array<string, mixed> */
    public function __serialize(): array
    {
        throw new LogicException('Purchase void scopes cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new LogicException('Purchase void scopes cannot be restored.');
    }

    private ?PurchaseVoidCapability $active = null;

    private ?VoidPurchaseAction $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $purchaseId = 0;

    private int $actorId = 0;

    private bool $voidVerified = false;

    /**
     * @template T
     *
     * @param  Closure(PurchaseVoidCapability): T  $callback
     * @return T
     */
    public function withinCanonicalVoid(VoidPurchaseAction $owner, Purchase $purchase, User $actor, Closure $callback): mixed
    {
        if ($this->active !== null || ! $owner->ownsVoidScope($this)) {
            throw new InvalidInventoryMovementException('Only the executing canonical Purchase void action may activate a non-reentrant void scope.');
        }

        $connection = DB::connection();
        $transaction = $this->transactions()->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel()
        );

        if ($connection->transactionLevel() === 0 || $transaction === null || ! $purchase->exists) {
            throw new InvalidInventoryMovementException('Purchase void scope requires its active canonical database transaction.');
        }

        $capability = new PurchaseVoidCapability;
        $this->active = $capability;
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = (int) $purchase->company_id;
        $this->purchaseId = (int) $purchase->id;
        $this->actorId = (int) $actor->id;
        $this->voidVerified = false;

        try {
            $result = $callback($capability);
            if (! $this->voidVerified) {
                throw new InvalidArgumentException('Purchase void requires verified canonical completion.');
            }

            return $result;
        } finally {
            $this->active = null;
            $this->owner = null;
            $this->connection = null;
            $this->pdo = null;
            $this->transaction = null;
            $this->companyId = $this->purchaseId = $this->actorId = 0;
            $this->voidVerified = false;
        }
    }

    public function isActive(?PurchaseVoidCapability $capability): bool
    {
        if ($capability === null || $this->connection === null || $this->pdo === null) {
            return false;
        }

        return $this->active === $capability && $this->owner?->ownsVoidScope($this) === true
            && $this->connection === DB::connection() && $this->connection->transactionLevel() > 0
            && $this->connection->transactionLevel() === $this->transaction?->level
            && $this->pdo === $this->connection->getPdo() && $this->pdo->inTransaction()
            && $this->transactions()->getPendingTransactions()->contains(
                fn (DatabaseTransactionRecord $record) => $record === $this->transaction
            );
    }

    public function assertCompletion(Purchase $purchase, User $actor, ?PurchaseVoidCapability $capability): void
    {
        $context = app(CompanyContext::class);
        if (! $this->isActive($capability) || (int) $purchase->company_id !== $this->companyId
            || (int) $purchase->id !== $this->purchaseId || (int) $actor->id !== $this->actorId
            || (int) auth()->id() !== $this->actorId || ! $context->hasCompany()
            || (int) $context->companyId() !== $this->companyId) {
            throw new InvalidArgumentException('Purchase void completion requires its matching active scope and actor.');
        }
    }

    public function assertMovement(StockMovementCommand $command, ?PurchaseVoidCapability $capability): void
    {
        $context = app(CompanyContext::class);
        if ($capability === null || ! $this->isActive($capability)
            || $command->movementType !== StockMovement::TYPE_PURCHASE_RETURN || $command->sourceType !== 'purchase_void'
            || $command->companyId !== $this->companyId || $command->sourceId !== $this->purchaseId
            || $command->createdBy !== $this->actorId || (int) auth()->id() !== $this->actorId
            || ! $context->hasCompany() || (int) $context->companyId() !== $this->companyId) {
            throw new InvalidInventoryMovementException('Purchase void movement requires the active matching canonical void capability.');
        }
    }

    public function assertVoid(StockMovementCommand $command, ?PurchaseVoidCapability $capability): void
    {
        $this->assertMovement($command, $capability);
    }

    public function assertReversal(PostingBatch $batch, User $actor): void
    {
        if ($this->active === null || ! $this->isActive($this->active)) {
            throw new InvalidArgumentException('Purchase void scope is not active for accounting reversal.');
        }

        $context = app(CompanyContext::class);
        if ((int) $batch->company_id !== $this->companyId
            || (int) $actor->id !== $this->actorId
            || (int) auth()->id() !== $this->actorId
            || ! $context->hasCompany()
            || (int) $context->companyId() !== $this->companyId
            || $batch->source_type !== 'purchase'
            || (int) $batch->source_id !== $this->purchaseId) {
            throw new InvalidInventoryMovementException('Action-prepared canonical Purchase reversal required.');
        }
    }

    public function assertPersistedVoid(VoidPurchaseAction $owner, Purchase $purchase): void
    {
        if ($this->active === null || $this->owner === null || $owner !== $this->owner || ! $owner->ownsVoidScope($this)) {
            throw new InvalidArgumentException('Only the executing canonical Purchase void action may verify persisted void.');
        }

        if (! $this->isActive($this->active)) {
            throw new InvalidArgumentException('Purchase void scope is not active.');
        }

        /** @var Purchase|null $persisted */
        $persisted = Purchase::withoutGlobalScopes()->where('company_id', $this->companyId)->findOrFail($this->purchaseId);

        if ((int) $persisted->id !== $this->purchaseId
            || (int) $persisted->company_id !== $this->companyId
            || $persisted->status !== Purchase::STATUS_VOID
            || $persisted->voided_at === null
            || (int) $persisted->voided_by !== $this->actorId) {
            throw new InvalidArgumentException('Purchase document state does not reflect canonical void lifecycle.');
        }

        app(PurchaseVoidIntegrity::class)->assertComplete($persisted);
        $this->voidVerified = true;
    }

    private function transactions(): DatabaseTransactionsManager
    {
        return app('db.transactions');
    }
}
