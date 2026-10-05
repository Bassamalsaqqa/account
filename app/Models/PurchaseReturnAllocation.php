<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Services\Purchasing\HistoricalPurchaseReceiptValue;
use App\Services\Purchasing\PurchaseReturnIssueCapability;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $company_id
 * @property int $purchase_return_id
 * @property int $purchase_return_line_id
 * @property int $original_stock_movement_id
 * @property int|null $purchase_line_lot_id
 * @property int|null $inventory_lot_id
 * @property string $quantity
 * @property string $quantity_base
 * @property string|null $historical_value_base
 * @property string|null $inventory_value_removed_base
 * @property int|null $stock_movement_id
 */
class PurchaseReturnAllocation extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    public const PROVENANCE_FIELDS = [
        'historical_value_base',
        'inventory_value_removed_base',
        'stock_movement_id',
    ];

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'purchase_return_id',
        'purchase_return_line_id',
        'original_stock_movement_id',
        'purchase_line_lot_id',
        'inventory_lot_id',
        'quantity',
        'quantity_base',
        'historical_value_base',
        'inventory_value_removed_base',
        'stock_movement_id',
    ];

    private bool $completingAllocation = false;

    public function completeCanonicalAllocation(
        StockMovement $movement,
        string $historicalValueBase,
        string $inventoryValueRemovedBase,
        User $actor,
        ?PurchaseReturnIssueCapability $capability = null
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new ImmutableRecordException('Purchase return allocation completion requires an existing outer posting transaction.');
        }

        if ($capability === null) {
            throw new ImmutableRecordException('Purchase return allocation completion requires active canonical posting capability.');
        }

        $scope = app(PurchaseReturnPostingScope::class);
        try {
            $scope->assertScope((int) $this->company_id, (int) $this->purchase_return_id, $actor, $capability);
        } catch (\Throwable $e) {
            throw new ImmutableRecordException($e->getMessage(), previous: $e);
        }

        DB::transaction(function () use ($movement, $historicalValueBase, $inventoryValueRemovedBase, $actor): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.return.manage');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');

            $this->assertMutableDraft();

            if (! $this->exists || $this->isDirty() || $this->stock_movement_id !== null) {
                throw new ImmutableRecordException('Only a clean persisted Draft allocation can complete a return.');
            }

            /** @var StockMovement|null $persistedMovement */
            $persistedMovement = StockMovement::withoutGlobalScopes()
                ->where('company_id', $this->company_id)
                ->find($movement->id);

            if ($persistedMovement === null
                || (int) $persistedMovement->company_id !== (int) $this->company_id
                || $persistedMovement->movement_type !== StockMovement::TYPE_PURCHASE_RETURN
                || $persistedMovement->source_type !== 'purchase_return'
                || (int) $persistedMovement->source_id !== (int) $this->purchase_return_id
                || (int) $persistedMovement->source_line_id !== (int) $this->purchase_return_line_id
                || (int) $persistedMovement->reversal_of_id !== (int) $this->original_stock_movement_id) {
                throw new ImmutableRecordException('Stock movement does not match the purchase return allocation provenance.');
            }

            if ($this->inventory_lot_id !== null) {
                if ((int) $persistedMovement->lot_id !== (int) $this->inventory_lot_id) {
                    throw new ImmutableRecordException('Stock movement lot does not match the purchase return allocation lot.');
                }
            } else {
                if ($persistedMovement->lot_id !== null) {
                    throw new ImmutableRecordException('Stock movement lot must be null for non-expiry allocation.');
                }
            }

            $qtyBase = BigDecimal::of((string) $persistedMovement->quantity_delta_base)->abs();
            if (! $qtyBase->isEqualTo(BigDecimal::of((string) $this->quantity_base))) {
                throw new ImmutableRecordException('Stock movement quantity does not match allocation quantity.');
            }

            $actualRemoved = BigDecimal::of((string) $persistedMovement->value_delta_base)->abs();
            if (! $actualRemoved->isEqualTo(BigDecimal::of($inventoryValueRemovedBase))) {
                throw new ImmutableRecordException('Allocation actual removed value does not match stock movement.');
            }

            $expectedHistorical = app(HistoricalPurchaseReceiptValue::class)->target(
                (int) $this->company_id,
                (int) $this->original_stock_movement_id,
                $qtyBase,
                null,
                (int) $this->purchaseReturn->purchase_id,
                (int) $this->purchaseReturnLine->purchase_line_id
            );

            if (! $expectedHistorical->isEqualTo(BigDecimal::of($historicalValueBase))) {
                throw new ImmutableRecordException('Allocation historical value does not match receipt target.');
            }

            $this->completingAllocation = true;
            try {
                $this->stock_movement_id = $persistedMovement->id;
                $this->historical_value_base = (string) $expectedHistorical;
                $this->inventory_value_removed_base = (string) $actualRemoved;
                $this->save();
            } finally {
                $this->completingAllocation = false;
            }
        });
    }

    protected static function booted(): void
    {
        static::saving(function (self $allocation): void {
            $allocation->assertMutableDraft();

            if ($allocation->completingAllocation) {
                if (array_diff(array_keys($allocation->getDirty()), ['stock_movement_id', 'historical_value_base', 'inventory_value_removed_base', 'updated_at']) !== []) {
                    throw new ImmutableRecordException('Allocation completion may only attach posting provenance.');
                }

                return;
            }

            if ($allocation->exists && $allocation->isDirty(['purchase_return_id', 'purchase_return_line_id', 'company_id', 'original_stock_movement_id', 'purchase_line_lot_id', 'inventory_lot_id'])) {
                throw new ImmutableRecordException('Purchase return allocation provenance is immutable.');
            }

            foreach (self::PROVENANCE_FIELDS as $field) {
                if ($allocation->getAttribute($field) !== null) {
                    throw new ImmutableRecordException('Purchase return draft allocations cannot carry inventory or valuation effects.');
                }
            }

            foreach (['quantity', 'quantity_base'] as $field) {
                $val = $allocation->getAttribute($field);
                if ($val !== null) {
                    $amount = MoneyAmount::from($val);
                    if ($amount->getAmount()->isNegative()) {
                        throw new \InvalidArgumentException('Purchase return allocation quantities cannot be negative.');
                    }
                }
            }
        });

        static::deleting(fn (self $allocation) => $allocation->assertMutableDraft());
    }

    public function assertMutableDraft(): void
    {
        $parent = PurchaseReturn::where('company_id', $this->company_id)->findOrFail(
            $this->exists ? $this->getRawOriginal('purchase_return_id') : $this->purchase_return_id
        );
        $parent->assertMutableDraft();
    }

    /** @return BelongsTo<PurchaseReturn, $this> */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /** @return BelongsTo<PurchaseReturnLine, $this> */
    public function purchaseReturnLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturnLine::class);
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function originalStockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'original_stock_movement_id');
    }

    /** @return BelongsTo<PurchaseLineLot, $this> */
    public function purchaseLineLot(): BelongsTo
    {
        return $this->belongsTo(PurchaseLineLot::class, 'purchase_line_lot_id');
    }

    /** @return BelongsTo<InventoryLot, $this> */
    public function inventoryLot(): BelongsTo
    {
        return $this->belongsTo(InventoryLot::class, 'inventory_lot_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
