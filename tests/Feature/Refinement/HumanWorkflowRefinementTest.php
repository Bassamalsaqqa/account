<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\Pages\Customers\CustomerForm;
use App\Livewire\Pages\Purchasing\PaymentForm as PurchasingPaymentForm;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Livewire\Pages\Purchasing\VendorForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\InvoiceIndex;
use App\Livewire\Pages\Sales\PaymentForm as SalesPaymentForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\StockMovement;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class HumanWorkflowRefinementTest extends Phase5ETestCase
{
    public function test_direct_purchase_confirmation_cannot_post_an_older_saved_draft_while_quick_create_is_open(): void
    {
        foreach (['showQuickVendorModal', 'showQuickProductModal'] as $modal) {
            $form = Livewire::test(PurchaseForm::class)
                ->set('vendor_id', $this->vendor->id)
                ->call('selectProduct', 0, $this->product->id)
                ->set('lines.0.quantity', '2')->set('lines.0.unit_cost', '25.00')
                ->call('saveDraft')->assertHasNoErrors();
            $draft = Purchase::where('public_id', $form->get('savedDraftPublicId'))->firstOrFail();
            $form->set('lines.0.quantity', '3')->set($modal, true)
                ->call('saveAndConfirm')->assertHasNoErrors()
                ->assertSet($modal, true)->assertSet('lines.0.quantity', '3');
            $this->assertTrue($draft->fresh()->isDraft());
            $this->assertSame('2.000000', $draft->lines()->sole()->quantity);
        }
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_direct_invoice_confirmation_preserves_quick_create_and_does_not_save_or_post(): void
    {
        foreach (['showQuickCustomerModal', 'showQuickProductModal'] as $modal) {
            Livewire::test(InvoiceForm::class)->set('customer_id', $this->customer->id)
                ->set('lines.0.item_description', 'Unsaved service')
                ->set('lines.0.quantity', '1')->set('lines.0.unit_price', '25.00')
                ->set($modal, true)->call('saveAndConfirm')->assertHasNoErrors()
                ->assertSet($modal, true)->assertSet('lines.0.item_description', 'Unsaved service');
        }
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('posting_batches', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_purchase_confirm_creates_one_canonical_receipt_and_retries_do_not_duplicate_it(): void
    {
        $form = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')->set('lines.0.unit_cost', '25.00')
            ->call('saveAndConfirm')->assertHasNoErrors();
        $purchase = Purchase::where('company_id', $this->company->id)->sole();
        $this->assertTrue($purchase->isPosted());
        $form->assertRedirect(route('purchases.show', $purchase->public_id));
        $form->call('saveAndConfirm')->assertHasNoErrors();
        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());
        $this->assertSame(1, PostingBatch::where('company_id', $this->company->id)->where('source_type', 'purchase')->count());
        $this->assertSame(1, StockMovement::where('company_id', $this->company->id)->where('source_type', 'purchase')->count());
    }

    public function test_a_free_line_in_a_paid_purchase_remains_a_real_zero_cost_stock_receipt(): void
    {
        $purchase = $this->createAndPostPurchase(['lines' => [
            ['quantity' => '2', 'unit_cost' => '25.00'],
            ['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'quantity' => '1', 'unit_cost' => '0.00'],
        ]]);
        $this->assertTrue($purchase->isPosted());
        $this->assertSame('50.000000', $purchase->grand_total_base);
        $this->assertSame('0.000000', $purchase->lines()->orderBy('id')->get()->last()->unit_cost);
    }

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل اختبار تجربة المستخدم',
            'name_en' => 'UX Refinement Customer',
            'preferred_locale' => 'ar',
            'default_currency_code' => 'ILS',
            'created_by' => $this->owner->id,
            'status' => 'active',
        ]);
    }

    public function test_document_locale_inherits_interface_language_and_party_selection_preserves_operator_choice(): void
    {
        // 1. When interface locale is 'en', new forms default to 'en'
        app()->setLocale('en');

        $invForm = Livewire::test(InvoiceForm::class);
        $this->assertSame('en', $invForm->get('document_locale'));

        $quoteForm = Livewire::test(QuotationForm::class);
        $this->assertSame('en', $quoteForm->get('document_locale'));

        $purchForm = Livewire::test(PurchaseForm::class);
        $this->assertSame('en', $purchForm->get('document_locale'));

        // 2. Selecting a customer with preferred_locale 'ar' does NOT overwrite the operator's choice 'en'
        $invForm->set('customer_id', $this->customer->id);
        $this->assertSame('en', $invForm->get('document_locale'));

        $quoteForm->set('customer_id', $this->customer->id);
        $this->assertSame('en', $quoteForm->get('document_locale'));

        $purchForm->set('vendor_id', $this->vendor->id);
        $this->assertSame('en', $purchForm->get('document_locale'));

        // 3. Existing draft loaded from DB retains stored intent
        $invoiceDraft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $this->product->id,
                'item_description' => 'Food Item',
                'quantity' => '5',
                'unit_price' => '100.00',
            ]],
        ]);

        $editForm = Livewire::test(InvoiceForm::class, ['publicId' => $invoiceDraft->public_id]);
        $this->assertSame('ar', $editForm->get('document_locale'));
    }

    public function test_document_lists_order_newest_created_first(): void
    {
        // Create two invoices with timestamp separation
        $first = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'item_description' => 'Item 1', 'quantity' => '1', 'unit_price' => '10.00']],
        ]);
        $first->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        $second = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'item_description' => 'Item 2', 'quantity' => '2', 'unit_price' => '20.00']],
        ]);
        $second->forceFill(['created_at' => now()])->saveQuietly();

        $component = Livewire::test(InvoiceIndex::class);
        $invoices = $component->viewData('invoices');
        $this->assertSame($second->id, $invoices->first()->id);

        // Analogous for purchases
        $purch1 = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '10.00']],
        ]);
        $purch1->forceFill(['created_at' => now()->subMinutes(10)])->saveQuietly();

        $purch2 = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'purchase_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '20.00']],
        ]);
        $purch2->forceFill(['created_at' => now()])->saveQuietly();

        $purchComponent = Livewire::test(PurchaseIndex::class);
        $purchases = $purchComponent->viewData('purchases');
        $this->assertSame($purch2->id, $purchases->first()->id);
    }

    public function test_sales_payment_form_preselects_and_allocates_to_source_invoice(): void
    {
        // First post purchase so inventory exists
        $this->createAndPostPurchase();

        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'lines' => [[
                'product_id' => $this->product->id,
                'item_description' => 'Food Item',
                'quantity' => '3',
                'unit_price' => '50.00',
            ]],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $due = (string) $invoice->calculateOutstanding();
        $this->assertSame('150.000000', $due);

        // Navigate with invoice_id
        $form = Livewire::test(SalesPaymentForm::class, ['invoice_id' => $invoice->id]);

        $this->assertSame($invoice->id, $form->get('sourceInvoiceId'));
        $this->assertSame('150.00', $form->get('amount'));
        $this->assertSame('150.00', $form->get('allocations.0.allocated_amount'));
        $this->assertSame('150.00', $form->get('allocatedTotal'));
        $this->assertSame('0.00', $form->get('unallocatedAmount'));
        $this->assertSame('1.0000000000', $form->get('exchange_rate'));

        // Partial payment amount updates allocation on originating invoice
        $form->set('amount', '60.00');
        $this->assertSame('60.00', $form->get('allocations.0.allocated_amount'));
        $this->assertSame('60.00', $form->get('allocatedTotal'));
        $this->assertSame('0.00', $form->get('unallocatedAmount'));

        // Full balance resets to full outstanding
        $form->call('useFullBalance');
        $this->assertSame('150.00', $form->get('amount'));
        $this->assertSame('150.000000', $form->get('allocations.0.allocated_amount'));
    }

    public function test_purchasing_payment_form_preselects_and_allocates_to_source_purchase(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [[
                'product_id' => $this->product->id,
                'quantity' => '4',
                'unit_cost' => '25.00',
            ]],
        ]);
        $outstanding = (string) $purchase->payablePosition()->outstanding;
        $this->assertSame('100.000000', $outstanding);

        // Navigate with purchase_id
        $form = Livewire::test(PurchasingPaymentForm::class, ['purchase_id' => $purchase->id]);

        $this->assertSame($purchase->id, $form->get('sourcePurchaseId'));
        $this->assertSame('100.00', $form->get('amount'));
        $this->assertSame('100.000000', $form->get('allocations.0.allocated_amount'));
        $this->assertSame('100.00', $form->get('allocatedTotal'));
        $this->assertSame('0.00', $form->get('unallocatedAmount'));
        $this->assertSame('1.0000000000', $form->get('exchange_rate'));

        // Partial payment amount updates allocation on originating purchase
        $form->set('amount', '40.00');
        $this->assertSame('40.00', $form->get('allocations.0.allocated_amount'));
        $this->assertSame('40.00', $form->get('allocatedTotal'));
        $this->assertSame('0.00', $form->get('unallocatedAmount'));

        // Full balance resets to full outstanding
        $form->call('useFullBalance');
        $this->assertSame('100.00', $form->get('amount'));
        $this->assertSame('100.000000', $form->get('allocations.0.allocated_amount'));
    }

    public function test_customer_and_vendor_form_advanced_details_visibility(): void
    {
        // Customer create: showAdvanced is false by default
        $createCustomer = Livewire::test(CustomerForm::class);
        $this->assertFalse($createCustomer->get('isEditing'));
        $createCustomer->assertSee('showAdvanced: false', false);

        // Customer edit: isEditing is true, showAdvanced is true
        $editCustomer = Livewire::test(CustomerForm::class, ['publicId' => $this->customer->public_id]);
        $this->assertTrue($editCustomer->get('isEditing'));
        $editCustomer->assertSee('showAdvanced: true', false);

        // Vendor create: showAdvanced is false by default
        $createVendor = Livewire::test(VendorForm::class);
        $this->assertNull($createVendor->get('vendorId'));
        $createVendor->assertSee('showAdvanced: false', false);

        // Vendor edit: vendorId is set, showAdvanced is true
        $editVendor = Livewire::test(VendorForm::class, ['publicId' => $this->vendor->public_id]);
        $this->assertSame($this->vendor->id, $editVendor->get('vendorId'));
        $editVendor->assertSee('showAdvanced: true', false);
    }

    public function test_payment_blank_and_overpayment_never_retains_stale_or_cross_allocates(): void
    {
        $this->createAndPostPurchase();

        // Create 2 invoices for the customer
        $draft1 = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'item_description' => 'Item 1', 'quantity' => '2', 'unit_price' => '50.00']],
        ]);
        $inv1 = app(PostSalesInvoiceAction::class)->execute($draft1, $this->owner);

        $draft2 = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'item_description' => 'Item 2', 'quantity' => '1', 'unit_price' => '50.00']],
        ]);
        $inv2 = app(PostSalesInvoiceAction::class)->execute($draft2, $this->owner);

        // Open payment for invoice 1 (outstanding 100)
        $form = Livewire::test(SalesPaymentForm::class, ['invoice_id' => $inv1->id]);
        $this->assertSame('100.00', $form->get('amount'));
        $this->assertSame('100.00', $form->get('allocations.0.allocated_amount'));
        $this->assertSame('0.00', $form->get('allocations.1.allocated_amount'));

        // 1. Amount cleared/blank -> allocated amount becomes 0.00, NOT stale 100
        $form->set('amount', '');
        $this->assertSame('0.00', $form->get('allocations.0.allocated_amount'));
        $this->assertSame('0.00', $form->get('allocatedTotal'));

        // 2. Overpayment (e.g. 250 on a 100 invoice) -> only caps at invoice 1 (100), NEVER auto-allocates to invoice 2
        $form->set('amount', '250.00');
        $this->assertSame('100.000000', $form->get('allocations.0.allocated_amount'));
        $this->assertSame('0.00', $form->get('allocations.1.allocated_amount'));
        $this->assertSame('150.00', $form->get('unallocatedAmount'));

        // 3. Malformed amount -> safe 0 allocation
        $form->set('amount', 'invalid');
        $this->assertSame('0.00', $form->get('allocations.0.allocated_amount'));
    }
}
