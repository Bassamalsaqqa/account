<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Livewire\Pages\Money\CheckIndex;
use App\Models\Check;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\User;
use App\Services\Expenses\ExpenseReadService;
use App\Services\Money\CheckFinancialSourceResolver;
use App\Services\Payroll\PayrollReadService;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;

class CheckIntegrationAndVisibilityTest extends Phase7TestCase
{
    public function test_check_financial_source_resolver_resolves_all_phase7_sources(): void
    {
        // 1. Expense check via IssueCheckAction
        $expenseCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'expense',
            'category_id' => $this->operatingCategory->id,
            'amount' => '100.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-07',
            'due_date' => '2026-10-15',
            'check_number' => 'CHK-EXP-001',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'classification' => 'operating',
            'description' => 'Office cleaning',
            'idempotency_key' => 'chk-exp-key-01',
        ]);

        $resolver = app(CheckFinancialSourceResolver::class);
        $adapter = $resolver->resolve($expenseCheck);
        $this->assertSame('expense', $adapter->sourceType());
        $this->assertSame('outgoing', $adapter->direction());

        // 2. Employee Advance check via IssueCheckAction
        $advanceCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'employee_advance',
            'employee_id' => $this->employee->id,
            'amount' => '500.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-07',
            'due_date' => '2026-10-20',
            'check_number' => 'CHK-ADV-001',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'idempotency_key' => 'chk-adv-key-01',
        ]);

        $advanceAdapter = $resolver->resolve($advanceCheck);
        $this->assertSame('employee_advance', $advanceAdapter->sourceType());

        // 3. Salary Payment check via IssueCheckAction
        $entry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'recognition_date' => '2026-10-31',
            'base_salary' => '3000.00',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'idempotency_key' => 'sal-entry-key-01',
        ]);

        $paymentCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'salary_payment',
            'employee_id' => $this->employee->id,
            'amount' => '1000.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-31',
            'due_date' => '2026-11-05',
            'check_number' => 'CHK-SAL-001',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'idempotency_key' => 'sal-pay-key-01',
            'allocations' => [
                [
                    'salary_entry_id' => $entry->id,
                    'allocated_amount' => '1000.00',
                ],
            ],
        ]);

        $paymentAdapter = $resolver->resolve($paymentCheck);
        $this->assertSame('salary_payment', $paymentAdapter->sourceType());
    }

    public function test_check_index_filters_outgoing_rows_by_permission(): void
    {
        // Create an expense check
        $expenseCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'expense',
            'category_id' => $this->operatingCategory->id,
            'amount' => '100.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-07',
            'due_date' => '2026-10-15',
            'check_number' => 'CHK-EXP-101',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'classification' => 'operating',
            'description' => 'Office cleaning',
            'idempotency_key' => 'chk-exp-flt-01',
        ]);

        // Create an employee advance check
        $advanceCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'employee_advance',
            'employee_id' => $this->employee->id,
            'amount' => '200.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-07',
            'due_date' => '2026-10-20',
            'check_number' => 'CHK-ADV-102',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'idempotency_key' => 'chk-adv-flt-01',
        ]);

        // Create an operator user with ONLY money.check.view and money.expense.view (NO payroll permissions)
        $expenseUser = User::factory()->create();
        $this->company->users()->attach($expenseUser->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $expenseUser->givePermissionTo('money.check.view');
        $expenseUser->givePermissionTo('money.expense.view');

        // Impersonate expenseUser
        $this->actingAs($expenseUser);
        app(CompanyContext::class)->setCompany($this->company);

        Livewire::test(CheckIndex::class)
            ->assertSee('CHK-EXP-101')
            ->assertDontSee('CHK-ADV-102');

        // Create another operator with ONLY money.check.view and payroll.salary.view (NO expense permissions)
        $payrollUser = User::factory()->create();
        $this->company->users()->attach($payrollUser->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $payrollUser->givePermissionTo('money.check.view');
        $payrollUser->givePermissionTo('payroll.salary.view');

        $this->actingAs($payrollUser);
        Livewire::test(CheckIndex::class)
            ->assertDontSee('CHK-EXP-101')
            ->assertSee('CHK-ADV-102');
    }

    public function test_read_service_redactions_for_unauthorized_actors(): void
    {
        // Landed cost expense
        $landedExpense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->landedCategory->id,
            'amount' => '350.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'classification' => 'landed_cost',
            'expense_date' => '2026-10-07',
            'description' => 'Freight from port',
            'idempotency_key' => 'exp-landed-redact-01',
        ]);

        // Operating expense
        $operatingExpense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'amount' => '50.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'classification' => 'operating',
            'expense_date' => '2026-10-07',
            'description' => 'Coffee supplies',
            'idempotency_key' => 'exp-oper-redact-01',
        ]);

        // User with money.expense.view but WITHOUT purchasing.cost.view
        $clerk = User::factory()->create();
        $this->company->users()->attach($clerk->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $clerk->givePermissionTo('money.expense.view');

        $this->activate($clerk);
        $expenseReader = app(ExpenseReadService::class);
        $paginated = $expenseReader->paginate($this->company->id, $clerk);

        $landedInList = $paginated->getCollection()->firstWhere('id', $landedExpense->id);
        $operatingInList = $paginated->getCollection()->firstWhere('id', $operatingExpense->id);

        $this->assertNull($landedInList);
        $this->assertSame('50.000000', (string) $operatingInList->amount);

        // Employee directory redaction
        // User with employees.view but WITHOUT payroll.salary.view
        $hrViewer = User::factory()->create();
        $this->company->users()->attach($hrViewer->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $hrViewer->givePermissionTo('employees.view');

        $this->activate($hrViewer);
        $payrollReader = app(PayrollReadService::class);
        $dir = $payrollReader->employeeDirectory($this->company->id, $hrViewer);
        $empInDir = $dir->getCollection()->firstWhere('id', $this->employee->id);

        $this->assertNull($empInDir->default_salary);
        $this->assertNull($empInDir->salary_currency_code);

        // But owner sees default_salary
        $this->activate($this->owner);
        $ownerDir = $payrollReader->employeeDirectory($this->company->id, $this->owner);
        $ownerEmpInDir = $ownerDir->getCollection()->firstWhere('id', $this->employee->id);
        $this->assertSame('1000.000000', (string) $ownerEmpInDir->default_salary);
    }

    public function test_expense_check_cancellation_reverses_linked_expense(): void
    {
        $this->actingAs($this->owner);

        $expenseCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'expense',
            'category_id' => $this->operatingCategory->id,
            'amount' => '100.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-07',
            'due_date' => '2026-10-15',
            'check_number' => 'CHK-EXP-CAN-01',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'classification' => 'operating',
            'description' => 'Office cleaning',
            'idempotency_key' => 'chk-exp-can-key-01',
        ]);

        $this->assertSame('issued', $expenseCheck->status);
        $expense = Expense::where('check_id', $expenseCheck->id)->firstOrFail();
        $this->assertSame('posted', $expense->status);

        // Cancel the check
        app(TransitionCheckAction::class)->execute($expenseCheck, $this->owner, [
            'event_type' => 'cancel',
            'event_date' => '2026-10-08',
            'idempotency_key' => 'chk-cancel-evt-01',
            'notes' => 'Cancelling check and reversing expense',
        ]);

        $expenseCheck->refresh();
        $expense->refresh();

        $this->assertSame('cancelled', $expenseCheck->status);
        $this->assertSame('reversed', $expense->status);
        $this->assertNotNull($expense->reversal_posting_batch_id);
    }

    public function test_advance_check_cancellation_blocked_if_consumed(): void
    {
        $this->actingAs($this->owner);

        // 1. Issue advance check
        $advanceCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'employee_advance',
            'employee_id' => $this->employee->id,
            'amount' => '200.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'date' => '2026-10-07',
            'due_date' => '2026-10-20',
            'check_number' => 'CHK-ADV-CAN-02',
            'bank_name' => 'Bank of Palestine',
            'money_account_id' => $this->usdBankAccount->id,
            'idempotency_key' => 'chk-adv-can-key-02',
        ]);

        $advance = EmployeeAdvance::where('check_id', $advanceCheck->id)->firstOrFail();

        // 2. Consume advance in salary entry
        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'recognition_date' => '2026-10-31',
            'base_salary' => '1000.000000',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'advances' => [
                ['advance_id' => $advance->id, 'allocated_amount' => '200.000000'],
            ],
            'idempotency_key' => 'sal-entry-consume-01',
        ]);

        // 3. Attempting to cancel the check must fail because the advance is consumed
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Cannot reverse an employee advance consumed by active salary entries.');

        app(TransitionCheckAction::class)->execute($advanceCheck, $this->owner, [
            'event_type' => 'cancel',
            'event_date' => '2026-11-01',
            'idempotency_key' => 'chk-adv-cancel-fail-01',
            'notes' => 'Attempting cancellation',
        ]);
    }
}
