<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Application\Platform\TenantWriteAccess;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileShiftClosure;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Audit\AuditService;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReportController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/reports/shift-summary
     */
    public function shiftSummary(
        Request $request,
        string $companyId,
        MoneyBalanceQuery $moneyBalanceQuery,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);
        app(CompanyContext::class)->setCompany($company, $user);

        if (! $user->hasPermissionTo('reports.financial.view') && ! $user->hasPermissionTo('money.cash.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required reports permission.',
                status: 403
            );
        }

        $baseCurrency = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurrency, $company);
        $companyTimezone = (string) ($company->timezone ?? config('app.timezone', 'UTC'));
        $today = now($companyTimezone)->toDateString();
        $yesterday = now($companyTimezone)->subDay()->toDateString();

        // Resolve approved cash drawer account
        $approvedCashAccount = $this->resolveApprovedCashAccount($company, $baseCurrency);

        // 1. Opening cash from canonical money ledger (as of start of day); never trust client float or invent zero on error
        $openingFloat = null;
        if ($approvedCashAccount !== null && $user->hasPermissionTo('money.cash.view')) {
            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $openingBalances = $moneyBalanceQuery->forType($company, 'cash', $yesterday);
                foreach ($openingBalances as $row) {
                    if ($row['public_id'] === $approvedCashAccount->public_id && ($row['currency_balance_available'] ?? false) && isset($row['balance_currency'])) {
                        $openingFloat = BigDecimal::of((string) $row['balance_currency']);
                        break;
                    }
                }
            } catch (Throwable) {
                $openingFloat = null;
            }
        }

        // 2. Today's posted sales count
        $salesCount = 0;
        if ($user->hasPermissionTo('sales.invoice.view')) {
            $salesCount = SalesInvoice::where('company_id', $company->id)
                ->where('status', SalesInvoice::STATUS_POSTED)
                ->where('currency_code', $baseCurrency)
                ->whereDate('issue_date', $today)
                ->count();
        }

        // 3. Today's customer cash movements: segregated into cash_sales (paid on today's posted invoices)
        // and cash_collections (prior invoices / on-account credit), preventing double counting.
        [$cashSales, $cashCollections] = $approvedCashAccount !== null
            ? $this->calculateCashSalesAndCollections($company, $baseCurrency, $today, $approvedCashAccount)
            : [BigDecimal::zero(), BigDecimal::zero()];

        // 4. Today's cash disbursements
        $disbursements = $approvedCashAccount !== null
            ? $this->calculateCashDisbursements($company, $baseCurrency, $today, $approvedCashAccount)
            : BigDecimal::zero();

        // 5. Today's operating cash expenses
        $expenses = $approvedCashAccount !== null
            ? $this->calculateCashExpenses($company, $baseCurrency, $today, $approvedCashAccount)
            : BigDecimal::zero();

        // 6. Expected drawer cash must come from canonical CURRENT MoneyBalanceQuery for exact approved account
        $expectedCash = null;
        if ($approvedCashAccount !== null && $user->hasPermissionTo('money.cash.view')) {
            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $currentBalances = $moneyBalanceQuery->forType($company, 'cash');
                foreach ($currentBalances as $row) {
                    if ($row['public_id'] === $approvedCashAccount->public_id && ($row['currency_balance_available'] ?? false) && isset($row['balance_currency'])) {
                        $expectedCash = BigDecimal::of((string) $row['balance_currency']);
                        break;
                    }
                }
            } catch (Throwable) {
                $expectedCash = null;
            }
        }

        return response()->json([
            'company_id' => $company->public_id,
            'shift_date' => $today,
            'currency' => $baseCurrency,
            'currency_minor_units' => $cmu,
            'opening_float' => $openingFloat !== null ? (string) $openingFloat->toScale($cmu, RoundingMode::HALF_UP) : null,
            'cash_sales' => (string) $cashSales->toScale($cmu, RoundingMode::HALF_UP),
            'cash_collections' => (string) $cashCollections->toScale($cmu, RoundingMode::HALF_UP),
            'cash_disbursements' => (string) $disbursements->toScale($cmu, RoundingMode::HALF_UP),
            'operating_expenses' => (string) $expenses->toScale($cmu, RoundingMode::HALF_UP),
            'expected_cash' => $expectedCash !== null ? (string) $expectedCash->toScale($cmu, RoundingMode::HALF_UP) : null,
            'sales_count' => $salesCount,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/reports/shift-close
     */
    public function shiftClose(
        Request $request,
        string $companyId,
        MoneyBalanceQuery $moneyBalanceQuery,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);
        app(CompanyContext::class)->setCompany($company, $user);

        if (! $user->hasPermissionTo('reports.financial.view') && ! $user->hasPermissionTo('money.cash.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required reports permission.',
                status: 403
            );
        }

        $rawKey = $request->header('Idempotency-Key') ?? $request->header('X-Idempotency-Key');
        $idempotencyKey = is_string($rawKey) ? trim($rawKey) : '';
        if ($idempotencyKey === '') {
            return MobileApiBoundary::buildErrorResponse(
                code: 'MISSING_IDEMPOTENCY_KEY',
                message: 'Idempotency-Key header is required for shift close.',
                status: 422
            );
        }
        if (strlen($idempotencyKey) > 128) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Idempotency-Key header must not exceed 128 characters.',
                status: 422
            );
        }

        $countedInput = $request->input('counted_cash', $request->input('counted_physical_cash'));
        if ($countedInput === null || ! is_string($countedInput)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Counted physical cash is required and must be a decimal string.',
                status: 422
            );
        }

        $baseCurrency = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurrency, $company);
        $companyTimezone = (string) ($company->timezone ?? config('app.timezone', 'UTC'));
        $today = now($companyTimezone)->toDateString();

        $strCounted = trim($countedInput);
        $maxDecimals = $cmu;
        $pattern = $maxDecimals > 0 ? '/^\d{1,14}(\.\d{1,'.$maxDecimals.'})?$/' : '/^\d{1,14}$/';
        if (preg_match($pattern, $strCounted) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: "Counted physical cash must be a non-negative decimal string with at most {$maxDecimals} decimal places.",
                status: 422
            );
        }
        $counted = BigDecimal::of($strCounted);

        $rawNotes = $request->input('notes');
        $notes = null;
        if ($rawNotes !== null) {
            if (! is_string($rawNotes)) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Notes must be a string.',
                    status: 422
                );
            }
            if (mb_strlen($rawNotes) > 1000) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'Notes must not exceed 1000 characters.',
                    status: 422
                );
            }
            $notes = trim($rawNotes);
        }

        // Validate explicit cash account ID format (canonical ULID string only)
        $explicitAccountId = null;
        if ($request->has('cash_account_id')) {
            $rawAccountId = $request->input('cash_account_id');
            if ($rawAccountId !== null) {
                if (! is_string($rawAccountId) || preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', trim($rawAccountId)) !== 1) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'VALIDATION_FAILED',
                        message: 'The specified cash account ID must be a canonical 26-character ULID string.',
                        status: 422
                    );
                }
                $explicitAccountId = trim($rawAccountId);
            }
        }

        $device = $request->attributes->get('mobile_device');
        $deviceId = $device instanceof MobileDevice ? (string) $device->device_id : (string) ($request->attributes->get('mobile_device_id') ?? '');
        $rawBearer = (string) $request->bearerToken();
        $tokenHash = hash('sha256', $rawBearer);

        return app(TenantWriteAccess::class)->transaction((int) $company->id, function () use (
            $company,
            $user,
            $deviceId,
            $tokenHash,
            $explicitAccountId,
            $idempotencyKey,
            $counted,
            $notes,
            $baseCurrency,
            $cmu,
            $today,
            $moneyBalanceQuery
        ): JsonResponse {
            // 1. Lock company (tenant root)
            /** @var Company|null $lockedCompany */
            $lockedCompany = Company::whereKey($company->id)->lockForUpdate()->first();
            if ($lockedCompany === null || $lockedCompany->status !== 'active') {
                return MobileApiBoundary::buildErrorResponse('FORBIDDEN', 'Target company not found or inactive.', 403);
            }

            // 2. Lock membership
            $membership = DB::table('company_user')
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();
            if ($membership === null) {
                return MobileApiBoundary::buildErrorResponse('FORBIDDEN', 'User is not an active member of this company.', 403);
            }

            // 3. Lock principal (user)
            /** @var User|null $lockedUser */
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->first();
            if ($lockedUser === null) {
                return MobileApiBoundary::buildErrorResponse('UNAUTHENTICATED', 'User not found.', 401);
            }

            // 4. Lock device
            /** @var MobileDevice|null $lockedDevice */
            $lockedDevice = MobileDevice::where('device_id', $deviceId)->lockForUpdate()->first();
            if ($lockedDevice === null || $lockedDevice->isRevoked() || (int) $lockedDevice->user_id !== (int) $lockedUser->id) {
                return MobileApiBoundary::buildErrorResponse('UNAUTHENTICATED', 'Device has been revoked or does not match user.', 401);
            }

            // 5. Lock bearer token and revalidate credentials
            /** @var MobileAccessToken|null $lockedToken */
            $lockedToken = MobileAccessToken::where('token_hash', $tokenHash)->lockForUpdate()->first();
            if ($lockedToken === null || ! $lockedToken->isValid() || (int) $lockedToken->user_id !== (int) $lockedUser->id || $lockedToken->device_id !== $lockedDevice->device_id) {
                return MobileApiBoundary::buildErrorResponse('UNAUTHENTICATED', 'The Bearer token has been revoked or expired.', 401);
            }
            if ($lockedToken->company_id !== null && (int) $lockedToken->company_id !== (int) $company->id) {
                return MobileApiBoundary::buildErrorResponse('FORBIDDEN', 'Token is bound to a different company.', 403);
            }
            $expectedEpoch = 'epoch-'.substr(hash('sha256', (string) $lockedUser->password), 0, 16);
            if (! hash_equals($expectedEpoch, (string) $lockedToken->password_epoch)) {
                return MobileApiBoundary::buildErrorResponse('UNAUTHENTICATED', 'User credentials have changed since token establishment.', 401);
            }

            // Revalidate permissions under lock
            setPermissionsTeamId($company->id);
            $lockedUser->unsetRelation('roles')->unsetRelation('permissions');
            if (! $lockedUser->hasPermissionTo('reports.financial.view') && ! $lockedUser->hasPermissionTo('money.cash.view')) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'FORBIDDEN',
                    message: 'Action is unauthorized. Missing required reports permission.',
                    status: 403
                );
            }

            // 6. Lock approved/explicit cash account and verify ledger provenance
            $lockedCashAccount = null;
            if ($explicitAccountId !== null) {
                $lockedCashAccount = MoneyAccount::where('company_id', $company->id)
                    ->where('public_id', $explicitAccountId)
                    ->where('account_type', MoneyAccount::TYPE_CASH)
                    ->where('currency_code', $baseCurrency)
                    ->where('is_active', true)
                    ->with('ledgerAccount')
                    ->lockForUpdate()
                    ->first();
            } else {
                $setting = CompanyMobileSetting::where('company_id', $company->id)->lockForUpdate()->first();
                if ($setting?->approved_cash_account_id) {
                    $lockedCashAccount = MoneyAccount::where('id', $setting->approved_cash_account_id)
                        ->where('company_id', $company->id)
                        ->where('account_type', MoneyAccount::TYPE_CASH)
                        ->where('currency_code', $baseCurrency)
                        ->where('is_active', true)
                        ->with('ledgerAccount')
                        ->lockForUpdate()
                        ->first();
                }
            }

            $lockedLedger = $lockedCashAccount?->ledgerAccount;
            if ($lockedCashAccount === null || $lockedLedger === null || (int) $lockedLedger->company_id !== (int) $company->id || ! $lockedLedger->active || empty(trim((string) $lockedLedger->code))) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'An approved company cash account with active ledger provenance is required for shift close.',
                    status: 422
                );
            }

            // Payload digest
            $payloadForDigest = [
                'counted_cash' => (string) $counted,
                'cash_account_id' => (string) $lockedCashAccount->public_id,
                'notes' => $notes,
            ];
            $requestHash = hash('sha256', json_encode($payloadForDigest, JSON_THROW_ON_ERROR));

            // Check existing idempotency record under lock
            $existing = MobileShiftClosure::withoutGlobalScopes()
                ->where('company_id', $company->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Scope check: must match original actor and device
                if ((int) $existing->actor_id !== (int) $lockedUser->id || (string) ($existing->device_id ?? '') !== (string) $lockedDevice->device_id) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'FORBIDDEN',
                        message: 'Idempotent replay is scoped to original actor and device.',
                        status: 403
                    );
                }

                if ($existing->request_hash !== $requestHash) {
                    return MobileApiBoundary::buildErrorResponse(
                        code: 'IDEMPOTENCY_CONFLICT',
                        message: 'A different payload was previously submitted with this idempotency key.',
                        status: 409
                    );
                }

                return response()->json([
                    'success' => true,
                    'observation_recorded' => true,
                    'is_shift_closed' => false,
                    'id' => (string) $existing->public_id,
                    'shift_closure_id' => (string) $existing->public_id,
                    'shift_date' => (string) $existing->shift_date->format('Y-m-d'),
                    'currency' => (string) $existing->currency_code,
                    'currency_minor_units' => (int) $existing->currency_minor_units,
                    'expected_cash' => (string) BigDecimal::of((string) $existing->expected_cash)->toScale($existing->currency_minor_units, RoundingMode::HALF_UP),
                    'counted_cash' => (string) BigDecimal::of((string) $existing->counted_cash)->toScale($existing->currency_minor_units, RoundingMode::HALF_UP),
                    'variance' => (string) BigDecimal::of((string) $existing->variance)->toScale($existing->currency_minor_units, RoundingMode::HALF_UP),
                    'variance_status' => (string) $existing->variance_status,
                    'closed_at' => $existing->closed_at->toIso8601ZuluString(),
                ], 200, [
                    'Content-Type' => 'application/json',
                    'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
                ]);
            }

            // Expected drawer cash must come from canonical CURRENT MoneyBalanceQuery for exact approved account
            $expectedCash = null;
            try {
                app(CompanyContext::class)->setCompany($company, $lockedUser);
                $currentBalances = $moneyBalanceQuery->forType($company, 'cash');
                foreach ($currentBalances as $row) {
                    if ($row['public_id'] === $lockedCashAccount->public_id && ($row['currency_balance_available'] ?? false) && isset($row['balance_currency'])) {
                        $expectedCash = BigDecimal::of((string) $row['balance_currency']);
                        break;
                    }
                }
            } catch (Throwable) {
                $expectedCash = null;
            }

            if ($expectedCash === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'LEDGER_UNAVAILABLE',
                    message: 'Authoritative ledger cash balance is unavailable for this drawer.',
                    status: 503
                );
            }

            $variance = $counted->minus($expectedCash);
            $varianceStatus = match (true) {
                $variance->isZero() => 'EXACT',
                $variance->isPositive() => 'OVERAGE',
                default => 'SHORTAGE',
            };

            // Immutable audited count observation persistence
            $closure = MobileShiftClosure::create([
                'company_id' => $company->id,
                'cash_account_id' => $lockedCashAccount->id,
                'actor_id' => $lockedUser->id,
                'device_id' => $lockedDevice->device_id,
                'shift_date' => $today,
                'currency_code' => $baseCurrency,
                'currency_minor_units' => $cmu,
                'opening_cash' => null,
                'expected_cash' => (string) $expectedCash,
                'counted_cash' => (string) $counted,
                'variance' => (string) $variance,
                'variance_status' => $varianceStatus,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'notes' => $notes,
                'actor_snapshot' => [
                    'id' => (int) $lockedUser->id,
                    'public_id' => (string) ($lockedUser->public_id ?? ''),
                    'name' => (string) $lockedUser->name,
                    'email' => (string) $lockedUser->email,
                ],
                'closed_at' => now(),
            ]);

            app(AuditService::class)->log(
                companyId: (int) $company->id,
                eventKey: 'mobile.shift_closure.recorded',
                summary: 'Mobile cash count observation recorded',
                actorUserId: (int) $lockedUser->id,
                subject: $closure,
                after: [
                    'public_id' => $closure->public_id,
                    'counted_cash' => (string) $counted,
                    'expected_cash' => (string) $expectedCash,
                    'variance' => (string) $variance,
                    'variance_status' => $varianceStatus,
                ],
                meta: [
                    'cash_account_id' => $lockedCashAccount->public_id,
                    'device_id' => $lockedDevice->device_id,
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            return response()->json([
                'success' => true,
                'observation_recorded' => true,
                'is_shift_closed' => false,
                'id' => (string) $closure->public_id,
                'shift_closure_id' => (string) $closure->public_id,
                'shift_date' => $today,
                'currency' => $baseCurrency,
                'currency_minor_units' => $cmu,
                'expected_cash' => (string) $expectedCash->toScale($cmu, RoundingMode::HALF_UP),
                'counted_cash' => (string) $counted->toScale($cmu, RoundingMode::HALF_UP),
                'variance' => (string) $variance->toScale($cmu, RoundingMode::HALF_UP),
                'variance_status' => $varianceStatus,
                'closed_at' => $closure->closed_at->toIso8601ZuluString(),
            ], 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        });
    }

    /**
     * GET /api/mobile/v1/companies/{companyId}/reports/treasury
     */
    public function treasury(Request $request, string $companyId, MoneyBalanceQuery $moneyBalanceQuery): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->hasPermissionTo('reports.financial.view') && ! $user->hasPermissionTo('money.cash.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required reports permission.',
                status: 403
            );
        }

        $cashAccounts = [];
        $bankAccounts = [];

        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $cashAccounts = $moneyBalanceQuery->forType($company, 'cash');
            $bankAccounts = $moneyBalanceQuery->forType($company, 'bank');
        } catch (Throwable) {
            // Keep empty on error
        }

        return response()->json([
            'company_id' => $company->public_id,
            'cash_accounts' => $cashAccounts,
            'bank_accounts' => $bankAccounts,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    private function resolveApprovedCashAccount(Company $company, string $baseCurrency): ?MoneyAccount
    {
        $setting = CompanyMobileSetting::where('company_id', $company->id)->first();
        if ($setting?->approved_cash_account_id) {
            /** @var MoneyAccount|null $ca */
            $ca = MoneyAccount::where('id', $setting->approved_cash_account_id)
                ->where('company_id', $company->id)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('currency_code', $baseCurrency)
                ->where('is_active', true)
                ->with('ledgerAccount')
                ->first();
            if ($ca !== null) {
                $ledger = $ca->ledgerAccount;
                if ($ledger !== null && (int) $ledger->company_id === (int) $company->id && $ledger->active && ! empty(trim((string) $ledger->code))) {
                    return $ca;
                }
            }
        }

        return null;
    }

    /**
     * @return array{0: BigDecimal, 1: BigDecimal}
     */
    private function calculateCashSalesAndCollections(
        Company $company,
        string $baseCurrency,
        string $today,
        ?MoneyAccount $cashAccount
    ): array {
        $todayPostedInvoiceIds = SalesInvoice::where('company_id', $company->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->where('currency_code', $baseCurrency)
            ->whereDate('issue_date', $today)
            ->pluck('id')
            ->all();

        $query = CustomerPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->where('currency_code', $baseCurrency)
            ->where('payment_method', CustomerPayment::METHOD_CASH)
            ->whereDate('payment_date', $today);

        if ($cashAccount !== null) {
            $query->where('money_account_id', $cashAccount->id);
        }

        $payments = $query->with(['allocations' => fn ($q) => $q->active()])->get();

        $cashSales = BigDecimal::zero();
        $cashCollections = BigDecimal::zero();

        foreach ($payments as $payment) {
            $paymentTotal = BigDecimal::of((string) $payment->amount);
            $allocatedToTodayInvoices = BigDecimal::zero();

            foreach ($payment->allocations as $alloc) {
                if (in_array((int) $alloc->sales_invoice_id, $todayPostedInvoiceIds, true)) {
                    $allocatedToTodayInvoices = $allocatedToTodayInvoices->plus(BigDecimal::of((string) $alloc->allocated_amount));
                }
            }

            if ($allocatedToTodayInvoices->isGreaterThan($paymentTotal)) {
                $allocatedToTodayInvoices = $paymentTotal;
            }

            $cashSales = $cashSales->plus($allocatedToTodayInvoices);
            $cashCollections = $cashCollections->plus($paymentTotal->minus($allocatedToTodayInvoices));
        }

        return [$cashSales, $cashCollections];
    }

    private function calculateCashDisbursements(
        Company $company,
        string $baseCurrency,
        string $today,
        ?MoneyAccount $cashAccount
    ): BigDecimal {
        $query = VendorPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->where('currency_code', $baseCurrency)
            ->where('payment_method', 'cash')
            ->whereDate('payment_date', $today);

        if ($cashAccount !== null) {
            $query->where('money_account_id', $cashAccount->id);
        }

        $disbursements = BigDecimal::zero();
        foreach ($query->get(['amount']) as $vp) {
            $disbursements = $disbursements->plus(BigDecimal::of((string) $vp->amount));
        }

        return $disbursements;
    }

    private function calculateCashExpenses(
        Company $company,
        string $baseCurrency,
        string $today,
        ?MoneyAccount $cashAccount
    ): BigDecimal {
        $query = Expense::where('company_id', $company->id)
            ->where('status', Expense::STATUS_POSTED)
            ->where('currency_code', $baseCurrency)
            ->where('payment_method', Expense::METHOD_CASH)
            ->whereDate('expense_date', $today);

        if ($cashAccount !== null) {
            $query->where('money_account_id', $cashAccount->id);
        }

        $expenses = BigDecimal::zero();
        foreach ($query->get(['amount']) as $exp) {
            $expenses = $expenses->plus(BigDecimal::of((string) $exp->amount));
        }

        return $expenses;
    }
}
