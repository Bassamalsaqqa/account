<?php

declare(strict_types=1);

namespace App\Application\Reporting\DTO;

final class ProfitReportResult extends ReportResult
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  list<array{
     *     account_id: int,
     *     code: string,
     *     name_ar: string,
     *     name_en: string,
     *     system_key: ?string,
     *     amount: string
     * }>  $operatingExpenseBreakdown
     * @param  list<array<string, mixed>>  $rows
     */
    public function __construct(
        array $filters,
        public readonly string $salesRevenue,
        public readonly string $salesReturns,
        public readonly string $netSales,
        public readonly string $cogs,
        public readonly string $grossProfit,
        public readonly string $operatingExpenses,
        public readonly array $operatingExpenseBreakdown,
        public readonly string $salaryExpense,
        public readonly string $inventoryLoss,
        public readonly string $expiryLoss,
        public readonly string $fxGain,
        public readonly string $fxLoss,
        public readonly string $otherRevenue,
        public readonly string $otherExpense,
        public readonly string $netProfit,
        public readonly string $baseCurrency,
        array $rows = [],
        string $generatedAt = ''
    ) {
        $totals = [
            'sales_revenue' => $salesRevenue,
            'sales_revenue_base' => $salesRevenue,
            'sales_returns' => $salesReturns,
            'sales_returns_base' => $salesReturns,
            'net_sales' => $netSales,
            'net_sales_base' => $netSales,
            'cogs' => $cogs,
            'cogs_base' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_profit_base' => $grossProfit,
            'operating_expenses' => $operatingExpenses,
            'operating_expenses_base' => $operatingExpenses,
            'salary_expense' => $salaryExpense,
            'salary_expense_base' => $salaryExpense,
            'inventory_loss' => $inventoryLoss,
            'inventory_loss_base' => $inventoryLoss,
            'expiry_loss' => $expiryLoss,
            'expiry_loss_base' => $expiryLoss,
            'fx_gain' => $fxGain,
            'fx_gain_base' => $fxGain,
            'fx_loss' => $fxLoss,
            'fx_loss_base' => $fxLoss,
            'other_revenue' => $otherRevenue,
            'other_revenue_base' => $otherRevenue,
            'other_expense' => $otherExpense,
            'other_expense_base' => $otherExpense,
            'net_profit' => $netProfit,
            'net_profit_base' => $netProfit,
        ];

        $currency = [
            'base_currency_code' => $baseCurrency,
        ];

        parent::__construct(
            reportType: 'profit',
            filters: $filters,
            totals: $totals,
            rows: $rows,
            currency: $currency,
            pagination: null,
            generatedAt: $generatedAt ?: now()->toIso8601String(),
            meta: [
                'gl_authoritative' => true,
            ]
        );
    }
}
