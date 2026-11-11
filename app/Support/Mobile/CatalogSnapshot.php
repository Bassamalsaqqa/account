<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Models\Company;
use App\Models\Product;

final class CatalogSnapshot
{
    public static function version(Company $company): string
    {
        // Only metadata permitted to this endpoint; stable ordering, no cost data.
        $rows = Product::where('company_id', $company->id)->where('active', true)
            ->orderBy('public_id')->get(['public_id', 'sku', 'name_ar', 'name_en', 'default_sale_price_base', 'updated_at']);

        return hash('sha256', $rows->toJson(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
