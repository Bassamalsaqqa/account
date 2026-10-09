<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

final class CatalogItem extends Model
{
    use BelongsToCompany;

    /** @var list<string> */
    protected $fillable = ['company_id', 'catalog_id', 'product_id', 'unit_id', 'image_id', 'position', 'name_ar', 'name_en', 'description_ar', 'description_en', 'custom_price'];

    /** @return array<string,string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'custom_price' => 'string'];
    }
}
