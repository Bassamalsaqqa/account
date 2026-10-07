<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Domain\Money\Queries\MoneyActivityQuery;
use App\Domain\Money\Queries\MoneyMovementQuery;
use App\Livewire\Pages\Money\CheckIndex;
use App\Models\Check;
use App\Models\EmployeeAdvance;
use App\Models\Expense;
use App\Models\PostingLine;
use App\Models\SalaryPayment;
use App\Services\Money\CheckHistory;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class Phase7CheckBoundaryTest extends Phase7TestCase
{
    public static function sources(): array
    {
        return [['expense'], ['employee_advance'], ['salary_payment']];
    }

    private function issue(string $type): Check
    {
        $data = ['source_type' => $type, 'employee_id' => $this->employee->id, 'category_id' => $this->operatingCategory->id, 'description' => 'SECRET_SOURCE_DESCRIPTION',
            'classification' => 'operating', 'payee_name' => 'SECRET_PAYEE', 'date' => '2026-10-02', 'due_date' => '2026-10-03', 'currency_code' => 'USD', 'amount' => '100', 'exchange_rate' => '3.5',
            'money_account_id' => $this->usdBankAccount->id, 'check_number' => 'SECRET_PHASE7_'.$type, 'bank_name' => 'QA Bank', 'idempotency_key' => 'check-'.$type];
        if ($type === 'salary_payment') {
            $entry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, ['employee_id' => $this->employee->id, 'recognition_date' => '2026-10-01', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'base_salary' => '100', 'idempotency_key' => 'check-salary']);
            $data['allocations'] = [['salary_entry_id' => $entry->id, 'allocated_amount' => '100']];
        }

        return app(IssueCheckAction::class)->execute($this->company, $this->owner, $data);
    }

    #[DataProvider('sources')]
    public function test_new_sources_clear_using_frozen_retired_bank_with_exact_fx(string $type): void
    {
        $check = $this->issue($type);
        $bank = $this->usdBankAccount;
        $bank->delete();
        DB::table('ledger_accounts')->where('id', $bank->ledger_account_id)->update(['active' => false]);
        DB::table('company_currencies')->where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]);
        $event = app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => 'clear', 'event_date' => '2026-10-03', 'exchange_rate' => '3.6', 'idempotency_key' => 'clear-'.$type]);
        $this->assertSame('cleared', $check->fresh()->status);
        $this->assertSame($bank->id, $event->money_account_id);
        $line = PostingLine::where('posting_batch_id', $event->posting_batch_id)->where('ledger_account_id', $bank->ledger_account_id)->sole();
        $this->assertSame('360.000000', $line->credit_base);
        $control = PostingLine::where('posting_batch_id', $event->posting_batch_id)->where('ledger_account_id', $this->account('checks_issued'))->sole();
        $this->assertSame('350.000000', $control->debit_base);
        $fx = PostingLine::where('posting_batch_id', $event->posting_batch_id)->where('ledger_account_id', $this->account('fx_loss'))->sole();
        $this->assertSame('10.000000', $fx->debit_base);
        app(CheckHistory::class)->validate($check->fresh());
        $this->addToAssertionCount(1);
    }

    private function account(string $key): int
    {
        return (int) DB::table('ledger_accounts')->where('company_id', $this->company->id)->where('system_key', $key)->value('id');
    }

    #[DataProvider('sources')]
    public function test_terminal_reason_and_canonical_source_reversal_are_atomic(string $type): void
    {
        $check = $this->issue($type);
        $source = match ($type) {
            'expense' => Expense::where('check_id', $check->id)->sole(),'employee_advance' => EmployeeAdvance::where('check_id', $check->id)->sole(),default => SalaryPayment::where('check_id', $check->id)->sole()
        };
        $before = [DB::table('check_events')->count(), DB::table('posting_batches')->count(), DB::table('document_sequences')->get()->toJson()];
        try {
            app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => 'cancel', 'event_date' => '2026-10-03', 'notes' => str_repeat('a', 501), 'idempotency_key' => 'terminal-'.$type]);
            $this->fail('Expected reason limit');
        } catch (\InvalidArgumentException) {
        }
        $this->assertSame($before, [DB::table('check_events')->count(), DB::table('posting_batches')->count(), DB::table('document_sequences')->get()->toJson()]);
        $this->assertSame('issued', $check->fresh()->status);
        $this->assertSame('posted', $source->fresh()->status);
        $reason = str_repeat('a', 500);
        app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => 'cancel', 'event_date' => '2026-10-03', 'notes' => $reason, 'idempotency_key' => 'terminal-'.$type]);
        $this->assertSame('cancelled', $check->fresh()->status);
        $this->assertSame('reversed', $source->fresh()->status);
        $this->assertSame($reason, $source->fresh()->reversal_reason);
        app(CheckHistory::class)->validate($check->fresh());
    }

    #[DataProvider('sources')]
    public function test_check_money_rows_and_source_identity_are_filtered_before_response(string $type): void
    {
        $check = $this->issue($type);
        app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => 'clear', 'event_date' => '2026-10-03', 'exchange_rate' => '3.6', 'idempotency_key' => 'clear-'.$type]);
        $reader = $this->customActor(['money.bank.view', 'money.cash.view', 'money.check.view']);
        $this->activate($reader);
        $this->assertCount(0, app(MoneyActivityQuery::class)->recent((int) $this->company->id, ['bank']));
        $this->assertCount(0, app(MoneyMovementQuery::class)->forAccount($this->usdBankAccount));
        Livewire::test(CheckIndex::class)->assertDontSee('SECRET_PHASE7')->assertViewHas('checks', fn ($checks) => $checks->total() === 0);
        $permission = match ($type) {
            'expense' => 'money.expense.view','employee_advance' => 'payroll.advance.manage',default => 'payroll.salary.view'
        };
        $reader->givePermissionTo($permission);
        $this->assertCount(1, app(MoneyActivityQuery::class)->recent((int) $this->company->id, ['bank']));
        $component = Livewire::test(CheckIndex::class)->assertSee('SECRET_PHASE7');
        $reader->revokePermissionTo($permission);
        $component->call('$refresh')->assertDontSee('SECRET_PHASE7')->assertViewHas('checks', fn ($checks) => $checks->total() === 0);
        $this->assertStringNotContainsString('SECRET_PHASE7', json_encode($component->snapshot));
    }
}
