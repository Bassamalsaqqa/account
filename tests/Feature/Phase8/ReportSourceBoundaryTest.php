<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Queries\ExpenseReportQuery;
use App\Application\Reporting\Queries\MoneyVendorPaymentReportQuery;
use App\Application\Reporting\Queries\SalesGrossProfitReportQuery;
use Illuminate\Auth\Access\AuthorizationException;

class ReportSourceBoundaryTest extends Phase8TestCase
{
    public function test_general_expense_report_permission_is_not_expense_financial_authority(): void
    {
        $reader = $this->customActor(['reports.expenses.view']);
        $this->activate($reader);
        $this->expectException(AuthorizationException::class);
        app(ExpenseReportQuery::class)->execute($this->company, ['period' => $this->period()], $reader);
    }

    public function test_cash_bank_and_report_permissions_are_not_vendor_financial_authority(): void
    {
        $reader = $this->customActor(['reports.money.view', 'money.cash.view', 'money.bank.view']);
        $this->activate($reader);
        $this->expectException(AuthorizationException::class);
        app(MoneyVendorPaymentReportQuery::class)->execute($this->company, ['period' => $this->period()], $reader);
    }

    public function test_sales_profit_does_not_grant_inventory_cost_from_reporting_permissions(): void
    {
        $reader = $this->customActor(['reports.sales.view', 'reports.profit.view', 'reports.cost.view', 'sales.invoice.view']);
        $this->activate($reader);
        $this->expectException(AuthorizationException::class);
        app(SalesGrossProfitReportQuery::class)->execute($this->company, ['period' => $this->period()], $reader);
    }

    private function period(): ReportPeriod
    {
        return ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
    }
}
