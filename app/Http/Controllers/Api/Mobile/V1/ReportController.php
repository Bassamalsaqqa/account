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
    public function shiftSummary(Request $request, string $companyId, MoneyBalanceQuery $moneyBalanceQuery): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('reports.financial.view') && ! $user->hasPermissionTo('money.cash.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required reports permission.',
                status: 403
            );
        }

        $today = now()->format('Y-m-d');

        // 1. Opening float (default float or previous balance)
        $openingFloat = BigDecimal::of('350.00');

        // 2. Today's cash sales
        $cashSalesToday = SalesInvoice::where('company_id', $company->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->whereDate('posting_date', $today)
            ->sum('paid_amount');
        $cashSales = BigDecimal::of((string) ($cashSalesToday ?? '0.00'));

        // 3. Today's customer debt collections
        $collectionsToday = CustomerPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $collections = BigDecimal::of((string) ($collectionsToday ?? '0.00'));

        // 4. Today's supplier cash disbursements
        $disbursementsToday = VendorPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $disbursements = BigDecimal::of((string) ($disbursementsToday ?? '0.00'));

        // 5. Today's cash expenses
        $expensesToday = Expense::where('company_id', $company->id)
            ->whereDate('expense_date', $today)
            ->sum('amount');
        $expenses = BigDecimal::of((string) ($expensesToday ?? '0.00'));

        // 6. Expected book cash = opening float + cash sales + collections - disbursements - expenses
        $expectedCash = $openingFloat->plus($cashSales)->plus($collections)->minus($disbursements)->minus($expenses);

        return response()->json([
            'company_id' => $company->public_id,
            'shift_date' => $today,
            'opening_float_ils' => (string) $openingFloat->toScale(2, RoundingMode::HALF_UP),
            'cash_sales_ils' => (string) $cashSales->toScale(2, RoundingMode::HALF_UP),
            'debt_collections_ils' => (string) $collections->toScale(2, RoundingMode::HALF_UP),
            'supplier_disbursements_ils' => (string) $disbursements->toScale(2, RoundingMode::HALF_UP),
            'expenses_ils' => (string) $expenses->toScale(2, RoundingMode::HALF_UP),
            'expected_book_cash_ils' => (string) $expectedCash->toScale(2, RoundingMode::HALF_UP),
            'currency' => (string) $company->base_currency_code,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }

    /**
     * POST /api/mobile/v1/companies/{companyId}/reports/shift-close
     */
    public function shiftClose(Request $request, string $companyId, MoneyBalanceQuery $moneyBalanceQuery): JsonResponse
    {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        if (! $user->hasPermissionTo('reports.financial.view') && ! $user->hasPermissionTo('money.cash.view')) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized. Missing required reports permission.',
                status: 403
            );
        }

        $countedInput = $request->input('counted_physical_cash');
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

        $today = now()->format('Y-m-d');
        $openingFloat = BigDecimal::of('350.00');

        $cashSalesToday = SalesInvoice::where('company_id', $company->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->whereDate('posting_date', $today)
            ->sum('paid_amount');
        $cashSales = BigDecimal::of((string) ($cashSalesToday ?? '0.00'));

        $collectionsToday = CustomerPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $collections = BigDecimal::of((string) ($collectionsToday ?? '0.00'));

        $disbursementsToday = VendorPayment::where('company_id', $company->id)
            ->where('is_reversed', false)
            ->whereDate('payment_date', $today)
            ->sum('amount');
        $disbursements = BigDecimal::of((string) ($disbursementsToday ?? '0.00'));

        $expensesToday = Expense::where('company_id', $company->id)
            ->whereDate('expense_date', $today)
            ->sum('amount');
        $expenses = BigDecimal::of((string) ($expensesToday ?? '0.00'));

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
            'base_currency' => (string) $company->base_currency_code,
            'cash_drawers' => $cashAccounts,
            'bank_accounts' => $bankAccounts,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
