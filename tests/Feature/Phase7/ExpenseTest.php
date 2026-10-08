<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Expenses\ReverseExpenseAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Models\Check;
use App\Models\Expense;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use LogicException;

class ExpenseTest extends Phase7TestCase
{
    public function test_post_operating_expense_cash(): void
    {
        $action = app(PostExpenseAction::class);

        $expense = $action->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => 'operating',
            'description' => 'فاتورة كهرباء',
            'currency_code' => 'ILS',
            'amount' => '150.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'exp-cash-01',
        ]);

        $this->assertInstanceOf(Expense::class, $expense);
        $this->assertSame('posted', $expense->status);
        $this->assertSame('150.000000', $expense->amount);
        $this->assertSame('150.000000', $expense->base_amount);
        $this->assertNotNull($expense->posting_batch_id);

        /** @var PostingBatch $batch */
        $batch = PostingBatch::findOrFail($expense->posting_batch_id);
        $this->assertSame('expense', $batch->source_type);
        $this->assertSame($expense->id, $batch->source_id);

        $lines = $batch->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);

        // Line 1: Debit Utilities
        $this->assertSame($this->operatingCategory->ledger_account_id, $lines[0]->ledger_account_id);
        $this->assertSame('150.000000', $lines[0]->debit_base);
        $this->assertSame('0.000000', $lines[0]->credit_base);

        // Line 2: Credit Cash
        $cashLedger = $this->cashAccount->ledgerAccount;
        $this->assertSame($cashLedger->id, $lines[1]->ledger_account_id);
        $this->assertSame('0.000000', $lines[1]->debit_base);
        $this->assertSame('150.000000', $lines[1]->credit_base);

        // Idempotency: exact same call returns existing
        $retry = $action->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => 'operating',
            'description' => 'فاتورة كهرباء',
            'currency_code' => 'ILS',
            'amount' => '150.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'exp-cash-01',
        ]);
        $this->assertSame($expense->id, $retry->id);
    }

    public function test_post_operating_expense_bank_usd(): void
    {
        $action = app(PostExpenseAction::class);

        $expense = $action->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => 'operating',
            'description' => 'خدمة اشتراك سحابي',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'bank',
            'money_account_id' => $this->usdBankAccount->id,
            'idempotency_key' => 'exp-bank-usd-01',
        ]);

        $this->assertSame('posted', $expense->status);
        $this->assertSame('50.000000', $expense->amount);
        $this->assertSame('175.000000', $expense->base_amount);

        /** @var PostingBatch $batch */
        $batch = PostingBatch::findOrFail($expense->posting_batch_id);
        $lines = $batch->lines()->orderBy('line_number')->get();

        $this->assertSame('175.000000', $lines[0]->debit_base);
        $this->assertSame('175.000000', $lines[1]->credit_base);
    }

    public function test_reverse_cash_expense(): void
    {
        $postAction = app(PostExpenseAction::class);
        $reverseAction = app(ReverseExpenseAction::class);

        $expense = $postAction->execute($this->company, $this->owner, [
            'category_id' => $this->operatingCategory->id,
            'expense_date' => '2026-10-02',
            'classification' => 'operating',
            'description' => 'مصروف للإلغاء',
            'currency_code' => 'ILS',
            'amount' => '80.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'idempotency_key' => 'exp-rev-01',
        ]);

        $reversed = $reverseAction->execute($expense, $this->owner, 'خطأ في القيد', '2026-10-03');

        $this->assertSame('reversed', $reversed->status);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertSame((int) $this->owner->id, (int) $reversed->reversed_by);
        $this->assertNotNull($reversed->reversal_posting_batch_id);
        $this->assertSame('خطأ في القيد', $reversed->reversal_reason);

        /** @var PostingBatch $revBatch */
        $revBatch = PostingBatch::findOrFail($reversed->reversal_posting_batch_id);
        $this->assertSame('reversal', $revBatch->source_type);
        $this->assertSame($expense->posting_batch_id, $revBatch->reversal_of_id);
    }

    public function test_post_expense_check_and_lifecycle(): void
    {
        // 1. Issue outgoing check for expense
        $check = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'expense',
            'date' => '2026-10-02',
            'due_date' => '2026-10-05',
            'currency_code' => 'USD',
            'amount' => '100.000000',
            'exchange_rate' => '3.5000000000',
            'check_number' => 'EXP-CHK-001',
            'bank_name' => 'بنك فلسطين',
            'drawer' => 'شركة الاختبار',
            'money_account_id' => $this->usdBankAccount->id,
            'category_id' => $this->operatingCategory->id,
            'classification' => 'operating',
            'description' => 'مصاريف صيانة بشيك',
            'notes' => 'شيك صيانة',
            'idempotency_key' => 'chk-exp-01',
        ]);

        $this->assertInstanceOf(Check::class, $check);
        $this->assertSame('issued', $check->status);
        $this->assertSame('outgoing', $check->direction);
        $this->assertSame('350.000000', $check->amount_base);

        /** @var Expense $expense */
        $expense = Expense::where('check_id', $check->id)->firstOrFail();
        $this->assertSame('posted', $expense->status);
        $this->assertSame('check', $expense->payment_method);
        $this->assertSame('350.000000', $expense->base_amount);

        // Verify initial GL batch: Debit Utilities / Credit Checks Issued
        $batch = PostingBatch::findOrFail($expense->posting_batch_id);
        $lines = $batch->lines()->orderBy('line_number')->get();
        $checksIssued = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'checks_issued')->firstOrFail();
        $this->assertSame($this->operatingCategory->ledger_account_id, $lines[0]->ledger_account_id);
        $this->assertSame($checksIssued->id, $lines[1]->ledger_account_id);

        // 2. Direct reversal of check-linked expense without check transition must fail
        $this->expectException(LogicException::class);
        app(ReverseExpenseAction::class)->execute($expense, $this->owner, 'Direct reversal should fail');
    }

    public function test_expense_check_clearance_and_return(): void
    {
        // Issue check for expense
        $check = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'expense',
            'date' => '2026-10-02',
            'due_date' => '2026-10-02',
            'currency_code' => 'USD',
            'amount' => '100.000000',
            'exchange_rate' => '3.5000000000',
            'check_number' => 'EXP-CHK-002',
            'bank_name' => 'بنك فلسطين',
            'money_account_id' => $this->usdBankAccount->id,
            'category_id' => $this->operatingCategory->id,
            'classification' => 'operating',
            'description' => 'مصاريف تسوية',
            'idempotency_key' => 'chk-exp-02',
        ]);

        $expense = Expense::where('check_id', $check->id)->firstOrFail();

        // Return check (before clear)
        $returnEvent = app(TransitionCheckAction::class)->execute($check, $this->owner, [
            'event_type' => 'return',
            'event_date' => '2026-10-03',
            'idempotency_key' => 'return-exp-chk-02',
            'notes' => 'إرجاع شيك المصروف',
        ]);

        $check->refresh();
        $expense->refresh();

        $this->assertSame('returned', $check->status);
        $this->assertSame('returned', $returnEvent->to_status);
        $this->assertSame('reversed', $expense->status);
        $this->assertNotNull($expense->reversal_posting_batch_id);
    }
}
