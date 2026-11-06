<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Livewire\Pages\Expenses\ExpenseForm;
use App\Livewire\Pages\Money\TransferForm;
use App\Livewire\Pages\Payroll\EmployeeForm;
use App\Livewire\Pages\Purchasing\PaymentForm as VendorPaymentForm;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\PaymentForm as SalesPaymentForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Livewire\Pages\Sales\ReturnForm;
use App\Models\Customer;
use App\Models\MoneyAccount;
use Carbon\Carbon;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class FormDefaultsTest extends Phase5ETestCase
{
    public function test_new_form_dates_use_the_company_day_across_midnight(): void
    {
        $this->company->update(['timezone' => 'Asia/Hebron']);
        $this->travelTo(Carbon::parse('2026-11-06 23:30:00', 'UTC'));
        foreach ([
            [InvoiceForm::class, 'issue_date'],
            [QuotationForm::class, 'issue_date'],
            [ReturnForm::class, 'issue_date'],
            [PurchaseForm::class, 'purchase_date'],
            [EmployeeForm::class, 'hireDate'],
        ] as [$form, $field]) {
            Livewire::test($form)->assertSet($field, '2026-11-07');
        }
    }

    public function test_quotation_currency_changes_require_an_explicit_foreign_rate(): void
    {
        Livewire::test(QuotationForm::class)->set('currency_code', 'USD')
            ->assertSet('exchange_rate', '')->set('exchange_rate', '3.5')->set('currency_code', 'ILS')
            ->assertSet('exchange_rate', '1.0000000000');
    }

    public function test_standalone_payments_prefer_base_currency_over_foreign_account_sort_order(): void
    {
        MoneyAccount::where('company_id', $this->company->id)->update(['sort_order' => 100]);
        $this->usdCashAccount->update(['sort_order' => -100]);
        foreach ([SalesPaymentForm::class, VendorPaymentForm::class] as $form) {
            Livewire::test($form)->assertSet('currency_code', 'ILS')
                ->assertSet('exchange_rate', '1.0000000000')
                ->assertSet('payment_date', now($this->company->timezone)->toDateString())
                ->assertDontSee('wire:model.blur="exchange_rate"', false)
                ->assertDontSee('more = !more', false);
        }
    }

    public function test_foreign_invoice_currency_is_inherited_without_guessing_a_settlement_rate(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل', 'name_en' => 'Customer', 'default_currency_code' => 'ILS', 'status' => 'active', 'created_by' => $this->owner->id]);
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5',
            'issue_date' => now()->toDateString(),
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        Livewire::test(SalesPaymentForm::class, ['invoice_id' => $invoice->id])
            ->assertSet('currency_code', 'USD')->assertSet('money_account_id', $this->usdCashAccount->id)
            ->assertSet('exchange_rate', '')->assertSee('wire:model.blur="exchange_rate"', false);
        $this->usdCashAccount->update(['is_active' => false]);
        $this->usdBankAccount->update(['is_active' => false]);
        Livewire::test(SalesPaymentForm::class, ['invoice_id' => $invoice->id])
            ->assertSet('currency_code', 'USD')->assertSet('money_account_id', null)->assertSet('exchange_rate', '');
    }

    public function test_transfer_base_rates_are_hidden_and_normalized_before_validation(): void
    {
        Livewire::test(TransferForm::class)->set('fromId', $this->ilsCashAccount->id)
            ->assertSet('fromRate', '1')->assertDontSee('wire:model="fromRate"', false)
            ->set('fromRate', '9')->call('save')->assertHasErrors('toId')->assertSet('fromRate', '1')
            ->set('fromId', $this->usdCashAccount->id)->assertSet('fromRate', '')
            ->assertSee('wire:model="fromRate"', false);
    }

    public function test_expense_base_rate_is_hidden_and_normalized_before_validation(): void
    {
        Livewire::test(ExpenseForm::class)->assertDontSee('wire:model="exchangeRate"', false)
            ->set('exchangeRate', '9')->call('save')->assertHasErrors('amount')->assertSet('exchangeRate', '1')
            ->set('currencyCode', 'USD')->assertSet('exchangeRate', '')
            ->assertSee('wire:model="exchangeRate"', false);
    }
}
