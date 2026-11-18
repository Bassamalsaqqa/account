<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Policies\ProductPolicy;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Mobile\CatalogSnapshot;
use App\Support\Mobile\WireResponse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

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
                // 1. Price validation
                if ($product->default_sale_price_base !== null) {
                    try {
                        $baseUnitPrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                            ->toScale(6, RoundingMode::UNNECESSARY);
                    } catch (Throwable) {
                        throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                            code: 'CONTRACT_INCOMPATIBLE',
                            message: 'Product unit price cannot be represented in exact 6 decimal scale without rounding.',
                            status: 409
                        ));
                    }
                } else {
                    $baseUnitPrice = null;
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
                    } catch (Throwable) {
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
                    } elseif ($product->default_sale_price_base !== null) {
                        $defaultSalePrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                            ->multipliedBy(BigDecimal::of((string) $defaultPu->conversion_to_base))
                            ->toScale(6, RoundingMode::UNNECESSARY);
                    } else {
                        $defaultSalePrice = null;
                    }
                } catch (Throwable) {
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
                        } elseif ($product->default_sale_price_base !== null) {
                            $puSalePrice = (string) BigDecimal::of((string) $product->default_sale_price_base)
                                ->multipliedBy(BigDecimal::of((string) $pu->conversion_to_base))
                                ->toScale(6, RoundingMode::UNNECESSARY);
                        } else {
                            $puSalePrice = null;
                        }
                    } catch (Throwable) {
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
                } catch (Throwable) {
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
     * GET /api/mobile/v1/companies/{companyId}/products/{productId}
     */
    public function show(
        Request $request,
        string $companyId,
        string $productId,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $this->canReadProducts($user, $company)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required products permission.',
                status: 403
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $productId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested product was not found.',
                status: 404
            );
        }

        $product = Product::where('company_id', $company->id)
            ->where('public_id', $productId)
            ->with(['baseUnit', 'primaryBarcode', 'costState', 'productUnits.unit'])
            ->first();

        if ($product === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested product was not found.',
                status: 404
            );
        }

        app(CompanyContext::class)->setCompany($company, $user);
        $canViewCost = app(ProductPolicy::class)->viewCost($user);
        $cmu = $minorUnitsResolver->resolve((string) $company->base_currency_code, $company);

        $salePrice = $product->default_sale_price_base !== null
            ? (string) BigDecimal::of((string) $product->default_sale_price_base)->toScale(6, RoundingMode::UNNECESSARY)
            : null;

        $purchaseCost = ($canViewCost && $product->default_purchase_cost_base !== null)
            ? (string) BigDecimal::of((string) $product->default_purchase_cost_base)->toScale(6, RoundingMode::UNNECESSARY)
            : null;

        $stock = (string) BigDecimal::of((string) $product->totalStockBase())->toScale(6, RoundingMode::UNNECESSARY);
        $minStock = $product->minimum_stock_base !== null
            ? (string) BigDecimal::of((string) $product->minimum_stock_base)->toScale(6, RoundingMode::UNNECESSARY)
            : null;

        $units = $product->productUnits->map(function (ProductUnit $pu): array {
            $u = $pu->unit;

            return [
                'id' => $u ? (string) $u->public_id : (string) $pu->id,
                'unit_id' => $u ? (string) $u->public_id : null,
                'unit_code' => $u ? (string) $u->code : '',
                'unit_name' => $u ? (string) $u->displayName() : '',
                'conversion_factor' => (string) BigDecimal::of((string) ($pu->conversion_to_base ?? '1.000000'))->toScale(6, RoundingMode::HALF_UP),
                'sale_price' => $pu->default_sale_price_base !== null ? (string) BigDecimal::of((string) $pu->default_sale_price_base)->toScale(6, RoundingMode::UNNECESSARY) : null,
                'is_base' => (bool) $pu->is_base,
                'is_default_sale' => (bool) $pu->is_default_sale,
            ];
        })->values()->all();

        return response()->json([
            'id' => (string) $product->public_id,
            'sku' => (string) $product->sku,
            'name' => (string) $product->displayName(),
            'name_ar' => $product->name_ar,
            'name_en' => $product->name_en,
            'barcode' => $product->primaryBarcode !== null ? (string) $product->primaryBarcode->barcode : '',
            'currency' => (string) $company->base_currency_code,
            'currency_minor_units' => $cmu,
            'sale_price' => $salePrice,
            'price_ils' => $salePrice,
            'purchase_cost' => $purchaseCost,
            'cost_ils' => $purchaseCost,
            'base_unit' => $product->baseUnit ? [
                'id' => (string) $product->baseUnit->public_id,
                'code' => (string) $product->baseUnit->code,
                'name' => (string) $product->baseUnit->displayName(),
                'symbol' => $product->baseUnit->symbol_en ?? $product->baseUnit->symbol_ar,
                'allows_fraction' => (bool) $product->baseUnit->allows_fraction,
                'decimal_places' => (int) $product->baseUnit->decimal_places,
            ] : null,
            'units' => $units,
            'available_quantity' => $stock,
            'stock_count' => $stock,
            'min_stock_threshold' => $minStock,
            'is_stock_tracked' => (bool) $product->track_stock,
            'is_weighted' => false,
            'active' => (bool) $product->active,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/products
     */
    public function store(Request $request, string $companyId, ProductCatalogService $catalogService): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('inventory.product.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required inventory.product.manage permission.',
                status: 403
            );
        }

        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for this operation.',
                status: 422
            );
        }

        $payload = $request->json()->all();
        if (empty($payload['name_ar']) && empty($payload['name_en']) && empty($payload['name'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Product name is required.',
                status: 422
            );
        }

        if (empty($payload['name_ar']) && ! empty($payload['name'])) {
            $payload['name_ar'] = $payload['name'];
        }

        // Resolve default base unit if not given
        if (empty($payload['base_unit_id'])) {
            $unit = Unit::where('company_id', $company->id)->where('active', true)->first();
            if ($unit !== null) {
                $payload['base_unit_id'] = $unit->id;
            } else {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'No active measurement unit configured for company.',
                    status: 422
                );
            }
        }

        if (isset($payload['price_ils'])) {
            $payload['default_sale_price_base'] = (string) BigDecimal::of((string) $payload['price_ils'])->toScale(6);
        }
        if (isset($payload['cost_ils'])) {
            $payload['default_purchase_cost_base'] = (string) BigDecimal::of((string) $payload['cost_ils'])->toScale(6);
        }

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $barcode = isset($payload['barcode']) && is_string($payload['barcode']) && trim($payload['barcode']) !== ''
                ? trim($payload['barcode'])
                : null;
            unset($payload['barcode']);

            $product = $catalogService->createProduct($company, $payload, (int) $user->id);

            if ($barcode !== null) {
                ProductBarcode::create([
                    'company_id' => $company->id,
                    'product_id' => $product->id,
                    'unit_id' => $product->base_unit_id,
                    'barcode' => $barcode,
                    'type' => 'code128',
                    'is_primary' => true,
                ]);
            }
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to create product SKU: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $product->public_id,
            'name' => (string) $product->displayName(),
            'sku' => $product->sku,
            'price_ils' => $product->default_sale_price_base !== null ? (string) BigDecimal::of((string) $product->default_sale_price_base)->toScale(2, RoundingMode::HALF_UP) : null,
            'stock_count' => '0.0000',
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * PUT /api/mobile/v1/companies/{companyId}/products/{productId}
     */
    public function update(Request $request, string $companyId, string $productId, ProductCatalogService $catalogService): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('inventory.product.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required inventory.product.manage permission.',
                status: 403
            );
        }

        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for this operation.',
                status: 422
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $productId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested product was not found.',
                status: 404
            );
        }

        $product = Product::where('company_id', $company->id)
            ->where('public_id', $productId)
            ->first();

        if ($product === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested product was not found.',
                status: 404
            );
        }

        $payload = $request->json()->all();

        if (isset($payload['price_ils'])) {
            $payload['default_sale_price_base'] = (string) BigDecimal::of((string) $payload['price_ils'])->toScale(6);
        }
        if (isset($payload['cost_ils'])) {
            $payload['default_purchase_cost_base'] = (string) BigDecimal::of((string) $payload['cost_ils'])->toScale(6);
        }
        if (isset($payload['min_stock_threshold'])) {
            $payload['minimum_stock_base'] = (string) BigDecimal::of((string) $payload['min_stock_threshold'])->toScale(6);
        }

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $updated = $catalogService->updateProduct($product, $payload, (int) $user->id);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to update product: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $updated->public_id,
            'name' => (string) $updated->displayName(),
            'sku' => $updated->sku,
            'price_ils' => $updated->default_sale_price_base !== null ? (string) BigDecimal::of((string) $updated->default_sale_price_base)->toScale(2, RoundingMode::HALF_UP) : null,
        ], 200, [
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Check if actor has permission to read products.
     */
    private function canReadProducts(User $user, Company $company): bool
    {
        return app(ProductPolicy::class)->viewAny($user);
    }
}
