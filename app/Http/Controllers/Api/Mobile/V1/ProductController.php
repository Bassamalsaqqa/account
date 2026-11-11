<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\TaxRate;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProductController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/products
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company') ?? app(CompanyContext::class)->company();
        /** @var User $user */
        $user = $request->attributes->get('mobile_user') ?? auth()->user();

        // 1. Authorize permission
        if (! $this->canReadProducts($user, $company)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required catalog permissions.',
                status: 403
            );
        }

        // 2. Resolve bounded limit (max 30 per specification)
        $maxLimit = (int) config('mobile.max_page_limit', 30);
        $rawLimit = $request->query('limit', $maxLimit);
        $limit = is_numeric($rawLimit) ? min(max((int) $rawLimit, 1), $maxLimit) : $maxLimit;

        // 3. Resolve search (max 100 characters)
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $search = mb_substr(trim($search), 0, 100);
        } else {
            $search = null;
        }

        // 4. Resolve cursor
        $cursor = $request->query('cursor');
        if (! is_string($cursor) || trim($cursor) === '') {
            $cursor = null;
        } else {
            $cursor = trim($cursor);
        }

        // 5. Query Products without cost columns (Strict Server-Side Redaction)
        $query = Product::where('company_id', $company->id)
            ->where('active', true)
            ->with([
                'baseUnit',
                'taxRate',
                'productUnits.unit',
                'primaryBarcode',
                'barcodes',
                'balances',
            ]);

        if ($search !== null) {
            $query->where(function ($q) use ($search): void {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhereHas('barcodes', function ($bq) use ($search): void {
                        $bq->where('barcode', 'like', "%{$search}%");
                    });
            });
        }

        if ($cursor !== null) {
            $query->where('public_id', '>', $cursor);
        }

        $records = $query->orderBy('public_id', 'asc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
            $nextCursor = $records->last()?->public_id;
        } else {
            $nextCursor = null;
        }

        // Default company tax rate for fallback
        /** @var CompanyMobileSetting|null $mobileSetting */
        $mobileSetting = CompanyMobileSetting::where('company_id', $company->id)->first();
        $defaultTaxRate = ($mobileSetting !== null && $mobileSetting->defaultTaxRate !== null)
            ? $mobileSetting->defaultTaxRate
            : TaxRate::where('company_id', $company->id)->where('active', true)->orderBy('rate', 'desc')->first();

        $catalogSnapshotVersion = ($mobileSetting !== null && ! empty($mobileSetting->catalog_snapshot_version))
            ? $mobileSetting->catalog_snapshot_version
            : 'snap-20261110-v1';

        // 6. Map products payload conforming to product-catalog-response.json
        $productsPayload = $records->map(function (Product $product) use ($defaultTaxRate) {
            $taxRate = $product->taxRate ?? $defaultTaxRate;
            $taxRateId = $taxRate !== null ? $taxRate->public_id : $product->public_id; // Schema requires ULID
            $taxRateValue = $taxRate !== null ? sprintf('%.6f', (float) $taxRate->rate) : '0.000000';

            // Resolve barcode: primaryBarcode relation or product_barcodes first or barcode column
            $primaryBarcode = $product->primaryBarcode;
            $firstBarcode = $product->barcodes->first();
            $barcode = ($primaryBarcode !== null ? $primaryBarcode->barcode : null)
                ?? ($firstBarcode !== null ? $firstBarcode->barcode : null)
                ?? $product->barcode;

            // Resolve stock availability
            $availableQuantity = $product->track_stock
                ? sprintf('%.6f', (float) $product->balances->sum('quantity_base'))
                : '0.000000';

            // Base Unit object
            $baseUnit = $product->baseUnit;
            $baseUnitPayload = [
                'id' => $baseUnit->public_id,
                'code' => $baseUnit->code,
                'name' => $baseUnit->name(),
            ];

            // Resolve Default Packaging Unit
            /** @var ProductUnit|null $defaultProductUnit */
            $defaultProductUnit = $product->productUnits->where('is_default_sale', true)->first()
                ?? $product->productUnits->where('is_base', true)->first()
                ?? $product->productUnits->first();

            $unitPrice = sprintf('%.6f', (float) ($product->default_sale_price_base ?? 0));

            $defaultUnitId = ($defaultProductUnit !== null && $defaultProductUnit->unit !== null)
                ? $defaultProductUnit->unit->public_id
                : $baseUnit->public_id;
            $defaultUnitConversion = $defaultProductUnit !== null
                ? sprintf('%.6f', (float) $defaultProductUnit->conversion_to_base)
                : '1.000000';
            $defaultUnitSalePrice = $defaultProductUnit?->default_sale_price_base !== null
                ? sprintf('%.6f', (float) $defaultProductUnit->default_sale_price_base)
                : $unitPrice;

            $defaultUnitPayload = [
                'unit_id' => $defaultUnitId,
                'product_unit_id' => $defaultUnitId, // mapped to unit's public_id
                'conversion_to_base' => $defaultUnitConversion,
                'sale_price' => $defaultUnitSalePrice,
            ];

            // Packaging units array
            $unitsList = $product->productUnits->where('active', true)->map(function (ProductUnit $pu) {
                $unit = $pu->unit;
                $unitPublicId = $unit !== null ? $unit->public_id : (string) $pu->unit_id;
                $unitCode = $unit !== null ? $unit->code : 'UNIT';
                $unitName = $unit !== null ? $unit->name() : 'Unit';

                return [
                    'product_unit_id' => $unitPublicId,
                    'unit_id' => $unitPublicId,
                    'code' => $unitCode,
                    'name' => $unitName,
                    'conversion_to_base' => sprintf('%.6f', (float) $pu->conversion_to_base),
                    'is_base' => (bool) $pu->is_base,
                    'is_default_sale' => (bool) $pu->is_default_sale,
                    'sale_price' => $pu->default_sale_price_base !== null
                        ? sprintf('%.6f', (float) $pu->default_sale_price_base)
                        : null,
                ];
            })->values()->all();

            // If product has no packaging units in table, synthesise base unit packaging row
            if (empty($unitsList)) {
                $unitsList = [
                    [
                        'product_unit_id' => $baseUnit->public_id,
                        'unit_id' => $baseUnit->public_id,
                        'code' => $baseUnit->code,
                        'name' => $baseUnit->name(),
                        'conversion_to_base' => '1.000000',
                        'is_base' => true,
                        'is_default_sale' => true,
                        'sale_price' => $unitPrice,
                    ],
                ];
            }

            return [
                'id' => $product->public_id,
                'sku' => $product->sku ?? ('SKU-'.$product->public_id),
                'barcode' => $barcode,
                'name' => $product->displayName(),
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRateValue,
                'tax_rate_id' => $taxRateId,
                'is_tracked' => (bool) $product->track_stock,
                'available_quantity' => $availableQuantity,
                'base_unit' => $baseUnitPayload,
                'default_unit' => $defaultUnitPayload,
                'units' => $unitsList,
            ];
        })->values()->all();

        return response()->json([
            'company_id' => $company->public_id,
            'catalog_snapshot_version' => $catalogSnapshotVersion,
            'generated_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'products' => $productsPayload,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Check if actor has permission to read products.
     */
    private function canReadProducts(User $user, Company $company): bool
    {
        if ($user->hasRole('Owner') || $user->hasRole('Admin') || $user->hasRole('Manager')) {
            return true;
        }

        return $user->hasPermissionTo('inventory.stock.view') || $user->hasPermissionTo('sales.invoice.view');
    }
}
