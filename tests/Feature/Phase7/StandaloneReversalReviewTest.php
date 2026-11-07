<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Livewire\Pages\Expenses\ExpenseDetail;
use App\Livewire\Pages\Payroll\EmployeeDetail;
use Livewire\Livewire;

class StandaloneReversalReviewTest extends Phase7TestCase
{
    public function test_expense_review_is_read_only_then_confirms_canonical_inverse(): void
    {
        $expense = app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id, 'expense_date' => '2026-10-02',
            'classification' => 'operating', 'description' => 'Review expense', 'currency_code' => 'ILS',
            'amount' => '80', 'exchange_rate' => '1', 'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id, 'idempotency_key' => 'review-expense',
        ]);
        $page = Livewire::test(ExpenseDetail::class, ['publicId' => $expense->public_id])
            ->call('openReversalReview')->assertHasNoErrors()->assertSee('80.00')->assertDontSee('wire:confirm=', false);
        $this->assertSame('posted', $expense->fresh()->status);
        $page->set('reversalReason', 'Correct expense')->set('reversalDate', '2026-10-03')
            ->call('confirmReversalReview')->assertHasNoErrors();
        $this->assertSame('reversed', $expense->fresh()->status);
        $this->assertNotNull($expense->fresh()->reversal_posting_batch_id);
    }

    public function test_salary_review_lists_advance_and_releases_it_before_advance_reversal(): void
    {
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'advance_date' => '2026-09-15', 'currency_code' => 'USD',
            'amount' => '20', 'exchange_rate' => '3.5', 'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id, 'idempotency_key' => 'review-advance',
        ]);
        $entry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id, 'recognition_date' => '2026-09-30',
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'currency_code' => 'USD',
            'exchange_rate' => '3.5', 'base_salary' => '100',
            'advances' => [['advance_id' => $advance->id, 'allocated_amount' => '20']], 'idempotency_key' => 'review-salary',
        ]);
        $page = Livewire::test(EmployeeDetail::class, ['publicId' => $this->employee->public_id])
            ->call('openReversalReview', 'advance', $advance->id)->assertSet('reversalReview.blocked', true)
            ->call('confirmReversalReview')->assertHasErrors('review');
        $this->assertSame('posted', $advance->fresh()->status);
        $page->call('openReversalReview', 'salary', $entry->id)->assertSee($advance->advance_number)
            ->assertSee($entry->salary_number)->assertSet('reversalReview.blocked', false)
            ->set('reversalReason', 'Correct salary')->set('reversalDate', '2026-10-03')
            ->call('confirmReversalReview')->assertHasNoErrors();
        $this->assertSame('reversed', $entry->fresh()->status);
        $page->call('openReversalReview', 'advance', $advance->id)->assertSet('reversalReview.blocked', false)
            ->set('reversalReason', 'Correct advance')->call('confirmReversalReview')->assertHasNoErrors();
        $this->assertSame('reversed', $advance->fresh()->status);
    }
}
