<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ProductCategory extends Model
{
    use BelongsToCompany;
    use SoftDeletes;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'company_id',
        'parent_id',
        'name_ar',
        'name_en',
        'description_ar',
        'description_en',
        'active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $category): void {
            if (empty($category->public_id)) {
                $category->public_id = (string) Str::ulid();
            }
        });

        static::saving(function (self $category): void {
            if ($category->parent_id !== null) {
                if ($category->exists && (int) $category->parent_id === (int) $category->id) {
                    throw new \InvalidArgumentException('A category cannot be its own parent.');
                }

                $parent = CompanyScope::executeWithoutScope(
                    fn () => self::find($category->parent_id)
                );

                if ($parent === null || (int) $parent->company_id !== (int) $category->company_id) {
                    throw new \InvalidArgumentException("Category parent [{$category->parent_id}] does not belong to the same company.");
                }

                // Check ancestry cycle
                if ($category->exists) {
                    $curr = $parent;
                    while ($curr && $curr->parent_id !== null) {
                        if ((int) $curr->parent_id === (int) $category->id) {
                            throw new \InvalidArgumentException('Category hierarchy cycle detected.');
                        }
                        $curr = CompanyScope::executeWithoutScope(
                            fn () => self::find($curr->parent_id)
                        );
                    }
                }
            }
        });
    }

    /**
     * @return BelongsTo<ProductCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ProductCategory, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name_ar');
    }

    /**
     * @return HasMany<Product, $this>
     */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
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
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }
}
