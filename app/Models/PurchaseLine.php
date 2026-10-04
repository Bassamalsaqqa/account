<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Sales\Calculators\TaxPercentage;
use App\Services\Purchasing\PurchaseAcquisitionValue;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Purchasing\PurchaseInputTaxAccount;
use App\Services\Purchasing\PurchaseStockProvenance;
use App\Services\Sales\SalesActorGuard;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $company_id
 * @property int $purchase_id
 * @property int $product_id
 * @property int $product_unit_id
 * @property string $quantity
 * @property string $quantity_base
 * @property string $unit_cost
 * @property string $discount_value
 * @property string $line_subtotal
 * @property string $line_discount
 * @property string $line_tax
 * @property string $line_total
 * @property string $line_subtotal_base
 * @property string $line_discount_base
 * @property string $line_tax_base
 * @property string $line_total_base
 * @property string $unit_conversion_ratio
 * @property string|null $tax_rate_snapshot
 * @property string|null $inventory_unit_cost_base
 */
class PurchaseLine extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = [
        'public_id', 'company_id', 'purchase_id', 'line_number', 'product_id', 'product_unit_id',
        'item_description', 'quantity', 'quantity_base', 'unit_cost', 'discount_type', 'discount_value',
        'line_discount', 'tax_rate_id', 'tax_rate_snapshot', 'tax_inclusive', 'line_subtotal', 'line_tax',
        'line_total', 'line_subtotal_base', 'line_discount_base', 'line_tax_base', 'line_total_base',
        'unit_conversion_ratio', 'unit_name_ar', 'unit_name_en', 'product_sku', 'product_name_ar',
        'product_name_en', 'inventory_unit_cost_base', 'stock_movement_id', 'purchase_tax_account_id',
    ];

    protected function casts(): array
    {
        return ['tax_inclusive' => 'boolean', 'inventory_unit_cost_base' => 'string'];
    }

    private bool $completingReceipt = false;

    public function completeCanonicalReceipt(StockMovement $firstMovement, ?int $taxAccountId, User $actor): void
    {
        DB::transaction(function () use ($firstMovement, $taxAccountId, $actor): void {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.purchase.post');
            app(SalesActorGuard::class)->lockAndAuthorize((int) $this->company_id, $actor, 'purchasing.cost.view');
            $this->assertMutableDraft();
            if (! $this->exists || $this->isDirty() || $this->stock_movement_id !== null || $this->inventory_unit_cost_base !== null) {
                throw new ImmutableRecordException('Only a clean persisted Draft line can complete a receipt.');
            }
            $tax = $this->tax_rate_id === null ? null : TaxRate::where('company_id', $this->company_id)->where('active', true)->lockForUpdate()->findOrFail($this->tax_rate_id);
            $resolved = app(PurchaseInputTaxAccount::class)->resolve((int) $this->company_id, $tax?->purchase_tax_account_id);
            if ($taxAccountId !== $resolved?->id) {
                throw new ImmutableRecordException('Input Tax snapshot must match canonical posting-time configuration.');
            }
            $this->completingReceipt = true;
            try {
                $this->purchase_tax_account_id = $taxAccountId;
                $this->stock_movement_id = $firstMovement->id;
                $value = app(PurchaseAcquisitionValue::class)->line($this, $taxAccountId);
                $this->inventory_unit_cost_base = app(PurchaseAcquisitionValue::class)->unitCost($this, $value);
                $this->save();
                $movements = StockMovement::where('company_id', $this->company_id)->where('source_type', 'purchase')
                    ->where('source_id', $this->purchase_id)->where('source_line_id', $this->id)->orderBy('id')->get();
                if ($movements->isEmpty() || (int) $movements->first()->id !== (int) $firstMovement->id
                    || $movements->count() !== max(1, $this->lots()->count())) {
                    throw new ImmutableRecordException('Complete Purchase stock provenance is required.');
                }
                foreach ($movements as $movement) {
                    app(PurchaseStockProvenance::class)->value($movement, false);
                }
            } finally {
                $this->completingReceipt = false;
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
            if ($line->completingReceipt) {
                if (array_diff(array_keys($line->getDirty()), ['purchase_tax_account_id', 'stock_movement_id', 'inventory_unit_cost_base', 'updated_at']) !== []) {
                    throw new ImmutableRecordException('Receipt completion may only attach posting provenance.');
                }

                return;
            }
            if ($line->exists && $line->isDirty(['purchase_id', 'company_id', 'public_id'])) {
                throw new ImmutableRecordException('Purchase line provenance is immutable.');
            }
            if ($line->inventory_unit_cost_base !== null || $line->stock_movement_id !== null || $line->purchase_tax_account_id !== null) {
                throw new ImmutableRecordException('Purchase draft lines cannot carry inventory effects.');
            }
            foreach (['unit_cost', 'discount_value', 'line_discount', 'line_subtotal', 'line_tax', 'line_total', 'line_subtotal_base', 'line_discount_base', 'line_tax_base', 'line_total_base'] as $field) {
                $amount = MoneyAmount::from($line->getAttributes()[$field] ?? null);
                if ($amount->getAmount()->isNegative()) {
                    throw new \InvalidArgumentException('Purchase line amounts cannot be negative.');
                }
                $line->setAttribute($field, (string) $amount);
            }
            $product = Product::where('company_id', $line->company_id)->findOrFail($line->product_id);
            $unit = ProductUnit::where('company_id', $line->company_id)->where('product_id', $product->id)->findOrFail($line->product_unit_id);
            app(PurchaseDocumentRules::class)->selectedUnit($product, $unit->id, $line->quantity);
            $quantity = Quantity::of($line->getAttributes()['quantity']);
            $base = app(UnitConversionService::class)->toBase($quantity, $unit);
            if (! $base->isEqualTo(Quantity::of($line->getAttributes()['quantity_base']))
                || ! Quantity::of($line->getAttributes()['unit_conversion_ratio'])->isEqualTo(Quantity::of($unit->getAttribute('conversion_to_base')))) {
                throw new \InvalidArgumentException('Purchase line unit conversion must match its selected unit.');
            }
            if ($line->tax_rate_id !== null) {
                TaxRate::where('company_id', $line->company_id)->where('active', true)->findOrFail($line->tax_rate_id);
            }
            if ($line->tax_rate_snapshot !== null) {
                $line->tax_rate_snapshot = (string) TaxPercentage::parse($line->getAttributes()['tax_rate_snapshot']);
            }
        });
        static::deleting(fn (self $line) => $line->assertMutableDraft());
    }

    public function assertMutableDraft(): void
    {
        $parent = Purchase::where('company_id', $this->company_id)->findOrFail($this->exists ? $this->getRawOriginal('purchase_id') : $this->purchase_id);
        $parent->assertMutableDraft();
    }

    /** @return BelongsTo<Purchase, $this> */
    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
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

    /** @return HasMany<PurchaseLineLot, $this> */
    public function lots(): HasMany
    {
        return $this->hasMany(PurchaseLineLot::class)->orderBy('id');
    }
}
