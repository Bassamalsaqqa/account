<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $public_id
 * @property int $company_id
 * @property int $quotation_id
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
 * @property string $line_total_base
 * @property string $unit_conversion_ratio
 * @property string $quantity_base
 * @property string|null $unit_name_ar
 * @property string|null $unit_name_en
 * @property string|null $product_sku
 * @property string|null $product_name_ar
 * @property string|null $product_name_en
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class QuotationLine extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'quotation_id',
        'line_number',
        'product_id',
        'product_unit_id',
        'item_description',
        'quantity',
        'unit_price',
        'discount_type',
        'discount_value',
        'tax_rate_id',
        'tax_rate_snapshot',
        'tax_inclusive',
        'line_subtotal',
        'line_discount',
        'line_tax',
        'line_total',
        'line_total_base',
        'unit_conversion_ratio',
        'quantity_base',
        'unit_name_ar',
        'unit_name_en',
        'product_sku',
        'product_name_ar',
        'product_name_en',
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
            'line_total_base' => 'string',
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

            $parent = Quotation::withoutGlobalScopes()->find($line->quotation_id);
            if ($parent === null || $parent->status !== Quotation::STATUS_DRAFT) {
                throw new ImmutableRecordException('Cannot add lines to a converted quotation.');
            }
        });

        static::updating(function (self $line): void {
            if ($line->isDirty('quotation_id') || $line->isDirty('company_id')) {
                throw new \InvalidArgumentException('Document line provenance cannot be changed.');
            }
            $originalParent = Quotation::withoutGlobalScopes()->find($line->getRawOriginal('quotation_id'));
            if ($originalParent === null || $originalParent->status !== Quotation::STATUS_DRAFT) {
                throw new \InvalidArgumentException('Non-draft document lines are immutable.');
            }

            if ($line->quotation && $line->quotation->status === Quotation::STATUS_CONVERTED) {
                throw new ImmutableRecordException('Lines of converted quotations cannot be updated.');
            }
        });

        static::deleting(function (self $line): void {
            $parent = Quotation::query()->find($line->getRawOriginal('quotation_id'));
            if ($parent === null || $parent->status !== Quotation::STATUS_DRAFT) {
                throw new \InvalidArgumentException('Non-draft document lines cannot be deleted.');
            }
            if ($line->quotation && $line->quotation->status === Quotation::STATUS_CONVERTED) {
                throw new ImmutableRecordException('Lines of converted quotations cannot be deleted.');
            }
        });
    }

    /**
     * @return BelongsTo<Quotation, $this>
     */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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
}
