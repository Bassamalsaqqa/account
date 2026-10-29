<?php

declare(strict_types=1);

namespace Tests\Feature\Fin1;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\PaymentDetail;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

final class ImmediateSaleLivewireTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    private function draft(): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5',
            'issue_date' => '2026-10-29', 'document_locale' => 'en',
            'lines' => [['item_description' => 'Manual description preserved', 'quantity' => '1', 'unit_price' => '100']],
        ]);
    }

    private function form(SalesInvoice $invoice)
    {
        return Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id]);
    }

    public function test_unpaid_is_the_default_and_keeps_the_existing_two_step_workflow(): void
    {
        $invoice = $this->draft();
        $this->form($invoice)->assertSet('settlement.mode', 'unpaid')
            ->call('save', true)->assertHasNoErrors()->assertSet('showPreview', true)
            ->assertSet('settlementPreview', false)->call('confirmPost')
            ->assertRedirect(route('invoices.show', $invoice->public_id));
        $this->assertTrue($invoice->fresh()->isPosted());
        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_paid_preview_and_explicit_confirmation_render_and_post_in_both_locales(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $invoice = $this->draft();
            $component = $this->form($invoice)->set('settlement.mode', 'partial')
                ->set('settlement.money_account_id', (string) $this->cash->id)
                ->set('settlement.amount', '40')->set('settlement.invoice_amount', '40')
                ->set('settlement.exchange_rate', '3.5')->call('save', true)
                ->assertHasNoErrors()->assertSet('showPreview', true)->assertSet('settlementPreview', true)
                ->assertSee(__('fin1.review'))->assertSet('previewData.settlement.outstanding_invoice_amount', '60.000000');
            $this->assertTrue($invoice->fresh()->isDraft());
            $component->call('confirmPost')->assertRedirect(route('invoices.show', $invoice->public_id));
            $this->assertTrue($invoice->fresh()->isPosted());
        }
        $this->assertDatabaseCount('customer_payments', 2);
    }

    public function test_payment_validation_preserves_saved_draft_and_manual_values(): void
    {
        $invoice = $this->draft();
        $this->form($invoice)->set('notes', 'Manual note retained')->set('settlement.mode', 'full')
            ->call('save', true)->assertHasErrors(['settlement.amount', 'settlement.money_account_id'])
            ->assertSet('notes', 'Manual note retained')->assertSet('lines.0.item_description', 'Manual description preserved')
            ->assertSet('showPreview', false)->assertNoRedirect();
        $this->assertTrue($invoice->fresh()->isDraft());
        $this->assertSame('Manual note retained', $invoice->fresh()->notes);
        $this->assertDatabaseCount('customer_payments', 0);
        $this->assertDatabaseCount('posting_batches', 0);
    }

    public function test_editing_payment_after_preview_requires_new_review(): void
    {
        $invoice = $this->draft();
        $this->form($invoice)->set('settlement.mode', 'full')
            ->set('settlement.money_account_id', (string) $this->cash->id)
            ->set('settlement.amount', '100')->set('settlement.exchange_rate', '3.5')
            ->call('save', true)->assertSet('showPreview', true)
            ->set('settlement.amount', '120')->assertSet('showPreview', false)
            ->call('confirmPost')->assertNoRedirect();
        $this->assertTrue($invoice->fresh()->isDraft());
        $this->assertDatabaseCount('customer_payments', 0);
    }

    public function test_check_metadata_is_reviewed_and_received_without_cash_account(): void
    {
        $invoice = $this->draft();
        $this->form($invoice)->set('settlement.mode', 'full')->set('settlement.payment_method', 'check')
            ->set('settlement.amount', '100')->set('settlement.exchange_rate', '3.5')
            ->set('settlement.currency_code', 'USD')->set('settlement.check_number', 'SYNTHETIC-UI-CHECK')
            ->set('settlement.bank_name', 'Synthetic Bank')->set('settlement.due_date', '2026-11-01')
            ->call('save', true)->assertHasNoErrors()->assertSet('settlementPreview', true)
            ->assertSee('SYNTHETIC-UI-CHECK')->call('confirmPost')
            ->assertRedirect(route('invoices.show', $invoice->public_id));
        $this->assertDatabaseHas('checks', ['check_number' => 'SYNTHETIC-UI-CHECK', 'status' => 'received']);
        $this->assertDatabaseHas('customer_payments', ['payment_method' => 'check', 'money_account_id' => null]);
        $payment = CustomerPayment::where('company_id', $this->company->id)->where('payment_method', 'check')->firstOrFail();
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])
                ->assertSee(__('fin1.check'))->assertSee(__('money.check_number'))
                ->assertDontSee('money.check')->assertDontSee('sales.check');
        }
    }
}
