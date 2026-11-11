<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Support\Mobile\CatalogSnapshot;
use App\Support\Mobile\WireResponse;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Exceptions\HttpResponseException;
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
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

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

        // 3. Resolve search (max 100 characters, literal prefix only - no leading wildcard)
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
            ->select([
                'id',
                'public_id',
                'company_id',
                'sku',
                'name_ar',
                'name_en',
                'track_stock',
                'product_type',
                'default_sale_price_base',
                'base_unit_id',
                'updated_at',
            ])
            ->with([
                'baseUnit',
                'primaryBarcode',
                'barcodes',
                'balances',
                'productUnits' => fn ($q) => $q->where('company_id', $company->id)->where('active', true)->with('unit'),
            ]);

        if ($search !== null) {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escapedSearch): void {
                $q->where('name_ar', 'like', "{$escapedSearch}%")
                    ->orWhere('name_en', 'like', "{$escapedSearch}%")
                    ->orWhere('sku', 'like', "{$escapedSearch}%")
                    ->orWhereHas('barcodes', function ($bq) use ($escapedSearch): void {
                        $bq->where('barcode', 'like', "{$escapedSearch}%");
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

        $productsPayload = [];
        foreach ($records as $product) {
            $primaryBarcode = $product->primaryBarcode;
            $barcode = $primaryBarcode !== null ? $primaryBarcode->barcode : $product->barcodes->first()?->barcode;

            $qty = BigDecimal::zero();
            if ($product->track_stock) {
                foreach ($product->balances as $balance) {
                    $qty = $qty->plus((string) $balance->quantity_base);
                }
            }
            if ($qty->isNegative()) {
                $qty = BigDecimal::zero();
            }
            $availableQuantity = (string) $qty->toScale(6, RoundingMode::UNNECESSARY);

            $baseUnit = $product->baseUnit;

            $activeUnits = $product->productUnits->where('company_id', $company->id)->where('active', true);
            $unitsPayload = [];
            foreach ($activeUnits as $pu) {
                $unitOverride = $pu->default_sale_price_base;
                if ($unitOverride !== null) {
                    $puSalePrice = (string) BigDecimal::of((string) $unitOverride)->toScale(6, RoundingMode::UNNECESSARY);
                } elseif ($product->default_sale_price_base !== null) {
                    $puSalePrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                        ->multipliedBy(BigDecimal::of((string) $pu->conversion_to_base))
                        ->toScale(6, RoundingMode::UNNECESSARY);
                } else {
                    $puSalePrice = null;
                }

                $unitsPayload[] = [
                    'product_unit_id' => $pu->unit->public_id,
                    'unit_id' => $pu->unit->public_id,
                    'code' => mb_substr((string) $pu->unit->code, 0, 32),
                    'name' => mb_substr((string) $pu->unit->displayName(), 0, 128),
                    'conversion_to_base' => (string) BigDecimal::of((string) $pu->conversion_to_base)->toScale(6, RoundingMode::UNNECESSARY),
                    'is_base' => (bool) $pu->is_base,
                    'is_default_sale' => (bool) $pu->is_default_sale,
                    'sale_price' => $puSalePrice,
                ];
            }

            if (empty($unitsPayload)) {
                $unitsPayload = [
                    [
                        'product_unit_id' => $baseUnit->public_id,
                        'unit_id' => $baseUnit->public_id,
                        'code' => mb_substr((string) $baseUnit->code, 0, 32),
                        'name' => mb_substr((string) $baseUnit->displayName(), 0, 128),
                        'conversion_to_base' => '1.000000',
                        'is_base' => true,
                        'is_default_sale' => true,
                        'sale_price' => $product->default_sale_price_base !== null
                            ? (string) BigDecimal::of((string) $product->default_sale_price_base)->toScale(6, RoundingMode::UNNECESSARY)
                            : null,
                    ],
                ];
            }

            /** @var ProductUnit|null $defaultPu */
            $defaultPu = $activeUnits->firstWhere('is_default_sale', true)
                ?? $activeUnits->firstWhere('is_base', true)
                ?? $activeUnits->first();

            if ($defaultPu !== null) {
                $defaultOverride = $defaultPu->default_sale_price_base;
                if ($defaultOverride !== null) {
                    $defaultSalePrice = (string) BigDecimal::of((string) $defaultOverride)->toScale(6, RoundingMode::UNNECESSARY);
                } elseif ($product->default_sale_price_base !== null) {
                    $defaultSalePrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                        ->multipliedBy(BigDecimal::of((string) $defaultPu->conversion_to_base))
                        ->toScale(6, RoundingMode::UNNECESSARY);
                } else {
                    $defaultSalePrice = null;
                }

                $defaultUnitPayload = [
                    'unit_id' => $defaultPu->unit->public_id,
                    'product_unit_id' => $defaultPu->unit->public_id,
                    'conversion_to_base' => (string) BigDecimal::of((string) $defaultPu->conversion_to_base)->toScale(6, RoundingMode::UNNECESSARY),
                    'sale_price' => $defaultSalePrice,
                ];
            } else {
                $defaultUnitPayload = [
                    'unit_id' => $baseUnit->public_id,
                    'product_unit_id' => $baseUnit->public_id,
                    'conversion_to_base' => '1.000000',
                    'sale_price' => $product->default_sale_price_base !== null
                        ? (string) BigDecimal::of((string) $product->default_sale_price_base)->toScale(6, RoundingMode::UNNECESSARY)
                        : null,
                ];
            }

            $productsPayload[] = [
                'id' => $product->public_id,
                'sku' => mb_substr((string) $product->sku, 0, 128),
                'barcode' => $barcode !== null && trim((string) $barcode) !== '' ? mb_substr(trim((string) $barcode), 0, 128) : null,
                'name' => mb_substr((string) $product->displayName(), 0, 255),
                'unit_price' => $product->default_sale_price_base !== null
                    ? (string) BigDecimal::of((string) $product->default_sale_price_base)->toScale(6, RoundingMode::UNNECESSARY)
                    : '0.000000',
                'tax_rate' => null,
                'tax_rate_id' => null,
                'is_tracked' => (bool) $product->track_stock,
                'available_quantity' => $availableQuantity,
                'base_unit' => [
                    'id' => $baseUnit->public_id,
                    'code' => mb_substr((string) $baseUnit->code, 0, 32),
                    'name' => mb_substr((string) $baseUnit->displayName(), 0, 128),
                ],
                'default_unit' => $defaultUnitPayload,
                'units' => $unitsPayload,
            ];
        }

        $response = response()->json([
            'company_id' => $company->public_id,
            'catalog_snapshot_version' => CatalogSnapshot::version($company),
            'generated_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'products' => $productsPayload,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);

        if (! WireResponse::accepts(json_decode((string) $response->getContent()), 'product-catalog-response')) {
            throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                code: 'CONTRACT_INCOMPATIBLE',
                message: 'Existing data cannot be represented by Mobile V1.',
                status: 409
            ));
        }

        return $response;
    }

    /**
     * Check if actor has permission to read products.
     */
    private function canReadProducts(User $user, Company $company): bool
    {
        return app(ProductPolicy::class)->viewAny($user);
    }
}
