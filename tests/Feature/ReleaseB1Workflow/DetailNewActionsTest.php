<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1Workflow;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Sales\InvoiceDetail;
use App\Models\Customer;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class DetailNewActionsTest extends Phase5ETestCase
{
    protected Customer $customer;

    protected SalesInvoice $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:6CUe2hhgD04d5Aic4q6P5q8n3mQ/v3Z9Dk4e2vW+1o8=']);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الاختبار',
            'name_en' => 'Test Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-15',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                ['item_description' => 'بند مبيعات اختباري', 'quantity' => '2', 'unit_price' => '100.00'],
            ],
        ]);
    }

    public function test_invoice_detail_shows_new_invoice_button_when_authorized(): void
    {
        $creator = $this->customActor([
            'sales.invoice.view',
            'sales.invoice.create',
        ]);
        $this->activate($creator);

        Livewire::actingAs($creator)
            ->test(InvoiceDetail::class, ['publicId' => $this->invoice->public_id])
            ->assertOk()
            ->assertSee(__('release_b1_workflow.new_invoice'))
            ->assertSee(route('invoices.create'))
            ->assertDontSee('wire:navigate');
    }

    public function test_invoice_detail_hides_new_invoice_button_when_unauthorized(): void
    {
        $viewOnlyUser = $this->customActor([
            'sales.invoice.view',
        ]);
        $this->activate($viewOnlyUser);

        Livewire::actingAs($viewOnlyUser)
            ->test(InvoiceDetail::class, ['publicId' => $this->invoice->public_id])
            ->assertOk()
            ->assertDontSee(__('release_b1_workflow.new_invoice'));
    }

    public function test_invoice_detail_retains_document_after_posting_so_print_and_payment_remain_available(): void
    {
        $this->activate($this->owner);

        // Post the invoice
        $postedInvoice = app(PostSalesInvoiceAction::class)->execute($this->invoice, $this->owner);

        $test = Livewire::test(InvoiceDetail::class, ['publicId' => $postedInvoice->public_id])
            ->assertOk()
            ->assertSee(__('release_b1_workflow.new_invoice'))
            ->assertSee(__('sales.new_payment'))
            ->assertSee(route('pdf.invoice', ['publicId' => $postedInvoice->public_id]));

        $this->assertSame(SalesInvoice::STATUS_POSTED, $postedInvoice->status);
    }

    public function test_purchase_detail_shows_new_purchase_button_and_wrapping_when_authorized(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '20'],
            ],
        ]);

        $authorizedUser = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.purchase.create',
            'purchasing.cost.view',
        ]);
        $this->activate($authorizedUser);

        Livewire::actingAs($authorizedUser)
            ->test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertSee(__('release_b1_workflow.new_purchase'))
            ->assertSee(route('purchases.create'))
            ->assertSee('flex-wrap')
            ->assertDontSee('wire:navigate');
    }

    public function test_purchase_detail_hides_new_purchase_button_when_unauthorized(): void
    {
        $this->activate($this->owner);

        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '20'],
            ],
        ]);

        // 1. Missing purchasing.purchase.create
        $noCreateUser = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.cost.view',
        ]);
        $this->activate($noCreateUser);

        Livewire::actingAs($noCreateUser)
            ->test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertDontSee(__('release_b1_workflow.new_purchase'));

        // 2. Missing purchasing.cost.view
        $noCostUser = $this->customActor([
            'purchasing.purchase.view',
            'purchasing.purchase.create',
        ]);
        $this->activate($noCostUser);

        Livewire::actingAs($noCostUser)
            ->test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertDontSee(__('release_b1_workflow.new_purchase'));
    }

    public function test_purchase_detail_retains_document_after_posting_and_preserves_wire_confirm(): void
    {
        $this->activate($this->owner);

        // Create a valid draft purchase using canonical action
        $purchase = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-10',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10.00'],
            ],
        ]);

        // Verify wire:confirm on post button before posting
        $component = Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
            ->assertOk()
            ->assertSee('wire:confirm="'.__('purchasing.post_confirmation').'"', false)
            ->assertSee(__('purchasing.post_purchase'));

        // Execute post action on component
        $component->call('post')
            ->assertHasNoErrors();

        $fresh = $purchase->fresh();
        $this->assertSame(Purchase::STATUS_POSTED, $fresh->status);

        // Document remains on page, status updated, Payment and PDF actions available
        $component->assertSee(__('purchasing.pay_vendor'))
            ->assertSee(__('release_b1_workflow.new_purchase'))
            ->assertSee(route('pdf.purchase', ['publicId' => $purchase->public_id]));
    }
}
