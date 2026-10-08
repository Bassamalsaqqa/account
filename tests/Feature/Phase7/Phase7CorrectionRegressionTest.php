<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Payroll\ReverseEmployeeAdvanceAction;
use App\Actions\Payroll\ReverseSalaryEntryAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Actions\Payroll\UpdateEmployeeAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Money\Queries\MoneyActivityQuery;
use App\Domain\Money\Queries\MoneyMovementQuery;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Exceptions\IdempotencyConflictException;
use App\Livewire\Pages\Payroll\EmployeeForm;
use App\Livewire\Pages\Payroll\SalaryEntryForm;
use App\Livewire\Pages\Payroll\SalaryPaymentForm;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\PostingBatch;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Payroll\PayrollReadService;
use App\Services\Phase7\Phase7EventScope;
use App\Services\Phase7\Phase7History;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

class Phase7CorrectionRegressionTest extends Phase7TestCase
{
    private function expense(string $classification = 'operating'): Expense
    {
        return app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-02', 'classification' => $classification,
            'description' => 'RESTRICTED_REVIEW_927', 'currency_code' => 'ILS',
            'amount' => '927.13', 'exchange_rate' => '1', 'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id, 'idempotency_key' => 'review-exp',
        ]);
    }

    public function test_posted_history_cannot_be_rewritten_with_status_switch(): void
    {
        $expense = $this->expense();
        $this->expectException(ImmutableRecordException::class);
        $expense->update(['status' => 'reversed', 'amount' => '1.000000']);
    }

    public function test_cash_viewer_cannot_receive_expense_movement(): void
    {
        $this->expense();
        $reader = $this->customActor(['money.cash.view', 'money.bank.view']);
        $this->activate($reader);
        $rows = app(MoneyActivityQuery::class)->recent((int) $this->company->id, ['cash']);
        $this->assertCount(0, $rows, $rows->toJson());
    }

    public function test_balanced_wrong_expense_routing_is_rejected_by_reconciliation(): void
    {
        $expense = $this->expense();
        $otherCategory = ExpenseCategory::where('company_id', $this->company->id)
            ->where('id', '!=', $this->operatingCategory->id)->firstOrFail();
        DB::table('posting_lines')->where('posting_batch_id', $expense->posting_batch_id)
            ->where('debit_base', '>', '0')->update(['ledger_account_id' => $otherCategory->ledger_account_id]);
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy, json_encode($report->violations));
    }

    public function test_reversed_pending_landed_expense_remains_healthy(): void
    {
        $expense = $this->expense('landed_cost');
        app(ReverseExpenseAction::class)->execute($expense, $this->owner, 'Review reversal', '2026-10-03');
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report->violations));
    }

    public function test_generic_writer_cannot_forge_expense_event_without_source_action(): void
    {
        $command = new PostingCommand(
            company: $this->company, postingDate: Carbon::parse('2026-10-02'),
            sourceType: 'expense', sourceId: 999999, transactionCurrencyCode: 'ILS',
            baseCurrencyCode: 'ILS', exchangeRate: ExchangeRate::from('1'),
            idempotencyKey: 'review-forged', postedBy: $this->owner,
            lines: [
                PostingLineCommand::debit(1, (int) $this->operatingCategory->ledger_account_id, '10', 'ILS', '10', '1'),
                PostingLineCommand::credit(2, (int) $this->cashAccount->ledger_account_id, '10', 'ILS', '10', '1'),
            ],
        );
        $this->expectException(\LogicException::class);
        app(AccountingPostingService::class)->post($command);
    }

    private function salary(array $advances = [], string $amount = '50'): SalaryEntry
    {
        return app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-10-03',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'base_salary' => $amount,
            'advances' => $advances, 'idempotency_key' => 'review-salary',
        ]);
    }

    public function test_duplicate_advance_rows_cannot_overconsume_one_advance(): void
    {
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'advance_date' => '2026-10-02',
            'currency_code' => 'ILS', 'amount' => '50', 'exchange_rate' => '1',
            'payment_method' => 'cash', 'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'review-advance',
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->salary([
            ['advance_id' => $advance->id, 'allocated_amount' => '50'],
            ['advance_id' => $advance->id, 'allocated_amount' => '50'],
        ], '100');
    }

    public function test_duplicate_salary_rows_cannot_overpay_one_entry(): void
    {
        $entry = $this->salary();
        $this->expectException(\InvalidArgumentException::class);
        app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'payment_date' => '2026-10-04',
            'currency_code' => 'ILS', 'amount' => '100', 'exchange_rate' => '1',
            'payment_method' => 'cash', 'money_account_id' => $this->cashAccount->id,
            'allocations' => [
                ['salary_entry_id' => $entry->id, 'allocated_amount' => '50'],
                ['salary_entry_id' => $entry->id, 'allocated_amount' => '50'],
            ], 'idempotency_key' => 'review-salary-payment',
        ]);
    }

    public function test_salary_payment_form_renders_real_unpaid_entry(): void
    {
        $this->employee->update(['salary_currency_code' => 'ILS']);
        $this->salary();
        Livewire::test(SalaryPaymentForm::class,
            ['employee' => $this->employee->public_id])->assertSuccessful();
    }

    /** @return array<string,mixed> */
    private function advanceData(string $key = 'boundary-advance'): array
    {
        return ['employee_id' => $this->employee->id, 'advance_date' => '2026-10-02', 'currency_code' => 'ILS', 'amount' => '50', 'exchange_rate' => '1',
            'payment_method' => 'cash', 'money_account_id' => $this->cashAccount->id, 'idempotency_key' => $key];
    }

    /** @return array<string,mixed> */
    private function paymentData(SalaryEntry $entry, string $key = 'boundary-payment'): array
    {
        return ['employee_id' => $this->employee->id, 'payment_date' => '2026-10-04', 'currency_code' => 'ILS', 'amount' => '50', 'exchange_rate' => '1',
            'payment_method' => 'cash', 'money_account_id' => $this->cashAccount->id, 'idempotency_key' => $key,
            'allocations' => [['salary_entry_id' => $entry->id, 'allocated_amount' => '50']]];
    }

    private function unchanged(callable $work, string $exception): void
    {
        $tables = ['posting_batches', 'posting_lines', 'expenses', 'employee_advances', 'salary_entries', 'salary_payments', 'salary_advance_allocations', 'salary_payment_allocations', 'audit_events', 'check_events'];
        $before = [];
        foreach ($tables as $t) {
            $before[$t] = DB::table($t)->count();
        }
        $seq = DB::table('document_sequences')->orderBy('id')->get()->toJson();
        try {
            $work();
            $this->fail('Expected controlled rejection.');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($exception, $e);
        }
        foreach ($tables as $t) {
            $this->assertSame($before[$t], DB::table($t)->count(), $t);
        }
        $this->assertSame($seq, DB::table('document_sequences')->orderBy('id')->get()->toJson());
    }

    public function test_generic_reversal_and_arbitrary_transaction_cannot_bypass_source(): void
    {
        $source = $this->expense();
        $this->unchanged(fn () => DB::transaction(fn () => app(AccountingReversalService::class)->reverse($source->postingBatch, $this->owner, 'Bypass', '2026-10-03')), \LogicException::class);
        $this->assertSame('posted', $source->fresh()->status);
    }

    public function test_inactive_action_cannot_mint_authority(): void
    {
        $action = app(PostExpenseAction::class);
        $this->unchanged(fn () => DB::transaction(fn () => app(Phase7EventScope::class)->within($action, (int) $this->company->id, $this->owner, fn () => null)), \LogicException::class);
    }

    public function test_reversed_history_cannot_be_quietly_rewritten(): void
    {
        $source = $this->expense();
        $source = app(ReverseExpenseAction::class)->execute($source, $this->owner, 'Undo', '2026-10-03');
        $this->unchanged(function () use ($source) {
            $source->amount = '1';
            $source->saveQuietly();
        }, ImmutableRecordException::class);
    }

    public function test_failure_during_source_completion_rolls_back_gl_and_numbering(): void
    {
        Expense::saved(function ($source): void {
            if ($source->posting_batch_id !== null) {
                throw new \RuntimeException('Injected completion failure');
            }
        });
        $this->unchanged(fn () => $this->expense(), \RuntimeException::class);
    }

    public function test_source_retry_rejects_changed_balanced_gl(): void
    {
        $source = $this->expense();
        DB::table('posting_lines')->where('posting_batch_id', $source->posting_batch_id)->update(['description' => 'Damaged']);
        $this->unchanged(fn () => $this->expense(), ImmutableRecordException::class);
    }

    public function test_salary_amount_and_allocation_corruption_fail_reconciliation(): void
    {
        $entry = $this->salary();
        $payment = app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, $this->paymentData($entry));
        DB::table('salary_payment_allocations')->where('salary_payment_id', $payment->id)->update(['salary_book_relief_base' => '49']);
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertFalse($report->isHealthy);
        $this->unchanged(fn () => app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, $this->paymentData($entry)), ImmutableRecordException::class);
    }

    public function test_reversed_salary_payment_residual_can_be_used_again(): void
    {
        $entry = $this->salary();
        $data = $this->paymentData($entry);
        $payment = app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, $data);
        app(ReverseSalaryPaymentAction::class)->execute($payment, $this->owner, 'Undo', '2026-10-05');
        $data['payment_date'] = '2026-10-06';
        $data['idempotency_key'] = 'payment-reused';
        app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, $data);
        $this->assertSame('0.000000', (string) $entry->getRemainingPayableAmount()->toScale(6));
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report->violations));
    }

    public function test_dependent_inverse_business_date_blocks_earlier_parent_reversal(): void
    {
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, $this->advanceData());
        $entry = $this->salary([['advance_id' => $advance->id, 'allocated_amount' => '50']]);
        app(ReverseSalaryEntryAction::class)->execute($entry, $this->owner, 'Release', '2026-10-10');
        $this->unchanged(fn () => app(ReverseEmployeeAdvanceAction::class)->execute($advance, $this->owner, 'Too early', '2026-10-05'), \InvalidArgumentException::class);
        app(ReverseEmployeeAdvanceAction::class)->execute($advance, $this->owner, 'After release', '2026-10-10');
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report->violations));
    }

    public function test_nonnegative_zero_salary_and_bonus_only_salary_are_supported(): void
    {
        $zero = $this->salary([], '0.000');
        $this->assertNull($zero->posting_batch_id);
        app(ReverseSalaryEntryAction::class)->execute($zero, $this->owner, 'Replace', '2026-10-04');
        $entry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-10-05', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'base_salary' => '0.000', 'bonus' => '50', 'idempotency_key' => 'bonus-only']);
        $this->assertSame('50.000000', $entry->net_payable);
        $report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report->violations));
    }

    public function test_tiny_jod_relief_uses_cumulative_exact_residual(): void
    {
        $rows = [];
        foreach (['a', 'b'] as $key) {
            $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, array_replace($this->advanceData('tiny-'.$key), [
                'currency_code' => 'JOD', 'amount' => '0.001', 'exchange_rate' => '0.0005', 'money_account_id' => $this->jodCashAccount->id]));
            $rows[] = ['advance_id' => $advance->id, 'allocated_amount' => '0.001'];
        }
        $entry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-10-03', 'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
            'currency_code' => 'JOD', 'exchange_rate' => '0.0005', 'base_salary' => '0.002', 'advances' => $rows, 'idempotency_key' => 'tiny-salary']);
        $this->assertSame('0.000000', $entry->base_payable);
        $this->assertSame('0.000001', $entry->base_earned_salary);
        $this->assertSame('0.000001', $entry->base_advance_relief);
        $this->assertSame('-0.000001', $entry->realized_fx_gain_loss_base);
        app(Phase7History::class)->validate($entry);
    }

    public function test_historical_money_and_employee_survive_retirement_and_retry(): void
    {
        $data = $this->advanceData();
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, $data);
        $this->employee->delete();
        $this->cashAccount->delete();
        $retry = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, $data);
        $this->assertSame($advance->id, $retry->id);
        $this->assertSame($this->employee->id, $retry->employee->id);
        $this->assertSame($this->cashAccount->id, $retry->moneyAccount->id);
        app(ReverseEmployeeAdvanceAction::class)->execute($retry, $this->owner, 'Retired', '2026-10-03');
    }

    public function test_overlong_reversal_reason_is_rejected_without_truncation(): void
    {
        $source = $this->expense();
        $this->unchanged(fn () => app(ReverseExpenseAction::class)->execute($source, $this->owner, str_repeat('a', 501), '2026-10-03'), \InvalidArgumentException::class);
        $inverse = app(ReverseExpenseAction::class)->execute($source, $this->owner, str_repeat('a', 500), '2026-10-03');
        $this->assertSame(str_repeat('a', 500), $inverse->reversal_reason);
    }

    public function test_money_visibility_hides_source_and_inverse_and_running_values(): void
    {
        $invoice = $this->invoice('ILS', '1', '10');
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'payment_method' => 'cash', 'customer_id' => $invoice->customer_id, 'payment_date' => '2026-10-01', 'amount' => '10', 'exchange_rate' => '1', 'money_account_id' => $this->cashAccount->id, 'idempotency_key' => 'visible-receipt']);
        $source = $this->expense();
        app(ReverseExpenseAction::class)->execute($source, $this->owner, 'SECRET_INVERSE', '2026-10-03');
        $reader = $this->customActor(['money.cash.view', 'money.bank.view']);
        $this->activate($reader);
        $rows = app(MoneyMovementQuery::class)->forAccount($this->cashAccount);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->running_base);
        $this->assertNull($rows[0]->known_running_currency);
        $this->assertStringNotContainsString('927.13', json_encode($rows->items()));
        $this->assertStringNotContainsString('SECRET', json_encode($rows->items()));
        $this->assertCount(1, app(MoneyActivityQuery::class)->recent((int) $this->company->id, ['cash']));
        $this->activate($this->owner);
        $all = app(MoneyMovementQuery::class)->forAccount($this->cashAccount);
        $this->assertCount(3, $all);
        $this->assertNotNull($all[0]->running_base);
    }

    public function test_actor_substitution_cannot_read_payroll(): void
    {
        $reader = $this->customActor(['employees.view']);
        $this->activate($reader);
        $this->expectException(AuthorizationException::class);
        app(PayrollReadService::class)->employeeDirectory((int) $this->company->id, $this->owner);
    }

    public function test_employee_form_clears_salary_after_revocation(): void
    {
        $reader = $this->customActor(['employees.manage', 'payroll.salary.view']);
        $this->activate($reader);
        $component = Livewire::test(EmployeeForm::class, ['publicId' => $this->employee->public_id])->assertSet('defaultSalary', '1000.000000');
        $reader->roles()->first()->revokePermissionTo('payroll.salary.view');
        $component->call('$refresh')->assertSet('defaultSalary', '');
        $this->assertStringNotContainsString('1000.000000', json_encode($component->snapshot));
    }

    public function test_post_only_user_does_not_receive_default_salary(): void
    {
        $reader = $this->customActor(['employees.view', 'payroll.salary.post']);
        $this->activate($reader);
        Livewire::test(SalaryEntryForm::class, ['employee' => $this->employee->public_id])->assertSet('baseSalary', '')->assertDontSee('1000.000000');
    }

    public function test_identity_only_employee_create_uses_safe_domain_defaults(): void
    {
        $reader = $this->customActor(['employees.manage', 'employees.view']);
        $this->activate($reader);
        Livewire::test(EmployeeForm::class)->set('name', 'Identity employee')->set('code', 'IDENTITY')->call('save')->assertHasNoErrors();
        $employee = Employee::where('code', 'IDENTITY')->firstOrFail();
        $this->assertSame('0.000000', $employee->default_salary);
        $this->assertSame('ILS', $employee->salary_currency_code);
        $this->unchanged(fn () => app(UpdateEmployeeAction::class)->execute($employee, $reader, ['default_salary' => '10']), AuthorizationException::class);
    }

    public function test_cash_salary_payment_form_posts_the_exact_allocated_amount(): void
    {
        $this->employee->update(['salary_currency_code' => 'ILS']);
        $entry = $this->salary();
        Livewire::test(SalaryPaymentForm::class, ['employee' => $this->employee->public_id])
            ->set('paymentDate', '2026-10-04')->set('entryAllocations', [$entry->id => '12.34'])->call('save')->assertHasNoErrors();
        $payment = SalaryPayment::firstOrFail();
        $this->assertSame('12.340000', $payment->amount);
        $this->assertSame('37.660000', (string) $entry->getRemainingPayableAmount()->toScale(6));
    }

    public function test_malformed_payroll_allocation_fields_fail_before_parsing(): void
    {
        $this->employee->update(['salary_currency_code' => 'ILS']);
        $entry = $this->salary();
        $before = PostingBatch::count();
        Livewire::test(SalaryPaymentForm::class, ['employee' => $this->employee->public_id])
            ->set('entryAllocations', [$entry->id => 'not-money'])->call('save')->assertHasErrors(['entryAllocations.'.$entry->id]);
        Livewire::test(SalaryEntryForm::class, ['employee' => $this->employee->public_id])
            ->set('advanceAllocations', [999 => 'not-money'])->call('save')->assertHasErrors(['advanceAllocations.999']);
        $this->assertSame($before, PostingBatch::count());
        $this->assertSame(0, SalaryPayment::count());
    }

    public function test_legacy_check_payload_hash_is_preserved_and_retries(): void
    {
        $data = $this->checkIntent('outgoing');
        $legacy = ['company_id' => (int) $this->company->id, 'actor_id' => (int) $this->owner->id, 'direction' => 'outgoing', 'party_id' => $data['party_id'],
            'date' => $data['date'], 'due_date' => $data['due_date'], 'currency_code' => 'USD', 'amount' => '100.000000', 'exchange_rate' => '3.5000000000',
            'check_number' => $data['check_number'], 'bank_name' => $data['bank_name'], 'drawer' => null, 'drawn_money_account_id' => $data['money_account_id'],
            'notes' => null, 'allocations' => [], 'document_locale' => null];
        $check = app(IssueCheckAction::class)->execute($this->company, $this->owner, $data);
        $this->assertSame($legacy, $check->request_payload);
        $this->assertSame(hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)), $check->request_hash);
        $this->usdBankAccount->delete();
        $retry = app(IssueCheckAction::class)->execute($this->company, $this->owner, $data);
        $this->assertSame($check->id, $retry->id);
        $data['amount'] = '101';
        $this->unchanged(fn () => app(IssueCheckAction::class)->execute($this->company, $this->owner, $data), IdempotencyConflictException::class);
    }
}
