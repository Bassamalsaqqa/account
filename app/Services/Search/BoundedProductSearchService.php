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
     * - Trim whitespace
     * - Map Eastern Arabic and Persian digits to ASCII digits
     * - Strip Arabic diacritics and tatweel
     * - Normalize Arabic letter variants (alef variants, taa marbuta, alef maqsura)
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

        return trim($term);
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
        $normalized = $this->normalize($query);
        if ($normalized === '') {
            return [];
        }

        $limit = max(1, min($limit, self::MAX_CANDIDATES));

        $this->authorize($companyId, $user, $context);

        $canViewCost = $user->hasPermissionTo('purchasing.cost.view');

        $candidateIds = $this->resolveCandidateIds($companyId, $normalized, $context, $limit);
        if (empty($candidateIds)) {
            return [];
        }

        $orderedIds = array_keys($candidateIds);

        $products = Product::with(['productUnits.unit', 'baseUnit'])
            ->where('company_id', $companyId)
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
                $isDefault = $context === 'purchasing' ? (bool) $pu->is_default_purchase : (bool) $pu->is_default_sale;
                if ($isDefault || $pu->is_base) {
                    $defaultUnitId ??= (int) $pu->id;
                }
                $units[] = [
                    'id' => (int) $pu->id,
                    'unit_id' => (int) $pu->unit_id,
                    'name' => $pu->unit->name(),
                    'is_base' => (bool) $pu->is_base,
                    'conversion_to_base' => (string) $pu->conversion_to_base,
                ];
            }

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
     *
     * @return Builder<Product>
     */
    public function searchProductsQuery(int $companyId, string $query, string $context = 'sales'): Builder
    {
        $term = mb_substr(trim($query), 0, 160);

        $queryBuilder = Product::where('company_id', $companyId)
            ->where('active', true);

        if ($context === 'purchasing') {
            $queryBuilder->where('product_type', Product::TYPE_STOCK)
                ->where('track_stock', true);
        }

        if ($term === '') {
            return $queryBuilder;
        }

        $normalized = $this->normalize($term);

        $barcodeSubquery = ProductBarcode::select('product_id')
            ->where('company_id', $companyId)
            ->where(function (Builder $b) use ($term, $normalized): void {
                $b->where('barcode', $term);
                if ($normalized !== $term) {
                    $b->orWhere('barcode', $normalized);
                }
            });

        $queryBuilder->where(function (Builder $q) use ($term, $normalized, $barcodeSubquery): void {
            // Exact / prefix SKU
            $q->where('sku', 'like', "{$term}%");
            if ($normalized !== $term) {
                $q->orWhere('sku', 'like', "{$normalized}%");
            }

            // Prefix name
            $q->orWhere('name_ar', 'like', "{$term}%")
                ->orWhere('name_en', 'like', "{$term}%");

            if ($normalized !== $term) {
                $q->orWhere('name_ar', 'like', "{$normalized}%")
                    ->orWhere('name_en', 'like', "{$normalized}%");
            }

            // Substring if term >= 3
            if (mb_strlen($normalized) >= self::MIN_SUBSTRING_LENGTH) {
                $q->orWhere('name_ar', 'like', "%{$normalized}%")
                    ->orWhere('name_en', 'like', "%{$normalized}%");
            }

            $q->orWhereIn('id', $barcodeSubquery);
        });

        return $queryBuilder;
    }

    /**
     * Resolve candidate IDs with tiered relevance scores.
     * Tier 1: Exact Barcode / SKU (100)
     * Tier 2: Prefix Barcode / SKU (80)
     * Tier 3: Prefix / Exact AR/EN Name (60)
     * Tier 4: Token Substring (40)
     *
     * @return array<int, array{score: int, barcode: ?string}>
     */
    private function resolveCandidateIds(int $companyId, string $term, string $context, int $limit): array
    {
        /** @var array<int, array{score: int, barcode: ?string}> $candidates */
        $candidates = [];

        $baseProductQuery = fn () => Product::where('company_id', $companyId)->where('active', true)
            ->when($context === 'purchasing', fn ($q) => $q->where('product_type', Product::TYPE_STOCK)->where('track_stock', true));

        // ── Tier 1: Exact Barcode / SKU ──
        $exactBarcodes = ProductBarcode::where('company_id', $companyId)
            ->where('barcode', $term)
            ->get(['product_id', 'barcode']);

        foreach ($exactBarcodes as $bc) {
            $pid = (int) $bc->product_id;
            $candidates[$pid] = ['score' => 100, 'barcode' => $bc->barcode];
        }

        $exactSkus = $baseProductQuery()
            ->where('sku', $term)
            ->pluck('id');

        foreach ($exactSkus as $id) {
            $pid = (int) $id;
            if (! isset($candidates[$pid]) || $candidates[$pid]['score'] < 95) {
                $candidates[$pid] = ['score' => 95, 'barcode' => $candidates[$pid]['barcode'] ?? null];
            }
        }

        // ── Tier 2: Prefix Barcode / SKU ──
        if (count($candidates) < $limit) {
            $prefixBarcodes = ProductBarcode::where('company_id', $companyId)
                ->where('barcode', 'like', "{$term}%")
                ->where('barcode', '!=', $term)
                ->limit($limit)
                ->get(['product_id', 'barcode']);

            foreach ($prefixBarcodes as $bc) {
                $pid = (int) $bc->product_id;
                if (! isset($candidates[$pid])) {
                    $candidates[$pid] = ['score' => 80, 'barcode' => $bc->barcode];
                }
            }

            $prefixSkus = $baseProductQuery()
                ->where('sku', 'like', "{$term}%")
                ->where('sku', '!=', $term)
                ->limit($limit)
                ->pluck('id');

            foreach ($prefixSkus as $id) {
                $pid = (int) $id;
                if (! isset($candidates[$pid])) {
                    $candidates[$pid] = ['score' => 75, 'barcode' => null];
                }
            }
        }

        // ── Tier 3: Prefix / Exact AR/EN Name ──
        if (count($candidates) < $limit) {
            $prefixNames = $baseProductQuery()
                ->where(function (Builder $q) use ($term): void {
                    $q->where('name_ar', 'like', "{$term}%")
                        ->orWhere('name_en', 'like', "{$term}%");
                })
                ->limit($limit)
                ->pluck('id');

            foreach ($prefixNames as $id) {
                $pid = (int) $id;
                if (! isset($candidates[$pid])) {
                    $candidates[$pid] = ['score' => 60, 'barcode' => null];
                }
            }
        }

        // ── Tier 4: Token Substring Match (safely bounded) ──
        if (count($candidates) < $limit && mb_strlen($term) >= self::MIN_SUBSTRING_LENGTH) {
            $needed = $limit - count($candidates);
            $substringNames = $baseProductQuery()
                ->where(function (Builder $q) use ($term): void {
                    $q->where('name_ar', 'like', "%{$term}%")
                        ->orWhere('name_en', 'like', "%{$term}%");
                })
                ->whereNotIn('id', array_keys($candidates))
                ->limit($needed)
                ->pluck('id');

            foreach ($substringNames as $id) {
                $pid = (int) $id;
                if (! isset($candidates[$pid])) {
                    $candidates[$pid] = ['score' => 40, 'barcode' => null];
                }
            }
        }

        // Sort candidates by score DESC, keeping array keys (product_id)
        uasort($candidates, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, $limit, true);
    }

    private function computeSalePrice(Product $product, ?int $unitId, string $currency, string $exchangeRate): string
    {
        try {
            $unit = app(SalesDocumentRules::class)->selectedUnit($product, $unitId, Quantity::of('1'));
            $rate = BigDecimal::of($exchangeRate);
            if (! $rate->isPositive()) {
                return '0.00';
            }
            $basePrice = $unit->default_sale_price_base === null
                ? BigDecimal::of($product->default_sale_price_base ?? '0')->multipliedBy($unit->conversion_to_base)
                : BigDecimal::of($unit->default_sale_price_base);

            $decimals = $currency === 'JOD' ? 3 : 2;

            return (string) $basePrice->dividedBy($rate, $decimals, RoundingMode::HALF_UP);
        } catch (\Throwable) {
            return '0.00';
        }
    }

    private function computePurchaseCost(Product $product, ?int $unitId, string $currency, string $exchangeRate, ?Company $company, string $locale): string
    {
        try {
            $rules = app(PurchaseDocumentRules::class);
            if ($company) {
                $rules->header($company, $currency, $exchangeRate, now($company->timezone)->format('Y-m-d'), null, $locale);
            }
            $unit = $rules->selectedUnit($product, $unitId, '1');
            $base = $unit->default_purchase_price_base !== null
                ? BigDecimal::of($unit->default_purchase_price_base)
                : BigDecimal::of($product->default_purchase_cost_base ?? '0')->multipliedBy($unit->conversion_to_base);

            $decimals = $currency === 'JOD' ? 3 : 2;

            return (string) $base->dividedBy(ExchangeRate::from($exchangeRate)->getValue(), $decimals, RoundingMode::HALF_UP);
        } catch (\Throwable) {
            return '0.00';
        }
    }

    /**
     * @return array{formatted: ?string, status: string}
     */
    private function computeAvailableQuantity(int $companyId, int $productId, ?int $unitId, ?int $warehouseId): array
    {
        try {
            $product = Product::find($productId);
            if (! $product || ! $product->track_stock) {
                return ['formatted' => null, 'status' => 'ok'];
            }

            $pu = $product->productUnits->firstWhere('id', $unitId) ?? $product->productUnits->firstWhere('is_base', true);
            $conversion = $pu ? BigDecimal::of((string) $pu->conversion_to_base) : BigDecimal::of('1');

            $query = InventoryBalance::where('company_id', $companyId)->where('product_id', $productId);
            if ($warehouseId !== null) {
                $query->where('warehouse_id', $warehouseId);
            }
            $sum = (string) $query->sum('quantity_base');
            $qty = BigDecimal::of($sum)->dividedBy($conversion, 6, RoundingMode::FLOOR);

            $status = 'ok';
            if ($qty->isLessThanOrEqualTo(0)) {
                $status = 'out_of_stock';
            } elseif ($product->minimum_stock_base !== null && $qty->isLessThanOrEqualTo($product->minimum_stock_base)) {
                $status = 'low_stock';
            }

            return [
                'formatted' => SalesMoneyFormatter::formatQuantity((string) $qty),
                'status' => $status,
            ];
        } catch (\Throwable) {
            return ['formatted' => null, 'status' => 'ok'];
        }
    }

    private function authorize(int $companyId, User $user, string $context): void
    {
        $isMember = CompanyUser::where('company_id', $companyId)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereHas('company', fn ($query) => $query->where('status', 'active'))
            ->exists();

        abort_unless($isMember, 403, 'Unauthorized company access.');

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
    }
}
