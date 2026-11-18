<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Sales\PostCustomerPaymentAction;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Sales\CustomerCatalogService;
use App\Support\Mobile\WireResponse;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class CustomerController extends Controller
{
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
            'status' => $customer->status === 'active' ? 'active' : 'inactive',
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/customers
     */
    public function store(Request $request, string $companyId, CustomerCatalogService $catalogService): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

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
        if (empty($payload['name_ar']) && empty($payload['name_en']) && empty($payload['name'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Customer name is required.',
                status: 422
            );
        }

        if (empty($payload['name_ar']) && ! empty($payload['name'])) {
            $payload['name_ar'] = $payload['name'];
        }

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $customer = $catalogService->save($company, $user, $payload);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to create customer: '.$e->getMessage(),
                status: 422
            );
        }

        $creditLimit = $customer->credit_limit !== null
            ? (string) BigDecimal::of((string) $customer->credit_limit)->toScale(2, RoundingMode::HALF_UP)
            : null;

        return response()->json([
            'id' => (string) $customer->public_id,
            'name' => (string) $customer->displayName(),
            'code' => $customer->code,
            'phone' => $customer->phone,
            'balance' => null,
            'credit_limit' => $creditLimit,
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * PUT /api/mobile/v1/companies/{companyId}/customers/{customerId}
     */
    public function update(Request $request, string $companyId, string $customerId, CustomerCatalogService $catalogService): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

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

        $payload = $request->json()->all();

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $updated = $catalogService->save($company, $user, $payload, (int) $customer->id);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to update customer: '.$e->getMessage(),
                status: 422
            );
        }

        $creditLimit = $updated->credit_limit !== null
            ? (string) BigDecimal::of((string) $updated->credit_limit)->toScale(2, RoundingMode::HALF_UP)
            : null;

        return response()->json([
            'id' => (string) $updated->public_id,
            'name' => (string) $updated->displayName(),
            'code' => $updated->code,
            'phone' => $updated->phone,
            'credit_limit' => $creditLimit,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
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
    public function recordPayment(Request $request, string $companyId, string $customerId, PostCustomerPaymentAction $postPaymentAction): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('money.receipt.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required money.receipt.create permission.',
                status: 403
            );
        }

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

        $amount = $request->input('amount');
        if ($amount === null || ! is_scalar($amount) || BigDecimal::of((string) $amount)->isNegativeOrZero()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Amount must be a strictly positive decimal.',
                status: 422
            );
        }

        // Resolve cash account from cash_account_id or money_account_id
        $rawAccountId = $request->input('cash_account_id', $request->input('money_account_id'));
        $cashAccount = null;
        if ($rawAccountId !== null && is_string($rawAccountId) && trim($rawAccountId) !== '') {
            $cashAccount = MoneyAccount::where('company_id', $company->id)
                ->where('public_id', trim($rawAccountId))
                ->first();
        }
        if ($cashAccount === null) {
            $cashAccount = MoneyAccount::where('company_id', $company->id)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('is_active', true)
                ->first();
        }

        if ($cashAccount === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'No approved cash account configured for this company.',
                status: 422
            );
        }

        $paymentData = [
            'customer_id' => (int) $customer->id,
            'money_account_id' => (int) $cashAccount->id,
            'amount' => (string) BigDecimal::of((string) $amount),
            'exchange_rate' => '1.0000000000',
            'payment_date' => (string) ($request->input('payment_date', now()->format('Y-m-d'))),
            'payment_method' => 'cash',
            'notes' => $request->has('notes') && is_string($request->input('notes')) ? $request->input('notes') : null,
            'reference_number' => $request->has('reference') && is_string($request->input('reference')) ? $request->input('reference') : null,
            'idempotency_key' => $idempotencyKey,
            'allocations' => [],
        ];

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $payment = $postPaymentAction->execute($company, $user, $paymentData);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Customer debt payment failed: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $payment->public_id,
            'payment_number' => (string) ($payment->payment_number ?? $payment->public_id),
            'amount' => (string) BigDecimal::of((string) $payment->amount)->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $payment->currency_code,
            'posted_at' => $payment->payment_date ? $payment->payment_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
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
