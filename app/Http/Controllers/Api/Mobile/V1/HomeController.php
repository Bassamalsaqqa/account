<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Money\CurrencyMinorUnitsResolver;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class HomeController extends Controller
{
    /**
     * GET /api/mobile/v1/companies/{companyId}/home
     * Return live merchant dashboard shell metrics:
     * - Today's sales count and total
     * - Cash drawer balance
     * - Low stock items count
     * - Total customer receivables
     * - Total supplier payables
     * - Recent invoices
     * - Business header info
     */
    public function show(
        Request $request,
        string $companyId,
        MoneyBalanceQuery $moneyBalanceQuery,
        CustomerBalanceQuery $customerBalanceQuery,
        VendorBalanceQuery $vendorBalanceQuery,
        CurrencyMinorUnitsResolver $minorUnitsResolver
    ): JsonResponse {
        /** @var Company $company */
        $company = $request->attributes->get('mobile_company');
        /** @var User $user */
        $user = $request->attributes->get('mobile_user');

        setPermissionsTeamId($company->id);

        if (! $user->memberships()->where('company_id', $company->id)->where('status', 'active')->exists()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized.',
                status: 403
            );
        }

        $baseCurrency = (string) $company->base_currency_code;
        $cmu = $minorUnitsResolver->resolve($baseCurrency, $company);
        $companyTimezone = (string) ($company->timezone ?? config('app.timezone', 'UTC'));
        $today = now($companyTimezone)->toDateString();

        // 1. Today's sales invoices count and total volume in base currency (null if unauthorized)
        $salesTodayCount = null;
        $salesTodayTotal = null;
        if ($user->hasPermissionTo('sales.invoice.view')) {
            $todayInvoices = SalesInvoice::where('company_id', $company->id)
                ->where('status', SalesInvoice::STATUS_POSTED)
                ->where('currency_code', $baseCurrency)
                ->whereDate('issue_date', $today)
                ->get(['id', 'grand_total_currency']);

            $salesTodayCount = $todayInvoices->count();
            $totalSales = BigDecimal::zero();
            foreach ($todayInvoices as $inv) {
                $totalSales = $totalSales->plus(BigDecimal::of((string) $inv->grand_total_currency));
            }
            $salesTodayTotal = (string) $totalSales->toScale($cmu, RoundingMode::HALF_UP);
        }

        // 2. Cash in drawer from approved cash drawer account or base currency cash accounts (null if unauthorized or unavailable)
        $cashInDrawer = null;
        if ($user->hasPermissionTo('money.cash.view')) {
            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $setting = CompanyMobileSetting::where('company_id', $company->id)->first();
                $approvedCashAccountId = $setting?->approved_cash_account_id;
                $approvedCashAccount = null;
                if ($approvedCashAccountId !== null) {
                    $approvedCashAccount = MoneyAccount::where('id', $approvedCashAccountId)
                        ->where('company_id', $company->id)
                        ->where('account_type', 'cash')
                        ->where('is_active', true)
                        ->first();
                }

                $cashAccounts = $moneyBalanceQuery->forType($company, 'cash');
                $drawerTotal = BigDecimal::zero();
                $foundValidAccount = false;

                foreach ($cashAccounts as $account) {
                    if (! ($account['is_active'] ?? false)) {
                        continue;
                    }

                    $matches = $approvedCashAccount !== null
                        ? ($account['public_id'] === $approvedCashAccount->public_id)
                        : ($account['currency_code'] === $baseCurrency);

                    if ($matches) {
                        if (! ($account['currency_balance_available'] ?? false) || ! isset($account['balance_currency'])) {
                            // If balance is unavailable or unconvertible, do NOT invent zero
                            $drawerTotal = null;
                            $foundValidAccount = false;
                            break;
                        }

                        $drawerTotal = $drawerTotal->plus(BigDecimal::of((string) $account['balance_currency']));
                        $foundValidAccount = true;
                    }
                }

                if ($foundValidAccount && $drawerTotal !== null) {
                    $cashInDrawer = (string) $drawerTotal->toScale($cmu, RoundingMode::HALF_UP);
                }
            } catch (Throwable) {
                $cashInDrawer = null;
            }
        }

        // 3. Low stock products count (null if unauthorized)
        $lowStockCount = null;
        if ($user->hasPermissionTo('inventory.stock.view')) {
            $lowStockCount = Product::where('company_id', $company->id)
                ->where('active', true)
                ->where('product_type', Product::TYPE_STOCK)
                ->whereNotNull('minimum_stock_base')
                ->whereHas('costState', function ($q): void {
                    $q->whereColumn('quantity_base', '<=', 'products.minimum_stock_base');
                })
                ->count();
        }

        // 4. Total customer receivables (base currency only, no cross-currency sums, null if unauthorized or error)
        $totalReceivables = null;
        if ($user->hasPermissionTo('customers.view')) {
            $customerIds = Customer::where('company_id', $company->id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();

            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $customerBalances = $customerBalanceQuery->execute($customerIds);
                $receivablesSum = BigDecimal::zero();
                foreach ($customerBalances as $custCurrencies) {
                    if (isset($custCurrencies[$baseCurrency])) {
                        $out = BigDecimal::of($custCurrencies[$baseCurrency]['outstanding']);
                        if ($out->isPositive()) {
                            $receivablesSum = $receivablesSum->plus($out);
                        }
                    }
                }
                $totalReceivables = (string) $receivablesSum->toScale($cmu, RoundingMode::HALF_UP);
            } catch (Throwable) {
                $totalReceivables = null;
            }
        }

        // 5. Total supplier payables (base currency only, no cross-currency sums, null if unauthorized or error)
        $totalPayables = null;
        if ($user->hasPermissionTo('vendors.view')) {
            $vendorIds = Vendor::where('company_id', $company->id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();

            try {
                app(CompanyContext::class)->setCompany($company, $user);
                $vendorBalances = $vendorBalanceQuery->execute($vendorIds);
                $payablesSum = BigDecimal::zero();
                foreach ($vendorBalances as $vendCurrencies) {
                    if (isset($vendCurrencies[$baseCurrency])) {
                        $bal = BigDecimal::of($vendCurrencies[$baseCurrency]['balance']);
                        if ($bal->isPositive()) {
                            $payablesSum = $payablesSum->plus($bal);
                        }
                    }
                }
                $totalPayables = (string) $payablesSum->toScale($cmu, RoundingMode::HALF_UP);
            } catch (Throwable) {
                $totalPayables = null;
            }
        }

        // 6. Recent 5 posted sales invoices (using canonical financial summary and immutable frozen snapshots)
        $recentInvoices = [];
        if ($user->hasPermissionTo('sales.invoice.view')) {
            $recentInvoices = SalesInvoice::where('company_id', $company->id)
                ->where('status', SalesInvoice::STATUS_POSTED)
                ->with(['customer' => fn ($q) => $q->where('company_id', $company->id)])
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get()
                ->map(function (SalesInvoice $inv) use ($cmu): array {
                    $summary = $inv->calculateFinancialSummary();
                    $paid = $summary['paid'];
                    $grandTotal = BigDecimal::of((string) $inv->grand_total_currency);
                    $remaining = $summary['outstanding'];

                    $isPaidInFull = $remaining->isZero() || $remaining->isNegative();
                    $isPartiallyPaid = $paid->isPositive() && $remaining->isPositive();

                    if ($isPaidInFull) {
                        $hasCashPayment = $inv->paymentAllocations()
                            ->whereHas('customerPayment', fn ($q) => $q->where('payment_method', CustomerPayment::METHOD_CASH))
                            ->exists();
                        $paymentStatus = $hasCashPayment ? 'PAID_CASH' : 'PAID';
                    } elseif ($isPartiallyPaid) {
                        $paymentStatus = 'PARTIAL';
                    } else {
                        $paymentStatus = 'ON_CREDIT';
                    }

                    $snapshot = $inv->customer_snapshot;
                    $customerName = is_array($snapshot)
                        ? (string) ($snapshot['name_ar'] ?? $snapshot['name_en'] ?? $snapshot['business_name_ar'] ?? $snapshot['business_name_en'] ?? $snapshot['business_name'] ?? '')
                        : '';

                    $date = $inv->issue_date instanceof Carbon
                        ? $inv->issue_date->format('Y-m-d')
                        : substr((string) $inv->issue_date, 0, 10);

                    return [
                        'id' => (string) $inv->public_id,
                        'invoice_number' => (string) ($inv->invoice_number ?? $inv->public_id),
                        'customer_name' => $customerName,
                        'date' => $date,
                        'total' => (string) $grandTotal->toScale($cmu, RoundingMode::HALF_UP),
                        'paid' => (string) $paid->toScale($cmu, RoundingMode::HALF_UP),
                        'remaining' => (string) $remaining->toScale($cmu, RoundingMode::HALF_UP),
                        'currency' => (string) $inv->currency_code,
                        'payment_status' => $paymentStatus,
                    ];
                })
                ->values()
                ->all();
        }

        // 7. Store / business info
        $businessInfo = [
            'name_ar' => (string) ($company->name_ar ?? $company->displayName()),
            'name_en' => (string) ($company->name_en ?? $company->displayName()),
            'city_ar' => (string) ($company->address_ar ?? ''),
            'city_en' => (string) ($company->address_en ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'tax_number' => (string) ($company->tax_number ?? ''),
            'default_currency' => $baseCurrency,
            'default_currency_minor_units' => $cmu,
        ];

        return response()->json([
            'company_id' => $company->public_id,
            'today' => $today,
            'sales_today_count' => $salesTodayCount,
            'sales_today_total' => $salesTodayTotal,
            'cash_in_drawer' => $cashInDrawer,
            'low_stock_count' => $lowStockCount,
            'total_receivables' => $totalReceivables,
            'total_payables' => $totalPayables,
            'recent_invoices' => $recentInvoices,
            'business_info' => $businessInfo,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
