<?php

declare(strict_types=1);

namespace App\Services\Catalogs;

use App\Models\Catalog;
use App\Models\PublicShare;
use App\Services\Sales\ManagedPublicUrl;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Read an already-issued publication link; never creates or renews a grant. */
final class CatalogBrowserShare
{
    public function existingUrl(int $catalogId): ?string
    {
        return DB::transaction(function () use ($catalogId): ?string {
            $company = app(CatalogService::class)->authorize('catalogs.share');
            $catalog = Catalog::where('company_id', $company->id)->whereKey($catalogId)->firstOrFail();
            if ($catalog->status !== 'active' || $catalog->published_payload === null) {
                return null;
            }
            if (array_key_exists('currency_code', $catalog->published_payload)) {
                app(CatalogService::class)->authorize('catalogs.show_prices');
            }
            $grant = PublicShare::where('company_id', $company->id)
                ->where('subject_type', CatalogService::SUBJECT)->where('subject_id', $catalogId)
                ->where('is_active', true)->whereNull('revoked_at')->orderByDesc('id')->first();
            if ($grant === null || ! $grant->isValid()) {
                return null;
            }

            return app(ManagedPublicUrl::class)->make('catalog', Crypt::decryptString($grant->encrypted_token));
        });
    }
}
