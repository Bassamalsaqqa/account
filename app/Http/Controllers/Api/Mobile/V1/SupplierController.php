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
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
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
        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $balances = $balanceQuery->execute($vendorIds);
        } catch (Throwable) {
            // Keep empty on error
        }

        $baseCurr = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurr, $company);

        $suppliers = $records->map(function (Vendor $v) use ($balances, $baseCurr, $cmu): array {
            $balData = $balances[$v->id] ?? null;
            $totalPayable = null;
            $totalAdvance = null;

            if ($balData !== null && isset($balData[$baseCurr])) {
                $b = BigDecimal::of($balData[$baseCurr]['balance']);
                if ($b->isPositive()) {
                    $totalPayable = (string) $b->toScale($cmu, RoundingMode::HALF_UP);
                } elseif ($b->isNegative()) {
                    $totalAdvance = (string) $b->abs()->toScale($cmu, RoundingMode::HALF_UP);
                } else {
                    $totalPayable = (string) $b->toScale($cmu, RoundingMode::HALF_UP);
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
        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $balances = $balanceQuery->execute([(int) $vendor->id]);
            $balData = $balances[(int) $vendor->id] ?? null;
            if ($balData !== null && isset($balData[$baseCurr])) {
                $b = BigDecimal::of($balData[$baseCurr]['balance']);
                if ($b->isPositive()) {
                    $totalPayable = (string) $b->toScale($cmu, RoundingMode::HALF_UP);
                } elseif ($b->isNegative()) {
                    $totalPayable = '0.'.str_repeat('0', $cmu);
                } else {
                    $totalPayable = (string) $b->toScale($cmu, RoundingMode::HALF_UP);
                }
            }
        } catch (Throwable) {
            // Balance remains null when error/unauthorized - never invent zero
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
        $unknown = array_diff(array_keys($payload), self::ALLOWED_SUPPLIER_FIELDS);
        if (! empty($unknown)) {
            $isLegacyCompanyNameOnly = count($unknown) === 1 && in_array('company_name', $unknown, true);
            if (! ($isLegacyCompanyNameOnly && $existingReceipt !== null)) {
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

        $hasLegacyCompanyName = array_key_exists('company_name', $payload);
        $normalizedPayload = $payload;
        if (empty($normalizedPayload['name_ar']) && ! empty($normalizedPayload['name'])) {
            $normalizedPayload['name_ar'] = $normalizedPayload['name'];
        }
        unset($normalizedPayload['company_name'], $normalizedPayload['address_line_1_ar'], $normalizedPayload['address_line_1_en']);
        if (array_key_exists('address', $normalizedPayload) && ! array_key_exists('address_ar', $normalizedPayload)) {
            $normalizedPayload['address_ar'] = $normalizedPayload['address'];
        }
        ksort($normalizedPayload);

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'command' => 'supplier_create',
            'payload' => $hasLegacyCompanyName ? $payload : $normalizedPayload,
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
            $rawCanonical = $canonicalPayload;
            $rawCanonical['payload'] = $payload;
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
        $unknown = array_diff(array_keys($payload), self::ALLOWED_SUPPLIER_FIELDS);
        if (! empty($unknown)) {
            $isLegacyCompanyNameOnly = count($unknown) === 1 && in_array('company_name', $unknown, true);
            if (! ($isLegacyCompanyNameOnly && $existingReceipt !== null)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Unrecognized field(s) in payload: '.implode(', ', $unknown),
                    status: 422
                );
            }
        }
        $hasLegacyCompanyName = array_key_exists('company_name', $payload);
        $normalizedPayload = $payload;
        if (array_key_exists('name', $normalizedPayload) && ! array_key_exists('name_ar', $normalizedPayload)) {
            $normalizedPayload['name_ar'] = $normalizedPayload['name'];
        }
        unset($normalizedPayload['company_name'], $normalizedPayload['address_line_1_ar'], $normalizedPayload['address_line_1_en']);
        if (array_key_exists('address', $normalizedPayload) && ! array_key_exists('address_ar', $normalizedPayload)) {
            $normalizedPayload['address_ar'] = $normalizedPayload['address'];
        }
        ksort($normalizedPayload);

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'supplier_id' => $supplierId,
            'command' => 'supplier_update',
            'payload' => $hasLegacyCompanyName ? $payload : $normalizedPayload,
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
            $rawCanonical = $canonicalPayload;
            $rawCanonical['payload'] = $payload;
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
     * POST /api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/payments
     * Supplier cash disbursement voucher
     */
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

        $paymentCurrency = $request->input('currency', (string) $company->base_currency_code);
        if (! is_string($paymentCurrency) || preg_match('/\A[A-Z]{3}\z/', $paymentCurrency) !== 1) {
            return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: 'Invalid payment currency.', status: 422);
        }

        $rawRate = $request->input('exchange_rate');
        if ($paymentCurrency !== (string) $company->base_currency_code) {
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
        }
        try {
            $paymentRate = NativeWriteContextResolver::validateDecimalString($rawRate ?? '1', 'exchange_rate', 10, true);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
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
        if (is_array($allocationsInput)) {
            foreach ($allocationsInput as $item) {
                if (! is_array($item)) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: 'Each allocation item must be an object.',
                        status: 422
                    );
                }
                $billId = $item['bill_id'] ?? $item['purchase_id'] ?? null;
                if (! is_string($billId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $billId) !== 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: 'Valid purchase bill public ID is required for each allocation.',
                        status: 422
                    );
                }
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
                $canonicalAllocations[] = [
                    'bill_id' => $billId,
                    'amount' => $itemAmount,
                ];
                if ($paymentAmount !== null) {
                    $canonicalAllocations[array_key_last($canonicalAllocations)]['payment_currency_amount'] = $paymentAmount;
                }
            }
            usort($canonicalAllocations, fn ($a, $b) => strcmp($a['bill_id'], $b['bill_id']));
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
                    foreach ($canonicalAllocations as $alloc) {
                        /** @var Purchase|null $purchase */
                        $purchase = Purchase::where('company_id', $lockedCompany->id)
                            ->where('public_id', $alloc['bill_id'])
                            ->lockForUpdate()
                            ->first();

                        if ($purchase === null || (int) $purchase->vendor_id !== (int) $vendor->id) {
                            throw new InvalidArgumentException("Purchase bill [{$alloc['bill_id']}] not found or does not belong to target supplier.");
                        }

                        if (! $purchase->isPosted()) {
                            throw new InvalidArgumentException("Purchase bill [{$alloc['bill_id']}] must be in posted status.");
                        }

                        $resolvedAllocations[] = [
                            'purchase_id' => (int) $purchase->id,
                            'allocated_amount' => $alloc['amount'],
                        ];
                        if (isset($alloc['payment_currency_amount'])) {
                            $resolvedAllocations[array_key_last($resolvedAllocations)]['payment_currency_amount'] = $alloc['payment_currency_amount'];
                        }
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

                    $snapshot = [
                        'id' => (string) $payment->public_id,
                        'voucher_id' => (string) $payment->public_id,
                        'payment_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'voucher_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'amount' => (string) BigDecimal::of((string) $payment->amount)->toScale($minorUnits, RoundingMode::HALF_UP),
                        'currency' => (string) $payment->currency_code,
                        'posted_at' => $payment->payment_date ? $payment->payment_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
                        'supplier_id' => (string) $vendor->public_id,
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
