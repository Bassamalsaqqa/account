<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Warehouse extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'code',
        'name_ar',
        'name_en',
        'address_ar',
        'address_en',
        'is_default',
        'active',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $warehouse): void {
            if (empty($warehouse->public_id)) {
                $warehouse->public_id = (string) Str::ulid();
            }

            if (empty($warehouse->created_by)) {
                if (auth()->check()) {
                    $warehouse->created_by = auth()->id();
                } else {
                    throw new \InvalidArgumentException('Warehouse created_by must be specified when no authenticated user is present.');
                }
            }
        });
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<InventoryBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(InventoryBalance::class, 'warehouse_id');
    }

    /**
     * @return HasMany<InventoryLotBalance, $this>
     */
    public function lotBalances(): HasMany
    {
        return $this->hasMany(InventoryLotBalance::class, 'warehouse_id');
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function stockMovements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'warehouse_id');
    }

    public function name(): string
    {
        return (app()->getLocale() === 'en' && ! empty($this->name_en))
            ? $this->name_en
            : $this->name_ar;
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
    public function scopeDefault(Builder $query): Builder
    {
        return $query->where('is_default', true);
    }
}
