<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * POST /api/mobile/v1/companies/{companyId}/suppliers/{supplierId}/payments
     * Supplier cash disbursement voucher
     */
    public function recordPayment(Request $request, string $companyId, string $supplierId, PostVendorPaymentAction $postPaymentAction): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('money.vendor_payment.create')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required money.vendor_payment.create permission.',
                status: 403
            );
        }

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

        $amount = $request->input('amount');
        if ($amount === null || ! is_scalar($amount) || BigDecimal::of((string) $amount)->isNegativeOrZero()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Amount must be a strictly positive decimal.',
                status: 422
            );
        }

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
            'vendor_id' => (int) $vendor->id,
            'money_account_id' => (int) $cashAccount->id,
            'currency_code' => (string) $company->base_currency_code,
            'exchange_rate' => '1.0000000000',
            'amount' => (string) BigDecimal::of((string) $amount),
            'payment_date' => (string) ($request->input('payment_date', now()->format('Y-m-d'))),
            'payment_method' => 'cash',
            'notes' => $request->has('notes') && is_string($request->input('notes')) ? $request->input('notes') : 'سند صرف للمورد من تطبيق الجوال',
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
                message: 'Supplier payment disbursement failed: '.$e->getMessage(),
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
}
