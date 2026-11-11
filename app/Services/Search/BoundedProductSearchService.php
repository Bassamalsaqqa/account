<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\InventoryBalance;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\User;
use App\Services\Purchasing\PurchaseDocumentRules;
use App\Services\Sales\SalesDocumentRules;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\PermissionRegistrar;

final class BoundedProductSearchService
{
    public const MAX_CANDIDATES = 30;

    public const MIN_SUBSTRING_LENGTH = 3;

    /**
     * Normalize search query according to commercial multi-platform specification:
     * - Trim whitespace and clamp to max 160 characters
     * - Map Eastern Arabic (٠-٩) and Persian (۰-۹) digits to ASCII digits (0-9)
     * - Strip Arabic diacritics (Harakat: \x{064B}-\x{065F}\x{0670}) and Tatweel (\x{0640})
     * - Normalize Arabic letter variants (alef variants to bare alef, taa marbuta to haa, alef maqsura to yaa)
     * - Normalize Latin / English characters to lowercase
     *
     * Server contract: stored catalog values are preserved. The literal query and a
     * folded query are tried as prefixes; stored columns are never transformed.
     * Symmetric Arabic/digit/diacritic folding is DEFERRED: a folded query may not
     * match a variant stored in the catalog. Android must not promise symmetric
     * matching. Collation behavior depends on the deployed MariaDB collation.
     * Candidate count is bounded; optimizer work and latency are not guaranteed.
     */
    public function normalize(string $rawQuery): string
    {
        $term = trim($rawQuery);
        if ($term === '') {
            return '';
        }

        // Clamp to max 160 chars
        if (mb_strlen($term) > 160) {
            $term = mb_substr($term, 0, 160);
        }

        // Normalize Eastern Arabic (٠-٩) and Persian (۰-۹) digits to ASCII (0-9)
        $easternArabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $asciiDigits = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
        $term = str_replace($easternArabic, $asciiDigits, $term);
        $term = str_replace($persian, $asciiDigits, $term);

        // Strip Arabic diacritics (Harakat: \x{064B}-\x{065F}\x{0670}) and Tatweel (\x{0640})
        $term = (string) preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{0640}]/u', '', $term);

        // Normalize Arabic letter variants
        $alefVariants = ['أ', 'إ', 'آ', 'ٱ'];
        $term = str_replace($alefVariants, 'ا', $term);
        $term = str_replace('ة', 'ه', $term);
        $term = str_replace('ى', 'ي', $term);

        // Normalize Latin lowercase
        $term = mb_strtolower($term);

