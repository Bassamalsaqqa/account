<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\DisposeExpiredStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Posting\DTO\PostingCommand;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Support\Facades\DB;
use PDO;

/** Authority belongs to the executing action, never to an arbitrary open transaction. */
final class StockEventScope
{
    /** @var array<class-string, array{string,list<string>,list<string>}> */
    private const array OWNERS = [
        PostOpeningStockAction::class => ['opening_stock', ['opening_balance'], ['inventory.stock.adjust', 'inventory.cost.view']],
        AdjustStockAction::class => ['stock_adjustment', ['adjustment_increase', 'adjustment_decrease', 'damage_or_loss'], ['inventory.stock.adjust']],
        DisposeExpiredStockAction::class => ['expiry_disposal', ['expiry_disposal'], ['inventory.stock.adjust']],
        PostSalesInvoiceAction::class => ['sales_invoice', ['sale'], ['sales.invoice.post']],
        PostSalesReturnAction::class => ['sales_return', ['sale_return'], ['sales.return.post']],
        VoidSalesInvoiceAction::class => ['sales_invoice_void', ['sale_return'], ['sales.invoice.void']],
        VoidSalesReturnAction::class => ['sales_return_void', ['sale'], ['sales.return.void']],
    ];

    private ?StockEventOwner $owner = null;

    private ?Connection $connection = null;

    private ?PDO $pdo = null;

    private ?DatabaseTransactionRecord $transaction = null;

    private int $companyId = 0;

    private int $sourceId = 0;

    private ?User $actor = null;

    /** @var array<int, array{capability:StockEventCapability,command:StockMovementCommand,used:bool,movements:?array<int,StockMovement>}> */
    private array $movements = [];

    private ?PostingCommand $posting = null;

    private ?int $reversalId = null;

    private function __clone() {}

    /** @return array<string,mixed> */
    public function __serialize(): array
    {
        throw new \LogicException('Stock event authority cannot be serialized.');
    }

