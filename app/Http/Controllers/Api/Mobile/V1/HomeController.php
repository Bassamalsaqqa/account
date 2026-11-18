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

        if (! $user->memberships()->where('company_id', $company->id)->where('status', 'active')->exists()) {
            return MobileApiBoundary::buildErrorResponse(
                code: 'FORBIDDEN',
                message: 'Action is unauthorized.',
                status: 403
            );
        }

        $today = now()->format('Y-m-d');

        // 1. Today's sales invoices count and total volume
        $todayInvoices = SalesInvoice::where('company_id', $company->id)
            ->where('status', SalesInvoice::STATUS_POSTED)
            ->whereDate('posting_date', $today)
            ->get(['id', 'grand_total_currency', 'grand_total_base']);

        $salesTodayCount = $todayInvoices->count();
        $salesTodayTotal = BigDecimal::zero();
        foreach ($todayInvoices as $inv) {
            $salesTodayTotal = $salesTodayTotal->plus((string) $inv->grand_total_currency);
        }

        // 2. Cash in drawer from active cash money accounts
        $cashInDrawer = BigDecimal::zero();
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

        // 3. Low stock products count
        $lowStockCount = Product::where('company_id', $company->id)
            ->where('active', true)
            ->where('product_type', Product::TYPE_STOCK)
            ->whereNotNull('minimum_stock_base')
            ->whereHas('costState', function ($q): void {
                $q->whereColumn('quantity_base', '<=', 'products.minimum_stock_base');
            })
            ->count();

        // 4. Total customer receivables (balance > 0)
        $customerIds = Customer::where('company_id', $company->id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $totalReceivables = BigDecimal::zero();
        try {
            $customerBalances = $customerBalanceQuery->execute($customerIds);
            foreach ($customerBalances as $custCurrencies) {
                foreach ($custCurrencies as $currencyData) {
                    $out = BigDecimal::of($currencyData['outstanding']);
                    if ($out->isPositive()) {
                        $totalReceivables = $totalReceivables->plus($out);
                    }
                }
            }
        } catch (Throwable) {
            // Keep zero if unauthorized
        }

        // 5. Total supplier payables (balance > 0)
        $vendorIds = Vendor::where('company_id', $company->id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        $totalPayables = BigDecimal::zero();
        try {
            $vendorBalances = $vendorBalanceQuery->execute($vendorIds);
            foreach ($vendorBalances as $vendCurrencies) {
                foreach ($vendCurrencies as $currencyData) {
                    $bal = BigDecimal::of($currencyData['balance']);
                    if ($bal->isPositive()) {
                        $totalPayables = $totalPayables->plus($bal);
                    }
                }
            }
        } catch (Throwable) {
            // Keep zero if unauthorized
        }

        // 6. Recent 5 posted sales invoices
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

                return [
                    'id' => (string) $inv->public_id,
                    'invoice_number' => (string) ($inv->invoice_number ?? $inv->public_id),
                    'customer_name' => $inv->customer ? (string) $inv->customer->displayName() : 'عميل نقدي عام',
                    'date' => $inv->posting_date ? $inv->posting_date->format('Y-m-d') : now()->format('Y-m-d'),
                    'total' => (string) $grandTotal->toScale(2, RoundingMode::HALF_UP),
                    'paid' => (string) $paid->toScale(2, RoundingMode::HALF_UP),
                    'remaining' => (string) $remaining->toScale(2, RoundingMode::HALF_UP),
                    'currency' => (string) $inv->currency_code,
                    'payment_status' => $paymentStatus,
                ];
            })
            ->values()
            ->all();

        // 7. Store / business info
        $businessInfo = [
            'name_ar' => (string) ($company->name_ar ?? $company->name),
            'name_en' => (string) ($company->name_en ?? $company->name),
            'city_ar' => (string) ($company->city_ar ?? $company->city ?? ''),
            'city_en' => (string) ($company->city_en ?? $company->city ?? ''),
            'phone' => (string) ($company->phone ?? ''),
            'tax_number' => (string) ($company->tax_number ?? ''),
            'default_currency' => (string) $company->base_currency_code,
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
