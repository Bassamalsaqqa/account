<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

final class QuickProductBoundaryTest extends Phase5ETestCase
{
    public function test_posted_invoice_cannot_create_product_from_stale_editor(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Synthetic customer', 'active' => true, 'created_by' => $this->owner->id]);
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'issue_date' => '2026-10-15', 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '10']]]);
        $page = Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id])->call('openQuickProductModal')
            ->set('quick_product_name_ar', 'Must not create')->set('quick_product_sku', 'DENIED');
        app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
        $before = [Product::count(), DB::table('product_creation_requests')->count()];
        $page->call('saveQuickProduct')->assertForbidden();
        $this->assertSame($before, [Product::count(), DB::table('product_creation_requests')->count()]);
    }

    public function test_sent_quote_cannot_create_product_from_stale_editor(): void
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Synthetic customer', 'active' => true, 'created_by' => $this->owner->id]);
        $quote = app(CreateQuotationAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'issue_date' => '2026-10-15', 'currency_code' => 'ILS', 'exchange_rate' => '1',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '10']]]);
        $page = Livewire::test(QuotationForm::class, ['publicId' => $quote->public_id])->call('openQuickProductModal')
            ->set('quick_product_name_ar', 'Must not create')->set('quick_product_sku', 'DENIED');
        $quote->transition(Quotation::STATUS_SENT, $this->owner);
        $before = Product::count();
        $page->call('saveQuickProduct')->assertForbidden();
        $this->assertSame($before, Product::count());
    }

    public function test_invalid_exchange_rate_retains_modal_input_and_creates_no_master(): void
    {
        $before = Product::count();
        Livewire::test(PurchaseForm::class)->set('currency_code', 'USD')->call('openQuickProductModal')
            ->set('quick_product_name_ar', 'Retained input')->set('quick_product_sku', 'RETAINED')->call('saveQuickProduct')
            ->assertHasErrors(['quick_product_name_ar'])->assertSet('showQuickProductModal', true)
            ->assertSet('quick_product_name_ar', 'Retained input');
        $this->assertSame($before, Product::count());
        $this->assertSame(0, DB::table('product_creation_requests')->count());
    }

    public function test_quick_receive_canonical_post_remains_healthy_under_all_six_reconciliations(): void
    {
        $page = Livewire::test(PurchaseForm::class, ['receiveProduct' => $this->product->public_id])
            ->set('vendor_id', $this->vendor->id)->set('lines.0.quantity', '2')->set('lines.0.unit_cost', '12.34')->call('saveDraft')->assertHasNoErrors();
        $purchase = Purchase::where('public_id', $page->get('savedDraftPublicId'))->firstOrFail();
        app(PostPurchaseAction::class)->execute($purchase, $this->owner);
        foreach ([AccountingReconciliationService::class,
            SalesReconciliationService::class, PayablesReconciliationService::class,
            MoneyReconciliationService::class, Phase7ReconciliationService::class] as $service) {
            $report = app($service)->reconcile($this->company);
            $this->assertTrue($report->isHealthy, $service.': '.implode('; ', $report->violations));
        }
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($report->isHealthy, implode('; ', $report->discrepancies));
    }
}