        return trim($term);
    }

    /**
     * Escape SQL LIKE metacharacters % and _ as well as backslash for literal prefix matching.
     */
    public function escapeLike(string $value): string
    {
        return addcslashes($value, '%_\\');
    }

    /**
     * Execute bounded search for product candidates with strict tenant scoping and permissions.
     *
     * @return list<array{
     *     id: int,
     *     public_id: string,
     *     sku: string,
     *     name_ar: string,
     *     name_en: string,
     *     display_name: string,
     *     base_unit_id: int,
     *     base_unit_name: string,
     *     default_unit_id: ?int,
     *     price: ?string,
     *     cost: ?string,
     *     available_quantity: ?string,
     *     stock_status: string,
     *     track_stock: bool,
     *     track_expiry: bool,
     *     matched_barcode: ?string,
     *     units: list<array{id: int, unit_id: int, name: string, is_base: bool, conversion_to_base: string}>
     * }>
     */
    public function search(
        int $companyId,
        User $user,
        string $query,
        string $context = 'sales',
        ?int $warehouseId = null,
        string $currencyCode = 'ILS',
        string $exchangeRate = '1.0000000000',
        int $limit = self::MAX_CANDIDATES
    ): array {
        $rawTerm = mb_substr(trim($query), 0, 160);
        if ($rawTerm === '') {
            return [];
        }

        $normalized = $this->normalize($rawTerm);
        $limit = max(1, min($limit, self::MAX_CANDIDATES));

        $this->authorize($companyId, $user, $context);

        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($companyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
            $user->unsetRelation('roles')->unsetRelation('permissions');
            $canViewCost = $user->hasPermissionTo('purchasing.cost.view');
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->setPermissionsTeamId($previousTeam);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }

        $candidateIds = $this->resolveCandidateIds($companyId, $rawTerm, $normalized, $context, $limit);
        if (empty($candidateIds)) {
            return [];
        }

        $orderedIds = array_keys($candidateIds);

        $products = Product::with([
            'productUnits' => fn ($q) => $q->where('company_id', $companyId)
                ->where('active', true)
                ->whereHas('unit', fn ($u) => $u->where('company_id', $companyId)->where('active', true))
                ->with(['unit' => fn ($u) => $u->where('company_id', $companyId)->where('active', true)]),
            'baseUnit' => fn ($u) => $u->where('company_id', $companyId)->where('active', true),
        ])
            ->where('company_id', $companyId)
            ->where('active', true)
            ->when($context === 'purchasing', fn ($q) => $q->where('product_type', Product::TYPE_STOCK)->where('track_stock', true))
            ->whereIn('id', $orderedIds)
            ->get()
            ->keyBy('id');

        $results = [];
        $company = Company::find($companyId);
        $locale = $company !== null ? (string) $company->default_locale : 'ar';

        foreach ($orderedIds as $productId) {
            /** @var Product|null $product */
            $product = $products->get($productId);
            if (! $product) {
                continue;
            }

            $matchedBarcode = $candidateIds[$productId]['barcode'] ?? null;
            $units = [];
            $defaultUnitId = null;

            foreach ($product->productUnits as $pu) {
                if (! $pu->active || ! $pu->unit || ! $pu->unit->active) {
                    continue;
                }
                $units[] = [
                    'id' => (int) $pu->id,
                    'unit_id' => (int) $pu->unit_id,
                    'name' => $pu->unit->name(),
                    'is_base' => (bool) $pu->is_base,
                    'conversion_to_base' => (string) $pu->conversion_to_base,
                ];
            }

            // 1. Context-specific default unit first
            foreach ($product->productUnits as $pu) {
                if (! $pu->active || ! $pu->unit || ! $pu->unit->active) {
                    continue;
                }
                $isDefault = $context === 'purchasing' ? (bool) $pu->is_default_purchase : (bool) $pu->is_default_sale;
                if ($isDefault) {
                    $defaultUnitId = (int) $pu->id;
                    break;
                }
            }

            // 2. Base unit fallback
            if ($defaultUnitId === null) {
                foreach ($product->productUnits as $pu) {
                    if (! $pu->active || ! $pu->unit || ! $pu->unit->active) {
                        continue;
                    }
                    if ($pu->is_base) {
                        $defaultUnitId = (int) $pu->id;
                        break;
                    }
                }
            }

            // 3. First active unit fallback
            if ($defaultUnitId === null && ! empty($units)) {
                $defaultUnitId = $units[0]['id'];
            }

            // Price calculation (Sales)
            $price = null;
            $availableQuantity = null;
            $stockStatus = 'ok';

            if ($context === 'sales') {
                $price = $this->computeSalePrice($product, $defaultUnitId, $currencyCode, $exchangeRate);
                if ($product->track_stock) {
                    $avail = $this->computeAvailableQuantity($companyId, (int) $product->id, $defaultUnitId, $warehouseId);
                    $availableQuantity = $avail['formatted'];
                    $stockStatus = $avail['status'];
                }
            }

            // Cost calculation (Purchasing)
            $cost = null;
            if ($context === 'purchasing') {
                if ($canViewCost) {
                    $cost = $this->computePurchaseCost($product, $defaultUnitId, $currencyCode, $exchangeRate, $company, $locale);
                } else {
                    $cost = null; // Strictly redacted
                }
            }

            $results[] = [
                'id' => (int) $product->id,
                'public_id' => (string) $product->public_id,
                'sku' => (string) ($product->sku ?? ''),
                'name_ar' => (string) ($product->name_ar ?? ''),
                'name_en' => (string) ($product->name_en ?? ''),
                'display_name' => $product->displayName(),
                'base_unit_id' => (int) $product->base_unit_id,
                'base_unit_name' => $product->baseUnit?->name() ?? '',
                'selected_unit_name' => collect($units)->firstWhere('id', $defaultUnitId)['name'] ?? '',
                'default_unit_id' => $defaultUnitId,
                'price' => $price,
                'cost' => $cost,
                'available_quantity' => $availableQuantity,
                'stock_status' => $stockStatus,
                'track_stock' => (bool) $product->track_stock,
                'track_expiry' => (bool) $product->track_expiry,
                'matched_barcode' => $matchedBarcode,
                'units' => $units,
            ];
        }

        return $results;
    }

    /**
     * Build matching products query for InvoiceForm / PurchaseForm view rendering.
     * Enforces context authorization, strict company/active/type scoping,
     * and safe prefix matching without leading wildcards.
     *
     * @return Builder<Product>
     */
    public function searchProductsQuery(int $companyId, User $user, string $query, string $context = 'sales'): Builder
    {
        $this->authorize($companyId, $user, $context);

        $queryBuilder = Product::where('company_id', $companyId)
            ->where('active', true);

        if ($context === 'purchasing') {
            $queryBuilder->where('product_type', Product::TYPE_STOCK)
                ->where('track_stock', true);
        }

        $term = mb_substr(trim($query), 0, 160);
        if ($term === '') {
            return $queryBuilder->whereRaw('0 = 1');
        }

        $rawEsc = $this->escapeLike($term);
        $normalized = $this->normalize($term);
        $normEsc = $this->escapeLike($normalized);

        $barcodeSubquery = ProductBarcode::select('product_barcodes.product_id')
            ->join('products', 'products.id', '=', 'product_barcodes.product_id')
            ->where('product_barcodes.company_id', $companyId)
            ->where('products.company_id', $companyId)
            ->where('products.active', true)
            ->when($context === 'purchasing', fn ($q) => $q->where('products.product_type', Product::TYPE_STOCK)->where('products.track_stock', true))
            ->where(function (Builder $b) use ($term, $normalized): void {
                $b->where('product_barcodes.barcode', $term);
                if ($normalized !== $term && $normalized !== '') {
                    $b->orWhere('product_barcodes.barcode', $normalized);
                }
            });

        $queryBuilder->where(function (Builder $q) use ($rawEsc, $normEsc, $barcodeSubquery): void {
            // Exact / prefix SKU (indexed via company_id, sku)
            $q->where('sku', 'like', "{$rawEsc}%");
            if ($normEsc !== $rawEsc && $normEsc !== '') {
                $q->orWhere('sku', 'like', "{$normEsc}%");
            }

            // Prefix name (indexed via company_id, active, name_ar/name_en)
            $q->orWhere('name_ar', 'like', "{$rawEsc}%")
                ->orWhere('name_en', 'like', "{$rawEsc}%");

            if ($normEsc !== $rawEsc && $normEsc !== '') {
                $q->orWhere('name_ar', 'like', "{$normEsc}%")
                    ->orWhere('name_en', 'like', "{$normEsc}%");
            }

            $q->orWhereIn('id', $barcodeSubquery);
        });

        return $queryBuilder;
    }

    /**
     * Resolve candidate IDs with tiered relevance scores and deterministic stable ID tie-breakers.
     * Tier 1: Exact Barcode (100) / Exact SKU (95)
     * Tier 2: Prefix Barcode (80) / Prefix SKU (75)
     * Tier 3: Prefix AR/EN Name (60)
     * Tier 4: (Unsupported unindexed leading-wildcard LIKE removed)
     *
     * @return array<int, array{id: int, score: int, barcode: ?string}>
     */
    private function resolveCandidateIds(int $companyId, string $raw, string $normalized, string $context, int $limit): array
    {
        /** @var array<int, array{id: int, score: int, barcode: ?string}> $candidates */
        $candidates = [];

        $baseProductQuery = fn () => Product::where('company_id', $companyId)
            ->where('active', true)
            ->when($context === 'purchasing', fn ($q) => $q->where('product_type', Product::TYPE_STOCK)->where('track_stock', true));

        $rawEsc = $this->escapeLike($raw);
        $normEsc = $this->escapeLike($normalized);

        // ── Tier 1: Exact Barcode / SKU ──
        $exactBarcodes = ProductBarcode::query()
            ->select(['product_barcodes.product_id', 'product_barcodes.barcode'])
            ->join('products', 'products.id', '=', 'product_barcodes.product_id')
            ->where('product_barcodes.company_id', $companyId)
            ->where('products.company_id', $companyId)
            ->where('products.active', true)
            ->when($context === 'purchasing', fn ($q) => $q->where('products.product_type', Product::TYPE_STOCK)->where('products.track_stock', true))
            ->where(function (Builder $b) use ($raw, $normalized): void {
                $b->where('product_barcodes.barcode', $raw);
                if ($normalized !== $raw && $normalized !== '') {
                    $b->orWhere('product_barcodes.barcode', $normalized);
                }
            })
            ->orderBy('products.id')
            ->limit($limit)
            ->get();

        foreach ($exactBarcodes as $bc) {
            $pid = (int) $bc->product_id;
            $candidates[$pid] = ['id' => $pid, 'score' => 100, 'barcode' => $bc->barcode];
        }

        $exactSkus = $baseProductQuery()
            ->where(function (Builder $q) use ($raw, $normalized): void {
                $q->where('sku', $raw);
                if ($normalized !== $raw && $normalized !== '') {
                    $q->orWhere('sku', $normalized);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($exactSkus as $id) {
            $pid = (int) $id;
            if (! isset($candidates[$pid]) || $candidates[$pid]['score'] < 95) {
                $candidates[$pid] = ['id' => $pid, 'score' => 95, 'barcode' => $candidates[$pid]['barcode'] ?? null];
            }
        }

        // ── Tier 2: Prefix Barcode / SKU ──
        if (count($candidates) < $limit) {
            $needed = $limit - count($candidates);
            $prefixBarcodes = ProductBarcode::query()
                ->select(['product_barcodes.product_id', 'product_barcodes.barcode'])
                ->join('products', 'products.id', '=', 'product_barcodes.product_id')
                ->where('product_barcodes.company_id', $companyId)
                ->where('products.company_id', $companyId)
                ->where('products.active', true)
                ->when($context === 'purchasing', fn ($q) => $q->where('products.product_type', Product::TYPE_STOCK)->where('products.track_stock', true))
                ->where(function (Builder $b) use ($rawEsc, $normEsc): void {
                    $b->where('product_barcodes.barcode', 'like', "{$rawEsc}%");
                    if ($normEsc !== $rawEsc && $normEsc !== '') {
                        $b->orWhere('product_barcodes.barcode', 'like', "{$normEsc}%");
                    }
                })
                ->whereNotIn('product_barcodes.product_id', array_keys($candidates))
                ->orderBy('products.id')
                ->limit($needed)
                ->get();

            foreach ($prefixBarcodes as $bc) {
                $pid = (int) $bc->product_id;
                if (! isset($candidates[$pid])) {
                    $candidates[$pid] = ['id' => $pid, 'score' => 80, 'barcode' => $bc->barcode];
                }
            }

            if (count($candidates) < $limit) {
                $needed = $limit - count($candidates);
                $prefixSkus = $baseProductQuery()
                    ->where(function (Builder $q) use ($rawEsc, $normEsc): void {
                        $q->where('sku', 'like', "{$rawEsc}%");
                        if ($normEsc !== $rawEsc && $normEsc !== '') {
                            $q->orWhere('sku', 'like', "{$normEsc}%");
                        }
                    })
                    ->whereNotIn('id', array_keys($candidates))
                    ->orderBy('id')
                    ->limit($needed)
                    ->pluck('id');

                foreach ($prefixSkus as $id) {
                    $pid = (int) $id;
                    if (! isset($candidates[$pid])) {
                        $candidates[$pid] = ['id' => $pid, 'score' => 75, 'barcode' => null];
                    }
                }
            }
        }

        // ── Tier 3: Prefix Name (AR and EN) ──
        if (count($candidates) < $limit) {
            $needed = $limit - count($candidates);
            $prefixNames = $baseProductQuery()
                ->where(function (Builder $q) use ($rawEsc, $normEsc): void {
                    $q->where('name_ar', 'like', "{$rawEsc}%")
                        ->orWhere('name_en', 'like', "{$rawEsc}%");
                    if ($normEsc !== $rawEsc && $normEsc !== '') {
                        $q->orWhere('name_ar', 'like', "{$normEsc}%")
                            ->orWhere('name_en', 'like', "{$normEsc}%");
                    }
                })
                ->whereNotIn('id', array_keys($candidates))
                ->orderBy('id')
                ->limit($needed)
                ->pluck('id');

            foreach ($prefixNames as $id) {
                $pid = (int) $id;
                if (! isset($candidates[$pid])) {
                    $candidates[$pid] = ['id' => $pid, 'score' => 60, 'barcode' => null];
                }
            }
        }

        // Deterministic sort: score DESC, then stable ID ASC
        uasort($candidates, function (array $a, array $b): int {
            if ($b['score'] !== $a['score']) {
                return $b['score'] <=> $a['score'];
            }

            return $a['id'] <=> $b['id'];
        });

        return array_slice($candidates, 0, $limit, true);
    }

    private function computeSalePrice(Product $product, ?int $unitId, string $currency, string $exchangeRate): ?string
    {
        try {
            $unit = app(SalesDocumentRules::class)->selectedUnit($product, $unitId, Quantity::of('1'));
            $rate = BigDecimal::of($exchangeRate);
            if (! $rate->isPositive()) {
                return null;
            }
            if ($unit->default_sale_price_base === null && $product->default_sale_price_base === null) {
                return null;
            }
            $basePrice = $unit->default_sale_price_base === null
                ? BigDecimal::of($product->default_sale_price_base)->multipliedBy($unit->conversion_to_base)
                : BigDecimal::of($unit->default_sale_price_base);

            $price = $basePrice->dividedBy($rate, 2, RoundingMode::HALF_UP);

            return SalesMoneyFormatter::formatMoneyInput($price);
        } catch (\Throwable) {
            return null;
        }
    }

    private function computePurchaseCost(Product $product, ?int $unitId, string $currency, string $exchangeRate, ?Company $company, string $locale): ?string
    {
        try {
            $rules = app(PurchaseDocumentRules::class);
            if ($company) {
                $rules->header($company, $currency, $exchangeRate, now($company->timezone)->format('Y-m-d'), null, $locale);
            }
            $unit = $rules->selectedUnit($product, $unitId, '1');
            if ($unit->default_purchase_price_base === null && $product->default_purchase_cost_base === null) {
                return null;
            }
            $base = $unit->default_purchase_price_base !== null
                ? BigDecimal::of($unit->default_purchase_price_base)
                : BigDecimal::of($product->default_purchase_cost_base)->multipliedBy($unit->conversion_to_base);

            $fx = ExchangeRate::from($exchangeRate)->getValue();
            $cost = $base->dividedBy($fx, 2, RoundingMode::HALF_UP);

            return SalesMoneyFormatter::formatMoneyInput($cost);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{formatted: ?string, status: string}
     */
    private function computeAvailableQuantity(int $companyId, int $productId, ?int $unitId, ?int $warehouseId): array
    {
        try {
            $product = Product::where('company_id', $companyId)->find($productId);
            if (! $product || ! $product->track_stock) {
                return ['formatted' => null, 'status' => 'ok'];
            }

            $pu = $product->productUnits->firstWhere('id', $unitId) ?? $product->productUnits->firstWhere('is_base', true);
            $conversion = $pu ? BigDecimal::of((string) $pu->conversion_to_base) : BigDecimal::of('1');

            $query = InventoryBalance::where('company_id', $companyId)->where('product_id', $productId);
            if ($warehouseId !== null) {
                $query->where('warehouse_id', $warehouseId);
            }
            $sumBase = (string) $query->sum('quantity_base');
            $qtyInUnit = BigDecimal::of($sumBase)->dividedBy($conversion, 6, RoundingMode::FLOOR);

            $status = 'ok';
            $baseQty = BigDecimal::of($sumBase);
            if ($baseQty->isLessThanOrEqualTo(0)) {
                $status = 'out_of_stock';
            } elseif ($product->minimum_stock_base !== null && $baseQty->isLessThanOrEqualTo($product->minimum_stock_base)) {
                $status = 'low_stock';
            }

            return [
                'formatted' => SalesMoneyFormatter::formatQuantity((string) $qtyInUnit),
                'status' => $status,
            ];
        } catch (\Throwable) {
            return ['formatted' => null, 'status' => 'unknown'];
        }
    }

    private function authorize(int $companyId, User $user, string $context): void
    {
        if ($context !== 'sales' && $context !== 'purchasing') {
            abort(403, 'Invalid search context.');
        }

        $companyContext = app(CompanyContext::class);
        if ($companyContext->hasCompany() && (int) $companyContext->companyId() !== $companyId) {
            abort(403, 'Company context mismatch.');
        }

        $isMember = CompanyUser::where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('company', fn ($query) => $query->where('status', 'active'))
            ->exists();

        abort_unless($isMember, 403, 'Unauthorized company access.');

        $previousTeam = getPermissionsTeamId();
        try {
            setPermissionsTeamId($companyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);
            $user->unsetRelation('roles')->unsetRelation('permissions');

            if ($context === 'sales') {
                abort_unless(
                    $user->hasPermissionTo('sales.invoice.create')
                    || $user->hasPermissionTo('sales.invoice.edit_draft')
                    || $user->hasPermissionTo('sales.invoice.view'),
                    403,
                    'Unauthorized sales search.'
                );
            } elseif ($context === 'purchasing') {
                abort_unless(
                    $user->hasPermissionTo('purchasing.purchase.create')
                    || $user->hasPermissionTo('purchasing.purchase.edit_draft')
                    || $user->hasPermissionTo('purchasing.purchase.view'),
                    403,
                    'Unauthorized purchasing search.'
                );
            }
        } finally {
            setPermissionsTeamId($previousTeam);
            app(PermissionRegistrar::class)->setPermissionsTeamId($previousTeam);
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }
}
