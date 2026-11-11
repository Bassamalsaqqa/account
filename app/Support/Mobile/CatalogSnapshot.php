<?php

declare(strict_types=1);

namespace App\Support\Mobile;

use App\Models\Company;
use App\Models\Product;

final class CatalogSnapshot
{
    /**
     * Truthfully reflects company catalog changes boundedly without memory scanning or cost leakage.
     */
    public static function version(Company $company): string
    {
        $summary = Product::where('company_id', $company->id)
            ->where('active', true)
            ->selectRaw('COUNT(*) as total_count, COALESCE(MAX(updated_at), "") as max_updated, COALESCE(MAX(id), 0) as max_id')
            ->first();

        $count = (int) ($summary->total_count ?? 0);
        if ($count === 0) {
            return self::emptyVersion();
        }

        $maxUpdated = (string) ($summary->max_updated ?? '');
        $maxId = (int) ($summary->max_id ?? 0);

        return hash('sha256', "catalog:{$company->id}:{$count}:{$maxUpdated}:{$maxId}");
    }

    /** Only valid after verifying that the authorized company catalog is empty. */
    public static function emptyVersion(): string
    {
        return hash('sha256', '[]');
    }
}
