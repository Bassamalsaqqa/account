<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\SalaryEntry;
use App\Models\SalaryPayment;
use InvalidArgumentException;

class PayrollTest extends Phase7TestCase
{
    public function test_post_salary_entry_with_advance_and_fx(): void
    {
        // 1. Employee advance: 200 USD @ 3.50 = 700 ILS base
        $advance = app(PostEmployeeAdvanceAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'advance_date' => '2026-09-15',
            'currency_code' => 'USD',
            'amount' => '200.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCashAccount->id,
            'notes' => 'سلفة قبل الراتب',
            'idempotency_key' => 'adv-pay-01',
        ]);

        $this->assertSame('200.000000', $advance->amount);
        $this->assertSame('700.000000', $advance->base_amount);

        // 2. Post salary entry for September: Base 1000 USD, Bonus 100 USD, Deduction 50 USD = Earned 1050 USD
        // Rate is 3.60. Base earned = 1050 * 3.60 = 3780 ILS.
        // Advance consumed: 200 USD. Advance book base = 700 ILS.
        // Salary entry relief at 3.60 = 200 * 3.60 = 720 ILS.
        // FX diff = 720 - 700 = 20 ILS gain (credit realized fx gain/loss).
        // Net payable = 1050 - 200 = 850 USD.
        // Base payable = 3780 - 720 = 3060 ILS.
        $salaryEntry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'base_salary' => '1000.000000',
            'bonus' => '100.000000',
            'deduction' => '50.000000',
            'advances' => [
                ['advance_id' => $advance->id, 'allocated_amount' => '200.000000'],
            ],
            'notes' => 'راتب شهر سبتمبر 2026',
            'idempotency_key' => 'sal-sep-01',
        ]);

        $this->assertInstanceOf(SalaryEntry::class, $salaryEntry);
        $this->assertSame('posted', $salaryEntry->status);
        $this->assertSame('1050.000000', $salaryEntry->earned_salary);
        $this->assertSame('200.000000', $salaryEntry->advance_applied);
        $this->assertSame('850.000000', $salaryEntry->net_payable);
        $this->assertSame('3780.000000', $salaryEntry->base_earned_salary);
        $this->assertSame('720.000000', $salaryEntry->base_advance_relief);
        $this->assertSame('3060.000000', $salaryEntry->base_payable);
        $this->assertSame('20.000000', $salaryEntry->realized_fx_gain_loss_base);

        // Verify batch lines
        /** @var PostingBatch $batch */
        $batch = PostingBatch::findOrFail($salaryEntry->posting_batch_id);
        $this->assertSame('salary_entry', $batch->source_type);
        $lines = $batch->lines()->orderBy('line_number')->get();

        $salaryExpAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_expense')->firstOrFail();
        $advanceAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'employee_advances')->firstOrFail();
        $salaryPayableAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_payable')->firstOrFail();
        $fxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'fx_gain')->firstOrFail();

        // Line 1: Debit Salary Expense: 3780
        $this->assertSame($salaryExpAccount->id, $lines[0]->ledger_account_id);
        $this->assertSame('3780.000000', $lines[0]->debit_base);
        $this->assertSame('0.000000', $lines[0]->credit_base);

        // Line 2: Credit Employee Advances: 700
        $this->assertSame($advanceAccount->id, $lines[1]->ledger_account_id);
        $this->assertSame('0.000000', $lines[1]->debit_base);
        $this->assertSame('700.000000', $lines[1]->credit_base);

        // Line 3: Credit Salary Payable: 3060
        $this->assertSame($salaryPayableAccount->id, $lines[2]->ledger_account_id);
        $this->assertSame('0.000000', $lines[2]->debit_base);
        $this->assertSame('3060.000000', $lines[2]->credit_base);

        // Line 4: Credit Realized FX Gain: 20
        $this->assertSame($fxAccount->id, $lines[3]->ledger_account_id);
        $this->assertSame('0.000000', $lines[3]->debit_base);
        $this->assertSame('20.000000', $lines[3]->credit_base);

        // Total debits = 3780, Total credits = 700 + 3060 + 20 = 3780 (Balanced!)
    }

    public function test_period_overlap_is_rejected_for_same_employee(): void
    {
        // First entry: 2026-09-01 to 2026-09-30
        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '1000.000000',
            'idempotency_key' => 'sal-entry-period-1',
        ]);

        // Overlapping entry: 2026-09-15 to 2026-10-15
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('overlaps with an existing posted salary entry');

        app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-10-15',
            'period_start' => '2026-09-15',
            'period_end' => '2026-10-15',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '1000.000000',
            'idempotency_key' => 'sal-entry-period-2',
        ]);
    }

    public function test_post_salary_payment_multi_entry_and_reversal(): void
    {
        // 1. Entry 1: 500 USD @ 3.50 = 1750 base payable
        $entry1 = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-08-31',
            'period_start' => '2026-08-01',
            'period_end' => '2026-08-31',
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'base_salary' => '500.000000',
            'idempotency_key' => 'sal-aug-01',
        ]);

        // 2. Entry 2: 500 USD @ 3.60 = 1800 base payable
        $entry2 = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'recognition_date' => '2026-09-30',
            'period_start' => '2026-09-01',
            'period_end' => '2026-09-30',
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'base_salary' => '500.000000',
            'idempotency_key' => 'sal-sep-02',
        ]);

        // Total remaining payable across two entries = 1000 USD.
        // Pay 700 USD total at rate 3.70 via Bank USD.
        // Allocations: 500 USD to entry1 (all), 200 USD to entry2 (partial).
        // Book relief:
        // Entry 1: 500 @ 3.50 = 1750 ILS
        // Entry 2: 200 @ 3.60 = 720 ILS
        // Total book relief = 2470 ILS.
        // Settlement: 700 @ 3.70 = 2590 ILS.
        // FX diff = 2590 - 2470 = 120 ILS loss (debit realized fx).
        $payment = app(PostSalaryPaymentAction::class)->execute($this->company, $this->owner, [
            'employee_id' => $this->employee->id,
            'payment_date' => '2026-10-05',
            'currency_code' => 'USD',
            'exchange_rate' => '3.7000000000',
            'amount' => '700.000000',
            'payment_method' => 'bank',
            'money_account_id' => $this->usdBankAccount->id,
            'allocations' => [
                ['salary_entry_id' => $entry1->id, 'allocated_amount' => '500.000000'],
                ['salary_entry_id' => $entry2->id, 'allocated_amount' => '200.000000'],
            ],
            'notes' => 'دفعة رواتب بنكية',
            'idempotency_key' => 'pay-multi-01',
        ]);

        $this->assertInstanceOf(SalaryPayment::class, $payment);
        $this->assertSame('posted', $payment->status);
        $this->assertSame('700.000000', $payment->amount);
        $this->assertSame('2590.000000', $payment->base_amount);
        $this->assertSame('2470.000000', $payment->salary_book_relief_base);
        $this->assertSame('120.000000', $payment->realized_fx_gain_loss_base);

        // Verify remaining payable on entries
        $this->assertSame('0.000000', (string) $entry1->getRemainingPayableAmount());
        $this->assertSame('300.000000', (string) $entry2->getRemainingPayableAmount());

        // Verify batch lines
        $batch = PostingBatch::findOrFail($payment->posting_batch_id);
        $lines = $batch->lines()->orderBy('line_number')->get();

        $salaryPayableAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'salary_payable')->firstOrFail();
        $fxAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'fx_loss')->firstOrFail();

        // Line 1: Debit Salary Payable: 2470
        $this->assertSame($salaryPayableAccount->id, $lines[0]->ledger_account_id);
        $this->assertSame('2470.000000', $lines[0]->debit_base);
        $this->assertSame('0.000000', $lines[0]->credit_base);

        // Line 2: Credit Bank Account: 2590
        $this->assertSame($this->usdBankAccount->ledgerAccount->id, $lines[1]->ledger_account_id);
        $this->assertSame('0.000000', $lines[1]->debit_base);
        $this->assertSame('2590.000000', $lines[1]->credit_base);

        // Line 3: Debit Realized FX Loss: 120
        $this->assertSame($fxAccount->id, $lines[2]->ledger_account_id);
        $this->assertSame('120.000000', $lines[2]->debit_base);
        $this->assertSame('0.000000', $lines[2]->credit_base);

        // Reversal of Salary Payment
        $reversedPayment = app(ReverseSalaryPaymentAction::class)->execute($payment, $this->owner, 'إلغاء الدفعة');
        $this->assertSame('reversed', $reversedPayment->status);

        // Payable restored
        $this->assertSame('500.000000', (string) $entry1->getRemainingPayableAmount());
        $this->assertSame('500.000000', (string) $entry2->getRemainingPayableAmount());
    }
}
