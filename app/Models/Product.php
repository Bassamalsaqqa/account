<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Product extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    public const string TYPE_STOCK = 'stock';

    public const string TYPE_NON_STOCK = 'non_stock';

    public const string TYPE_SERVICE = 'service';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'sku',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'product_type',
        'category_id',
        'brand_id',
        'base_unit_id',
        'track_stock',
        'track_expiry',
        'minimum_stock_base',
        'default_purchase_cost_base',
        'default_sale_price_base',
        'active',
        'created_by',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'track_stock' => 'boolean',
            'track_expiry' => 'boolean',
            'active' => 'boolean',
            'minimum_stock_base' => 'string',
            'default_purchase_cost_base' => 'string',
            'default_sale_price_base' => 'string',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $product): void {
            if (empty($product->public_id)) {
                $product->public_id = (string) Str::ulid();
            }

            if (empty($product->created_by)) {
                if (auth()->check()) {
                    $product->created_by = auth()->id();
                } else {
                    throw new \InvalidArgumentException('Product created_by must be specified when no authenticated user is present.');
                }
            }

            if ($product->product_type === self::TYPE_SERVICE || $product->product_type === self::TYPE_NON_STOCK) {
                $product->track_stock = false;
                $product->track_expiry = false;
            }

            if ($product->track_expiry) {
                $product->track_stock = true;
            }
        });

        static::saving(function (self $product): void {
            if ($product->category_id !== null) {
                $category = CompanyScope::executeWithoutScope(fn () => ProductCategory::find($product->category_id));
                if ($category === null || (int) $category->company_id !== (int) $product->company_id) {
                    throw new \InvalidArgumentException("Product category [{$product->category_id}] does not belong to the same company.");
                }
            }

            if ($product->brand_id !== null) {
                $brand = CompanyScope::executeWithoutScope(fn () => Brand::find($product->brand_id));
                if ($brand === null || (int) $brand->company_id !== (int) $product->company_id) {
                    throw new \InvalidArgumentException("Product brand [{$product->brand_id}] does not belong to the same company.");
                }
            }

            if ($product->base_unit_id > 0) {
                $unit = CompanyScope::executeWithoutScope(fn () => Unit::find($product->base_unit_id));
                if ($unit === null || (int) $unit->company_id !== (int) $product->company_id) {
                    throw new \InvalidArgumentException("Product base unit [{$product->base_unit_id}] does not belong to the same company.");
                }
            }
        });
    }

    public function getMinimumStockAttribute(): ?string
    {
        return $this->minimum_stock_base !== null ? (string) $this->minimum_stock_base : null;
    }

    public function setMinimumStockAttribute(?string $val): void
    {
        $this->attributes['minimum_stock_base'] = $val;
    }

    public function getDefaultSalePriceAttribute(): ?string
    {
        return $this->default_sale_price_base !== null ? (string) $this->default_sale_price_base : null;
    }

    public function setDefaultSalePriceAttribute(?string $val): void
    {
        $this->attributes['default_sale_price_base'] = $val;
    }

    public function getStockQuantityAttribute(): string
    {
        return $this->totalStockBase();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /**
     * @return HasMany<ProductUnit, $this>
     */
    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class, 'product_id');
    }

    /**
     * @return HasMany<ProductBarcode, $this>
     */
    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'product_id');
    }

    /**
     * @return HasOne<ProductBarcode, $this>
     */
    public function primaryBarcode(): HasOne
    {
        return $this->hasOne(ProductBarcode::class, 'product_id')->where('is_primary', true);
    }

    /**
     * @return HasMany<ProductImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'product_id')->orderBy('sort_order');
    }

    /**
     * @return HasOne<ProductImage, $this>
     */
    public function primaryImage(): HasOne
    {
        return $this->hasOne(ProductImage::class, 'product_id')->where('is_primary', true);
    }

    /**
     * @return HasMany<InventoryBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class, 'product_id');
    }

    /**
     * @return HasOne<InventoryCostState, $this>
     */
    public function costState(): HasOne
    {
        return $this->hasOne(InventoryCostState::class, 'product_id');
    }

    /**
     * @return HasMany<InventoryLot, $this>
     */
    public function lots(): HasMany
    {
        return $this->hasMany(InventoryLot::class, 'product_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'product_id');
    }

    public function name(): string
    {
        return (app()->getLocale() === 'en' && ! empty($this->name_en))
            ? $this->name_en
            : $this->name_ar;
    }

    public function displayName(): string
    {
        return $this->name();
    }

    public function isStock(): bool
    {
        return $this->product_type === self::TYPE_STOCK;
    }

    public function isService(): bool
    {
        return $this->product_type === self::TYPE_SERVICE;
    }

    public function isNonStock(): bool
    {
        return $this->product_type === self::TYPE_NON_STOCK;
    }

    public function hasMovements(): bool
    {
        return StockMovement::where('product_id', $this->id)->exists();
    }

    public function totalStockBase(): string
    {
        return (string) ($this->costState->quantity_base ?? '0.000000');
    }

    public function averageCostBase(): string
    {
        return (string) ($this->costState->average_cost_base ?? '0.000000');
    }

    public function inventoryValueBase(): string
    {
        return (string) ($this->costState->inventory_value_base ?? '0.000000');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeStock(Builder $query): Builder
    {
        return $query->where('product_type', self::TYPE_STOCK);
    }
}
