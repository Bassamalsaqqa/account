<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\VendorPayment;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        if (! $user->hasPermissionTo('reports.financial.view') && ! $user->hasPermissionTo('money.cash.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required reports permission.',
                status: 403
            );
        }

        $baseCurrency = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurrency, $company);
        $today = now()->format('Y-m-d');

        // 1. Opening float (from query or zero default - never invent 350.00)
        $openingFloat = BigDecimal::zero();
        if ($request->has('opening_float') && is_scalar($request->query('opening_float'))) {
            try {
                $openingFloat = BigDecimal::of((string) $request->query('opening_float'));
            } catch (Throwable) {
                $openingFloat = BigDecimal::zero();
            }
        }

        // 2. Today's cash sales
        $salesInvoicesQuery = SalesInvoice::where('company_id', $company->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->where('currency_code', $baseCurrency)
            ->whereDate('issue_date', $today);

        $salesCount = $salesInvoicesQuery->count();
        $cashSalesToday = $salesInvoicesQuery->sum('paid_amount');
        $cashSales = BigDecimal::of((string) $cashSalesToday);

        // 3. Today's customer debt collections
        $collectionsToday = CustomerPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->where('currency_code', $baseCurrency)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $collections = BigDecimal::of((string) $collectionsToday);

        // 4. Today's supplier cash disbursements
        $disbursementsToday = VendorPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->where('currency_code', $baseCurrency)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $disbursements = BigDecimal::of((string) $disbursementsToday);

        // 5. Today's cash expenses
        $expensesToday = Expense::where('company_id', $company->id)
            ->where('currency_code', $baseCurrency)
            ->whereDate('expense_date', $today)
            ->sum('amount');
        $expenses = BigDecimal::of((string) $expensesToday);

        // 6. Expected book cash = opening float + cash sales + collections - disbursements - expenses
        $expectedCash = $openingFloat->plus($cashSales)->plus($collections)->minus($disbursements)->minus($expenses);

        return response()->json([
            'company_id' => $company->public_id,
            'shift_date' => $today,
            'currency' => $baseCurrency,
            'currency_minor_units' => $cmu,
            'opening_float' => (string) $openingFloat->toScale(2, RoundingMode::HALF_UP),
            'cash_sales' => (string) $cashSales->toScale(2, RoundingMode::HALF_UP),
            'cash_collections' => (string) $collections->toScale(2, RoundingMode::HALF_UP),
            'cash_disbursements' => (string) $disbursements->toScale(2, RoundingMode::HALF_UP),
            'operating_expenses' => (string) $expenses->toScale(2, RoundingMode::HALF_UP),
            'expected_cash' => (string) $expectedCash->toScale(2, RoundingMode::HALF_UP),
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

        $counted = BigDecimal::of((string) $countedInput);
        if ($counted->isNegative()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'VALIDATION_FAILED',
                message: 'Counted physical cash cannot be negative.',
                status: 422
            );
        }

        $baseCurrency = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurrency, $company);
        $today = now()->format('Y-m-d');

        $openingFloat = BigDecimal::zero();
        if ($request->has('opening_float') && is_scalar($request->input('opening_float'))) {
            try {
                $openingFloat = BigDecimal::of((string) $request->input('opening_float'));
            } catch (Throwable) {
                $openingFloat = BigDecimal::zero();
            }
        }

        $cashSalesToday = SalesInvoice::where('company_id', $company->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->where('currency_code', $baseCurrency)
            ->whereDate('issue_date', $today)
            ->sum('paid_amount');
        $cashSales = BigDecimal::of((string) $cashSalesToday);

        $collectionsToday = CustomerPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->where('currency_code', $baseCurrency)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $collections = BigDecimal::of((string) $collectionsToday);

        $disbursementsToday = VendorPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->where('currency_code', $baseCurrency)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $disbursements = BigDecimal::of((string) $disbursementsToday);

        $expensesToday = Expense::where('company_id', $company->id)
            ->where('currency_code', $baseCurrency)
            ->whereDate('expense_date', $today)
            ->sum('amount');
        $expenses = BigDecimal::of((string) $expensesToday);

        $expectedCash = $openingFloat->plus($cashSales)->plus($collections)->minus($disbursements)->minus($expenses);
        $variance = $counted->minus($expectedCash);

        $varianceStatus = match (true) {
            $variance->isZero() => 'MATCH',
            $variance->isPositive() => 'OVERAGE',
            default => 'SHORTAGE',
        };

        return response()->json([
            'company_id' => $company->public_id,
            'shift_date' => $today,
            'expected_book_cash_ils' => (string) $expectedCash->toScale(2, RoundingMode::HALF_UP),
            'counted_physical_cash_ils' => (string) $counted->toScale(2, RoundingMode::HALF_UP),
            'variance_ils' => (string) $variance->toScale(2, RoundingMode::HALF_UP),
            'variance_status' => $varianceStatus,
            'is_shift_closed' => true,
            'closed_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'closed_by' => (string) $user->name,
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
}
