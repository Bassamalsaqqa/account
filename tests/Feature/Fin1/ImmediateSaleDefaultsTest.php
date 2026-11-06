<?php

declare(strict_types=1);

namespace Tests\Feature\Fin1;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

final class ImmediateSaleDefaultsTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    private function draft(string $currency = 'ILS'): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => $currency,
            'exchange_rate' => $currency === 'ILS' ? '1' : '3.5', 'issue_date' => now($this->company->timezone)->toDateString(),
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
    }

    public function test_base_payment_is_ready_without_currency_conversion_fields_and_posts_atomically(): void
    {
        $invoice = $this->draft();
        $form = Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id])->set('settlement.mode', 'full')
            ->assertSet('settlement.payment_date', now($this->company->timezone)->toDateString())
            ->assertSet('settlement.amount', '100.00')->assertSet('settlement.exchange_rate', '1.0000000000')
            ->assertDontSee('id="settlement-exchange_rate"', false)->assertDontSee('id="settlement-payment_currency_amount"', false)
            ->assertSee('data-mobile-confirm', false)->call('saveAndConfirm')->assertHasNoErrors();
        if ($form->get('showPreview')) {
            $form->call('confirmPost')->assertHasNoErrors();
        }
        $this->assertTrue($invoice->fresh()->isPosted());
        $this->assertDatabaseCount('customer_payments', 1);
        $this->assertDatabaseHas('customer_payments', ['amount' => '100.000000', 'currency_code' => 'ILS', 'exchange_rate' => '1.0000000000']);
    }

    public function test_foreign_payment_inherits_invoice_currency_and_keeps_explicit_rate_one(): void
    {
        $invoice = $this->draft('USD');
        Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id])->set('settlement.mode', 'full')
            ->assertSet('settlement.money_account_id', (string) $this->cash->id)
            ->assertSet('settlement.exchange_rate', '')->assertSet('settlement.amount', '100.00')
            ->assertSee('id="settlement-exchange_rate"', false)->assertDontSee('id="settlement-payment_currency_amount"', false)
            ->set('settlement.exchange_rate', '1')->set('settlement.payment_date', '2026-10-29')
            ->assertSet('settlement.exchange_rate', '1')->assertSet('settlement.payment_date', '2026-10-29');
    }

    public function test_currency_switch_reselects_only_automatic_accounts_and_clears_amount_denominations(): void
    {
        $form = Livewire::test(InvoiceForm::class, ['publicId' => $this->draft()->public_id])->set('settlement.mode', 'full')
            ->set('currency_code', 'USD')->assertSet('exchange_rate', '')
            ->assertSet('settlement.money_account_id', (string) $this->cash->id)->assertSet('settlement.exchange_rate', '')
            ->set('settlement.amount', '37')->set('currency_code', 'ILS')
            ->assertSet('settlement.amount', '100.00')->assertSet('settlement.exchange_rate', '1.0000000000');
        $form->set('settlement.money_account_id', (string) $this->cash->id)
            ->assertSet('settlement.amount', '')->assertSee('id="settlement-payment_currency_amount"', false)
            ->set('currency_code', 'USD')->assertSet('settlement.money_account_id', (string) $this->cash->id);
    }

    public function test_partial_same_currency_uses_one_received_amount_and_preserves_cleared_fields(): void
    {
        $invoice = $this->draft();
        $form = Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id])->set('settlement.mode', 'partial')
            ->assertDontSee('id="settlement-invoice_amount"', false)
            ->set('settlement.amount', '40')->call('save', true)->assertHasNoErrors()
            ->assertSet('previewData.settlement.outstanding_invoice_amount', '60.000000');
        $form->set('settlement.payment_date', '')->call('save', true)->assertHasErrors('settlement.payment_date');
    }

    public function test_full_payment_tracks_total_until_edited_and_invoice_currency_clears_allocation_units(): void
    {
        $form = Livewire::test(InvoiceForm::class, ['publicId' => $this->draft()->public_id])->set('settlement.mode', 'full')
            ->set('lines.0.unit_price', '200')->call('recalculate')->assertSet('settlement.amount', '200.00')
            ->set('settlement.amount', '80')->set('lines.0.unit_price', '300')->call('recalculate')->assertSet('settlement.amount', '80');
        $base = $form->get('settlement.money_account_id');
        $form->set('settlement.money_account_id', $base)->set('settlement.invoice_amount', '40')
            ->set('currency_code', 'USD')->assertSet('settlement.amount', '80')
            ->assertSet('settlement.invoice_amount', '')->assertSet('settlement.payment_currency_amount', '');
    }

    public function test_mobile_confirm_is_omitted_without_post_permission(): void
    {
        $invoice = $this->draft();
        foreach ($this->owner->roles as $role) {
            $role->revokePermissionTo('sales.invoice.post');
        }
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');
        Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id])->assertDontSee('data-mobile-confirm', false);
    }

    public function test_disabled_accounts_are_never_defaulted_and_missing_selection_is_not_replaced_on_submit(): void
    {
        $invoice = $this->draft('USD');
        $this->cash->update(['is_active' => false]);
        $form = Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id])->set('settlement.mode', 'full');
        $selected = $form->get('settlement.money_account_id');
        $this->assertNotSame((string) $this->cash->id, $selected);
        $this->assertTrue($selected === '' || MoneyAccount::findOrFail($selected)->is_active);
        $form->set('settlement.money_account_id', '')->set('settlement.amount', '100')->call('save', true)
            ->assertHasErrors('settlement.money_account_id')->assertSet('settlement.money_account_id', '');
    }
}
