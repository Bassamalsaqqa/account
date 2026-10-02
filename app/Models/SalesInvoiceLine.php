<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $sales_invoice_id
 * @property int $line_number
 * @property int|null $product_id
 * @property int|null $product_unit_id
 * @property string $item_description
 * @property string $quantity
 * @property string $unit_price
 * @property string $discount_type
 * @property string $discount_value
 * @property int|null $tax_rate_id
 * @property string|null $tax_rate_snapshot
 * @property bool $tax_inclusive
 * @property string $line_subtotal
 * @property string $line_discount
 * @property string $line_tax
 * @property string $line_total
 * @property string $line_subtotal_base
 * @property string $line_discount_base
 * @property string $line_tax_base
 * @property string $line_total_base
 * @property string $cogs_total_base
 * @property string $cogs_unit_base
 * @property string $unit_conversion_ratio
 * @property string $quantity_base
 * @property string|null $unit_name_ar
 * @property string|null $unit_name_en
 * @property string|null $product_sku
 * @property string|null $product_name_ar
 * @property string|null $product_name_en
 * @property int|null $stock_movement_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class SalesInvoiceLine extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'sales_invoice_id',
        'line_number',
        'product_id',
        'product_unit_id',
        'item_description',
        'quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'tax_rate_id',
        'sales_tax_account_id',
        'tax_rate_snapshot',
        'tax_inclusive',
        'line_subtotal',
        'line_discount',
        'line_tax',
        'line_total',
        'line_subtotal_base',
        'line_discount_base',
        'line_tax_base',
        'line_total_base',
        'cogs_total_base',
        'cogs_unit_base',
        'unit_conversion_ratio',
        'quantity_base',
        'unit_name_ar',
        'unit_name_en',
        'product_sku',
        'product_name_ar',
        'product_name_en',
        'stock_movement_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'quantity' => 'string',
            'unit_price' => 'string',
            'discount_value' => 'string',
            'tax_rate_snapshot' => 'string',
            'tax_inclusive' => 'boolean',
            'line_subtotal' => 'string',
            'line_discount' => 'string',
            'line_tax' => 'string',
            'line_total' => 'string',
            'line_subtotal_base' => 'string',
            'line_discount_base' => 'string',
            'line_tax_base' => 'string',
            'line_total_base' => 'string',
            'cogs_total_base' => 'string',
            'cogs_unit_base' => 'string',
            'unit_conversion_ratio' => 'string',
            'quantity_base' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            if (empty($line->public_id)) {
                $line->public_id = (string) Str::ulid();
            }

            $parent = SalesInvoice::withoutGlobalScopes()->find($line->sales_invoice_id);
            if ($parent !== null && $parent->status !== SalesInvoice::STATUS_DRAFT) {
                throw new ImmutableRecordException('Cannot add lines to a posted or voided sales invoice.');
            }
        });

        static::updating(function (self $line): void {
            if ($line->isDirty('sales_invoice_id') || $line->isDirty('company_id')) {
                throw new \InvalidArgumentException('Document line provenance cannot be changed.');
            }
            $originalParent = SalesInvoice::withoutGlobalScopes()->find($line->getRawOriginal('sales_invoice_id'));
            if ($originalParent === null || $originalParent->status !== SalesInvoice::STATUS_DRAFT) {
                throw new \InvalidArgumentException('Non-draft document lines are immutable.');
            }

            if ($line->salesInvoice && $line->salesInvoice->status !== SalesInvoice::STATUS_DRAFT) {
                throw new ImmutableRecordException('Lines of posted or voided sales invoices are immutable and cannot be edited.');
            }
        });

        static::deleting(function (self $line): void {
            $parent = SalesInvoice::query()->find($line->getRawOriginal('sales_invoice_id'));
            if ($parent === null || $parent->status !== SalesInvoice::STATUS_DRAFT) {
                throw new \InvalidArgumentException('Non-draft document lines cannot be deleted.');
            }
            if ($line->salesInvoice && $line->salesInvoice->status !== SalesInvoice::STATUS_DRAFT) {
                throw new ImmutableRecordException('Lines of posted or voided sales invoices cannot be deleted.');
            }
        });
    }

    /**
     * @return BelongsTo<SalesInvoice, $this>
     */
    public function salesInvoice(): BelongsTo
    {
        return $this->belongsTo(SalesInvoice::class);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<ProductUnit, $this>
     */
    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }

    /**
     * @return BelongsTo<TaxRate, $this>
     */
    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    /**
     * @return BelongsTo<StockMovement, $this>
     */
    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class);
    }

    /**
     * @return HasMany<SalesInvoiceLotAllocation, $this>
     */
    public function lotAllocations(): HasMany
    {
        return $this->hasMany(SalesInvoiceLotAllocation::class);
    }

    /**
     * @return HasMany<SalesReturnLine, $this>
     */
    public function returnLines(): HasMany
    {
        return $this->hasMany(SalesReturnLine::class, 'sales_invoice_line_id');
    }
}
