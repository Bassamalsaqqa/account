<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1Workflow;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Livewire\Pages\Products\BarcodeLabels;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Purchasing\PaymentDetail as VendorPaymentDetail;
use App\Livewire\Pages\Purchasing\PaymentForm as VendorPaymentForm;
use App\Livewire\Pages\Sales\PaymentDetail as CustomerPaymentDetail;
use App\Livewire\Pages\Sales\PaymentForm as CustomerPaymentForm;
use App\Models\Customer;
use App\Models\ProductBarcode;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class SourceContextContinuityTest extends Phase5ETestCase
{
    protected Customer $customer;

    protected SalesInvoice $invoice;

    protected Purchase $purchase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:6CUe2hhgD04d5Aic4q6P5q8n3mQ/v3Z9Dk4e2vW+1o8=']);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل المصدر',
            'name_en' => 'Source Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $draftInvoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'issue_date' => '2026-10-15',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                ['item_description' => 'بند مبيعات مصدري', 'quantity' => '1', 'unit_price' => '150.00'],
            ],
        ]);
        $this->invoice = app(PostSalesInvoiceAction::class)->execute($draftInvoice, $this->owner);

        $this->purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '20.00'],
            ],
        ]);
    }

    public function test_customer_payment_form_preserves_authorized_source_invoice_link(): void
    {
        $authorizedUser = $this->customActor([
            'money.receipt.create',
            'sales.invoice.view',
        ]);
        $this->activate($authorizedUser);

        Livewire::actingAs($authorizedUser)
            ->test(CustomerPaymentForm::class, ['invoice_id' => $this->invoice->id])
            ->assertOk()
            ->assertSet('sourceInvoicePublicId', $this->invoice->public_id)
            ->assertSee(__('release_b1_workflow.back_to_invoice').': '.$this->invoice->invoice_number)
            ->assertSee(route('invoices.show', $this->invoice->public_id));
    }

    public function test_customer_payment_form_ignores_cross_company_or_unauthorized_source_invoice(): void
    {
        // 1. User without sales.invoice.view must not see source invoice link
        $noViewUser = $this->customActor([
            'money.receipt.create',
        ]);
        $this->activate($noViewUser);

        Livewire::actingAs($noViewUser)
            ->test(CustomerPaymentForm::class, ['invoice_id' => $this->invoice->id])
            ->assertOk()
            ->assertSet('sourceInvoicePublicId', null)
            ->assertDontSee(__('release_b1_workflow.back_to_invoice'));

        // 2. Cross-company invoice ID must be safely ignored
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'ar']);
        $companyB = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($otherOwner);
        app(CompanyContext::class)->setCompany($companyB, $otherOwner);
        $otherCustomer = Customer::create([
            'company_id' => $companyB->id,
            'name_ar' => 'عميل شركة أخرى',
            'active' => true,
            'created_by' => $otherOwner->id,
        ]);
        $otherInvoice = app(CreateSalesInvoiceDraftAction::class)->execute($companyB, $otherOwner, [
            'customer_id' => $otherCustomer->id,
            'issue_date' => '2026-10-15',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [
                ['item_description' => 'بند شركة أخرى', 'quantity' => '1', 'unit_price' => '50.00'],
            ],
        ]);

        $this->activate($this->owner);

        Livewire::actingAs($this->owner)
            ->test(CustomerPaymentForm::class, ['invoice_id' => $otherInvoice->id])
            ->assertOk()
            ->assertSet('sourceInvoicePublicId', null)
            ->assertDontSee($otherInvoice->invoice_number ?? 'DRAFT');
    }

    public function test_customer_payment_detail_shows_back_to_invoice_for_single_allocated_invoice(): void
    {
        $this->activate($this->owner);

        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-16',
            'payment_method' => 'cash',
            'amount' => '150.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'src-ctx-pmt-1',
            'allocations' => [
                ['sales_invoice_id' => $this->invoice->id, 'allocated_amount' => '150.00'],
            ],
        ]);

        // Authorized user sees Back to Invoice link
        Livewire::test(CustomerPaymentDetail::class, ['publicId' => $payment->public_id])
            ->assertOk()
            ->assertSee(__('release_b1_workflow.back_to_invoice').': '.$this->invoice->invoice_number)
            ->assertSee(route('invoices.show', $this->invoice->public_id));

        // User without sales.invoice.view does not see Back to Invoice link
        $noInvoiceViewUser = $this->customActor([
            'money.receipt.view',
        ]);
        $this->activate($noInvoiceViewUser);

        Livewire::actingAs($noInvoiceViewUser)
            ->test(CustomerPaymentDetail::class, ['publicId' => $payment->public_id])
            ->assertOk()
            ->assertDontSee(__('release_b1_workflow.back_to_invoice'))
            ->assertDontSee(route('invoices.show', $this->invoice->public_id));
    }

    public function test_vendor_payment_form_preserves_authorized_source_purchase_link(): void
    {
        $authorizedUser = $this->customActor([
            'vendors.view',
            'purchasing.cost.view',
            'money.vendor_payment.create',
            'purchasing.purchase.view',
        ]);
        $this->activate($authorizedUser);

        Livewire::actingAs($authorizedUser)
            ->test(VendorPaymentForm::class, ['purchase_id' => $this->purchase->id])
            ->assertOk()
            ->assertSet('sourcePurchasePublicId', $this->purchase->public_id)
            ->assertSee(__('release_b1_workflow.back_to_purchase').': '.$this->purchase->purchase_number)
            ->assertSee(route('purchases.show', $this->purchase->public_id));
    }

    public function test_vendor_payment_form_ignores_cross_company_or_unauthorized_source_purchase(): void
    {
        // 1. User without purchasing.purchase.view
        $noPurchaseViewUser = $this->customActor([
            'vendors.view',
            'purchasing.cost.view',
            'money.vendor_payment.create',
        ]);
        $this->activate($noPurchaseViewUser);

        Livewire::actingAs($noPurchaseViewUser)
            ->test(VendorPaymentForm::class, ['purchase_id' => $this->purchase->id])
            ->assertOk()
            ->assertSet('sourcePurchasePublicId', null)
            ->assertDontSee(__('release_b1_workflow.back_to_purchase'));

        // 2. Cross-company purchase
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'ar']);
        $companyB = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية للمشتريات',
            'name_en' => 'Second Purchasing Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->activate($this->owner);

        Livewire::actingAs($this->owner)
            ->test(VendorPaymentForm::class, ['purchase_id' => 999999])
            ->assertOk()
            ->assertSet('sourcePurchasePublicId', null)
            ->assertDontSee(__('release_b1_workflow.back_to_purchase'));
    }

    public function test_vendor_payment_detail_shows_back_to_purchase_for_single_allocated_purchase(): void
    {
        $this->activate($this->owner);

        $vendorPayment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-16',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'src-ctx-vpmt-1',
            'allocations' => [
                ['purchase_id' => $this->purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // Authorized user sees Back to Purchase link
        Livewire::test(VendorPaymentDetail::class, ['publicId' => $vendorPayment->public_id])
            ->assertOk()
            ->assertSee(__('release_b1_workflow.back_to_purchase').': '.$this->purchase->purchase_number)
            ->assertSee(route('purchases.show', $this->purchase->public_id));

        // User without purchasing.purchase.view does not see Back to Purchase link
        $noPurchaseViewUser = $this->customActor([
            'vendors.view',
            'purchasing.cost.view',
            'money.vendor_payment.create',
        ]);
        $this->activate($noPurchaseViewUser);

        Livewire::actingAs($noPurchaseViewUser)
            ->test(VendorPaymentDetail::class, ['publicId' => $vendorPayment->public_id])
            ->assertOk()
            ->assertDontSee(__('release_b1_workflow.back_to_purchase'))
            ->assertDontSee(route('purchases.show', $this->purchase->public_id));
    }

    public function test_product_detail_barcodes_tab_links_to_barcode_labels_and_prefills_search(): void
    {
        $this->activate($this->owner);

        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unit->unit_id,
            'barcode' => '6281001234567',
            'type' => 'CODE128',
            'is_primary' => true,
        ]);

        $detail = Livewire::test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->set('activeTab', 'barcodes')
            ->assertOk()
            ->assertSee(__('release_b1_workflow.print_barcode_labels'))
            ->assertSee(route('labels.index', ['search' => $this->product->sku]));

        $viewer = $this->customActor(['inventory.stock.view']);
        $this->activate($viewer);
        Livewire::actingAs($viewer)->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->set('activeTab', 'barcodes')
            ->assertDontSee(__('release_b1_workflow.print_barcode_labels'));
        $this->activate($this->owner);

        // Prefill search in BarcodeLabels component
        Livewire::test(BarcodeLabels::class, ['search' => $this->product->sku])
            ->assertOk()
            ->assertSet('search', $this->product->sku);
    }
}
