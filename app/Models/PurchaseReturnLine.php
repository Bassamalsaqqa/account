<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Services\Purchasing\PurchaseReturnIssueCapability;
use App\Services\Purchasing\PurchaseReturnPostingScope;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $purchase_return_id
 * @property int $purchase_line_id
 * @property int $line_number
 * @property int $product_id
 * @property int $product_unit_id
 * @property string $item_description
 * @property string $quantity
 * @property string $quantity_base
 * @property string $unit_cost
 * @property string|null $discount_type
 * @property string $discount_value
 * @property string $line_discount
 * @property string|null $tax_rate_snapshot
 * @property bool $tax_inclusive
 * @property int|null $purchase_tax_account_id
 * @property string $line_subtotal
 * @property string $line_tax
 * @property string $line_total
 * @property string $line_subtotal_base
 * @property string $line_discount_base
 * @property string $line_tax_base
 * @property string $line_total_base
 * @property string $unit_conversion_ratio
 * @property string|null $unit_name_ar
 * @property string|null $unit_name_en
 * @property string|null $product_sku
 * @property string $product_name_ar
 * @property string|null $product_name_en
 * @property string|null $historical_receipt_value_base
 * @property string|null $inventory_value_removed_base
 * @property string|null $valuation_adjustment_base
 * @property int|null $stock_movement_id
 */
class PurchaseReturnLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    public const PROVENANCE_FIELDS = [
        'historical_receipt_value_base',
        'inventory_value_removed_base',
        'valuation_adjustment_base',
        'stock_movement_id',
    ];

    /** @var list<string> */
    protected $fillable = [
        'public_id', 'company_id', 'purchase_return_id', 'purchase_line_id', 'line_number',
        'product_id', 'product_unit_id', 'item_description', 'quantity', 'quantity_base',
        'unit_cost', 'discount_type', 'discount_value', 'line_discount', 'tax_rate_snapshot',
        'tax_inclusive', 'purchase_tax_account_id', 'line_subtotal', 'line_tax', 'line_total',
        'line_subtotal_base', 'line_discount_base', 'line_tax_base', 'line_total_base',
        'unit_conversion_ratio', 'unit_name_ar', 'unit_name_en', 'product_sku', 'product_name_ar',
        'product_name_en', 'historical_receipt_value_base', 'inventory_value_removed_base',
        'valuation_adjustment_base', 'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'tax_inclusive' => 'boolean',
        ];
    }

    private bool $completingReturn = false;

    public function completeCanonicalReturn(
        StockMovement $firstMovement,
        string $historicalValueBase,
        string $inventoryValueRemovedBase,
        string $valuationAdjustmentBase,
        User $actor,
        ?PurchaseReturnIssueCapability $capability = null
    ): void {
        if (DB::transactionLevel() === 0) {
            throw new ImmutableRecordException('Purchase return line completion requires an existing outer posting transaction.');
        }

        if ($capability === null) {
            throw new ImmutableRecordException('Purchase return line completion requires active canonical posting capability.');
        }

        $scope = app(PurchaseReturnPostingScope::class);
        try {
            $scope->assertScope((int) $this->company_id, (int) $this->purchase_return_id, $actor, $capability);
        } catch (\Throwable $e) {
            throw new ImmutableRecordException($e->getMessage(), previous: $e);
        }

        DB::transaction(function () use ($firstMovement, $historicalValueBase, $inventoryValueRemovedBase, $valuationAdjustmentBase, $actor): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.return.manage');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');
            $this->assertMutableDraft();
            if (! $this->exists || $this->isDirty() || $this->stock_movement_id !== null) {
                throw new ImmutableRecordException('Only a clean persisted Draft line can complete a return.');
            }

            /** @var StockMovement|null $persistedMovement */
            $persistedMovement = StockMovement::withoutGlobalScopes()
                ->where('company_id', $this->company_id)
                ->find($firstMovement->id);

            if ($persistedMovement === null
                || (int) $persistedMovement->company_id !== (int) $this->company_id
                || $persistedMovement->movement_type !== StockMovement::TYPE_PURCHASE_RETURN
                || $persistedMovement->source_type !== 'purchase_return'
                || (int) $persistedMovement->source_id !== (int) $this->purchase_return_id
                || (int) $persistedMovement->source_line_id !== (int) $this->id
                || (int) $persistedMovement->product_id !== (int) $this->product_id) {
                throw new ImmutableRecordException('Stock movement does not match purchase return line provenance.');
            }

            if (! BigDecimal::of((string) $persistedMovement->quantity_delta_base)->isNegative()
                || BigDecimal::of((string) $persistedMovement->value_delta_base)->isPositive()) {
                throw new ImmutableRecordException('Stock movement must be outbound with non-positive value delta.');
            }

            $allocations = $this->allocations()->withoutGlobalScopes()->get();
            if ($allocations->isEmpty()) {
                throw new ImmutableRecordException('Purchase return line has no allocations.');
            }

            $sumAllocHistorical = BigDecimal::zero();
            $sumAllocActual = BigDecimal::zero();
            $sumAllocQtyBase = BigDecimal::zero();

            foreach ($allocations as $alloc) {
                if ($alloc->stock_movement_id === null
                    || $alloc->historical_value_base === null
                    || $alloc->inventory_value_removed_base === null) {
                    throw new ImmutableRecordException('All line allocations must be completed before line completion.');
                }
                $sumAllocHistorical = $sumAllocHistorical->plus($alloc->historical_value_base);
                $sumAllocActual = $sumAllocActual->plus($alloc->inventory_value_removed_base);
                $sumAllocQtyBase = $sumAllocQtyBase->plus($alloc->quantity_base);
            }

            if (! $sumAllocQtyBase->isEqualTo(BigDecimal::of((string) $this->quantity_base))) {
                throw new ImmutableRecordException('Allocation base quantities do not equal line base quantity.');
            }

            if (! BigDecimal::of($historicalValueBase)->isEqualTo($sumAllocHistorical)) {
                throw new ImmutableRecordException('Line historical receipt value does not match allocation targets.');
            }

            if (! BigDecimal::of($inventoryValueRemovedBase)->isEqualTo($sumAllocActual)) {
                throw new ImmutableRecordException('Line actual inventory value removed does not match allocations.');
            }

            $commercialH = BigDecimal::of((string) $this->line_total_base)
                ->minus($this->purchase_tax_account_id !== null ? (string) $this->line_tax_base : '0');
            $expectedAdjustment = $sumAllocActual->minus($commercialH);

            if (! BigDecimal::of($valuationAdjustmentBase)->isEqualTo($expectedAdjustment)) {
                throw new ImmutableRecordException('Line valuation adjustment does not match actual removed minus commercial capitalized value.');
            }

            $this->completingReturn = true;
            try {
                $this->stock_movement_id = $persistedMovement->id;
                $this->historical_receipt_value_base = (string) $sumAllocHistorical;
                $this->inventory_value_removed_base = (string) $sumAllocActual;
                $this->valuation_adjustment_base = (string) $expectedAdjustment;
                $this->save();
            } finally {
                $this->completingReturn = false;
            }
        });
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->public_id ??= (string) Str::ulid();
        });
        static::saving(function (self $line): void {
            $line->assertMutableDraft();
            if ($line->completingReturn) {
                if (array_diff(array_keys($line->getDirty()), ['stock_movement_id', 'historical_receipt_value_base', 'inventory_value_removed_base', 'valuation_adjustment_base', 'updated_at']) !== []) {
                    throw new ImmutableRecordException('Return completion may only attach posting provenance.');
                }

                return;
            }
            if ($line->exists && $line->isDirty(['purchase_return_id', 'purchase_line_id', 'company_id', 'public_id'])) {
                throw new ImmutableRecordException('Purchase return line provenance is immutable.');
            }
            foreach (self::PROVENANCE_FIELDS as $field) {
                if ($line->getAttribute($field) !== null) {
                    throw new ImmutableRecordException('Purchase return draft lines cannot carry inventory or valuation effects.');
                }
            }
            foreach (['unit_cost', 'discount_value', 'line_discount', 'line_subtotal', 'line_tax', 'line_total', 'line_subtotal_base', 'line_discount_base', 'line_tax_base', 'line_total_base'] as $field) {
                $amount = MoneyAmount::from($line->getAttributes()[$field] ?? null);
                if ($amount->getAmount()->isNegative()) {
                    throw new \InvalidArgumentException('Purchase return line amounts cannot be negative.');
                }
                $line->setAttribute($field, (string) $amount);
            }
        });
        static::deleting(fn (self $line) => $line->assertMutableDraft());
    }

    public function assertMutableDraft(): void
    {
        $parent = PurchaseReturn::where('company_id', $this->company_id)->findOrFail($this->exists ? $this->getRawOriginal('purchase_return_id') : $this->purchase_return_id);
        $parent->assertMutableDraft();
    }

    /** @return BelongsTo<PurchaseReturn, $this> */
    public function purchaseReturn(): BelongsTo
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    /** @return BelongsTo<PurchaseLine, $this> */
    public function purchaseLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseLine::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** @return BelongsTo<ProductUnit, $this> */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /** @return BelongsTo<LedgerAccount, $this> */
    public function taxAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'purchase_tax_account_id');
    }

    /** @return BelongsTo<StockMovement, $this> */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /** @return HasMany<PurchaseReturnAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(PurchaseReturnAllocation::class)->orderBy('id');
    }
}
