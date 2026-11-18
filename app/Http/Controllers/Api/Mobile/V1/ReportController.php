<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\MobileDevice;
use App\Models\MobileShiftClosure;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\VendorPayment;
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
        if ($user->hasPermissionTo('money.cash.view')) {
            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $openingBalances = $moneyBalanceQuery->forType($company, 'cash', $yesterday);
                foreach ($openingBalances as $row) {
                    $matches = $approvedCashAccount !== null
                        ? ($row['public_id'] === $approvedCashAccount->public_id)
                        : ($row['currency_code'] === $baseCurrency);
                    if ($matches && ($row['currency_balance_available'] ?? false) && isset($row['balance_currency'])) {
                        $bal = BigDecimal::of((string) $row['balance_currency']);
                        $openingFloat = $openingFloat !== null ? $openingFloat->plus($bal) : $bal;
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
        [$cashSales, $cashCollections] = $this->calculateCashSalesAndCollections($company, $baseCurrency, $today, $approvedCashAccount);

        // 4. Today's cash disbursements
        $disbursements = $this->calculateCashDisbursements($company, $baseCurrency, $today, $approvedCashAccount);

        // 5. Today's operating cash expenses
        $expenses = $this->calculateCashExpenses($company, $baseCurrency, $today, $approvedCashAccount);

        // 6. Expected book cash
        $expectedCash = null;
        if ($openingFloat !== null) {
            $expectedCash = $openingFloat->plus($cashSales)->plus($cashCollections)->minus($disbursements)->minus($expenses);
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

        $countedInput = $request->input('counted_cash', $request->input('counted_physical_cash'));
        if ($countedInput === null || ! is_scalar($countedInput)) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Counted physical cash is required.',
                status: 422
            );
        }

        $strCounted = trim((string) $countedInput);
        if (preg_match('/^\d+(\.\d{1,4})?$/', $strCounted) !== 1) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Counted physical cash must be a non-negative decimal string with at most 4 decimal places.',
                status: 422
            );
        }

        $counted = BigDecimal::of($strCounted);
        $baseCurrency = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurrency, $company);
        $companyTimezone = (string) ($company->timezone ?? config('app.timezone', 'UTC'));
        $today = now($companyTimezone)->toDateString();
        $yesterday = now($companyTimezone)->subDay()->toDateString();

        // Resolve explicit approved same-company cash account
        $cashAccount = null;
        $rawAccountId = $request->input('cash_account_id');
        if ($rawAccountId !== null && is_scalar($rawAccountId)) {
            $caStr = trim((string) $rawAccountId);
            $cashAccount = preg_match('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $caStr) === 1
                ? MoneyAccount::where('company_id', $company->id)->where('public_id', $caStr)->where('account_type', 'cash')->where('is_active', true)->first()
                : (ctype_digit($caStr) ? MoneyAccount::where('company_id', $company->id)->where('id', (int) $caStr)->where('account_type', 'cash')->where('is_active', true)->first() : null);

            if ($cashAccount === null) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'VALIDATION_FAILED',
                    message: 'The specified cash account is invalid, inactive, or belongs to another company.',
                    status: 422
                );
            }
        }

        if ($cashAccount === null) {
            $cashAccount = $this->resolveApprovedCashAccount($company, $baseCurrency);
        }

        if ($cashAccount === null) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'An approved company cash account is required for shift close.',
                status: 422
            );
        }

        $rawNotes = $request->input('notes');
        $notes = is_string($rawNotes) ? trim($rawNotes) : null;

        $payloadForDigest = [
            'counted_cash' => (string) $counted,
            'cash_account_id' => (string) $cashAccount->public_id,
            'notes' => $notes,
        ];
        $requestHash = hash('sha256', json_encode($payloadForDigest, JSON_THROW_ON_ERROR));

        // Idempotent persistence check
        $existing = MobileShiftClosure::where('company_id', $company->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                return MobileApiBoundary::buildErrorResponse(
                    code: 'IDEMPOTENCY_CONFLICT',
                    message: 'A different payload was previously submitted with this idempotency key.',
                    status: 409
                );
            }

            return response()->json([
                'success' => true,
                'id' => (string) $existing->public_id,
                'shift_date' => (string) $existing->shift_date->format('Y-m-d'),
                'currency' => (string) $existing->currency_code,
                'currency_minor_units' => (int) $existing->currency_minor_units,
                'expected_cash' => (string) BigDecimal::of((string) $existing->expected_cash)->toScale($existing->currency_minor_units, RoundingMode::HALF_UP),
                'counted_cash' => (string) BigDecimal::of((string) $existing->counted_cash)->toScale($existing->currency_minor_units, RoundingMode::HALF_UP),
                'variance' => (string) BigDecimal::of((string) $existing->variance)->toScale($existing->currency_minor_units, RoundingMode::HALF_UP),
                'variance_status' => (string) $existing->variance_status,
                'is_shift_closed' => true,
                'closed_at' => $existing->closed_at->toIso8601ZuluString(),
            ], 200, [
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
            ]);
        }

        // Canonical ledger calculations scoped to the approved cash account
        $openingFloat = BigDecimal::zero();
        try {
            app(CompanyContext::class)->setCompany($company, $user);
            $openingBalances = $moneyBalanceQuery->forType($company, 'cash', $yesterday);
            foreach ($openingBalances as $row) {
                if ($row['public_id'] === $cashAccount->public_id && ($row['currency_balance_available'] ?? false) && isset($row['balance_currency'])) {
                    $openingFloat = BigDecimal::of((string) $row['balance_currency']);
                    break;
                }
            }
        } catch (Throwable) {
            $openingFloat = BigDecimal::zero();
        }

        [$cashSales, $cashCollections] = $this->calculateCashSalesAndCollections($company, $baseCurrency, $today, $cashAccount);
        $disbursements = $this->calculateCashDisbursements($company, $baseCurrency, $today, $cashAccount);
        $expenses = $this->calculateCashExpenses($company, $baseCurrency, $today, $cashAccount);

        $expectedCash = $openingFloat->plus($cashSales)->plus($cashCollections)->minus($disbursements)->minus($expenses);
        $variance = $counted->minus($expectedCash);

        $varianceStatus = match (true) {
            $variance->isZero() => 'EXACT',
            $variance->isPositive() => 'OVERAGE',
            default => 'SHORTAGE',
        };

        $device = $request->attributes->get('mobile_device');
        $deviceId = $device instanceof MobileDevice ? (string) $device->device_id : (string) ($request->attributes->get('mobile_device_id') ?? '');

        // Immutable audited count observation persistence: does NOT mutate ledger or auto-correct variance
        $closure = DB::transaction(function () use (
            $company,
            $cashAccount,
            $user,
            $deviceId,
            $today,
            $baseCurrency,
            $cmu,
            $openingFloat,
            $expectedCash,
            $counted,
            $variance,
            $varianceStatus,
            $idempotencyKey,
            $requestHash,
            $notes
        ): MobileShiftClosure {
            return MobileShiftClosure::create([
                'company_id' => $company->id,
                'cash_account_id' => $cashAccount->id,
                'actor_id' => $user->id,
                'device_id' => $deviceId !== '' ? $deviceId : null,
                'shift_date' => $today,
                'currency_code' => $baseCurrency,
                'currency_minor_units' => $cmu,
                'opening_cash' => (string) $openingFloat,
                'expected_cash' => (string) $expectedCash,
                'counted_cash' => (string) $counted,
                'variance' => (string) $variance,
                'variance_status' => $varianceStatus,
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
                'notes' => $notes,
                'actor_snapshot' => [
                    'id' => (int) $user->id,
                    'public_id' => (string) ($user->public_id ?? ''),
                    'name' => (string) $user->name,
                    'email' => (string) $user->email,
                ],
                'closed_at' => now(),
            ]);
        });

        return response()->json([
            'success' => true,
            'id' => (string) $closure->public_id,
            'shift_date' => $today,
            'currency' => $baseCurrency,
            'currency_minor_units' => $cmu,
            'expected_cash' => (string) $expectedCash->toScale($cmu, RoundingMode::HALF_UP),
            'counted_cash' => (string) $counted->toScale($cmu, RoundingMode::HALF_UP),
            'variance' => (string) $variance->toScale($cmu, RoundingMode::HALF_UP),
            'variance_status' => $varianceStatus,
            'is_shift_closed' => true,
            'closed_at' => $closure->closed_at->toIso8601ZuluString(),
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
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
            $ca = MoneyAccount::where('id', $setting->approved_cash_account_id)
                ->where('company_id', $company->id)
                ->where('account_type', 'cash')
                ->where('is_active', true)
                ->first();
            if ($ca !== null) {
                return $ca;
            }
        }

        return MoneyAccount::where('company_id', $company->id)
            ->where('account_type', 'cash')
            ->where('currency_code', $baseCurrency)
            ->where('is_active', true)
            ->first();
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
