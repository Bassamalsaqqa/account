<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\NativeCommandReceipt;
use App\Models\OpeningTradeItem;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Purchasing\VendorFinancialRead;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use App\Support\Mobile\SettlementTargetsReader;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class SupplierController extends Controller
{
    public const ALLOWED_SUPPLIER_FIELDS = [
        'name', 'name_ar', 'name_en', 'business_name_ar', 'business_name_en',
        'code', 'phone', 'whatsapp', 'email', 'tax_number',
        'address', 'address_ar', 'address_en',
        'city_ar', 'city_en', 'postal_code', 'notes', 'country_code', 'status', 'active',
        'preferred_locale', 'default_currency_code',
    ];

    /**
     * GET /api/mobile/v1/companies/{companyId}/suppliers
     */
    public function index(
        Request $request,
        string $companyId,
        VendorBalanceQuery $balanceQuery,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('vendors.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required vendors.view permission.',
                status: 403
            );
        }

        $limit = min((int) ($request->query('limit', 30)), 100);
        if ($limit < 1) {
            $limit = 30;
        }

        $query = Vendor::where('company_id', $company->id)
            ->where('status', 'active');

        $search = $request->query('search');
        if ($search !== null && is_string($search) && trim($search) !== '') {
            $term = trim($search);
            $query->where(function ($q) use ($term): void {
                $q->where('name_ar', 'like', "%{$term}%")
                    ->orWhere('name_en', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%");
            });
        }

        $records = $query->orderBy('id', 'asc')
            ->limit($limit + 1)
            ->get();

        $hasMore = $records->count() > $limit;
        if ($hasMore) {
            $records = $records->slice(0, $limit);
        }

        $vendorIds = $records->pluck('id')->all();
        $balances = [];
        $balancesLoaded = false;
        $canReadFinancial = app(VendorFinancialRead::class)->allows((int) $company->id);
        if ($canReadFinancial) {
            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $balances = $balanceQuery->execute($vendorIds);
                $balancesLoaded = true;
            } catch (Throwable) {
                // Keep empty on error
            }
        }

        $baseCurr = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurr, $company);

        $suppliers = $records->map(function (Vendor $v) use ($balances, $balancesLoaded, $canReadFinancial, $baseCurr, $cmu): array {
            $balData = $balances[$v->id] ?? null;
            $totalPayable = null;
            $totalAdvance = null;

            if ($canReadFinancial && $balancesLoaded) {
                $zeroFormatted = (string) BigDecimal::zero()->toScale($cmu);
                if ($balData !== null && isset($balData[$baseCurr])) {
                    $b = BigDecimal::of($balData[$baseCurr]['balance']);
                    if ($b->isPositive()) {
                        $totalPayable = (string) $b->toScale($cmu, RoundingMode::HALF_UP);
                        $totalAdvance = $zeroFormatted;
                    } elseif ($b->isNegative()) {
                        $totalPayable = $zeroFormatted;
                        $totalAdvance = (string) $b->abs()->toScale($cmu, RoundingMode::HALF_UP);
                    } else {
                        $totalPayable = $zeroFormatted;
                        $totalAdvance = $zeroFormatted;
                    }
                } else {
                    $totalPayable = $zeroFormatted;
                    $totalAdvance = $zeroFormatted;
                }
            }

            return [
                'id' => (string) $v->public_id,
                'code' => $v->code,
                'name' => (string) $v->displayName(),
                'name_ar' => $v->name_ar,
                'name_en' => $v->name_en,
                'phone' => $v->phone,
                'currency' => $baseCurr,
                'currency_minor_units' => $cmu,
                'balance_owed' => $totalPayable,
                'owed' => $totalPayable,
                'advances' => $totalAdvance,
            ];
        })->values()->all();

        return response()->json([
            'company_id' => $company->public_id,
            'count' => count($suppliers),
            'has_more' => $hasMore,
            'suppliers' => $suppliers,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/suppliers/{supplierId}
     */
    public function show(
        Request $request,
        string $companyId,
        string $supplierId,
        VendorBalanceQuery $balanceQuery,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('vendors.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required vendors.view permission.',
                status: 403
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $supplierId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested supplier was not found.',
                status: 404
            );
        }

        $vendor = Vendor::where('company_id', $company->id)
            ->where('public_id', $supplierId)
            ->first();

        if ($vendor === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested supplier was not found.',
                status: 404
            );
        }

        $baseCurr = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurr, $company);
        $totalPayable = null;
        $totalAdvance = null;
        $canReadFinancial = app(VendorFinancialRead::class)->allows((int) $company->id);

        if ($canReadFinancial) {
            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $balances = $balanceQuery->execute([(int) $vendor->id]);
                $balData = $balances[(int) $vendor->id] ?? null;
                $zeroFormatted = (string) BigDecimal::zero()->toScale($cmu);
                if ($balData !== null && isset($balData[$baseCurr])) {
                    $b = BigDecimal::of($balData[$baseCurr]['balance']);
                    if ($b->isPositive()) {
                        $totalPayable = (string) $b->toScale($cmu, RoundingMode::HALF_UP);
                        $totalAdvance = $zeroFormatted;
                    } elseif ($b->isNegative()) {
                        $totalPayable = $zeroFormatted;
                        $totalAdvance = (string) $b->abs()->toScale($cmu, RoundingMode::HALF_UP);
                    } else {
                        $totalPayable = $zeroFormatted;
                        $totalAdvance = $zeroFormatted;
                    }
                } else {
                    $totalPayable = $zeroFormatted;
                    $totalAdvance = $zeroFormatted;
                }
            } catch (Throwable) {
                // Balance remains null when error/unauthorized - never invent zero
            }
        }

        return response()->json([
            'id' => (string) $vendor->public_id,
            'code' => $vendor->code,
            'name' => (string) $vendor->displayName(),
            'name_ar' => $vendor->name_ar,
            'name_en' => $vendor->name_en,
            'phone' => $vendor->phone,
            'whatsapp' => $vendor->whatsapp,
            'email' => $vendor->email,
            'tax_number' => $vendor->tax_number,
            'address' => $vendor->address_ar ?? $vendor->address_en,
            'currency' => $baseCurr,
            'currency_minor_units' => $cmu,
            'balance_owed' => $totalPayable,
            'owed' => $totalPayable,
            'advances' => $totalAdvance,
            'status' => $vendor->status === 'active' ? 'active' : 'inactive',
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/suppliers
     */
    public function store(
        Request $request,
        string $companyId,
        VendorCatalogService $catalogService,
        NativeCommandReceiptManager $receiptManager,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        if ($company === null || $user === null || $token === null || $device === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        setPermissionsTeamId($company->id);
        if (! $user->hasPermissionTo('vendors.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required vendors.manage permission.',
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

        $existingReceipt = NativeCommandReceipt::where('company_id', $company->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $payload = $request->json()->all();

        $isExactHistoricalReplay = false;
        $replayCanonicalPayload = null;
        if ($existingReceipt !== null) {
            $h598cPayload = $payload;
            if (empty($h598cPayload['name_ar']) && ! empty($h598cPayload['name'])) {
                $h598cPayload['name_ar'] = $h598cPayload['name'];
            }
            if (array_key_exists('address', $h598cPayload) && ! array_key_exists('address_ar', $h598cPayload)) {
                $h598cPayload['address_ar'] = $h598cPayload['address'];
            }
            if (array_key_exists('company_name', $h598cPayload) && ! array_key_exists('business_name_ar', $h598cPayload)) {
                $h598cPayload['business_name_ar'] = $h598cPayload['company_name'];
            }
            ksort($h598cPayload);
            $h598cCanonical = [
                'actor_id' => (int) $user->id,
                'device_id' => (string) $device->device_id,
                'company_id' => (string) $company->public_id,
                'command' => 'supplier_create',
                'payload' => $h598cPayload,
            ];
            $h598cHash = hash('sha256', json_encode($h598cCanonical, JSON_THROW_ON_ERROR));

            $rawCanonical = [
                'actor_id' => (int) $user->id,
                'device_id' => (string) $device->device_id,
                'company_id' => (string) $company->public_id,
                'command' => 'supplier_create',
                'payload' => $payload,
            ];
            $rawHash = hash('sha256', json_encode($rawCanonical, JSON_THROW_ON_ERROR));

            if (hash_equals((string) $existingReceipt->request_hash, $h598cHash)) {
                $isExactHistoricalReplay = true;
                $replayCanonicalPayload = $h598cCanonical;
            } elseif (hash_equals((string) $existingReceipt->request_hash, $rawHash)) {
                $isExactHistoricalReplay = true;
                $replayCanonicalPayload = $rawCanonical;
            }
        }

        if (! $isExactHistoricalReplay) {
            $unknown = array_diff(array_keys($payload), self::ALLOWED_SUPPLIER_FIELDS);
            if (! empty($unknown)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Unrecognized field(s) in payload: '.implode(', ', $unknown),
                    status: 422
                );
            }
        }
        if (empty($payload['name_ar']) && empty($payload['name_en']) && empty($payload['name'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Supplier name is required.',
                status: 422
            );
        }

        $normalizedPayload = $payload;
        if (empty($normalizedPayload['name_ar']) && ! empty($normalizedPayload['name'])) {
            $normalizedPayload['name_ar'] = $normalizedPayload['name'];
        }
        unset($normalizedPayload['company_name'], $normalizedPayload['address_line_1_ar'], $normalizedPayload['address_line_1_en']);
        if (array_key_exists('address', $normalizedPayload) && ! array_key_exists('address_ar', $normalizedPayload)) {
            $normalizedPayload['address_ar'] = $normalizedPayload['address'];
        }
        ksort($normalizedPayload);

        $canonicalPayload = $replayCanonicalPayload ?? [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'command' => 'supplier_create',
            'payload' => $normalizedPayload,
        ];

        $legacyHashes = [];
        // Pinned 598c handler: alias-expanded, sorted, includes both company_name AND business_name_ar
        $h598cPayload = $payload;
        if (empty($h598cPayload['name_ar']) && ! empty($h598cPayload['name'])) {
            $h598cPayload['name_ar'] = $h598cPayload['name'];
        }
        if (array_key_exists('address', $h598cPayload) && ! array_key_exists('address_ar', $h598cPayload)) {
            $h598cPayload['address_ar'] = $h598cPayload['address'];
        }
        if (array_key_exists('company_name', $h598cPayload) && ! array_key_exists('business_name_ar', $h598cPayload)) {
            $h598cPayload['business_name_ar'] = $h598cPayload['company_name'];
        }
        ksort($h598cPayload);
        $h598cCanonical = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'command' => 'supplier_create',
            'payload' => $h598cPayload,
        ];
        $legacyHashes[] = hash('sha256', json_encode($h598cCanonical, JSON_THROW_ON_ERROR));

        if ($normalizedPayload !== $payload) {
            $rawCanonical = [
                'actor_id' => (int) $user->id,
                'device_id' => (string) $device->device_id,
                'company_id' => (string) $company->public_id,
                'command' => 'supplier_create',
                'payload' => $payload,
            ];
            $legacyHashes[] = hash('sha256', json_encode($rawCanonical, JSON_THROW_ON_ERROR));
        }

        try {
            $result = $receiptManager->execute(
                companyId: (int) $company->id,
                actor: $user,
                token: $token,
                device: $device,
                commandType: 'supplier_create',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: ['vendors.manage'],
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $catalogService,
                    $normalizedPayload,
                    $minorUnitsResolver
                ): array {
                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

                    $vendor = $catalogService->save($lockedCompany, $lockedActor, $normalizedPayload);

                    $baseCurr = (string) $lockedCompany->base_currency_code;
                    $cmu = $minorUnitsResolver->resolve($baseCurr, $lockedCompany);

                    $snapshot = [
                        'id' => (string) $vendor->public_id,
                        'code' => $vendor->code,
                        'name' => (string) $vendor->displayName(),
                        'name_ar' => $vendor->name_ar,
                        'name_en' => $vendor->name_en,
                        'phone' => $vendor->phone,
                        'address' => $vendor->address_ar ?? $vendor->address_en,
                        'balance' => null,
                        'balance_owed' => null,
                        'owed' => null,
                        'advances' => null,
                        'currency' => $baseCurr,
                        'currency_minor_units' => $cmu,
                        'status' => $vendor->status === 'active' ? 'active' : 'inactive',
                    ];

                    return [
                        'status' => 201,
                        'snapshot' => $snapshot,
                        'entity_type' => 'supplier',
                        'entity_id' => (int) $vendor->id,
                        'entity_public_id' => (string) $vendor->public_id,
                    ];
                },
                legacyHashes: $legacyHashes
            );

            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => $idempotencyKey,
            ];
            if ($result['outcome'] === 'replay') {
                $headers['X-Idempotent-Replay'] = 'true';
            }

            return response()->json($result['snapshot'], $result['status'], $headers);
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'IDEMPOTENCY_KEY_CONFLICT', message: $e->getMessage(), status: 409);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to create supplier: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * PUT /api/mobile/v1/companies/{companyId}/suppliers/{supplierId}
     */
    public function update(
        Request $request,
        string $companyId,
        string $supplierId,
        VendorCatalogService $catalogService,
        NativeCommandReceiptManager $receiptManager,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        if ($company === null || $user === null || $token === null || $device === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        setPermissionsTeamId($company->id);
        if (! $user->hasPermissionTo('vendors.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required vendors.manage permission.',
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

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $supplierId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested supplier was not found.',
                status: 404
            );
        }

        $existingReceipt = NativeCommandReceipt::where('company_id', $company->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $payload = $request->json()->all();

        $isExactHistoricalReplay = false;
        $replayCanonicalPayload = null;
        if ($existingReceipt !== null) {
            $h598cPayload = $payload;
            if (array_key_exists('name', $h598cPayload) && ! array_key_exists('name_ar', $h598cPayload)) {
                $h598cPayload['name_ar'] = $h598cPayload['name'];
            }
            if (array_key_exists('address', $h598cPayload) && ! array_key_exists('address_ar', $h598cPayload)) {
                $h598cPayload['address_ar'] = $h598cPayload['address'];
            }
            if (array_key_exists('company_name', $h598cPayload) && ! array_key_exists('business_name_ar', $h598cPayload)) {
                $h598cPayload['business_name_ar'] = $h598cPayload['company_name'];
            }
            ksort($h598cPayload);
            $h598cCanonical = [
                'actor_id' => (int) $user->id,
                'device_id' => (string) $device->device_id,
                'company_id' => (string) $company->public_id,
                'supplier_id' => $supplierId,
                'command' => 'supplier_update',
                'payload' => $h598cPayload,
            ];
            $h598cHash = hash('sha256', json_encode($h598cCanonical, JSON_THROW_ON_ERROR));

            $rawCanonical = [
                'actor_id' => (int) $user->id,
                'device_id' => (string) $device->device_id,
                'company_id' => (string) $company->public_id,
                'supplier_id' => $supplierId,
                'command' => 'supplier_update',
                'payload' => $payload,
            ];
            $rawHash = hash('sha256', json_encode($rawCanonical, JSON_THROW_ON_ERROR));

            if (hash_equals((string) $existingReceipt->request_hash, $h598cHash)) {
                $isExactHistoricalReplay = true;
                $replayCanonicalPayload = $h598cCanonical;
            } elseif (hash_equals((string) $existingReceipt->request_hash, $rawHash)) {
                $isExactHistoricalReplay = true;
                $replayCanonicalPayload = $rawCanonical;
            }
        }

        if (! $isExactHistoricalReplay) {
            $unknown = array_diff(array_keys($payload), self::ALLOWED_SUPPLIER_FIELDS);
            if (! empty($unknown)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Unrecognized field(s) in payload: '.implode(', ', $unknown),
                    status: 422
                );
            }
        }

        $normalizedPayload = $payload;
        if (array_key_exists('name', $normalizedPayload) && ! array_key_exists('name_ar', $normalizedPayload)) {
            $normalizedPayload['name_ar'] = $normalizedPayload['name'];
        }
        unset($normalizedPayload['company_name'], $normalizedPayload['address_line_1_ar'], $normalizedPayload['address_line_1_en']);
        if (array_key_exists('address', $normalizedPayload) && ! array_key_exists('address_ar', $normalizedPayload)) {
            $normalizedPayload['address_ar'] = $normalizedPayload['address'];
        }
        ksort($normalizedPayload);

        $canonicalPayload = $replayCanonicalPayload ?? [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'supplier_id' => $supplierId,
            'command' => 'supplier_update',
            'payload' => $normalizedPayload,
        ];

        $legacyHashes = [];
        // Pinned 598c handler: alias-expanded, sorted, includes both company_name AND business_name_ar
        $h598cPayload = $payload;
        if (array_key_exists('name', $h598cPayload) && ! array_key_exists('name_ar', $h598cPayload)) {
            $h598cPayload['name_ar'] = $h598cPayload['name'];
        }
        if (array_key_exists('address', $h598cPayload) && ! array_key_exists('address_ar', $h598cPayload)) {
            $h598cPayload['address_ar'] = $h598cPayload['address'];
        }
        if (array_key_exists('company_name', $h598cPayload) && ! array_key_exists('business_name_ar', $h598cPayload)) {
            $h598cPayload['business_name_ar'] = $h598cPayload['company_name'];
        }
        ksort($h598cPayload);
        $h598cCanonical = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'supplier_id' => $supplierId,
            'command' => 'supplier_update',
            'payload' => $h598cPayload,
        ];
        $legacyHashes[] = hash('sha256', json_encode($h598cCanonical, JSON_THROW_ON_ERROR));

        if ($normalizedPayload !== $payload) {
            $rawCanonical = [
                'actor_id' => (int) $user->id,
                'device_id' => (string) $device->device_id,
                'company_id' => (string) $company->public_id,
                'supplier_id' => $supplierId,
                'command' => 'supplier_update',
                'payload' => $payload,
            ];
            $legacyHashes[] = hash('sha256', json_encode($rawCanonical, JSON_THROW_ON_ERROR));
        }

        try {
            $result = $receiptManager->execute(
                companyId: (int) $company->id,
                actor: $user,
                token: $token,
                device: $device,
                commandType: 'supplier_update',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: ['vendors.manage'],
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $supplierId,
                    $catalogService,
                    $normalizedPayload,
                    $minorUnitsResolver
                ): array {
                    /** @var Vendor|null $lockedVendor */
                    $lockedVendor = Vendor::where('company_id', $lockedCompany->id)
                        ->where('public_id', $supplierId)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedVendor === null) {
                        throw new InvalidArgumentException('The requested supplier was not found.');
                    }

                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);
                    $updated = $catalogService->save($lockedCompany, $lockedActor, $normalizedPayload, (int) $lockedVendor->id);

                    $baseCurr = (string) $lockedCompany->base_currency_code;
                    $cmu = $minorUnitsResolver->resolve($baseCurr, $lockedCompany);

                    $snapshot = [
                        'id' => (string) $updated->public_id,
                        'code' => $updated->code,
                        'name' => (string) $updated->displayName(),
                        'name_ar' => $updated->name_ar,
                        'name_en' => $updated->name_en,
                        'phone' => $updated->phone,
                        'address' => $updated->address_ar ?? $updated->address_en,
                        'balance' => null,
                        'balance_owed' => null,
                        'owed' => null,
                        'advances' => null,
                        'currency' => $baseCurr,
                        'currency_minor_units' => $cmu,
                        'status' => $updated->status === 'active' ? 'active' : 'inactive',
                    ];

                    return [
                        'status' => 200,
                        'snapshot' => $snapshot,
                        'entity_type' => 'supplier',
                        'entity_id' => (int) $updated->id,
                        'entity_public_id' => (string) $updated->public_id,
                    ];
                },
                legacyHashes: $legacyHashes
            );

            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => $idempotencyKey,
            ];
            if ($result['outcome'] === 'replay') {
                $headers['X-Idempotent-Replay'] = 'true';
            }

            return response()->json($result['snapshot'], $result['status'], $headers);
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'IDEMPOTENCY_KEY_CONFLICT', message: $e->getMessage(), status: 409);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return MobileApiBoundary::buildErrorResponse(code: 'NOT_FOUND', message: $e->getMessage(), status: 404);
            }

            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to update supplier: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/settlement-targets
     * Authoritative outstanding settlement target reads (purchase bills and opening trade items)
     */
    public function settlementTargets(
        Request $request,
        string $companyId,
        string $supplierId,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $supplierId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested supplier was not found.',
                status: 404
            );
        }

        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');

        if ($company === null || $user === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        setPermissionsTeamId($company->id);
        if (! $user->hasPermissionTo('vendors.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required vendors.view permission.',
                status: 403
            );
        }

        // Cost redaction: financial read requires purchasing cost permission
        if (! app(VendorFinancialRead::class)->allows((int) $company->id)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Viewing supplier financial settlement targets requires purchasing cost permission.',
                status: 403
            );
        }

        $vendor = Vendor::where('company_id', $company->id)
            ->where('public_id', $supplierId)
            ->first();

        if ($vendor === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested supplier was not found.',
                status: 404
            );
        }

        return app(SettlementTargetsReader::class)->readSupplierTargets($request, $company, $vendor, $minorUnitsResolver);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/payments
     * Supplier cash disbursement voucher
     */
    public function recordPayment(
        Request $request,
        string $companyId,
        string $supplierId,
        PostVendorPaymentAction $postPaymentAction,
        NativeCommandReceiptManager $receiptManager,
        NativeWriteContextResolver $contextResolver
    ): JsonResponse {
        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for supplier payment recording.',
                status: 422
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $supplierId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested supplier was not found.',
                status: 404
            );
        }

        /** @var Company|null $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User|null $user */
        $user = $request->attributes->get('mobile_user');
        /** @var MobileAccessToken|null $token */
        $token = $request->attributes->get('mobile_token');
        /** @var MobileDevice|null $device */
        $device = $request->attributes->get('mobile_device');

        if ($company === null || $user === null || $token === null || $device === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        try {
            $amount = NativeWriteContextResolver::validateDecimalString(
                $request->input('amount'),
                'amount',
                6,
                true
            );
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        }

        $existingReceipt = NativeCommandReceipt::where('company_id', $company->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        $companyTimezone = (string) ($company->timezone ?: 'UTC');
        try {
            if ($request->has('payment_date')) {
                $paymentDate = NativeWriteContextResolver::validateDate($request->input('payment_date'), 'payment_date');
            } elseif ($existingReceipt !== null && isset($existingReceipt->payload['payment_date']) && is_string($existingReceipt->payload['payment_date'])) {
                $paymentDate = $existingReceipt->payload['payment_date'];
            } else {
                $paymentDate = Carbon::now($companyTimezone)->toDateString();
            }
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: $e->getMessage(),
                status: 422
            );
        }

        $paymentCurrency = $request->has('currency') && is_string($request->input('currency')) && trim($request->input('currency')) !== ''
            ? strtoupper(trim($request->input('currency')))
            : (string) $company->base_currency_code;
        if (preg_match('/\A[A-Z]{3}\z/', $paymentCurrency) !== 1) {
            return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: 'Invalid payment currency.', status: 422);
        }

        $rawRate = $request->input('exchange_rate');
        if ($paymentCurrency === (string) $company->base_currency_code) {
            $paymentRate = '1.0000000000';
            if ($rawRate !== null && trim((string) $rawRate) !== '') {
                try {
                    $parsedRate = NativeWriteContextResolver::validateDecimalString($rawRate, 'exchange_rate', 10, true);
                    if (BigDecimal::of($parsedRate)->compareTo(1) !== 0) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: 'Base currency exchange rate must equal exactly 1.0000000000.',
                            status: 422
                        );
                    }
                } catch (Throwable $e) {
                    return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
                }
            }
        } else {
            if (! $request->has('exchange_rate') || $rawRate === null || trim((string) $rawRate) === '') {
                if ($existingReceipt !== null) {
                    $rawRate = isset($existingReceipt->payload['exchange_rate']) ? (string) $existingReceipt->payload['exchange_rate'] : '1.0000000000';
                } else {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: 'Explicit exchange_rate is required for foreign currency payment.',
                        status: 422
                    );
                }
            }
            try {
                $paymentRate = NativeWriteContextResolver::validateDecimalString($rawRate, 'exchange_rate', 10, true);
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
            }
        }

        $rawAccountId = $request->input('cash_account_id', $request->input('money_account_id'));
        if ($rawAccountId === null && $existingReceipt !== null && array_key_exists('cash_account_id', $existingReceipt->payload)) {
            $rawAccountId = $existingReceipt->payload['cash_account_id'];
        }

        if ($request->has('notes') && is_string($request->input('notes'))) {
            $notes = trim($request->input('notes'));
        } elseif ($existingReceipt !== null && array_key_exists('notes', $existingReceipt->payload)) {
            $notes = $existingReceipt->payload['notes'];
        } else {
            $notes = 'سند صرف للمورد من تطبيق الجوال';
        }

        if ($request->has('reference') && is_string($request->input('reference'))) {
            $reference = trim($request->input('reference'));
        } elseif ($existingReceipt !== null && array_key_exists('reference', $existingReceipt->payload)) {
            $reference = $existingReceipt->payload['reference'];
        } else {
            $reference = null;
        }
        $allocationsInput = $request->input('allocations');

        if ($allocationsInput !== null && ! is_array($allocationsInput)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Allocations must be an array of allocation items.',
                status: 422
            );
        }

        // Canonical payload construction
        $canonicalAllocations = [];
        $legacyAllocations = [];
        $isPureLegacyWire = true;
        if (is_array($allocationsInput)) {
            $seenTargets = [];
            foreach ($allocationsInput as $index => $item) {
                if (! is_array($item)) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} must be an object.",
                        status: 422
                    );
                }

                // Intra-alias consistency: reject conflicting aliases for the same field
                $typeAliases = array_values(array_filter([
                    $item['target_type'] ?? null,
                    $item['kind'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if (count(array_unique(array_map(fn ($v) => strtolower(trim((string) $v)), $typeAliases))) > 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} has conflicting target_type/kind aliases.",
                        status: 422
                    );
                }

                $genericIdAliases = array_values(array_filter([
                    $item['target_id'] ?? null,
                    $item['id'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if (count(array_unique(array_map('strval', $genericIdAliases))) > 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} has conflicting target_id/id aliases.",
                        status: 422
                    );
                }

                $billAliases = array_values(array_filter([
                    $item['bill_id'] ?? null,
                    $item['purchase_id'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if (count(array_unique(array_map('strval', $billAliases))) > 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} has conflicting bill_id/purchase_id aliases.",
                        status: 422
                    );
                }

                $openingAliases = array_values(array_filter([
                    $item['opening_trade_item_id'] ?? null,
                    $item['opening_balance_id'] ?? null,
                    $item['opening_item_id'] ?? null,
                ], fn ($v) => $v !== null && $v !== ''));
                if (count(array_unique(array_map('strval', $openingAliases))) > 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} has conflicting opening_trade_item_id aliases.",
                        status: 422
                    );
                }

                $rawTargetType = ! empty($typeAliases) ? (string) $typeAliases[0] : null;
                $genericTargetId = ! empty($genericIdAliases) ? (string) $genericIdAliases[0] : null;
                $billKey = ! empty($billAliases) ? (string) $billAliases[0] : null;
                $openingKey = ! empty($openingAliases) ? (string) $openingAliases[0] : null;

                // Ambiguity detection: multiple contradictory identifier keys
                if ($billKey !== null && $openingKey !== null) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} is ambiguous: specifies both bill_id and opening_trade_item_id.",
                        status: 422
                    );
                }

                $targetType = null;
                $targetId = null;

                if ($rawTargetType !== null) {
                    $normType = strtolower(trim($rawTargetType));
                    $targetType = match ($normType) {
                        'purchase', 'bill', 'purchase_bill' => 'purchase',
                        'opening_trade_item', 'opening_balance', 'opening_item' => 'opening_trade_item',
                        default => null,
                    };

                    if ($targetType === null) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has invalid target_type [{$rawTargetType}]. Must be 'purchase' or 'opening_trade_item'.",
                            status: 422
                        );
                    }

                    if ($targetType === 'purchase') {
                        if ($openingKey !== null) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: "Allocation item at index {$index} has conflicting target_type 'purchase' and opening identifier.",
                                status: 422
                            );
                        }
                        if ($genericTargetId !== null && $billKey !== null && $genericTargetId !== $billKey) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: "Allocation item at index {$index} has conflicting target_id [{$genericTargetId}] and bill_id [{$billKey}].",
                                status: 422
                            );
                        }
                        if ($genericTargetId === null && $billKey === null) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: "Allocation item at index {$index} must identify a target (target_id or bill_id).",
                                status: 422
                            );
                        }
                        $targetId = $genericTargetId ?? $billKey;
                    } else {
                        // $targetType === 'opening_trade_item'
                        if ($billKey !== null) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: "Allocation item at index {$index} has conflicting target_type 'opening_trade_item' and purchase bill identifier.",
                                status: 422
                            );
                        }
                        if ($genericTargetId !== null && $openingKey !== null && $genericTargetId !== $openingKey) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: "Allocation item at index {$index} has conflicting target_id [{$genericTargetId}] and opening_trade_item_id [{$openingKey}].",
                                status: 422
                            );
                        }
                        if ($genericTargetId === null && $openingKey === null) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: "Allocation item at index {$index} must identify a target (target_id or opening_trade_item_id).",
                                status: 422
                            );
                        }
                        $targetId = $genericTargetId ?? $openingKey;
                    }
                    $isPureLegacyWire = false;
                } elseif ($openingKey !== null) {
                    $targetType = 'opening_trade_item';
                    if ($genericTargetId !== null && $genericTargetId !== $openingKey) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has conflicting target_id [{$genericTargetId}] and opening_trade_item_id [{$openingKey}].",
                            status: 422
                        );
                    }
                    $targetId = $openingKey;
                    $isPureLegacyWire = false;
                } elseif ($billKey !== null) {
                    $targetType = 'purchase';
                    if ($genericTargetId !== null && $genericTargetId !== $billKey) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has conflicting target_id [{$genericTargetId}] and bill_id [{$billKey}].",
                            status: 422
                        );
                    }
                    $targetId = $billKey;
                    if ($genericTargetId !== null) {
                        $isPureLegacyWire = false;
                    }
                } elseif ($genericTargetId !== null) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} specifies target_id without target_type.",
                        status: 422
                    );
                } else {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} must identify a target (target_type and target_id, or bill_id, or opening_trade_item_id).",
                        status: 422
                    );
                }

                if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $targetId) !== 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Valid target public ID (ULID) is required for allocation item at index {$index}.",
                        status: 422
                    );
                }

                // Duplicate target detection
                $compositeKey = "{$targetType}:{$targetId}";
                if (isset($seenTargets[$compositeKey])) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Duplicate allocation target [{$compositeKey}]. Each target may only be allocated once per payment.",
                        status: 422
                    );
                }
                $seenTargets[$compositeKey] = true;

                // Validate allocated amount
                try {
                    $itemAmount = NativeWriteContextResolver::validateDecimalString(
                        $item['document_currency_amount'] ?? $item['amount'] ?? $item['allocated_amount'] ?? null,
                        'allocated_amount',
                        6,
                        true
                    );
                } catch (Throwable $e) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: $e->getMessage(),
                        status: 422
                    );
                }
                $paymentAmount = null;
                if (array_key_exists('payment_currency_amount', $item) || array_key_exists('document_currency_amount', $item)) {
                    try {
                        $paymentAmount = NativeWriteContextResolver::validateDecimalString($item['payment_currency_amount'] ?? null, 'payment_currency_amount', 6, true);
                        NativeWriteContextResolver::validateDecimalString($item['document_currency_amount'] ?? null, 'document_currency_amount', 6, true);
                    } catch (Throwable $e) {
                        return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
                    }
                }

                $allocRow = [
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'amount' => $itemAmount,
                ];
                if ($targetType === 'opening_trade_item') {
                    $allocRow['opening_trade_item_id'] = $targetId;
                } else {
                    $allocRow['bill_id'] = $targetId;
                }
                if ($paymentAmount !== null) {
                    $allocRow['payment_currency_amount'] = $paymentAmount;
                }
                $canonicalAllocations[] = $allocRow;

                if ($targetType === 'purchase') {
                    $legacyAllocRow = [
                        'bill_id' => $targetId,
                        'amount' => $itemAmount,
                    ];
                    if ($paymentAmount !== null) {
                        $legacyAllocRow['payment_currency_amount'] = $paymentAmount;
                    }
                    $legacyAllocations[] = $legacyAllocRow;
                }
            }

            usort($canonicalAllocations, fn ($a, $b) => strcmp($a['target_type'].':'.$a['target_id'], $b['target_type'].':'.$b['target_id']));
            if (! empty($legacyAllocations)) {
                usort($legacyAllocations, fn ($a, $b) => strcmp($a['bill_id'], $b['bill_id']));
            }
        }

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'supplier_id' => $supplierId,
            'cash_account_id' => is_string($rawAccountId) ? trim($rawAccountId) : null,
            'amount' => (string) BigDecimal::of($amount)->toScale(6, RoundingMode::HALF_UP),
            'currency' => $paymentCurrency,
            'payment_date' => $paymentDate,
            'reference' => $reference,
            'notes' => $notes,
            'allocations' => $canonicalAllocations,
        ];

        if ($request->has('exchange_rate') || $request->has('currency') || $existingReceipt !== null) {
            $canonicalPayload['exchange_rate'] = (string) BigDecimal::of($paymentRate)->toScale(10);
        }

        $legacyHashes = [];
        if ($paymentCurrency === (string) $company->base_currency_code && BigDecimal::of($paymentRate)->compareTo(1) === 0) {
            if (isset($canonicalPayload['exchange_rate'])) {
                $legacyPayload = $canonicalPayload;
                unset($legacyPayload['exchange_rate']);
                $legacyHashes[] = hash('sha256', json_encode($legacyPayload, JSON_THROW_ON_ERROR));
            } else {
                $legacyPayload = $canonicalPayload;
                $legacyPayload['exchange_rate'] = '1.0000000000';
                $legacyHashes[] = hash('sha256', json_encode($legacyPayload, JSON_THROW_ON_ERROR));
            }
        }
        $aliasPayload = $canonicalPayload;
        $aliasPayload['vendor_id'] = $aliasPayload['supplier_id'];
        unset($aliasPayload['supplier_id']);
        $legacyHashes[] = hash('sha256', json_encode($aliasPayload, JSON_THROW_ON_ERROR));

        if ($paymentCurrency === (string) $company->base_currency_code && BigDecimal::of($paymentRate)->compareTo(1) === 0) {
            if (isset($aliasPayload['exchange_rate'])) {
                $aliasRatePayload = $aliasPayload;
                unset($aliasRatePayload['exchange_rate']);
                $legacyHashes[] = hash('sha256', json_encode($aliasRatePayload, JSON_THROW_ON_ERROR));
            } else {
                $aliasRatePayload = $aliasPayload;
                $aliasRatePayload['exchange_rate'] = '1.0000000000';
                $legacyHashes[] = hash('sha256', json_encode($aliasRatePayload, JSON_THROW_ON_ERROR));
            }
        }

        if ($isPureLegacyWire && ! empty($legacyAllocations)) {
            $legacyAllocPayload = $canonicalPayload;
            $legacyAllocPayload['allocations'] = $legacyAllocations;
            $legacyHashes[] = hash('sha256', json_encode($legacyAllocPayload, JSON_THROW_ON_ERROR));

            $aliasAllocPayload = $legacyAllocPayload;
            $aliasAllocPayload['vendor_id'] = $aliasAllocPayload['supplier_id'];
            unset($aliasAllocPayload['supplier_id']);
            $legacyHashes[] = hash('sha256', json_encode($aliasAllocPayload, JSON_THROW_ON_ERROR));

            if ($paymentCurrency === (string) $company->base_currency_code && BigDecimal::of($paymentRate)->compareTo(1) === 0) {
                if (isset($legacyAllocPayload['exchange_rate'])) {
                    $comboPayload = $legacyAllocPayload;
                    unset($comboPayload['exchange_rate']);
                    $legacyHashes[] = hash('sha256', json_encode($comboPayload, JSON_THROW_ON_ERROR));

                    $comboAliasPayload = $aliasAllocPayload;
                    unset($comboAliasPayload['exchange_rate']);
                    $legacyHashes[] = hash('sha256', json_encode($comboAliasPayload, JSON_THROW_ON_ERROR));
                } else {
                    $comboPayload = $legacyAllocPayload;
                    $comboPayload['exchange_rate'] = '1.0000000000';
                    $legacyHashes[] = hash('sha256', json_encode($comboPayload, JSON_THROW_ON_ERROR));

                    $comboAliasPayload = $aliasAllocPayload;
                    $comboAliasPayload['exchange_rate'] = '1.0000000000';
                    $legacyHashes[] = hash('sha256', json_encode($comboAliasPayload, JSON_THROW_ON_ERROR));
                }
            }
        }

        try {
            $result = $receiptManager->execute(
                companyId: (int) $company->id,
                actor: $user,
                token: $token,
                device: $device,
                commandType: 'supplier_payment',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: ['money.vendor_payment.create', 'purchasing.cost.view'],
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $supplierId,
                    $amount,
                    $paymentDate,
                    $paymentCurrency,
                    $paymentRate,
                    $rawRate,
                    $request,
                    $rawAccountId,
                    $notes,
                    $reference,
                    $canonicalAllocations,
                    $idempotencyKey,
                    $postPaymentAction,
                    $contextResolver
                ): array {
                    if ($paymentCurrency !== (string) $lockedCompany->base_currency_code && (! $request->has('exchange_rate') || $rawRate === null || trim((string) $rawRate) === '')) {
                        throw new InvalidArgumentException('Explicit exchange_rate is required for foreign currency payment.');
                    }
                    /** @var Vendor|null $vendor */
                    $vendor = Vendor::where('company_id', $lockedCompany->id)
                        ->where('public_id', $supplierId)
                        ->lockForUpdate()
                        ->first();

                    if ($vendor === null) {
                        throw new InvalidArgumentException('The requested supplier was not found.');
                    }

                    if ($vendor->status !== 'active') {
                        throw new InvalidArgumentException("Supplier [{$vendor->public_id}] is inactive.");
                    }

                    $cashAccount = $contextResolver->resolveCashAccount(
                        $lockedCompany,
                        is_string($rawAccountId) ? $rawAccountId : null,
                        $paymentCurrency
                    );

                    $resolvedAllocations = [];
                    $totalAllocatedPayment = BigDecimal::zero();

                    foreach ($canonicalAllocations as $alloc) {
                        if ($alloc['target_type'] === 'opening_trade_item') {
                            /** @var OpeningTradeItem|null $openingItem */
                            $openingItem = OpeningTradeItem::where('company_id', $lockedCompany->id)
                                ->where('public_id', $alloc['target_id'])
                                ->lockForUpdate()
                                ->first();

                            if ($openingItem === null || (int) $openingItem->vendor_id !== (int) $vendor->id || $openingItem->side !== OpeningTradeItem::SIDE_VENDOR) {
                                throw new InvalidArgumentException("Opening trade item [{$alloc['target_id']}] not found or does not belong to target supplier.");
                            }

                            if (! $openingItem->isPosted() || $openingItem->isReversed()) {
                                throw new InvalidArgumentException("Opening trade item [{$alloc['target_id']}] must be in posted status.");
                            }

                            $remaining = $openingItem->calculateOutstanding();
                            if (BigDecimal::of($alloc['amount'])->isGreaterThan($remaining)) {
                                throw new InvalidArgumentException("Allocation [{$alloc['amount']}] exceeds unpaid balance [{$remaining}] for opening trade item [{$alloc['target_id']}].");
                            }

                            $resolvedRow = [
                                'opening_trade_item_id' => (int) $openingItem->id,
                                'allocated_amount' => $alloc['amount'],
                            ];
                            if (isset($alloc['payment_currency_amount'])) {
                                $resolvedRow['payment_currency_amount'] = $alloc['payment_currency_amount'];
                                $totalAllocatedPayment = $totalAllocatedPayment->plus(BigDecimal::of($alloc['payment_currency_amount']));
                            } else {
                                $totalAllocatedPayment = $totalAllocatedPayment->plus(BigDecimal::of($alloc['amount']));
                            }
                            $resolvedAllocations[] = $resolvedRow;
                        } else {
                            /** @var Purchase|null $purchase */
                            $purchase = Purchase::where('company_id', $lockedCompany->id)
                                ->where('public_id', $alloc['target_id'])
                                ->lockForUpdate()
                                ->first();

                            if ($purchase === null || (int) $purchase->vendor_id !== (int) $vendor->id) {
                                throw new InvalidArgumentException("Purchase bill [{$alloc['target_id']}] not found or does not belong to target supplier.");
                            }

                            if (! $purchase->isPosted()) {
                                throw new InvalidArgumentException("Purchase bill [{$alloc['target_id']}] must be in posted status.");
                            }

                            $remaining = $purchase->calculateOutstanding();
                            if (BigDecimal::of($alloc['amount'])->isGreaterThan($remaining)) {
                                throw new InvalidArgumentException("Allocation [{$alloc['amount']}] exceeds unpaid balance [{$remaining}] for purchase bill [{$alloc['target_id']}].");
                            }

                            $resolvedRow = [
                                'purchase_id' => (int) $purchase->id,
                                'allocated_amount' => $alloc['amount'],
                            ];
                            if (isset($alloc['payment_currency_amount'])) {
                                $resolvedRow['payment_currency_amount'] = $alloc['payment_currency_amount'];
                                $totalAllocatedPayment = $totalAllocatedPayment->plus(BigDecimal::of($alloc['payment_currency_amount']));
                            } else {
                                $totalAllocatedPayment = $totalAllocatedPayment->plus(BigDecimal::of($alloc['amount']));
                            }
                            $resolvedAllocations[] = $resolvedRow;
                        }
                    }

                    if ($totalAllocatedPayment->isGreaterThan(BigDecimal::of($amount))) {
                        throw new InvalidArgumentException("Total allocated amount [{$totalAllocatedPayment}] exceeds payment amount [{$amount}].");
                    }

                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

                    $paymentData = [
                        'vendor_id' => (int) $vendor->id,
                        'money_account_id' => (int) $cashAccount->id,
                        'currency_code' => $paymentCurrency,
                        'exchange_rate' => $paymentRate,
                        'amount' => $amount,
                        'payment_date' => $paymentDate,
                        'payment_method' => 'cash',
                        'notes' => $notes,
                        'reference_number' => $reference,
                        'idempotency_key' => $idempotencyKey,
                        'allocations' => $resolvedAllocations,
                    ];

                    $payment = $postPaymentAction->execute($lockedCompany, $lockedActor, $paymentData);

                    $minorUnits = app(CurrencyMinorUnitsResolver::class)->resolveForCompany(
                        (int) $lockedCompany->id,
                        (string) $payment->currency_code
                    );

                    $isAdvance = empty($resolvedAllocations);
                    $unallocated = BigDecimal::of((string) ($payment->unallocated_amount ?? $payment->amount));
                    $allocated = BigDecimal::of((string) $payment->amount)->minus($unallocated);
                    if ($allocated->isNegative()) {
                        $allocated = BigDecimal::zero();
                    }

                    $snapshot = [
                        'id' => (string) $payment->public_id,
                        'voucher_id' => (string) $payment->public_id,
                        'payment_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'voucher_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'amount' => (string) BigDecimal::of((string) $payment->amount)->toScale($minorUnits, RoundingMode::HALF_UP),
                        'amount_exact' => (string) BigDecimal::of((string) $payment->amount)->toScale(6),
                        'currency' => (string) $payment->currency_code,
                        'posted_at' => $payment->payment_date ? $payment->payment_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
                        'supplier_id' => (string) $vendor->public_id,
                        'is_advance' => $isAdvance,
                        'unallocated_amount' => (string) $unallocated->toScale($minorUnits, RoundingMode::HALF_UP),
                        'unallocated_amount_exact' => (string) $unallocated->toScale(6),
                        'allocated_amount' => (string) $allocated->toScale($minorUnits, RoundingMode::HALF_UP),
                        'allocated_amount_exact' => (string) $allocated->toScale(6),
                        'allocations' => array_map(function (array $alloc) use ($minorUnits): array {
                            return [
                                'target_type' => $alloc['target_type'],
                                'target_id' => $alloc['target_id'],
                                'amount' => (string) BigDecimal::of($alloc['amount'])->toScale($minorUnits, RoundingMode::HALF_UP),
                                'allocated_amount' => (string) BigDecimal::of($alloc['amount'])->toScale($minorUnits, RoundingMode::HALF_UP),
                                'amount_exact' => (string) BigDecimal::of($alloc['amount'])->toScale(6),
                                'allocated_amount_exact' => (string) BigDecimal::of($alloc['amount'])->toScale(6),
                            ];
                        }, $canonicalAllocations),
                        'success' => true,
                    ];

                    return [
                        'snapshot' => $snapshot,
                        'entity_type' => 'vendor_payment',
                        'entity_id' => (int) $payment->id,
                        'entity_public_id' => (string) $payment->public_id,
                    ];
                },
                legacyHashes: $legacyHashes
            );

            $headers = [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                'Idempotency-Key' => $idempotencyKey,
            ];
            if ($result['outcome'] === 'replay') {
                $headers['X-Idempotent-Replay'] = 'true';
            }

            return response()->json($result['snapshot'], $result['status'], $headers);
        } catch (AuthenticationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'UNAUTHENTICATED', message: $e->getMessage(), status: 401);
        } catch (AuthorizationException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'FORBIDDEN', message: $e->getMessage(), status: 403);
        } catch (IdempotencyConflictException $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'IDEMPOTENCY_KEY_CONFLICT', message: $e->getMessage(), status: 409);
        } catch (Throwable $e) {
            if (str_contains($e->getMessage(), 'not found')) {
                return MobileApiBoundary::buildErrorResponse(code: 'NOT_FOUND', message: $e->getMessage(), status: 404);
            }

            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Supplier payment disbursement failed: '.$e->getMessage(),
                status: 422
            );
        }
    }
}
