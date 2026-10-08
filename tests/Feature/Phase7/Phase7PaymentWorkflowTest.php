<?php

declare(strict_types=1);

namespace Tests\Feature\Phase7;

use App\Actions\Payroll\PostSalaryEntryAction;
use App\Livewire\Pages\Expenses\ExpenseForm;
use App\Livewire\Pages\Money\CheckDetail;
use App\Livewire\Pages\Payroll\EmployeeDetail;
use App\Livewire\Pages\Payroll\SalaryPaymentForm;
use App\Models\Check;
use App\Models\Expense;
use App\Models\PostingLine;
use App\Models\SalaryPayment;
use App\Services\Phase7\Phase7History;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

final class Phase7PaymentWorkflowTest extends Phase7TestCase
{
    public static function methods(): array
    {
        return [['cash'], ['bank'], ['check']];
    }

    #[DataProvider('methods')]
    public function test_salary_form_posts_partial_payment_in_each_method_then_reverses_coherently(string $method): void
    {
        $entry = app(PostSalaryEntryAction::class)->execute($this->company, $this->owner, ['employee_id' => $this->employee->id, 'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'recognition_date' => '2026-10-01', 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'base_salary' => '100', 'idempotency_key' => 'ui-salary']);
        $account = $method === 'cash' ? $this->usdCashAccount : $this->usdBankAccount;
        $form = Livewire::test(SalaryPaymentForm::class, ['employee' => $this->employee->public_id])->set('currencyCode', 'USD')->set('exchangeRate', '3.6')->set('paymentDate', '2026-10-02')->set('paymentMethod', $method)->set('moneyAccountId', $account->id)->set('entryAllocations.'.$entry->id, '50');
        if ($method === 'check') {
            $form->set('checkNumber', 'UI-SALARY')->set('bankName', 'QA Bank')->set('dueDate', '2026-10-03')->set('drawnMoneyAccountId', $account->id);
        }
        $form->call('save')->assertHasNoErrors();
        $payment = SalaryPayment::sole();
        $this->assertSame('50.000000', $payment->amount);
        $this->assertSame('180.000000', $payment->base_amount);
        $this->assertSame($method, $payment->payment_method);
        $this->assertSame('50.000000', (string) $entry->fresh()->getRemainingPayableAmount());
        $allocation = $payment->allocations()->sole();
        $this->assertSame('175.000000', $allocation->salary_book_relief_base);
        $this->assertSame('5.000000', $allocation->realized_fx_gain_loss_base);
        app(Phase7History::class)->validate($payment);
        Livewire::test(EmployeeDetail::class, ['publicId' => $this->employee->public_id])->set('tab', 'statement')->assertDontSee('money.reverse')->assertDontSee('expenses.reverse');
        if ($method === 'check') {
            $check = Check::sole();
            Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')->set('notes', 'Cancel salary check')->call('recordTransition', 'cancel')->assertHasNoErrors();
            $this->assertSame('cancelled', $check->fresh()->status);
        } else {
            Livewire::test(EmployeeDetail::class, ['publicId' => $this->employee->public_id])->set('reversalReason', 'Correct salary payment')->set('reversalDate', '2026-10-03')->call('reverseSalaryPayment', $payment->id)->assertHasNoErrors();
        }
        $this->assertSame('reversed', $payment->fresh()->status);
        $this->assertSame('100.000000', (string) $entry->fresh()->getRemainingPayableAmount());
        app(Phase7History::class)->validate($payment->fresh());
    }

    public function test_rejected_expense_upload_leaves_no_private_or_financial_orphan(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->create('receipt.pdf', 100, 'application/pdf');
        $before = PostingLine::count();
        Livewire::test(ExpenseForm::class)->set('categoryId', $this->operatingCategory->id)->set('description', 'Invalid precision receipt')->set('expenseDate', '2026-10-02')->set('amount', '1.001')->set('currencyCode', 'ILS')->set('paymentMethod', 'cash')->set('moneyAccountId', $this->cashAccount->id)->set('attachment', $file)->call('save')->assertHasErrors('payment');
        $this->assertSame(0, Expense::count());
        $this->assertSame($before, PostingLine::count());
        $this->assertSame([], Storage::disk('local')->allFiles('expenses/'.$this->company->id));
    }
}
