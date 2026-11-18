<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Mobile\V1;

use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Sales\Queries\CustomerBalanceQuery;
use App\Http\Controllers\Controller;
use App\Http\Middleware\MobileApiBoundary;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Models\Vendor;
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
        VendorBalanceQuery $vendorBalanceQuery
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
        $today = now()->format('Y-m-d');

        // 1. Today's sales invoices count and total volume (in base currency to prevent cross-currency distortion)
        $salesTodayCount = 0;
        $salesTodayTotal = BigDecimal::zero();
        if ($user->hasPermissionTo('sales.invoice.view')) {
            $todayInvoices = SalesInvoice::where('company_id', $company->id)
                ->where('status', SalesInvoice::STATUS_POSTED)
                ->whereDate('issue_date', $today)
                ->get(['id', 'grand_total_base']);

            $salesTodayCount = $todayInvoices->count();
            foreach ($todayInvoices as $inv) {
                $salesTodayTotal = $salesTodayTotal->plus((string) $inv->grand_total_base);
            }
        }

        // 2. Cash in drawer from active cash money accounts
        $cashInDrawer = BigDecimal::zero();
        if ($user->hasPermissionTo('money.cash.view') || $user->hasPermissionTo('reports.financial.view')) {
            try {
                $cashAccounts = $moneyBalanceQuery->forType($company, 'cash');
                foreach ($cashAccounts as $account) {
                    if ($account['is_active'] && isset($account['balance_currency'])) {
                        $cashInDrawer = $cashInDrawer->plus((string) $account['balance_currency']);
                    }
                }
            } catch (Throwable) {
                // If money query not permitted or unconfigured, keep zero
            }
        }

        // 3. Low stock products count
        $lowStockCount = 0;
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

        // 4. Total customer receivables (base currency only, no cross-currency sums)
        $totalReceivables = BigDecimal::zero();
        if ($user->hasPermissionTo('customers.view')) {
            $customerIds = Customer::where('company_id', $company->id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();

            try {
                $customerBalances = $customerBalanceQuery->execute($customerIds);
                foreach ($customerBalances as $custCurrencies) {
                    if (isset($custCurrencies[$baseCurrency])) {
                        $out = BigDecimal::of($custCurrencies[$baseCurrency]['outstanding']);
                        if ($out->isPositive()) {
                            $totalReceivables = $totalReceivables->plus($out);
                        }
                    }
                }
            } catch (Throwable) {
                // Keep zero if unauthorized
            }
        }

        // 5. Total supplier payables (base currency only, no cross-currency sums)
        $totalPayables = BigDecimal::zero();
        if ($user->hasPermissionTo('vendors.view')) {
            $vendorIds = Vendor::where('company_id', $company->id)
                ->where('status', 'active')
                ->pluck('id')
                ->all();

            try {
                $vendorBalances = $vendorBalanceQuery->execute($vendorIds);
                foreach ($vendorBalances as $vendCurrencies) {
                    if (isset($vendCurrencies[$baseCurrency])) {
                        $bal = BigDecimal::of($vendCurrencies[$baseCurrency]['balance']);
                        if ($bal->isPositive()) {
                            $totalPayables = $totalPayables->plus($bal);
                        }
                    }
                }
            } catch (Throwable) {
                // Keep zero if unauthorized
            }
        }

        // 6. Recent 5 posted sales invoices (using stored immutable customer snapshots)
        $recentInvoices = [];
        if ($user->hasPermissionTo('sales.invoice.view')) {
            $recentInvoices = SalesInvoice::where('company_id', $company->id)
                ->where('status', SalesInvoice::STATUS_POSTED)
                ->with(['customer' => fn ($q) => $q->where('company_id', $company->id)])
                ->orderBy('id', 'desc')
                ->limit(5)
                ->get()
                ->map(function (SalesInvoice $inv): array {
                    $paid = BigDecimal::of((string) ($inv->paid_amount ?? '0.000000'));
                    $grandTotal = BigDecimal::of((string) $inv->grand_total_currency);
                    $remaining = $grandTotal->minus($paid);

                    $paymentStatus = match (true) {
                        $remaining->isZero() || $remaining->isNegative() => 'PAID_CASH',
                        $paid->isPositive() => 'PARTIAL',
                        default => 'ON_CREDIT',
                    };

                    $customerName = is_array($inv->customer_snapshot) && ! empty($inv->customer_snapshot['name'])
                        ? (string) $inv->customer_snapshot['name']
                        : (string) $inv->customer->displayName();

                    $date = $inv->issue_date instanceof Carbon
                        ? $inv->issue_date->format('Y-m-d')
                        : substr((string) $inv->issue_date, 0, 10);

                    return [
                        'id' => (string) $inv->public_id,
                        'invoice_number' => (string) ($inv->invoice_number ?? $inv->public_id),
                        'customer_name' => $customerName,
                        'date' => $date,
                        'total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
                        'paid' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
                        'remaining' => (string) $remaining->toScale(2, RoundingMode::HALF_UP),
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
            'city_ar' => (string) ($company->city_ar ?? ''),
            'city_en' => (string) ($company->city_en ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'tax_number' => (string) ($company->tax_number ?? ''),
            'default_currency' => $baseCurrency,
        ];

        return response()->json([
            'company_id' => $company->public_id,
            'today' => $today,
            'sales_today_count' => $salesTodayCount,
            'sales_today_total' => (string) $salesTodayTotal->toScale(2, RoundingMode::HALF_UP),
            'cash_in_drawer' => (string) $cashInDrawer->toScale(2, RoundingMode::HALF_UP),
            'low_stock_count' => $lowStockCount,
            'total_receivables' => (string) $totalReceivables->toScale(2, RoundingMode::HALF_UP),
            'total_payables' => (string) $totalPayables->toScale(2, RoundingMode::HALF_UP),
            'recent_invoices' => $recentInvoices,
            'business_info' => $businessInfo,
        ], 200, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, private',
        ]);
    }
}
