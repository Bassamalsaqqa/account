<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Purchasing\AllocateLandedCostAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\CreatePurchaseReturnDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostPurchaseReturnAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Application\Reporting\DTO\ProfitReportResult;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\Exceptions\MissingSystemAccountException;
use App\Application\Reporting\Queries\ProfitReportQuery;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\LandedCostAllocation;
use App\Models\LedgerAccount;
use App\Models\SalesInvoice;
use Carbon\Carbon;

class ProfitReportTest extends Phase8TestCase
{
    public function test_profit_calculation_with_canonical_gl_and_salary_double_count_prevention(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');

        // 1. Post Sales Invoice for 1,000 ILS (Revenue = 1000)
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل المبيعات',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $invoiceDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-05',
            'lines' => [
                ['product_id' => null, 'item_description' => 'استشارة تقنية', 'quantity' => '1', 'unit_price' => '1000.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $this->owner);

        // 2. Post Sales Return for 150 ILS (Sales Returns = 150 -> Net Sales = 850)
        $returnDraft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'sales_invoice_id' => $invoiceDraft->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-06',
            'lines' => [
                ['sales_invoice_line_id' => $invoiceDraft->lines->first()->id, 'quantity' => '0.15', 'unit_price' => '1000.000000'],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($returnDraft, $this->owner);

        // 3. Post Operating Expense for 120 ILS electricity (Operating Expenses = 120)
        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-07',
            'classification' => 'operating',
            'description' => 'فاتورة كهرباء تشرين',
            'currency_code' => 'ILS',
            'amount' => '120.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'exp-profit-01',
        ]);

        // 4. Post Salary Entry for 300 ILS (Salary Expense = 300)
        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-08',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'base_salary' => '300.000000',
            'allowances' => '0.000000',
            'deductions' => '0.000000',
            'notes' => 'راتب شهر تشرين',
            'idempotency_key' => 'sal-profit-01',
        ]);

