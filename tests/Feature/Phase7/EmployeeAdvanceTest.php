<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\ReverseEmployeeAdvanceAction;
use App\Actions\Payroll\ReverseSalaryEntryAction;
use App\Models\Check;
use App\Models\EmployeeAdvance;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use InvalidArgumentException;
use LogicException;

class EmployeeAdvanceTest extends Phase7TestCase
{
    public function test_post_employee_advance_cash(): void
    {
        $action = app(PostEmployeeAdvanceAction::class);

        $advance = $action->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'advance_date' => '2026-10-02',
            'currency_code' => 'USD',
            'amount' => '100.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'notes' => 'سلفة نقدية',
            'idempotency_key' => 'adv-cash-01',
        ]);

        $this->assertInstanceOf(EmployeeAdvance::class, $advance);
        $this->assertSame('posted', $advance->status);
        $this->assertSame('100.000000', $advance->amount);
        $this->assertSame('350.000000', $advance->base_amount);
        $this->assertNotNull($advance->posting_batch_id);

        /** @var PostingBatch $batch */
        $batch = PostingBatch::findOrFail($advance->posting_batch_id);
        $this->assertSame('employee_advance', $batch->source_type);
        $this->assertSame($advance->id, $batch->source_id);

        $lines = $batch->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);

        $advanceLedger = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'employee_advances')->firstOrFail();

        // Line 1: Debit Employee Advances
        $this->assertSame($advanceLedger->id, $lines[0]->ledger_account_id);
        $this->assertSame('350.000000', $lines[0]->debit_base);
        $this->assertSame('0.000000', $lines[0]->credit_base);

        // Line 2: Credit Cash
        $this->assertSame($this->usdCashAccount->ledgerAccount->id, $lines[1]->ledger_account_id);
        $this->assertSame('0.000000', $lines[1]->debit_base);
        $this->assertSame('350.000000', $lines[1]->credit_base);
    }

    public function test_post_employee_advance_check_and_lifecycle(): void
    {
        $check = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'employee_advance',
            'date' => '2026-10-02',
            'due_date' => '2026-10-05',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.5000000000',
            'check_number' => 'ADV-CHK-001',
            'bank_name' => 'بنك فلسطين',
            'money_account_id' => $this->usdBankAccount->id,
            'employee_id' => $this->employee->id,
            'notes' => 'سلفة شيك للموظف',
            'idempotency_key' => 'chk-adv-01',
        ]);

        $this->assertInstanceOf(Check::class, $check);
        $this->assertSame('issued', $check->status);
        $this->assertSame('outgoing', $check->direction);
        $this->assertNull($check->customer_id);
        $this->assertNull($check->vendor_id);

        /** @var EmployeeAdvance $advance */
        $advance = EmployeeAdvance::where('check_id', $check->id)->firstOrFail();
        $this->assertSame('posted', $advance->status);
        $this->assertSame('check', $advance->payment_method);
        $this->assertSame('175.000000', $advance->base_amount);

        // Direct reversal must fail
        $this->expectException(LogicException::class);
        app(ReverseEmployeeAdvanceAction::class)->execute($advance, $this->owner, 'Direct reversal should fail');
    }

    public function test_reverse_advance_blocked_if_consumed_by_active_salary_entry(): void
    {
        // 1. Post advance of 50 USD @ 3.50 = 175 base
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'advance_date' => '2026-10-01',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'idempotency_key' => 'adv-consumed-01',
        ]);

        // 2. Post salary entry consuming this 50 USD advance
        $salaryEntry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-02',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency_code' => 'USD',
            'exchange_rate' => '4.0000000000',
            'base_salary' => '150.000000',
            'advances' => [
                ['advance_id' => $advance->id, 'allocated_amount' => '50.000000'],
            ],
            'idempotency_key' => 'sal-entry-01',
        ]);

        $this->assertSame('posted', $salaryEntry->status);
        $this->assertSame('50.000000', $salaryEntry->advance_applied);
        $this->assertSame('100.000000', $salaryEntry->net_payable);

        // 3. Attempting to reverse advance must throw blocker exception
        try {
            app(ReverseEmployeeAdvanceAction::class)->execute($advance, $this->owner, 'Reversal attempt');
            $this->fail('Expected InvalidArgumentException was not thrown.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('consumed by active salary entries', $e->getMessage());
        }

        // 4. Reverse salary entry first
        app(ReverseSalaryEntryAction::class)->execute($salaryEntry, $this->owner, 'Reversing salary entry');
        $salaryEntry->refresh();
        $this->assertSame('reversed', $salaryEntry->status);

        // 5. Now reversing advance succeeds
        $reversedAdvance = app(ReverseEmployeeAdvanceAction::class)->execute($advance, $this->owner, 'Now reversing advance');
        $this->assertSame('reversed', $reversedAdvance->status);
        $this->assertNotNull($reversedAdvance->reversal_posting_batch_id);
    }
}
