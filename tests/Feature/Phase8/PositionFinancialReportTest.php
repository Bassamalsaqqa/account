<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Application\Reporting\Exceptions\InvalidReportFilterException;
use App\Application\Reporting\Exceptions\ReportingException;
use App\Application\Reporting\Queries\CheckRegisterReportQuery;
use App\Application\Reporting\Queries\ExpenseDetailReportQuery;
use App\Application\Reporting\Queries\ExpenseReportQuery;
use App\Application\Reporting\Queries\MoneyBalanceReportQuery;
use App\Application\Reporting\Queries\MoneyMovementReportQuery;
use App\Application\Reporting\Queries\MoneyVendorPaymentReportQuery;
use App\Application\Reporting\Queries\PayrollAdvanceReportQuery;
use App\Application\Reporting\Queries\PayrollEmployeeStatementReportQuery;
use App\Application\Reporting\Queries\PayrollPaymentReportQuery;
use App\Application\Reporting\Queries\PayrollSummaryReportQuery;
use App\Application\Reporting\Queries\ReceiptRegisterReportQuery;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\User;
use App\Models\VendorPayment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class PositionFinancialReportTest extends PositionTestCase
{
    private function filters(array $extra = []): array
    {
        return ['from' => '2026-10-01', 'to' => '2026-10-10', ...$extra];
    }

    /** @param list<string> $permissions */
    private function reader(array $permissions): User
    {
        $u = User::factory()->create();
        $this->company->users()->attach($u->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $r = Role::create(['company_id' => $this->company->id, 'name' => 'reader-'.Str::ulid(), 'guard_name' => 'web']);
        $r->syncPermissions($permissions);
        $u->assignRole($r);
        $this->activate($u);

        return $u;
    }

    private function expense(string $amount = '30', string $classification = 'operating'): Expense
    {
        return app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $classification === 'operating' ? $this->operatingCategory->id : $this->landedCategory->id,
            'expense_date' => '2026-10-02', 'classification' => $classification, 'description' => 'Frozen Expense',
            'currency_code' => 'ILS', 'amount' => $amount, 'exchange_rate' => '1', 'payment_method' => 'cash',
            'money_account_id' => $this->ilsCashAccount->id, 'idempotency_key' => 'expense-'.Str::ulid()]);
    }

    private function receipt(string $amount, string $date): CustomerPayment
    {
        $c = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Frozen customer', 'name_en' => 'Frozen customer', 'active' => true, 'created_by' => $this->owner->id]);

        return app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $c->id, 'payment_date' => $date, 'currency_code' => 'ILS', 'amount' => $amount, 'exchange_rate' => '1',
            'payment_method' => 'cash', 'money_account_id' => $this->ilsCashAccount->id, 'idempotency_key' => 'receipt-'.Str::ulid(), 'allocations' => []]);
    }

    private function vendorPayment(string $amount = '23.456789'): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'payment_date' => '2026-10-03', 'currency_code' => 'ILS', 'amount' => $amount, 'exchange_rate' => '1',
            'payment_method' => 'cash', 'money_account_id' => $this->ilsCashAccount->id, 'idempotency_key' => 'vendor-'.Str::ulid(), 'allocations' => []]);
    }

    public function test_report_permission_never_grants_expense_or_vendor_source_finance(): void
    {
        $this->expense();
        $this->vendorPayment('23');
        $u = $this->reader(['reports.expenses.view', 'reports.money.view', 'money.cash.view', 'money.bank.view']);
        foreach ([ExpenseReportQuery::class, MoneyVendorPaymentReportQuery::class] as $class) {
            try {
                app($class)->execute($this->company, $this->filters(), $u);
                $this->fail('Source guard missing.');
            } catch (AuthorizationException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_hidden_vendor_original_and_inverse_are_absent_and_running_values_unavailable(): void
    {
        $this->receipt('10', '2026-10-01');
        $p = $this->vendorPayment('23');
        $this->receipt('5', '2026-10-05');
        app(ReverseVendorPaymentAction::class)->execute($p, $this->owner, 'Sensitive reverse', '2026-10-06');
        $u = $this->reader(['reports.money.view', 'money.cash.view']);
        $f = $this->filters(['money_account_id' => $this->ilsCashAccount->id, 'per_page' => 1]);
        $r = app(MoneyMovementReportQuery::class)->execute($this->company, $f, $u);
        $this->assertSame(2, $r->pagination['total']);
        $this->assertCount(1, $r->rows);
        $this->assertNull($r->rows[0]['running_base']);
        $this->assertNull($r->rows[0]['running_currency']);
        $payload = json_encode($r->toArray());
        $this->assertStringNotContainsString($p->payment_number, $payload);
        $this->assertStringNotContainsString('vendor_payment', $payload);
        $this->assertSame('15.000000', app(MoneyBalanceReportQuery::class)->execute($this->company, $f, $u)->totals['total_base_balance']);
        $this->activate($this->owner);
        $r = app(MoneyMovementReportQuery::class)->execute($this->company, $this->filters(['money_account_id' => $this->ilsCashAccount->id]), $this->owner);
        $this->assertCount(4, $r->rows);
        $this->assertSame('15.000000', $r->rows[3]['running_base']);
    }

    public function test_money_windows_include_opening_activity_before_period_and_sql_totals_are_not_page_totals(): void
    {
        $this->receipt('10', '2026-10-01');
        $this->receipt('5', '2026-10-05');
        $this->receipt('7', '2026-10-06');
        $r = app(MoneyMovementReportQuery::class)->execute($this->company, $this->filters(['from' => '2026-10-05', 'money_account_id' => $this->ilsCashAccount->id, 'per_page' => 1]), $this->owner);
        $this->assertSame('15.000000', $r->rows[0]['running_base']);
        $this->assertSame('12.000000', $r->totals['total_debit_base']);
        $this->assertSame(2, $r->pagination['total']);
    }

    public function test_vendor_and_receipt_register_use_frozen_party_and_business_inverse_date(): void
    {
        $v = $this->vendorPayment('23');
        $c = $this->receipt('10', '2026-10-01');
        $this->vendor->update(['name_ar' => 'Renamed master', 'name_en' => 'Renamed master']);
        app(ReverseVendorPaymentAction::class)->execute($v, $this->owner, 'reason', '2026-10-20');
        $r = app(MoneyVendorPaymentReportQuery::class)->execute($this->company, $this->filters(), $this->owner);
        $this->assertSame('23.000000', $r->totals['total_base_amount']);
        $this->assertCount(1, $r->rows);
        $this->assertNotSame('Renamed master', $r->rows[0]['party_name']);
        $after = app(MoneyVendorPaymentReportQuery::class)->execute($this->company, $this->filters(['to' => '2026-10-20']), $this->owner);
        $this->assertSame('0.000000', $after->totals['total_base_amount']);
        $this->assertCount(2, $after->rows);
        $receipt = app(ReceiptRegisterReportQuery::class)->execute($this->company, $this->filters(), $this->owner);
        $this->assertSame($c->payment_number, $receipt->rows[0]['payment_number']);
    }

    public function test_check_status_asof_uses_completed_event_history_and_visibility_before_limit(): void
    {
        $incoming = $this->check('incoming');
        $outgoing = $this->check('outgoing');
        $this->event($incoming, 'deposit', '3.50', '2026-10-03');
        $this->event($incoming, 'clear', '3.50', '2026-10-20');
        $u = $this->reader(['reports.money.view', 'money.check.view']);
        $r = app(CheckRegisterReportQuery::class)->execute($this->company, $this->filters(['per_page' => 1]), $u);
        $this->assertSame(1, $r->pagination['total']);
        $this->assertSame('deposited', $r->rows[0]['status']);
        $this->assertSame($incoming->check_number, $r->rows[0]['check_number']);
        $this->assertStringNotContainsString($outgoing->check_number, json_encode($r->toArray()));
        $later = app(CheckRegisterReportQuery::class)->execute($this->company, $this->filters(['to' => '2026-10-20']), $u);
        $this->assertSame('cleared', $later->rows[0]['status']);
    }

    public function test_expense_frozen_category_and_signed_inverse_are_exact_and_landeds_are_source_redacted(): void
    {
        $e = $this->expense('30');
        $this->expense('10', 'landed_cost');
        $name = $e->category_snapshot['name_ar'];
        $this->operatingCategory->update(['name_ar' => 'Renamed category']);
        app(ReverseExpenseAction::class)->execute($e, $this->owner, 'reason', '2026-10-20');
        $r = $this->assertZeroEconomicWrites(fn () => app(ExpenseReportQuery::class)->execute($this->company, $this->filters(), $this->owner));
        $this->assertSame('30.000000', $r->totals['ordinary_operating_expense_base']);
        $this->assertSame('10.000000', $r->totals['landed_cost_clearing_base']);
        $this->assertSame($name, $r->rows[0]['category_name']);
        $after = app(ExpenseReportQuery::class)->execute($this->company, $this->filters(['to' => '2026-10-20']), $this->owner);
        $this->assertSame('0.000000', $after->totals['ordinary_operating_expense_base']);
        $u = $this->reader(['reports.expenses.view', 'money.expense.view', 'reports.cost.view']);
        $detail = app(ExpenseDetailReportQuery::class)->execute($this->company, $this->filters(), $u);
        $this->assertCount(1, $detail->rows);
        $this->assertSame('operating', $detail->rows[0]['classification']);
        $summary = app(ExpenseReportQuery::class)->execute($this->company, $this->filters(), $u);
        $this->assertArrayNotHasKey('landed_cost_clearing_base', $summary->totals);
    }

    public function test_payroll_asof_reconstructs_historical_principal_book_relief_and_payment_inverse_date(): void
    {
        $a = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, ['employee_id' => $this->employee->id,
            'advance_date' => '2026-09-01', 'currency_code' => 'USD', 'amount' => '20', 'exchange_rate' => '3.5', 'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id, 'idempotency_key' => 'adv-report']);
        $e = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, ['employee_id' => $this->employee->id,
            'recognition_date' => '2026-09-30', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency_code' => 'USD',
            'exchange_rate' => '3.5', 'base_salary' => '100', 'advances' => [['advance_id' => $a->id, 'allocated_amount' => '10']], 'idempotency_key' => 'salary-report']);
        $p = app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, ['employee_id' => $this->employee->id,
            'payment_date' => '2026-10-05', 'currency_code' => 'USD', 'amount' => '40', 'exchange_rate' => '3.5', 'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id, 'allocations' => [['salary_entry_id' => $e->id, 'allocated_amount' => '40']], 'idempotency_key' => 'salary-payment-report']);
        app(ReverseSalaryPaymentAction::class)->execute($p, $this->owner, 'reason', '2026-10-20');
        $this->employee->update(['name' => 'Renamed Employee']);
        $r = $this->assertZeroEconomicWrites(fn () => app(PayrollSummaryReportQuery::class)->execute($this->company, $this->filters(), $this->owner));
        $this->assertSame('175.000000', $r->totals['outstanding_unpaid_salary_base']);
        $this->assertSame('50.000000', $r->totals['unpaid_salary_by_currency']['USD']);
        $this->assertSame('35.000000', $r->totals['outstanding_advances_base']);
        $this->assertNotSame('Renamed Employee', $r->rows[0]['employee_name']);
        $after = app(PayrollSummaryReportQuery::class)->execute($this->company, $this->filters(['to' => '2026-10-20']), $this->owner);
        $this->assertSame('315.000000', $after->totals['outstanding_unpaid_salary_base']);
        $adv = app(PayrollAdvanceReportQuery::class)->execute($this->company, $this->filters(), $this->owner);
        $this->assertSame('10.000000', $adv->rows[0]['remaining_amount']);
        $pay = app(PayrollPaymentReportQuery::class)->execute($this->company, $this->filters(['to' => '2026-10-20']), $this->owner);
        $this->assertSame('0.000000', $pay->totals['total_base_payments']);
        $stmt = app(PayrollEmployeeStatementReportQuery::class)->execute($this->company, $this->filters(['employee_id' => $this->employee->id, 'from' => '2026-09-01']), $this->owner);
        $this->assertSame('50.000000', $stmt->totals['outstanding_salary_by_currency']['USD']);
    }

    public function test_incoherent_reversal_provenance_fails_closed_instead_of_using_runtime_timestamp(): void
    {
        $e = $this->expense();
        app(ReverseExpenseAction::class)->execute($e, $this->owner, 'reason', '2026-10-20');
        DB::table('posting_batches')->where('id', $e->fresh()->reversal_posting_batch_id)->update(['reversal_of_id' => null]);
        $this->expectException(ReportingException::class);
        app(ExpenseReportQuery::class)->execute($this->company, $this->filters(), $this->owner);
    }

    public function test_stale_payroll_permission_and_malformed_raw_filters_fail_closed(): void
    {
        $u = $this->reader(['reports.payroll.view', 'employees.view', 'payroll.salary.view']);
        $role = $u->roles()->first();
        $role->revokePermissionTo('payroll.salary.view');
        try {
            app(PayrollSummaryReportQuery::class)->execute($this->company, $this->filters(), $u);
            $this->fail('Revoked permission accepted.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
        $this->activate($this->owner);
        $this->expectException(InvalidReportFilterException::class);
        app(ExpenseReportQuery::class)->execute($this->company, $this->filters(['category_id' => '3abc']), $this->owner);
    }
}
