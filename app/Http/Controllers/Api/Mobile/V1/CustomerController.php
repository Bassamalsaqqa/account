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
use App\Models\OpeningTradeItem;
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
     * GET /api/mobile/v1/companies/{companyId}/customers/{customerId}/settlement-targets
     * Authoritative outstanding settlement target reads (invoices and opening trade items)
     */
    public function settlementTargets(
        Request $request,
        string $companyId,
        string $customerId,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
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

        if ($company === null || $user === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'UNAUTHENTICATED',
                message: 'Authentication required or token expired.',
                status: 401
            );
        }

        setPermissionsTeamId($company->id);
        if (! $user->hasPermissionTo('customers.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required customers.view permission.',
                status: 403
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

        $currencyFilter = $request->query('currency');
        $currencyCode = is_string($currencyFilter) && trim($currencyFilter) !== ''
            ? strtoupper(trim($currencyFilter))
            : null;

        $tz = (string) ($company->timezone ?: 'UTC');
        $today = now($tz)->toDateString();

        // 1. Read posted open invoices
        $invoiceQuery = SalesInvoice::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->where('status', SalesInvoice::STATUS_POSTED);

        if ($currencyCode !== null) {
            $invoiceQuery->where('currency_code', $currencyCode);
        }

        $targets = [];
        $totalRemainingByCurrency = [];
        $totalOriginalByCurrency = [];

        $invoices = $invoiceQuery->orderBy('issue_date', 'asc')->orderBy('id', 'asc')->get();
        foreach ($invoices as $invoice) {
            $remaining = $invoice->calculateOutstanding();
            if (! $remaining->isPositive()) {
                continue;
            }

            $invCurr = (string) $invoice->currency_code;
            $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $invCurr);
            $grandTotal = BigDecimal::of((string) $invoice->grand_total_currency);
            $allocated = $grandTotal->minus($remaining);
            if ($allocated->isNegative()) {
                $allocated = BigDecimal::zero();
            }

            $issueDate = Carbon::parse($invoice->issue_date, $tz)->toDateString();
            $dueDate = $invoice->due_date ? Carbon::parse($invoice->due_date, $tz)->toDateString() : null;
            $daysOverdue = ($dueDate === null || $dueDate >= $today)
                ? 0
                : max(0, (int) Carbon::parse($dueDate, $tz)->diffInDays(now($tz), false));

            $targets[] = [
                'target_type' => 'invoice',
                'target_id' => (string) $invoice->public_id,
                'target_number' => (string) ($invoice->invoice_number ?? $invoice->public_id),
                'business_date' => $issueDate,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'days_overdue' => $daysOverdue,
                'currency' => $invCurr,
                'currency_minor_units' => $cmu,
                'original_amount' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
                'original_amount_exact' => (string) $grandTotal->toScale(6),
                'allocated_amount' => (string) $allocated->toScale($cmu, RoundingMode::HALF_UP),
                'allocated_amount_exact' => (string) $allocated->toScale(6),
                'remaining_amount' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                'remaining_amount_exact' => (string) $remaining->toScale(6),
            ];

            $totalRemainingByCurrency[$invCurr] = ($totalRemainingByCurrency[$invCurr] ?? BigDecimal::zero())->plus($remaining);
            $totalOriginalByCurrency[$invCurr] = ($totalOriginalByCurrency[$invCurr] ?? BigDecimal::zero())->plus($grandTotal);
        }

        // 2. Read posted open customer opening trade items
        $openingQuery = OpeningTradeItem::query()
            ->where('company_id', $company->id)
            ->where('customer_id', $customer->id)
            ->where('side', OpeningTradeItem::SIDE_CUSTOMER)
            ->where('status', OpeningTradeItem::STATUS_POSTED)
            ->whereNotNull('posting_batch_id')
            ->with(['postingBatch', 'reversalPostingBatch']);

        if ($currencyCode !== null) {
            $openingQuery->where('currency_code', $currencyCode);
        }

        $openingItems = $openingQuery->orderBy('opening_date', 'asc')->orderBy('id', 'asc')->get();
        foreach ($openingItems as $item) {
            $remaining = $item->calculateOutstanding();
            if (! $remaining->isPositive()) {
                continue;
            }

            $itemCurr = (string) $item->currency_code;
            $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $itemCurr);
            $original = BigDecimal::of((string) $item->amount_currency);
            $allocated = $original->minus($remaining);
            if ($allocated->isNegative()) {
                $allocated = BigDecimal::zero();
            }

            $openDate = Carbon::parse($item->opening_date, $tz)->toDateString();
            $dueDate = $item->due_date ? Carbon::parse($item->due_date, $tz)->toDateString() : null;
            $daysOverdue = ($dueDate === null || $dueDate >= $today)
                ? 0
                : max(0, (int) Carbon::parse($dueDate, $tz)->diffInDays(now($tz), false));

            $refNum = $item->original_reference ?: ('OPEN-'.$item->public_id);

            $targets[] = [
                'target_type' => 'opening_trade_item',
                'target_id' => (string) $item->public_id,
                'target_number' => (string) $refNum,
                'business_date' => $openDate,
                'opening_date' => $openDate,
                'due_date' => $dueDate,
                'days_overdue' => $daysOverdue,
                'currency' => $itemCurr,
                'currency_minor_units' => $cmu,
                'original_amount' => (string) $original->toScale($cmu, RoundingMode::HALF_UP),
                'original_amount_exact' => (string) $original->toScale(6),
                'allocated_amount' => (string) $allocated->toScale($cmu, RoundingMode::HALF_UP),
                'allocated_amount_exact' => (string) $allocated->toScale(6),
                'remaining_amount' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                'remaining_amount_exact' => (string) $remaining->toScale(6),
            ];

            $totalRemainingByCurrency[$itemCurr] = ($totalRemainingByCurrency[$itemCurr] ?? BigDecimal::zero())->plus($remaining);
            $totalOriginalByCurrency[$itemCurr] = ($totalOriginalByCurrency[$itemCurr] ?? BigDecimal::zero())->plus($original);
        }

        // Sort combined targets chronologically: business_date asc, then target_type, then target_id
        usort($targets, function (array $a, array $b): int {
            $cmpDate = strcmp($a['business_date'], $b['business_date']);
            if ($cmpDate !== 0) {
                return $cmpDate;
            }
            $cmpType = strcmp($a['target_type'], $b['target_type']);
            if ($cmpType !== 0) {
                return $cmpType;
            }

            return strcmp($a['target_id'], $b['target_id']);
        });

        $baseCurr = (string) $company->base_currency_code;
        $baseCmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $baseCurr);

        $formattedRemainingTotals = [];
        $formattedOriginalTotals = [];
        foreach ($totalRemainingByCurrency as $curr => $total) {
            $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $curr);
            $formattedRemainingTotals[$curr] = [
                'amount' => (string) $total->toScale($cmu, RoundingMode::HALF_UP),
                'amount_exact' => (string) $total->toScale(6),
                'minor_units' => $cmu,
            ];
            $origTotal = $totalOriginalByCurrency[$curr] ?? BigDecimal::zero();
            $formattedOriginalTotals[$curr] = [
                'amount' => (string) $origTotal->toScale($cmu, RoundingMode::HALF_UP),
                'amount_exact' => (string) $origTotal->toScale(6),
                'minor_units' => $cmu,
            ];
        }

        return response()->json([
            'customer_id' => (string) $customer->public_id,
            'customer_name' => (string) $customer->displayName(),
            'currency' => $currencyCode ?? $baseCurr,
            'currency_minor_units' => $currencyCode ? $minorUnitsResolver->resolveForCompany((int) $company->id, $currencyCode) : $baseCmu,
            'total_targets' => count($targets),
            'targets' => $targets,
            'totals' => [
                'remaining_by_currency' => $formattedRemainingTotals,
                'original_by_currency' => $formattedOriginalTotals,
            ],
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

        try {
            $paymentDate = $request->has('payment_date')
                ? NativeWriteContextResolver::validateDate($request->input('payment_date'), 'payment_date')
                : now()->format('Y-m-d');
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
            if ($rawRate === null || trim((string) $rawRate) === '') {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Explicit exchange_rate is required for foreign currency payment.',
                    status: 422
                );
            }
            try {
                $paymentRate = NativeWriteContextResolver::validateDecimalString($rawRate, 'exchange_rate', 10, true);
            } catch (Throwable $e) {
                return MobileApiBoundary::buildErrorResponse(code: 'VALIDATION_FAILED', message: $e->getMessage(), status: 422);
            }
        }

        $rawAccountId = $request->input('cash_account_id', $request->input('money_account_id'));
        $notes = $request->has('notes') && is_string($request->input('notes')) ? trim($request->input('notes')) : null;
        $reference = $request->has('reference') && is_string($request->input('reference')) ? trim($request->input('reference')) : null;
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

                $rawTargetType = $item['target_type'] ?? $item['kind'] ?? null;
                $invKey = $item['invoice_id'] ?? $item['sales_invoice_id'] ?? null;
                $openingKey = $item['opening_trade_item_id'] ?? $item['opening_balance_id'] ?? $item['opening_item_id'] ?? null;
                $genericTargetId = $item['target_id'] ?? $item['id'] ?? null;

                // Ambiguity detection: multiple contradictory identifier keys
                if ($invKey !== null && $openingKey !== null) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} is ambiguous: specifies both invoice_id and opening_trade_item_id.",
                        status: 422
                    );
                }

                $targetType = null;
                $targetId = null;

                if ($rawTargetType !== null) {
                    $normType = strtolower(trim((string) $rawTargetType));
                    $targetType = match ($normType) {
                        'invoice', 'sales_invoice' => 'invoice',
                        'opening_trade_item', 'opening_balance', 'opening_item' => 'opening_trade_item',
                        default => null,
                    };

                    if ($targetType === null) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has invalid target_type [{$rawTargetType}]. Must be 'invoice' or 'opening_trade_item'.",
                            status: 422
                        );
                    }

                    if ($targetType === 'invoice' && $openingKey !== null) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has conflicting target_type 'invoice' and opening identifier.",
                            status: 422
                        );
                    }

                    if ($targetType === 'opening_trade_item' && $invKey !== null) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has conflicting target_type 'opening_trade_item' and invoice identifier.",
                            status: 422
                        );
                    }

                    $targetId = $genericTargetId ?? ($targetType === 'opening_trade_item' ? $openingKey : $invKey);
                    $isPureLegacyWire = false;
                } elseif ($openingKey !== null) {
                    $targetType = 'opening_trade_item';
                    $targetId = $openingKey;
                    $isPureLegacyWire = false;
                } elseif ($invKey !== null) {
                    $targetType = 'invoice';
                    $targetId = $invKey;
                } elseif ($genericTargetId !== null) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} specifies target_id without target_type.",
                        status: 422
                    );
                } else {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: "Allocation item at index {$index} must identify a target (target_type and target_id, or invoice_id, or opening_trade_item_id).",
                        status: 422
                    );
                }

                if (! is_string($targetId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $targetId) !== 1) {
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
                        $item['amount'] ?? $item['allocated_amount'] ?? $item['document_currency_amount'] ?? null,
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
                if (array_key_exists('payment_currency_amount', $item)) {
                    try {
                        $paymentAmount = NativeWriteContextResolver::validateDecimalString(
                            $item['payment_currency_amount'],
                            'payment_currency_amount',
                            6,
                            true
                        );
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
                    $allocRow['invoice_id'] = $targetId;
                }
                if ($paymentAmount !== null) {
                    $allocRow['payment_currency_amount'] = $paymentAmount;
                }
                $canonicalAllocations[] = $allocRow;

                if ($targetType === 'invoice') {
                    $legacyAllocRow = [
                        'invoice_id' => $targetId,
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
                usort($legacyAllocations, fn ($a, $b) => strcmp($a['invoice_id'], $b['invoice_id']));
            }
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

        if ($request->has('exchange_rate') || $request->has('currency')) {
            $canonicalPayload['exchange_rate'] = (string) BigDecimal::of($paymentRate)->toScale(10);
        }

        $legacyHashes = [];
        if ($isPureLegacyWire && ! empty($legacyAllocations)) {
            $legacyPayload = $canonicalPayload;
            $legacyPayload['allocations'] = $legacyAllocations;
            $legacyHashes[] = hash('sha256', json_encode($legacyPayload, JSON_THROW_ON_ERROR));
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
                    $totalAllocatedPayment = BigDecimal::zero();

                    foreach ($canonicalAllocations as $alloc) {
                        if ($alloc['target_type'] === 'opening_trade_item') {
                            /** @var OpeningTradeItem|null $openingItem */
                            $openingItem = OpeningTradeItem::where('company_id', $lockedCompany->id)
                                ->where('public_id', $alloc['target_id'])
                                ->lockForUpdate()
                                ->first();

                            if ($openingItem === null || (int) $openingItem->customer_id !== (int) $customer->id || $openingItem->side !== OpeningTradeItem::SIDE_CUSTOMER) {
                                throw new InvalidArgumentException("Opening trade item [{$alloc['target_id']}] not found or does not belong to target customer.");
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
                            /** @var SalesInvoice|null $invoice */
                            $invoice = SalesInvoice::where('company_id', $lockedCompany->id)
                                ->where('public_id', $alloc['target_id'])
                                ->lockForUpdate()
                                ->first();

                            if ($invoice === null || (int) $invoice->customer_id !== (int) $customer->id) {
                                throw new InvalidArgumentException("Sales invoice [{$alloc['target_id']}] not found or does not belong to target customer.");
                            }

                            if ($invoice->status !== SalesInvoice::STATUS_POSTED) {
                                throw new InvalidArgumentException("Sales invoice [{$alloc['target_id']}] must be in posted status.");
                            }

                            $remaining = $invoice->calculateOutstanding();
                            if (BigDecimal::of($alloc['amount'])->isGreaterThan($remaining)) {
                                throw new InvalidArgumentException("Allocation [{$alloc['amount']}] exceeds unpaid balance [{$remaining}] for invoice [{$alloc['target_id']}].");
                            }

                            $resolvedRow = [
                                'sales_invoice_id' => (int) $invoice->id,
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
                        'customer_id' => (int) $customer->id,
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
                        'receipt_id' => (string) $payment->public_id,
                        'payment_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'receipt_number' => (string) ($payment->payment_number ?? $payment->public_id),
                        'amount' => (string) BigDecimal::of((string) $payment->amount)->toScale($minorUnits, RoundingMode::HALF_UP),
                        'amount_exact' => (string) BigDecimal::of((string) $payment->amount)->toScale(6),
                        'currency' => (string) $payment->currency_code,
                        'posted_at' => $payment->payment_date ? $payment->payment_date->format('Y-m-d\TH:i:s\Z') : now()->format('Y-m-d\TH:i:s\Z'),
                        'customer_id' => (string) $customer->public_id,
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