        // 5. Query Profit Report for this month (October 2026)
        $query = app(ProfitReportQuery::class);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);

        $result = $query->execute($this->company, [
            'period' => $period,
        ], $this->owner);

        $this->assertInstanceOf(ProfitReportResult::class, $result);
        $this->assertSame('1000.000000', $result->salesRevenue);
        $this->assertSame('150.000000', $result->salesReturns);
        $this->assertSame('850.000000', $result->netSales);
        $this->assertSame('0.000000', $result->cogs);
        $this->assertSame('850.000000', $result->grossProfit);

        // Operating expenses must be strictly 120.000000, excluding salary expense
        $this->assertSame('120.000000', $result->operatingExpenses);
        $this->assertSame('300.000000', $result->salaryExpense);

        // Net Profit = Gross Profit (850) - Operating Expenses (120) - Salary Expense (300) = 430.000000
        $this->assertSame('430.000000', $result->netProfit);
        $this->assertSame('ILS', $result->baseCurrency);

        // Verify operating expense breakdown has electricity
        $this->assertCount(1, $result->operatingExpenseBreakdown);
        $this->assertSame('120.000000', $result->operatingExpenseBreakdown[0]['amount']);
        $this->assertSame($this->operatingCategory->ledger_account_id, $result->operatingExpenseBreakdown[0]['account_id']);
    }

    public function test_profit_reversal_in_later_period_respects_event_dates(): void
    {
        // 1. Post Sales Invoice in Period 1: January 15, 2026 (500 ILS)
        Carbon::setTestNow('2026-01-15 12:00:00');

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الفترة الأولى',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $invoiceDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-01-15',
            'lines' => [
                ['product_id' => null, 'item_description' => 'بضاعة يناير', 'quantity' => '1', 'unit_price' => '500.000000'],
            ],
        ]);
        /** @var SalesInvoice $invoice */
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $this->owner);

        // 2. Void Sales Invoice in Period 2: February 20, 2026
        Carbon::setTestNow('2026-02-20 12:00:00');
        app(VoidSalesInvoiceAction::class)->execute($invoice, $this->owner, 'إلغاء بناء على طلب العميل');

        $query = app(ProfitReportQuery::class);

        // Query Period 1: January 2026
        $janPeriod = ReportPeriod::custom('2026-01-01', '2026-01-31', $this->company);
        $janResult = $query->execute($this->company, ['period' => $janPeriod], $this->owner);
        $this->assertSame('500.000000', $janResult->salesRevenue);
        $this->assertSame('500.000000', $janResult->netProfit);

        // Query Period 2: February 2026 (reversal leg lands here as negative revenue)
        $febPeriod = ReportPeriod::custom('2026-02-01', '2026-02-28', $this->company);
        $febResult = $query->execute($this->company, ['period' => $febPeriod], $this->owner);
        $this->assertSame('-500.000000', $febResult->salesRevenue);
        $this->assertSame('-500.000000', $febResult->netProfit);

        // Query Combined Period: January 1 to February 28, 2026 (nets out to 0)
        $combinedPeriod = ReportPeriod::custom('2026-01-01', '2026-02-28', $this->company);
        $combinedResult = $query->execute($this->company, ['period' => $combinedPeriod], $this->owner);
        $this->assertSame('0.000000', $combinedResult->salesRevenue);
        $this->assertSame('0.000000', $combinedResult->netProfit);
    }

    public function test_landed_cost_clearing_is_excluded_from_operating_expenses_and_profit(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');

        // Post Landed Cost Expense (classification = 'landed_cost') for 250 ILS
        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => '2026-10-09',
            'classification' => 'landed_cost',
            'description' => 'أجور شحن ونقل مشتريات (Landed Cost)',
            'currency_code' => 'ILS',
            'amount' => '250.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'exp-landed-01',
        ]);

        $query = app(ProfitReportQuery::class);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
        $result = $query->execute($this->company, ['period' => $period], $this->owner);

        // Landed cost hits landed_cost_clearing (asset), so operating expenses and profit must remain 0
        $this->assertSame('0.000000', $result->operatingExpenses);
        $this->assertSame('0.000000', $result->netProfit);
        $this->assertEmpty($result->operatingExpenseBreakdown);
    }

    public function test_foreign_currency_exact_decimal_conversion(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');

        // Post invoice in JOD at exchange rate 5.0000000000
        // 100 JOD * 5.00 = 500.000000 ILS base
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل دينار',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $invoiceDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'JOD',
            'exchange_rate' => '5.0000000000',
            'issue_date' => '2026-10-05',
            'lines' => [
                ['product_id' => null, 'item_description' => 'بضاعة دينار', 'quantity' => '1', 'unit_price' => '100.000000'],
            ],
        ]);
        app(PostSalesInvoiceAction::class)->execute($invoiceDraft, $this->owner);

        $query = app(ProfitReportQuery::class);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
        $result = $query->execute($this->company, ['period' => $period], $this->owner);

        $this->assertSame('500.000000', $result->salesRevenue);
        $this->assertSame('500.000000', $result->netSales);
        $this->assertSame('500.000000', $result->netProfit);
    }

    public function test_fail_closed_when_required_system_account_missing(): void
    {
        // Delete cogs account for this company
        LedgerAccount::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('system_key', 'cogs')
            ->delete();

        $query = app(ProfitReportQuery::class);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);

        $this->expectException(MissingSystemAccountException::class);
        $query->execute($this->company, ['period' => $period], $this->owner);
    }

    public function test_purchase_return_valuation_difference_affects_cogs(): void
    {
        Carbon::setTestNow('2026-10-10 10:00:00');

        // 1. Post Landed Cost Expense of 100 ILS (dated 2026-10-02)
        $expense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => Expense::CLASSIFICATION_LANDED_COST,
            'description' => 'شحن وتخليص جمركي',
            'currency_code' => 'ILS',
            'amount' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'landed-profit-01',
        ]);

        // 2. Post Purchase with 10 units at 100 ILS commercial = 1000 ILS (dated 2026-10-03)
        $purchaseDraft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_invoice_number' => 'INV-P8-01',
            'purchase_date' => '2026-10-03',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '100.000000',
                    'lots' => [],
                ],
            ],
        ]);

        // Allocate the 100 ILS landed cost to this purchase (1000 commercial + 100 landed = 1100 total inventory value, 110/unit)
        app(AllocateLandedCostAction::class)->execute(
            $purchaseDraft,
            $expense,
            LandedCostAllocation::METHOD_VALUE,
            $this->owner
        );

        $postedPurchase = app(PostPurchaseAction::class)->execute($purchaseDraft, $this->owner);

        // 3. Return 5 units (dated 2026-10-04)
        // Commercial AP relief is 5 * 100 = 500 ILS
        // Inventory removed is 5 * 110 = 550 ILS
        // Valuation adjustment D = 550 - 500 = 50 ILS debited to COGS
        $returnDraft = app(CreatePurchaseReturnDraftAction::class)->execute($this->company, $this->owner, [
            'purchase_id' => $postedPurchase->id,
            'return_date' => '2026-10-04',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                [
                    'purchase_line_id' => $postedPurchase->lines->first()->id,
                    'quantity' => '5',
                    'lots' => [],
                ],
            ],
        ]);
        app(PostPurchaseReturnAction::class)->execute($returnDraft, $this->owner);

        // 4. Query Profit Report for October 2026
        $query = app(ProfitReportQuery::class);
        $period = ReportPeriod::custom('2026-10-01', '2026-10-31', $this->company);
        $result = $query->execute($this->company, ['period' => $period], $this->owner);

        // In this period:
        // COGS = 50.000000 (from the purchase return valuation difference)
        // Landed cost clearing was capitalized and excluded from operating expenses
        // Gross Profit = -50.000000
        // Net Profit = -50.000000
        $this->assertSame('50.000000', $result->cogs);
        $this->assertSame('-50.000000', $result->grossProfit);
        $this->assertSame('-50.000000', $result->netProfit);
        $this->assertSame('0.000000', $result->operatingExpenses);
    }
}
