<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Brand extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'name_ar',
        'name_en',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $brand): void {
            if (empty($brand->public_id)) {
                $brand->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'brand_id');
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
}
