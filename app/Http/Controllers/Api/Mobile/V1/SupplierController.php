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
use App\Models\OpeningTradeItem;
use App\Models\Purchase;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Purchasing\VendorFinancialRead;
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
    public function store(Request $request, string $companyId, VendorCatalogService $catalogService): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

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

        $payload = $request->json()->all();
        if (empty($payload['name_ar']) && empty($payload['name_en']) && empty($payload['name'])) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Supplier name is required.',
                status: 422
            );
        }

        if (empty($payload['name_ar']) && ! empty($payload['name'])) {
            $payload['name_ar'] = $payload['name'];
        }

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $vendor = $catalogService->save($company, $user, $payload);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to create supplier: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $vendor->public_id,
            'name' => (string) $vendor->displayName(),
            'code' => $vendor->code,
            'phone' => $vendor->phone,
            'balance_owed' => null,
        ], 201, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * PUT /api/mobile/v1/companies/{companyId}/suppliers/{supplierId}
     */
    public function update(Request $request, string $companyId, string $supplierId, VendorCatalogService $catalogService): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

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

        $payload = $request->json()->all();

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $updated = $catalogService->save($company, $user, $payload, (int) $vendor->id);
        } catch (Throwable $e) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Failed to update supplier: '.$e->getMessage(),
                status: 422
            );
        }

        return response()->json([
            'id' => (string) $updated->public_id,
            'name' => (string) $updated->displayName(),
            'code' => $updated->code,
            'phone' => $updated->phone,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
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

        $currencyFilter = $request->query('currency');
        $currencyCode = is_string($currencyFilter) && trim($currencyFilter) !== ''
            ? strtoupper(trim($currencyFilter))
            : null;

        $tz = (string) ($company->timezone ?: 'UTC');
        $today = now($tz)->toDateString();

        // 1. Read posted open purchases (purchase bills)
        $purchaseQuery = Purchase::query()
            ->where('company_id', $company->id)
            ->where('vendor_id', $vendor->id)
            ->where('status', Purchase::STATUS_POSTED);

        if ($currencyCode !== null) {
            $purchaseQuery->where('currency_code', $currencyCode);
        }

        $targets = [];
        $totalRemainingByCurrency = [];
        $totalOriginalByCurrency = [];

        $purchases = $purchaseQuery->orderBy('purchase_date', 'asc')->orderBy('id', 'asc')->get();
        foreach ($purchases as $purchase) {
            $remaining = $purchase->calculateOutstanding();
            if (! $remaining->isPositive()) {
                continue;
            }

            $purCurr = (string) $purchase->currency_code;
            $cmu = $minorUnitsResolver->resolveForCompany((int) $company->id, $purCurr);
            $grandTotal = BigDecimal::of((string) $purchase->grand_total_currency);
            $allocated = $grandTotal->minus($remaining);
            if ($allocated->isNegative()) {
                $allocated = BigDecimal::zero();
            }

            $purchaseDate = Carbon::parse($purchase->purchase_date, $tz)->toDateString();
            $dueDate = $purchase->due_date ? Carbon::parse($purchase->due_date, $tz)->toDateString() : null;
            $daysOverdue = ($dueDate === null || $dueDate >= $today)
                ? 0
                : max(0, (int) Carbon::parse($dueDate, $tz)->diffInDays(now($tz), false));

            $targets[] = [
                'target_type' => 'purchase',
                'target_id' => (string) $purchase->public_id,
                'target_number' => (string) ($purchase->purchase_number ?? $purchase->public_id),
                'business_date' => $purchaseDate,
                'purchase_date' => $purchaseDate,
                'due_date' => $dueDate,
                'days_overdue' => $daysOverdue,
                'currency' => $purCurr,
                'currency_minor_units' => $cmu,
                'original_amount' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
                'original_amount_exact' => (string) $grandTotal->toScale(6),
                'allocated_amount' => (string) $allocated->toScale($cmu, RoundingMode::HALF_UP),
                'allocated_amount_exact' => (string) $allocated->toScale(6),
                'remaining_amount' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                'remaining_amount_exact' => (string) $remaining->toScale(6),
            ];

            $totalRemainingByCurrency[$purCurr] = ($totalRemainingByCurrency[$purCurr] ?? BigDecimal::zero())->plus($remaining);
            $totalOriginalByCurrency[$purCurr] = ($totalOriginalByCurrency[$purCurr] ?? BigDecimal::zero())->plus($grandTotal);
        }

        // 2. Read posted open vendor opening trade items
        $openingQuery = OpeningTradeItem::query()
            ->where('company_id', $company->id)
            ->where('vendor_id', $vendor->id)
            ->where('side', OpeningTradeItem::SIDE_VENDOR)
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
            'supplier_id' => (string) $vendor->public_id,
            'supplier_name' => (string) $vendor->displayName(),
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
        $notes = $request->has('notes') && is_string($request->input('notes')) ? trim($request->input('notes')) : 'سند صرف للمورد من تطبيق الجوال';
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
                $billKey = $item['bill_id'] ?? $item['purchase_id'] ?? null;
                $openingKey = $item['opening_trade_item_id'] ?? $item['opening_balance_id'] ?? $item['opening_item_id'] ?? null;
                $genericTargetId = $item['target_id'] ?? $item['id'] ?? null;

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
                    $normType = strtolower(trim((string) $rawTargetType));
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

                    if ($targetType === 'purchase' && $openingKey !== null) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has conflicting target_type 'purchase' and opening identifier.",
                            status: 422
                        );
                    }

                    if ($targetType === 'opening_trade_item' && $billKey !== null) {
                        return MobileApiBoundary::buildErrorResponse(
                            code: 'VALIDATION_FAILED',
                            message: "Allocation item at index {$index} has conflicting target_type 'opening_trade_item' and purchase bill identifier.",
                            status: 422
                        );
                    }

                    $targetId = $genericTargetId ?? ($targetType === 'opening_trade_item' ? $openingKey : $billKey);
                    $isPureLegacyWire = false;
                } elseif ($openingKey !== null) {
                    $targetType = 'opening_trade_item';
                    $targetId = $openingKey;
                    $isPureLegacyWire = false;
                } elseif ($billKey !== null) {
                    $targetType = 'purchase';
                    $targetId = $billKey;
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