    /** @param array<string,mixed> $data */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('Stock event authority cannot be restored.');
    }

    /** @template T
     * @param Closure(): T $callback
     * @return T */
    public function within(StockEventOwner $owner, int $companyId, int $sourceId, User $actor, Closure $callback): mixed
    {
        if ($this->owner !== null || ! isset(self::OWNERS[$owner::class]) || ! $owner->ownsStockEvent($this)) {
            throw new InvalidInventoryMovementException('Only the executing concrete canonical stock action may enter its scope.');
        }
        $connection = DB::connection();
        $transaction = app('db.transactions')->getPendingTransactions()->last(
            fn (DatabaseTransactionRecord $record) => $record->connection === $connection->getName()
                && $record->level === $connection->transactionLevel());
        if ($transaction === null || ! $connection->getPdo()->inTransaction()) {
            throw new InvalidInventoryMovementException('Canonical stock transaction required.');
        }
        $this->owner = $owner;
        $this->connection = $connection;
        $this->pdo = $connection->getPdo();
        $this->transaction = $transaction;
        $this->companyId = $companyId;
        $this->sourceId = $sourceId;
        $this->actor = $actor;
        try {
            $this->assertActive();
            $result = $callback();
            $this->assertComplete();

            return $result;
        } finally {
            $this->owner = $this->connection = $this->pdo = $this->transaction = $this->actor = null;
            $this->companyId = $this->sourceId = 0;
            $this->movements = [];
            $this->posting = null;
            $this->reversalId = null;
        }
    }

    private function assertActive(): void
    {
        $context = app(CompanyContext::class);
        if ($this->owner === null || ! $this->owner->ownsStockEvent($this) || $this->actor === null
            || $this->connection !== DB::connection() || $this->pdo !== $this->connection->getPdo()
            || ! $this->pdo->inTransaction() || ! app('db.transactions')->getPendingTransactions()->contains(
                fn (DatabaseTransactionRecord $record) => $record === $this->transaction)
            || ! $context->hasCompany() || (int) $context->companyId() !== $this->companyId
            || (int) auth()->id() !== (int) $this->actor->id) {
            throw new InvalidInventoryMovementException('Fresh matching canonical stock authority required.');
        }
        foreach (self::OWNERS[$this->owner::class][2] as $permission) {
            app(SalesActorGuard::class)->lockAndAuthorize($this->companyId, $this->actor, $permission);
        }
    }

    public function prepareMovement(StockEventOwner $owner, StockMovementCommand $command): StockEventCapability
    {
        $this->assertActive();
        if ($owner !== $this->owner) {
            throw new InvalidInventoryMovementException('Executing stock event owner required.');
        }
        [$source, $types] = self::OWNERS[$owner::class];
        if ($owner !== $this->owner || $command->companyId !== $this->companyId || $command->sourceId !== $this->sourceId
            || $command->createdBy !== (int) $this->actor->id || $command->sourceType !== $source
            || ! in_array($command->movementType, $types, true)) {
            throw new InvalidInventoryMovementException('Stock command does not match its canonical event.');
        }
        if ($source === 'sales_invoice') {
            $products = SalesInvoiceLine::withoutGlobalScopes()->where('company_id', $this->companyId)
                ->where('sales_invoice_id', $this->sourceId)
                ->when($command->sourceLineId !== null, fn ($query) => $query->whereKey($command->sourceLineId))
                ->pluck('product_id')->map(fn ($id) => (int) $id)->all();
            if ($products === [] || array_filter($command->lines, fn ($item) => ! in_array((int) $item->productId, $products, true)) !== []) {
                throw new InvalidInventoryMovementException('Stock source line must belong to the canonical Sales invoice.');
            }
        }
        if ($command->movementType === 'adjustment_increase' && $command->lines[0]->unitCostBase !== null) {
            app(SalesActorGuard::class)->lockAndAuthorize($this->companyId, $this->actor, 'inventory.cost.view');
        }
        $capability = new StockEventCapability;
        $this->movements[spl_object_id($capability)] = ['capability' => $capability, 'command' => $command, 'used' => false, 'movements' => null];

        return $capability;
    }

    public function consumeMovement(StockMovementCommand $command, ?StockEventCapability $capability): void
    {
        $this->assertActive();
        $id = $capability === null ? -1 : spl_object_id($capability);
        $prepared = $this->movements[$id] ?? null;
        if ($prepared === null || $prepared['capability'] !== $capability || $prepared['command'] !== $command || $prepared['used']) {
            throw new InvalidInventoryMovementException('One-use exact canonical stock command capability required.');
        }
        $this->movements[$id]['used'] = true;
    }

    /** @param list<StockMovement> $movements */
    public function completeMovement(StockEventCapability $capability, array $movements): void
    {
        $this->assertActive();
        $id = spl_object_id($capability);
        if (! isset($this->movements[$id]) || ! $this->movements[$id]['used'] || $movements === []) {
            throw new InvalidInventoryMovementException('Canonical movement completion required.');
        }
        $command = $this->movements[$id]['command'];
        $persisted = StockMovement::withoutGlobalScopes()->where('company_id', $this->companyId)
            ->where('source_type', $command->sourceType)->where('source_id', $command->sourceId)
            ->whereIn('id', array_map(fn (StockMovement $movement) => $movement->id, $movements))->get();
        if ($persisted->count() !== count($command->lines)) {
            throw new InvalidInventoryMovementException('Actual persisted canonical stock effects required.');
        }
        $this->movements[$id]['movements'] = $persisted->all();
    }

    public function preparePosting(StockEventOwner $owner, PostingCommand $command): void
    {
        $this->assertActive();
        if ($owner !== $this->owner) {
            throw new InvalidInventoryMovementException('Executing stock event owner required.');
        }
        $source = self::OWNERS[$owner::class][0];
        if ($owner !== $this->owner || $command->sourceType !== $source || (int) $command->company->id !== $this->companyId
            || (int) $command->postedBy->id !== (int) $this->actor->id) {
            throw new InvalidInventoryMovementException('Matching canonical stock accounting command required.');
        }
        $expectedIds = [];
        if (str_starts_with($source, 'sales_')) {
            $expectedIds[] = $this->sourceId;
        } else {
            foreach ($this->movements as $prepared) {
                foreach ($prepared['movements'] ?? [] as $movement) {
                    $expectedIds[] = (int) $movement->id;
                }
            }
        }
        if (! in_array($command->sourceId, $expectedIds, true)) {
            throw new InvalidInventoryMovementException('Accounting source must match this canonical stock event.');
        }
        $this->posting = $command;
    }

    public function assertPosting(PostingCommand $command): void
    {
        $this->assertActive();
        if ($command !== $this->posting) {
            throw new InvalidInventoryMovementException('Accounting must use the action-prepared stock event command.');
        }
    }

    public function prepareReversal(StockEventOwner $owner, PostingBatch $batch, User $actor): void
    {
        $this->assertActive();
        if ($owner !== $this->owner) {
            throw new InvalidInventoryMovementException('Executing stock event owner required.');
        }
        $source = self::OWNERS[$owner::class][0];
        if ($owner !== $this->owner || ! in_array($source, ['sales_invoice_void', 'sales_return_void'], true)
            || (int) $actor->id !== (int) $this->actor->id || (int) $batch->company_id !== $this->companyId
            || $batch->source_type !== str_replace('_void', '', $source) || (int) $batch->source_id !== $this->sourceId) {
            throw new InvalidInventoryMovementException('Matching canonical Sales void required for accounting reversal.');
        }
        $this->reversalId = (int) $batch->id;
    }

    public function assertReversal(PostingBatch $batch, User $actor): void
    {
        $this->assertActive();
        if ((int) $batch->id !== $this->reversalId || (int) $batch->company_id !== $this->companyId
            || (int) $actor->id !== (int) $this->actor->id
            || (int) $batch->source_id !== $this->sourceId
            || $batch->source_type !== str_replace('_void', '', self::OWNERS[$this->owner::class][0])) {
            throw new InvalidInventoryMovementException('Action-prepared Sales reversal required.');
        }
    }

    /** Before commit, verify actual persisted accounting, not a mock or returned batch. */
    private function assertComplete(): void
    {
        $this->assertActive();
        $source = self::OWNERS[$this->owner::class][0];
        $movements = [];
        foreach ($this->movements as $prepared) {
            if (! $prepared['used'] || $prepared['movements'] === null) {
                throw new InvalidInventoryMovementException('Incomplete canonical stock effect.');
            }
            $movements = array_merge($movements, $prepared['movements']);
        }
        if (str_starts_with($source, 'sales_')) {
            $model = str_starts_with($source, 'sales_invoice') ? SalesInvoice::class : SalesReturn::class;
            $document = $model::withoutGlobalScopes()->where('company_id', $this->companyId)->findOrFail($this->sourceId);
            $isVoid = str_ends_with($source, '_void');
            if ($document->status !== ($isVoid ? 'void' : 'posted')) {
                throw new InvalidInventoryMovementException('Canonical Sales lifecycle completion required.');
            }
            $batchId = $isVoid ? $document->void_posting_batch_id : $document->posting_batch_id;
            $batch = PostingBatch::withoutGlobalScopes()->where('company_id', $this->companyId)->find($batchId);
            if ($batch === null) {
                throw new InvalidInventoryMovementException('Canonical Sales accounting completion required.');
            }
            if (! $isVoid && ($batch->source_type !== $source || (int) $batch->source_id !== $this->sourceId)) {
                throw new InvalidInventoryMovementException('Canonical Sales posting provenance required.');
            }
            if ($isVoid && ((int) $batch->reversal_of_id !== (int) $document->posting_batch_id || $batch->source_type !== 'reversal')) {
                throw new InvalidInventoryMovementException('Canonical Sales reversal provenance required.');
            }
            if ($batch->status !== PostingBatch::STATUS_POSTED) {
                throw new InvalidInventoryMovementException('Canonical accounting batch status required.');
            }
            if ($isVoid) {
                $original = PostingBatch::withoutGlobalScopes()->where('company_id', $this->companyId)->find($document->posting_batch_id);
                if ($original === null || (int) $original->reversed_by_batch_id !== (int) $batch->id
                    || $original->status !== PostingBatch::STATUS_REVERSED) {
                    throw new InvalidInventoryMovementException('Reciprocal canonical accounting reversal required.');
                }
            } elseif ($this->posting !== null && ! $this->posting->matchesBatch($batch, null)) {
                throw new InvalidInventoryMovementException('Persisted accounting must match the prepared Sales payload.');
            }
            if ($movements !== []) {
                $this->assertInventoryValue($batch, $movements);
            }
        } else {
            if ($movements === []) {
                throw new InvalidInventoryMovementException('Canonical inventory effect required.');
            }
            foreach ($movements as $movement) {
                if (BigDecimal::of((string) $movement->value_delta_base)->isZero()) {
                    continue; // Accepted zero-cost events do not require zero-amount GL batches.
                }
                $batch = PostingBatch::withoutGlobalScopes()->where('company_id', $this->companyId)
                    ->where('source_type', $source)->where('source_id', $movement->id)->first();
                if ($batch === null) {
                    throw new InvalidInventoryMovementException('Stock valuation and financial recognition must commit together.');
                }
                if ($batch->status !== PostingBatch::STATUS_POSTED || $this->posting === null || ! $this->posting->matchesBatch($batch, null)) {
                    throw new InvalidInventoryMovementException('Persisted accounting must match the prepared inventory payload.');
                }
                $this->assertInventoryValue($batch, [$movement]);
            }
        }
    }

    /** @param list<StockMovement> $movements */
    private function assertInventoryValue(PostingBatch $batch, array $movements): void
    {
        $account = LedgerAccount::withoutGlobalScopes()->where('company_id', $this->companyId)->where('system_key', 'inventory')->first();
        $actual = BigDecimal::zero();
        if ($account !== null) {
            foreach (PostingLine::withoutGlobalScopes()->where('company_id', $this->companyId)->where('posting_batch_id', $batch->id)
                ->where('ledger_account_id', $account->id)->get() as $line) {
                $actual = $actual->plus($line->debit_base)->minus($line->credit_base);
            }
        }
        $expected = BigDecimal::zero();
        foreach ($movements as $movement) {
            $expected = $expected->plus($movement->value_delta_base);
        }
        if (! $actual->isEqualTo($expected)) {
            throw new InvalidInventoryMovementException('Canonical stock valuation does not match its inventory GL effect.');
        }
    }
}
