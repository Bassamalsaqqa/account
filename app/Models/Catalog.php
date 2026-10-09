<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** @property array<string,mixed>|null $published_payload */
final class Catalog extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'public_id', 'name_ar', 'name_en', 'description_ar', 'description_en', 'locale', 'status',
        'show_prices', 'show_sku', 'show_description', 'show_images', 'currency_code', 'tax_basis', 'draft_revision', 'published_revision',
        'published_payload', 'published_hash', 'publication_key', 'publication_intent_hash', 'created_by', 'updated_by', 'published_at'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['show_prices' => 'boolean', 'show_sku' => 'boolean', 'show_description' => 'boolean', 'show_images' => 'boolean',
            'draft_revision' => 'integer', 'published_revision' => 'integer', 'published_payload' => 'array', 'published_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        self::creating(function (self $catalog): void {
            $catalog->public_id ??= (string) Str::ulid();
        });
    }

    /** @return HasMany<CatalogItem,$this> */
    public function items(): HasMany
    {
        return $this->hasMany(CatalogItem::class);
    }
}
