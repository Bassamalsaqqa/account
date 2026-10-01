<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Unit extends Model
{
    use BelongsToCompany;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'code',
        'name_ar',
        'name_en',
        'symbol_ar',
        'symbol_en',
        'allows_fraction',
        'decimal_places',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'allows_fraction' => 'boolean',
            'decimal_places' => 'integer',
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $unit): void {
            if (empty($unit->public_id)) {
                $unit->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * @return HasMany<ProductUnit, $this>
     */
    public function productUnits(): HasMany
    {
        return $this->hasMany(ProductUnit::class, 'unit_id');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function baseProducts(): HasMany
    {
        return $this->hasMany(Product::class, 'base_unit_id');
    }

    public function name(): string
    {
        return (app()->getLocale() === 'en' && ! empty($this->name_en))
            ? $this->name_en
            : $this->name_ar;
    }

    public function symbol(): ?string
    {
        return (app()->getLocale() === 'en' && ! empty($this->symbol_en))
            ? $this->symbol_en
            : ($this->symbol_ar ?? $this->name());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
