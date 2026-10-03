<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Sales\Calculators\TaxPercentage;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'product_name_en', 'inventory_unit_cost_base', 'stock_movement_id',
    ];

    protected function casts(): array
    {
        return ['tax_inclusive' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            $line->public_id ??= (string) Str::ulid();
        });
        static::saving(function (self $line): void {
            $line->assertMutableDraft();
            if ($line->exists && $line->isDirty(['purchase_id', 'company_id', 'public_id'])) {
                throw new ImmutableRecordException('Purchase line provenance is immutable.');
            }
            if ($line->inventory_unit_cost_base !== null || $line->stock_movement_id !== null) {
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
