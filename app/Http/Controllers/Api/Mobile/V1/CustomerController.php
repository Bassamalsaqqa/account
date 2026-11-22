<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Sales\PostCustomerPaymentAction;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\NativeCommandReceipt;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Sales\CustomerCatalogService;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Mobile\Receipt\NativeWriteContextResolver;
use App\Support\Mobile\WireResponse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Throwable;

final class CustomerController extends Controller
{
    public const ALLOWED_CUSTOMER_FIELDS = [
        'name', 'name_ar', 'name_en', 'business_name_ar', 'business_name_en',
        'code', 'phone', 'whatsapp', 'email', 'tax_number',
        'address', 'address_ar', 'address_en', 'address_line_1_ar', 'address_line_1_en',
        'city_ar', 'city_en', 'notes', 'credit_limit', 'credit_limit_exact', 'credit_limit_exact6', 'preferred_locale',
        'default_currency_code', 'status', 'active',
    ];

    /**
     * GET /api/mobile/v1/companies/{companyId}/customers
     */
    public function index(Request $request, string $companyId): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        // 1. Authorize permission
        if (! $this->canReadCustomers($user, $company)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required customer directory permissions.',
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

        // 5. Query active customers
        $query = Customer::where('company_id', $company->id)
            ->where('status', 'active');

        if ($search !== null) {
            $escapedSearch = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
            $query->where(function ($q) use ($escapedSearch): void {
                $q->where('name_ar', 'like', "{$escapedSearch}%")
                    ->orWhere('name_en', 'like', "{$escapedSearch}%")
                    ->orWhere('code', 'like', "{$escapedSearch}%")
                    ->orWhere('phone', 'like', "{$escapedSearch}%");
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

        // Determine configured walk-in customer ID for this company
        /** @var CompanyMobileSetting|null $mobileSetting */
        $mobileSetting = CompanyMobileSetting::where('company_id', $company->id)->first();
        $walkInCustomerId = $mobileSetting?->walk_in_customer_id;

        // 6. Map customers payload conforming to customer-directory-response.json
        $customersPayload = $records->map(function (Customer $customer) use ($walkInCustomerId) {
            $isWalkIn = false;
            if ($walkInCustomerId !== null && (int) $customer->id === (int) $walkInCustomerId) {
                $isWalkIn = true;
            }

            $code = $customer->code !== null && trim((string) $customer->code) !== '' ? trim((string) $customer->code) : null;

            return [
                'id' => $customer->public_id,
                'code' => $code,
                'name' => (string) $customer->displayName(),
                'phone' => $customer->phone !== null && trim((string) $customer->phone) !== '' ? trim((string) $customer->phone) : null,
                'is_walk_in' => $isWalkIn,
            ];
        })->values()->all();

        $response = response()->json([
            'company_id' => $company->public_id,
            'generated_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
            'customers' => $customersPayload,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            'Pragma' => 'no-cache',
        ]);

        if (! WireResponse::accepts(json_decode((string) $response->getContent()), 'customer-directory-response')) {
            throw new HttpResponseException(MobileApiBoundary::buildErrorResponse(
                code: 'CONTRACT_INCOMPATIBLE',
                message: 'Existing data cannot be represented by Mobile V1.',
                status: 409
            ));
        }

        return $response;
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/customers/{customerId}
     */
    public function show(
        Request $request,
        string $companyId,
        string $customerId,
        CustomerBalanceQuery $balanceQuery,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $this->canReadCustomers($user, $company)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required customer directory permissions.',
                status: 403
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $customerId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested customer was not found.',
                status: 404
            );
        }

        $customer = Customer::where('company_id', $company->id)
            ->where('public_id', $customerId)
            ->first();

        if ($customer === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested customer was not found.',
                status: 404
            );
        }

        $cmu = $minorUnitsResolver->resolve((string) $company->base_currency_code, $company);
        $balance = null;
        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $balances = $balanceQuery->execute([(int) $customer->id]);
            $custBalances = $balances[(int) $customer->id] ?? [];
            $baseCurr = (string) $company->base_currency_code;
            if (isset($custBalances[$baseCurr])) {
                $out = BigDecimal::of($custBalances[$baseCurr]['outstanding']);
                $balance = (string) $out->toScale($cmu, RoundingMode::HALF_UP);
            }
        } catch (Throwable) {
            // Balance remains null when error/unauthorized - never invent zero
        }

        $creditLimit = $customer->credit_limit !== null
            ? (string) BigDecimal::of((string) $customer->credit_limit)->toScale($cmu, RoundingMode::HALF_UP)
            : null;
        $creditLimitExact = $customer->credit_limit !== null
            ? (string) BigDecimal::of((string) $customer->credit_limit)->toScale(6)
            : null;

        return response()->json([
            'id' => (string) $customer->public_id,
            'code' => $customer->code,
            'name' => (string) $customer->displayName(),
            'name_ar' => $customer->name_ar,
            'name_en' => $customer->name_en,
            'phone' => $customer->phone,
            'whatsapp' => $customer->whatsapp,
            'email' => $customer->email,
            'tax_number' => $customer->tax_number,
            'address' => $customer->address_ar ?? $customer->address_line_1_ar ?? $customer->address_en,
            'currency' => (string) $company->base_currency_code,
            'currency_minor_units' => $cmu,
            'balance' => $balance,
            'credit_limit' => $creditLimit,
            'credit_limit_exact' => $creditLimitExact,
            'credit_limit_exact6' => $creditLimitExact,
            'status' => $customer->status === 'active' ? 'active' : 'inactive',
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/customers
     */
    public function store(
        Request $request,
        string $companyId,
        CustomerCatalogService $catalogService,
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
        if (! $user->hasPermissionTo('customers.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required customers.manage permission.',
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
        $unknown = array_diff(array_keys($payload), self::ALLOWED_CUSTOMER_FIELDS);
        if (! empty($unknown)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Unrecognized field(s) in payload: '.implode(', ', $unknown),
                status: 422
            );
        }
        $hasDisplay = array_key_exists('credit_limit', $payload);
        $hasExact = array_key_exists('credit_limit_exact', $payload);
        $hasExact6 = array_key_exists('credit_limit_exact6', $payload);

        if ($hasDisplay && ($hasExact || $hasExact6)) {
            $exactRaw = $hasExact ? $payload['credit_limit_exact'] : $payload['credit_limit_exact6'];
            $displayRaw = $payload['credit_limit'];
            if ($displayRaw !== null && $exactRaw !== null && trim((string) $displayRaw) !== '' && trim((string) $exactRaw) !== '') {
                try {
                    $displayDec = BigDecimal::of(trim((string) $displayRaw));
                    $exactDec = BigDecimal::of(trim((string) $exactRaw));
                    if (! $displayDec->isEqualTo($exactDec)) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: 'Conflicting credit_limit and exact alias values supplied.',
                            status: 422
                        );
                    }
                } catch (Throwable) {
                    // Let subsequent validation report formatting errors
                }
            } elseif ($displayRaw !== $exactRaw) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Conflicting credit_limit and exact alias values supplied.',
                    status: 422
                );
            }
        }
        if (($hasExact || $hasExact6) && (! $hasDisplay || $payload['credit_limit'] === null)) {
            $payload['credit_limit'] = $hasExact ? $payload['credit_limit_exact'] : $payload['credit_limit_exact6'];
        }
        if (array_key_exists('credit_limit', $payload)) {
            $rawLimit = $payload['credit_limit'];
            if (is_bool($rawLimit) || is_float($rawLimit) || (! is_string($rawLimit) && ! is_int($rawLimit) && $rawLimit !== null)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Floating point and boolean values are not permitted for credit_limit. Use exact string or integer representation.',
                    status: 422
                );
            }
            if ($rawLimit !== null) {
                $trimmed = trim((string) $rawLimit);
                if ($trimmed !== '') {
                    if (! preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: 'credit_limit must be a valid non-negative number.',
                            status: 422
                        );
                    }
                    try {
                        $dec = BigDecimal::of($trimmed);
                        if ($dec->getScale() > 6) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: 'credit_limit exceeds maximum precision of 6 decimal places.',
                                status: 422
                            );
                        }
                    } catch (Throwable $e) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: 'credit_limit is invalid: '.$e->getMessage(),
                            status: 422
                        );
                    }
                }
            }
        }
        if (empty($payload['name_ar']) && empty($payload['name_en']) && empty($payload['name'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Customer name is required.',
                status: 422
            );
        }

        $normalizedPayload = $payload;
        if (empty($normalizedPayload['name_ar']) && ! empty($normalizedPayload['name'])) {
            $normalizedPayload['name_ar'] = $normalizedPayload['name'];
        }
        if (array_key_exists('address', $normalizedPayload) && ! array_key_exists('address_ar', $normalizedPayload)) {
            $normalizedPayload['address_ar'] = $normalizedPayload['address'];
        }
        if (array_key_exists('credit_limit', $normalizedPayload)) {
            if ($normalizedPayload['credit_limit'] === null || (is_string($normalizedPayload['credit_limit']) && trim($normalizedPayload['credit_limit']) === '')) {
                $normalizedPayload['credit_limit'] = null;
            } else {
                $normalizedPayload['credit_limit'] = (string) BigDecimal::of((string) $normalizedPayload['credit_limit'])->toScale(6);
            }
        }
        unset($normalizedPayload['credit_limit_exact'], $normalizedPayload['credit_limit_exact6']);
        ksort($normalizedPayload);

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'command' => 'customer_create',
            'payload' => $normalizedPayload,
        ];

        $legacyHashes = [];
        // 598c legacy hash: alias-expanded, sorted, with original unscaled credit_limit
        if (array_key_exists('credit_limit', $payload) && $payload['credit_limit'] !== null && (string) $payload['credit_limit'] !== ($normalizedPayload['credit_limit'] ?? null)) {
            $h598cPayload = $normalizedPayload;
            $h598cPayload['credit_limit'] = $payload['credit_limit'];
            ksort($h598cPayload);
            $h598cCanonical = $canonicalPayload;
            $h598cCanonical['payload'] = $h598cPayload;
            $legacyHashes[] = hash('sha256', json_encode($h598cCanonical, JSON_THROW_ON_ERROR));
        }
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
                commandType: 'customer_create',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: ['customers.manage'],
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $catalogService,
                    $normalizedPayload,
                    $minorUnitsResolver
                ): array {
                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

                    $customer = $catalogService->save($lockedCompany, $lockedActor, $normalizedPayload);

                    $cmu = $minorUnitsResolver->resolve((string) $lockedCompany->base_currency_code, $lockedCompany);
                    $creditLimit = $customer->credit_limit !== null
                        ? (string) BigDecimal::of((string) $customer->credit_limit)->toScale($cmu, RoundingMode::HALF_UP)
                        : null;
                    $creditLimitExact = $customer->credit_limit !== null
                        ? (string) BigDecimal::of((string) $customer->credit_limit)->toScale(6)
                        : null;

                    $snapshot = [
                        'id' => (string) $customer->public_id,
                        'code' => $customer->code,
                        'name' => (string) $customer->displayName(),
                        'name_ar' => $customer->name_ar,
                        'name_en' => $customer->name_en,
                        'phone' => $customer->phone,
                        'address' => $customer->address_ar ?? $customer->address_line_1_ar ?? $customer->address_en,
                        'balance' => null,
                        'credit_limit' => $creditLimit,
                        'credit_limit_exact' => $creditLimitExact,
                        'credit_limit_exact6' => $creditLimitExact,
                        'currency' => (string) $lockedCompany->base_currency_code,
                        'currency_minor_units' => $cmu,
                        'status' => $customer->status === 'active' ? 'active' : 'inactive',
                    ];

                    return [
                        'status' => 201,
                        'snapshot' => $snapshot,
                        'entity_type' => 'customer',
                        'entity_id' => (int) $customer->id,
                        'entity_public_id' => (string) $customer->public_id,
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
                message: 'Failed to create customer: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * PUT /api/mobile/v1/companies/{companyId}/customers/{customerId}
     */
    public function update(
        Request $request,
        string $companyId,
        string $customerId,
        CustomerCatalogService $catalogService,
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
        if (! $user->hasPermissionTo('customers.manage')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required customers.manage permission.',
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

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $customerId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested customer was not found.',
                status: 404
            );
        }

        $payload = $request->json()->all();
        $unknown = array_diff(array_keys($payload), self::ALLOWED_CUSTOMER_FIELDS);
        if (! empty($unknown)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Unrecognized field(s) in payload: '.implode(', ', $unknown),
                status: 422
            );
        }
        $hasDisplay = array_key_exists('credit_limit', $payload);
        $hasExact = array_key_exists('credit_limit_exact', $payload);
        $hasExact6 = array_key_exists('credit_limit_exact6', $payload);

        if ($hasDisplay && ($hasExact || $hasExact6)) {
            $exactRaw = $hasExact ? $payload['credit_limit_exact'] : $payload['credit_limit_exact6'];
            $displayRaw = $payload['credit_limit'];
            if ($displayRaw !== null && $exactRaw !== null && trim((string) $displayRaw) !== '' && trim((string) $exactRaw) !== '') {
                try {
                    $displayDec = BigDecimal::of(trim((string) $displayRaw));
                    $exactDec = BigDecimal::of(trim((string) $exactRaw));
                    if (! $displayDec->isEqualTo($exactDec)) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: 'Conflicting credit_limit and exact alias values supplied.',
                            status: 422
                        );
                    }
                } catch (Throwable) {
                    // Let subsequent validation report formatting errors
                }
            } elseif ($displayRaw !== $exactRaw) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Conflicting credit_limit and exact alias values supplied.',
                    status: 422
                );
            }
        }
        if (($hasExact || $hasExact6) && (! $hasDisplay || $payload['credit_limit'] === null)) {
            $payload['credit_limit'] = $hasExact ? $payload['credit_limit_exact'] : $payload['credit_limit_exact6'];
        }
        if (array_key_exists('credit_limit', $payload)) {
            $rawLimit = $payload['credit_limit'];
            if (is_bool($rawLimit) || is_float($rawLimit) || (! is_string($rawLimit) && ! is_int($rawLimit) && $rawLimit !== null)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Floating point and boolean values are not permitted for credit_limit. Use exact string or integer representation.',
                    status: 422
                );
            }
            if ($rawLimit !== null) {
                $trimmed = trim((string) $rawLimit);
                if ($trimmed !== '') {
                    if (! preg_match('/^\d+(\.\d+)?$/', $trimmed)) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: 'credit_limit must be a valid non-negative number.',
                            status: 422
                        );
                    }
                    try {
                        $dec = BigDecimal::of($trimmed);
                        if ($dec->getScale() > 6) {
                            return MobileApiBoundary::buildErrorResponse(
                                code: 'VALIDATION_FAILED',
                                message: 'credit_limit exceeds maximum precision of 6 decimal places.',
                                status: 422
                            );
                        }
                    } catch (Throwable $e) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: 'credit_limit is invalid: '.$e->getMessage(),
                            status: 422
                        );
                    }
                }
            }
        }

        $normalizedPayload = $payload;
        if (array_key_exists('name', $normalizedPayload) && ! array_key_exists('name_ar', $normalizedPayload)) {
            $normalizedPayload['name_ar'] = $normalizedPayload['name'];
        }
        if (array_key_exists('address', $normalizedPayload) && ! array_key_exists('address_ar', $normalizedPayload)) {
            $normalizedPayload['address_ar'] = $normalizedPayload['address'];
        }
        if (array_key_exists('credit_limit', $normalizedPayload)) {
            if ($normalizedPayload['credit_limit'] === null || (is_string($normalizedPayload['credit_limit']) && trim($normalizedPayload['credit_limit']) === '')) {
                $normalizedPayload['credit_limit'] = null;
            } else {
                $normalizedPayload['credit_limit'] = (string) BigDecimal::of((string) $normalizedPayload['credit_limit'])->toScale(6);
            }
        }
        unset($normalizedPayload['credit_limit_exact'], $normalizedPayload['credit_limit_exact6']);
        ksort($normalizedPayload);

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'company_id' => (string) $company->public_id,
            'customer_id' => $customerId,
            'command' => 'customer_update',
            'payload' => $normalizedPayload,
        ];

        $legacyHashes = [];
        // 598c legacy hash: alias-expanded, sorted, with original unscaled credit_limit
        if (array_key_exists('credit_limit', $payload) && $payload['credit_limit'] !== null && (string) $payload['credit_limit'] !== ($normalizedPayload['credit_limit'] ?? null)) {
            $h598cPayload = $normalizedPayload;
            $h598cPayload['credit_limit'] = $payload['credit_limit'];
            ksort($h598cPayload);
            $h598cCanonical = $canonicalPayload;
            $h598cCanonical['payload'] = $h598cPayload;
            $legacyHashes[] = hash('sha256', json_encode($h598cCanonical, JSON_THROW_ON_ERROR));
        }
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
                commandType: 'customer_update',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: ['customers.manage'],
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $customerId,
                    $catalogService,
                    $normalizedPayload,
                    $minorUnitsResolver
                ): array {
                    /** @var Customer|null $lockedCustomer */
                    $lockedCustomer = Customer::where('company_id', $lockedCompany->id)
                        ->where('public_id', $customerId)
                        ->lockForUpdate()
                        ->first();

                    if ($lockedCustomer === null) {
                        throw new InvalidArgumentException('The requested customer was not found.');
                    }

                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);
                    $updated = $catalogService->save($lockedCompany, $lockedActor, $normalizedPayload, (int) $lockedCustomer->id);

                    $cmu = $minorUnitsResolver->resolve((string) $lockedCompany->base_currency_code, $lockedCompany);
                    $creditLimit = $updated->credit_limit !== null
                        ? (string) BigDecimal::of((string) $updated->credit_limit)->toScale($cmu, RoundingMode::HALF_UP)
                        : null;
                    $creditLimitExact = $updated->credit_limit !== null
                        ? (string) BigDecimal::of((string) $updated->credit_limit)->toScale(6)
                        : null;

                    $snapshot = [
                        'id' => (string) $updated->public_id,
                        'code' => $updated->code,
                        'name' => (string) $updated->displayName(),
                        'name_ar' => $updated->name_ar,
                        'name_en' => $updated->name_en,
                        'phone' => $updated->phone,
                        'address' => $updated->address_ar ?? $updated->address_line_1_ar ?? $updated->address_en,
                        'balance' => null,
                        'credit_limit' => $creditLimit,
                        'credit_limit_exact' => $creditLimitExact,
                        'credit_limit_exact6' => $creditLimitExact,
                        'currency' => (string) $lockedCompany->base_currency_code,
                        'currency_minor_units' => $cmu,
                        'status' => $updated->status === 'active' ? 'active' : 'inactive',
                    ];

                    return [
                        'status' => 200,
                        'snapshot' => $snapshot,
                        'entity_type' => 'customer',
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
                message: 'Failed to update customer: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/customers/{customerId}/statement
     */
    public function statement(Request $request, string $companyId, string $customerId, CustomerStatementQuery $statementQuery): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $this->canReadCustomers($user, $company)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required customer permissions.',
                status: 403
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $customerId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested customer was not found.',
                status: 404
            );
        }

        $customer = Customer::where('company_id', $company->id)
            ->where('public_id', $customerId)
            ->first();

        if ($customer === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested customer was not found.',
                status: 404
            );
        }

        $fromDate = $request->query('from_date');
        $toDate = $request->query('to_date');

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $result = $statementQuery->execute($customer, is_string($fromDate) ? $fromDate : null, is_string($toDate) ? $toDate : null);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Statement generation failed: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'customer_id' => (string) $customer->public_id,
            'customer_name' => (string) $customer->displayName(),
            'from_date' => $result['from_date'],
            'to_date' => $result['to_date'],
            'currencies' => $result['currencies'],
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/customers/{customerId}/payments
     * Customer debt collection voucher
     */
    public function recordPayment(
        Request $request,
        string $companyId,
        string $customerId,
        PostCustomerPaymentAction $postPaymentAction,
        NativeCommandReceiptManager $receiptManager,
        NativeWriteContextResolver $contextResolver
    ): JsonResponse {
        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for customer payment recording.',
                status: 422
            );
        }

        if (preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $customerId) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'NOT_FOUND',
                message: 'The requested customer was not found.',
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
            $notes = null;
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
                $invId = $item['invoice_id'] ?? $item['sales_invoice_id'] ?? null;
                if (! is_string($invId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $invId) !== 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: 'Valid sales invoice public ID is required for each allocation.',
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
                    'invoice_id' => $invId,
                    'amount' => $itemAmount,
                ];
                if ($paymentAmount !== null) {
                    $canonicalAllocations[array_key_last($canonicalAllocations)]['payment_currency_amount'] = $paymentAmount;
                }
            }
            usort($canonicalAllocations, fn ($a, $b) => strcmp($a['invoice_id'], $b['invoice_id']));
        }

        $canonicalPayload = [
            'actor_id' => (int) $user->id,
            'device_id' => (string) $device->device_id,
            'customer_id' => $customerId,
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

        try {
            $result = $receiptManager->execute(
                companyId: (int) $company->id,
                actor: $user,
                token: $token,
                device: $device,
                commandType: 'customer_payment',
                idempotencyKey: $idempotencyKey,
                canonicalPayload: $canonicalPayload,
                requiredPermissions: ['money.receipt.create'],
                executor: function (Company $lockedCompany, User $lockedActor, MobileDevice $lockedDevice, MobileAccessToken $lockedToken) use (
                    $customerId,
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
                    /** @var Customer|null $customer */
                    $customer = Customer::where('company_id', $lockedCompany->id)
                        ->where('public_id', $customerId)
                        ->lockForUpdate()
                        ->first();

                    if ($customer === null) {
                        throw new InvalidArgumentException('The requested customer was not found.');
                    }

                    if (! $customer->active) {
                        throw new InvalidArgumentException("Customer [{$customer->public_id}] is inactive.");
                    }

                    $cashAccount = $contextResolver->resolveCashAccount(
                        $lockedCompany,
                        is_string($rawAccountId) ? $rawAccountId : null,
                        $paymentCurrency
                    );

                    $resolvedAllocations = [];
                    foreach ($canonicalAllocations as $alloc) {
                        /** @var SalesInvoice|null $invoice */
                        $invoice = SalesInvoice::where('company_id', $lockedCompany->id)
                            ->where('public_id', $alloc['invoice_id'])
                            ->lockForUpdate()
                            ->first();

                        if ($invoice === null || (int) $invoice->customer_id !== (int) $customer->id) {
                            throw new InvalidArgumentException("Sales invoice [{$alloc['invoice_id']}] not found or does not belong to target customer.");
                        }

                        if ($invoice->status !== SalesInvoice::STATUS_POSTED) {
                            throw new InvalidArgumentException("Sales invoice [{$alloc['invoice_id']}] must be in posted status.");
                        }

                        $resolvedAllocations[] = [
                            'sales_invoice_id' => (int) $invoice->id,
                            'allocated_amount' => $alloc['amount'],
                        ];
                        if (isset($alloc['payment_currency_amount'])) {
                            $resolvedAllocations[array_key_last($resolvedAllocations)]['payment_currency_amount'] = $alloc['payment_currency_amount'];
                        }
                    }

                    app(CompanyContext::class)->setCompany($lockedCompany, $lockedActor);

                    $paymentData = [
                        'customer_id' => (int) $customer->id,
                        'money_account_id' => (int) $cashAccount->id,
                        'currency_code' => $paymentCurrency,
                        'amount' => $amount,
                        'exchange_rate' => $paymentRate,
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
                        'receipt_id' => (string) $payment->public_id,
                        'payment_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'receipt_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'amount' => (string) BigDecimal::of((string) $payment->amount)->toScale($minorUnits, RoundingMode::HALF_UP),
                        'currency' => (string) $payment->currency_code,
                        'posted_at' => $payment->payment_date ? $payment->payment_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
                        'customer_id' => (string) $customer->public_id,
                        'success' => true,
                    ];

                    return [
                        'snapshot' => $snapshot,
                        'entity_type' => 'customer_payment',
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
                message: 'Customer debt payment failed: '.$e->getMessage(),
                status: 422
            );
        }
    }

    /**
     * Check if actor has permission to read customers.
     */
    private function canReadCustomers(User $user, Company $company): bool
    {
        setPermissionsTeamId($company->id);

        return $user->memberships()->where('company_id', $company->id)->where('status', 'active')->exists()
            && $user->hasPermissionTo('customers.view');
    }
}
