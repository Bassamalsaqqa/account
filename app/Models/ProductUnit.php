<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductUnit extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'product_id',
        'unit_id',
        'conversion_to_base',
        'is_base',
        'is_default_purchase',
        'is_default_sale',
        'default_purchase_price_base',
        'default_sale_price_base',
        'active',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'active' => true,
        'is_base' => false,
        'is_default_purchase' => false,
        'is_default_sale' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversion_to_base' => 'string',
            'is_base' => 'boolean',
            'is_default_purchase' => 'boolean',
            'is_default_sale' => 'boolean',
            'default_purchase_price_base' => 'string',
            'default_sale_price_base' => 'string',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $productUnit): void {
            if ($productUnit->product_id > 0) {
                $product = CompanyScope::executeWithoutScope(fn () => Product::find($productUnit->product_id));
                if ($product === null || (int) $product->company_id !== (int) $productUnit->company_id) {
                    throw new \InvalidArgumentException("ProductUnit product [{$productUnit->product_id}] does not belong to the same company.");
                }
            }

            if ($productUnit->unit_id > 0) {
                $unit = CompanyScope::executeWithoutScope(fn () => Unit::find($productUnit->unit_id));
                if ($unit === null || (int) $unit->company_id !== (int) $productUnit->company_id) {
                    throw new \InvalidArgumentException("ProductUnit unit [{$productUnit->unit_id}] does not belong to the same company.");
                }
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
