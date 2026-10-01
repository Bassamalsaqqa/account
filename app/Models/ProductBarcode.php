<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductBarcode extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'product_id',
        'unit_id',
        'barcode',
        'type',
        'is_primary',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $barcode): void {
            if ($barcode->product_id > 0) {
                $product = CompanyScope::executeWithoutScope(fn () => Product::find($barcode->product_id));
                if ($product === null || (int) $product->company_id !== (int) $barcode->company_id) {
                    throw new \InvalidArgumentException("ProductBarcode product [{$barcode->product_id}] does not belong to the same company.");
                }
            }

            if ($barcode->unit_id !== null) {
                $unit = CompanyScope::executeWithoutScope(fn () => Unit::find($barcode->unit_id));
                if ($unit === null || (int) $unit->company_id !== (int) $barcode->company_id) {
                    throw new \InvalidArgumentException("ProductBarcode unit [{$barcode->unit_id}] does not belong to the same company.");
                }

                $productUnit = CompanyScope::executeWithoutScope(
                    fn () => ProductUnit::where('product_id', $barcode->product_id)->where('unit_id', $barcode->unit_id)->first()
                );
                if ($productUnit === null) {
                    throw new \InvalidArgumentException("ProductBarcode unit [{$barcode->unit_id}] is not configured for product [{$barcode->product_id}].");
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
