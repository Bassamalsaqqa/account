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

        // 2. Resolve bounded limit (absolute cap 30 per specification, OpenAPI 1..100)
        if ($request->has('limit')) {
            $rawLimit = $request->query('limit');
            if (is_array($rawLimit) || ! is_scalar($rawLimit) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', (string) $rawLimit) !== 1) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Limit must be a valid integer.',
                    status: 422
                );
            }
            $intLimit = (int) $rawLimit;
            if ($intLimit < 1 || $intLimit > 100) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Limit must be an integer between 1 and 100.',
                    status: 422
                );
            }
            $limit = min($intLimit, 30);
        } else {
            $configDefault = (int) config('mobile.max_page_limit', 30);
            if ($configDefault < 1) {
                $configDefault = 30;
            }
            $limit = min($configDefault, 30);
        }

        // 3. Resolve search (max 100 characters, literal prefix only - no leading wildcard)
        if ($request->has('search')) {
            $rawSearch = $request->query('search');
            if (is_array($rawSearch) || ! is_string($rawSearch)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Search query must be a valid string.',
                    status: 422
                );
            }
            if (mb_strlen($rawSearch) > 100) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Search parameter cannot exceed 100 characters.',
                    status: 422
                );
            }
            $search = trim($rawSearch) !== '' ? trim($rawSearch) : null;
        } else {
            $search = null;
        }

        // 4. Resolve cursor (must be 26-char Crockford ULID uppercase pattern)
        if ($request->has('cursor')) {
            $rawCursor = $request->query('cursor');
            if (is_array($rawCursor) || ! is_string($rawCursor)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Cursor must be a valid ULID string.',
                    status: 422
                );
            }
            if (strlen($rawCursor) > 255) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Cursor exceeds maximum length of 255 characters.',
                    status: 422
                );
            }
            if ($rawCursor !== '') {
                if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $rawCursor) !== 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: 'Cursor must match canonical Crockford ULID format.',
                        status: 422
                    );
                }
                $cursor = $rawCursor;
            } else {
                $cursor = null;
            }
        } else {
            $cursor = null;
        }

        // Bind page query, related DTO reads, and catalog fingerprint in ONE verified consistent read
        return CatalogSnapshot::withConsistentRead(function () use ($company, $limit, $search, $cursor): JsonResponse {
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
                    'baseUnit' => fn ($q) => $q->where('company_id', $company->id)->where('active', true),
                    'primaryBarcode' => fn ($q) => $q->where('company_id', $company->id),
                    'barcodes' => fn ($q) => $q->where('company_id', $company->id),
                    'balances' => fn ($q) => $q->where('company_id', $company->id),
                    'productUnits' => fn ($q) => $q->where('company_id', $company->id)
                        ->where('active', true)
                        ->with(['unit' => fn ($uq) => $uq->where('company_id', $company->id)->where('active', true)]),
                ]);

            if ($search !== null) {
                $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
                $query->where(function ($q) use ($escapedSearch, $company): void {
                    $q->where('name_ar', 'like', "{$escapedSearch}%")
                        ->orWhere('name_en', 'like', "{$escapedSearch}%")
                        ->orWhere('sku', 'like', "{$escapedSearch}%")
                        ->orWhereHas('barcodes', function ($bq) use ($escapedSearch, $company): void {
                            $bq->where('company_id', $company->id)
                                ->where('barcode', 'like', "{$escapedSearch}%");
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
                // 1. Price validation (Finding 2)
                if ($product->default_sale_price_base === null) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Unpriced products cannot be represented by Mobile V1.',
                        status: 409
                    ));
                }

                try {
                    $baseUnitPrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                        ->toScale(6, RoundingMode::UNNECESSARY);
                } catch (\Throwable) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Product unit price cannot be represented in exact 6 decimal scale without rounding.',
                        status: 409
                    ));
                }

                // 2. Base unit validation (Finding 3)
                $baseUnit = $product->baseUnit;
                if ($baseUnit === null || (int) $baseUnit->company_id !== (int) $company->id || ! $baseUnit->active) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Product declared base unit is missing, inactive, or belongs to another company.',
                        status: 409
                    ));
                }

                // 3. ProductUnits validation (Finding 3: Canonical SalesDocumentRules::selectedUnit invariants)
                $activeUnits = $product->productUnits->where('company_id', $company->id)->where('active', true);
                if ($activeUnits->isEmpty()) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Product has no active product units.',
                        status: 409
                    ));
                }

                // Verify each active product unit has an active, same-company unit and valid conversion factor
                foreach ($activeUnits as $pu) {
                    if ($pu->unit === null || (int) $pu->unit->company_id !== (int) $company->id || ! $pu->unit->active) {
                        throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                            code: 'CONTRACT_INCOMPATIBLE',
                            message: 'Product unit references missing, inactive, or foreign unit.',
                            status: 409
                        ));
                    }
                    try {
                        $conv = BigDecimal::of((string) $pu->conversion_to_base);
                        if (! $conv->isPositive()) {
                            throw new \InvalidArgumentException;
                        }
                        $conv->toScale(6, RoundingMode::UNNECESSARY);
                    } catch (\Throwable) {
                        throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                            code: 'CONTRACT_INCOMPATIBLE',
                            message: 'Unit conversion factor must be positive and representable in 6 decimal scale.',
                            status: 409
                        ));
                    }
                }

                // Exactly one base unit invariant matching products.base_unit_id with conversion 1.000000
                $baseRows = $activeUnits->where('is_base', true);
                if ($baseRows->count() !== 1) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Product must have exactly one active base ProductUnit row.',
                        status: 409
                    ));
                }
                /** @var ProductUnit $basePu */
                $basePu = $baseRows->first();
                if ((int) $basePu->unit_id !== (int) $product->base_unit_id) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Base ProductUnit unit_id does not match product base_unit_id.',
                        status: 409
                    ));
                }
                if (! BigDecimal::of((string) $basePu->conversion_to_base)->isEqualTo(BigDecimal::one())) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Base ProductUnit must have conversion factor of exactly 1.000000.',
                        status: 409
                    ));
                }

                // Exactly one active default sales unit invariant
                $defaultRows = $activeUnits->where('is_default_sale', true);
                if ($defaultRows->count() !== 1) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Exactly one active default sales unit is required.',
                        status: 409
                    ));
                }
                /** @var ProductUnit $defaultPu */
                $defaultPu = $defaultRows->first();

                // Default unit payload calculation
                try {
                    if ($defaultPu->default_sale_price_base !== null) {
                        $defaultSalePrice = (string) BigDecimal::of((string) $defaultPu->default_sale_price_base)
                            ->toScale(6, RoundingMode::UNNECESSARY);
                    } else {
                        $defaultSalePrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                            ->multipliedBy(BigDecimal::of((string) $defaultPu->conversion_to_base))
                            ->toScale(6, RoundingMode::UNNECESSARY);
                    }
                } catch (\Throwable) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Default sales unit price cannot be represented without rounding.',
                        status: 409
                    ));
                }

                $defaultUnitPayload = [
                    'unit_id' => $defaultPu->unit->public_id,
                    'product_unit_id' => $defaultPu->unit->public_id,
                    'conversion_to_base' => (string) BigDecimal::of((string) $defaultPu->conversion_to_base)->toScale(6, RoundingMode::UNNECESSARY),
                    'sale_price' => $defaultSalePrice,
                ];

                // Build units payload
                $unitsPayload = [];
                foreach ($activeUnits as $pu) {
                    try {
                        if ($pu->default_sale_price_base !== null) {
                            $puSalePrice = (string) BigDecimal::of((string) $pu->default_sale_price_base)->toScale(6, RoundingMode::UNNECESSARY);
                        } else {
                            $puSalePrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                                ->multipliedBy(BigDecimal::of((string) $pu->conversion_to_base))
                                ->toScale(6, RoundingMode::UNNECESSARY);
                        }
                    } catch (\Throwable) {
                        throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                            code: 'CONTRACT_INCOMPATIBLE',
                            message: 'Unit sale price cannot be represented without rounding.',
                            status: 409
                        ));
                    }

                    $unitsPayload[] = [
                        'product_unit_id' => $pu->unit->public_id,
                        'unit_id' => $pu->unit->public_id,
                        'code' => (string) $pu->unit->code,
                        'name' => (string) $pu->unit->displayName(),
                        'conversion_to_base' => (string) BigDecimal::of((string) $pu->conversion_to_base)->toScale(6, RoundingMode::UNNECESSARY),
                        'is_base' => (bool) $pu->is_base,
                        'is_default_sale' => (bool) $pu->is_default_sale,
                        'sale_price' => $puSalePrice,
                    ];
                }

                // Inventory balances & barcode (strictly company-scoped)
                $primaryBarcode = $product->primaryBarcode;
                $barcode = ($primaryBarcode !== null && (int) $primaryBarcode->company_id === (int) $company->id)
                    ? $primaryBarcode->barcode
                    : $product->barcodes->firstWhere('company_id', $company->id)?->barcode;

                $qty = BigDecimal::zero();
                if ($product->track_stock) {
                    foreach ($product->balances as $balance) {
                        if ((int) $balance->company_id === (int) $company->id) {
                            $qty = $qty->plus((string) $balance->quantity_base);
                        }
                    }
                }
                if ($qty->isNegative()) {
                    $qty = BigDecimal::zero();
                }
                try {
                    $availableQuantity = (string) $qty->toScale(6, RoundingMode::UNNECESSARY);
                } catch (\Throwable) {
                    throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                        code: 'CONTRACT_INCOMPATIBLE',
                        message: 'Available quantity cannot be represented without rounding.',
                        status: 409
                    ));
                }

                $productsPayload[] = [
                    'id' => $product->public_id,
                    'sku' => (string) $product->sku,
                    'barcode' => $barcode !== null && trim((string) $barcode) !== '' ? trim((string) $barcode) : null,
                    'name' => (string) $product->displayName(),
                    'unit_price' => $baseUnitPrice,
                    'tax_rate' => null,
                    'tax_rate_id' => null,
                    'is_tracked' => (bool) $product->track_stock,
                    'available_quantity' => $availableQuantity,
                    'base_unit' => [
                        'id' => $baseUnit->public_id,
                        'code' => (string) $baseUnit->code,
                        'name' => (string) $baseUnit->displayName(),
                    ],
                    'default_unit' => $defaultUnitPayload,
                    'units' => $unitsPayload,
                ];
            }

            $response = response()->json([
                'company_id' => $company->public_id,
                'catalog_snapshot_version' => CatalogSnapshot::computeVersion($company),
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
        });
    }

    /**
     * Check if actor has permission to read products.
     */
    private function canReadProducts(User $user, Company $company): bool
    {
        return app(ProductPolicy::class)->viewAny($user);
    }
}
