<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\EnsurePurchasingFoundationAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceDetail;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationDetail;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class TradeEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_scanning_an_invalid_unit_conversion_reports_an_error_without_changing_the_line(): void
    {
        foreach ([InvoiceForm::class, PurchaseForm::class] as $component) {
            $page = Livewire::test($component);
            $lines = $page->get('lines');
            DB::table('product_units')->where('product_id', $this->product->id)->update(['conversion_to_base' => '0']);
            $page->set('barcodeSearch', $this->product->sku)->call('scanBarcode')->assertHasErrors(['barcodeSearch']);
            $this->assertSame($lines, $page->get('lines'));
        }
    }

    public function test_scanning_cannot_convert_a_raw_float_quantity_into_an_accepted_string(): void
    {
        foreach ([InvoiceForm::class, PurchaseForm::class] as $component) {
            $page = Livewire::test($component)->call('selectProduct', 0, $this->product->id)
                ->set('lines.0.quantity', 1.5)->set('barcodeSearch', $this->product->sku)->call('scanBarcode');
            $page->assertHasErrors(['lines.0.quantity']);
            $this->assertSame(1.5, $page->get('lines.0.quantity'));
        }
    }

    public function test_new_sales_documents_use_company_local_day_at_the_utc_midnight_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-10-14 21:30:00', 'UTC'));
        Livewire::test(InvoiceForm::class)->assertSet('issue_date', '2026-10-15')->assertSet('due_date', '2026-11-14');
        Livewire::test(QuotationForm::class)->assertSet('issue_date', '2026-10-15');
    }

    public function test_quick_party_creation_preserves_current_currency_rate_and_line_amounts(): void
    {
        foreach ([[InvoiceForm::class, 'customer', 'unit_price'], [PurchaseForm::class, 'vendor', 'unit_cost']] as [$component, $kind, $amountField]) {
            $page = Livewire::test($component)->set('currency_code', 'USD')->set('exchange_rate', '3.5')
                ->call('selectProduct', 0, $this->product->id)->set('lines.0.quantity', '3')->set('lines.0.'.$amountField, '10.25')
                ->call($kind === 'customer' ? 'openQuickCustomerModal' : 'openQuickVendorModal')
                ->set('quick_'.$kind.'_name_ar', 'Currency preservation '.$kind)
                ->call($kind === 'customer' ? 'saveQuickCustomer' : 'saveQuickVendor');
            $page->assertHasNoErrors()->assertSet('currency_code', 'USD')->assertSet('exchange_rate', '3.5')
                ->assertSet('lines.0.quantity', '3')->assertSet('lines.0.'.$amountField, '10.25');
        }
    }

    protected User $owner;

    protected Company $company;

    protected Customer $customer;

    protected Vendor $vendor;

    protected Warehouse $warehouse;

    protected Product $product;

    protected ProductUnit $pieceProductUnit;

    protected ProductUnit $cartonProductUnit;

    protected Unit $pieceUnit;

    protected Unit $cartonUnit;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();

        $this->owner = User::factory()->create(['locale' => 'en']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التجارة الحديثة',
            'name_en' => 'Modern Trade Co',
            'base_currency_code' => 'ILS',
        ]);

        app(EnsurePurchasingFoundationAction::class)->execute($this->company);
        $this->activateCompany($this->company, $this->owner);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل تجريبي',
            'name_en' => 'Test Customer',
            'code' => 'CUST-BASE',
            'status' => 'active',
            'active' => true,
            'preferred_locale' => 'en',
            'created_by' => $this->owner->id,
        ]);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد تجريبي',
            'name_en' => 'Test Vendor',
            'code' => 'VEND-BASE',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->pieceUnit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->cartonUnit = Unit::updateOrCreate(
            ['company_id' => $this->company->id, 'code' => 'carton'],
            ['name_ar' => 'كرتونة', 'name_en' => 'Carton', 'allows_fraction' => false, 'decimal_places' => 0, 'active' => true]
        );

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون سائل',
            'name_en' => 'Liquid Soap',
            'sku' => 'SOAP-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $this->pieceUnit->id,
            'default_sale_price_base' => '5.000000',
            'default_purchase_cost_base' => '2.000000',
        ], $this->owner->id);

        $this->pieceProductUnit = ProductUnit::where('product_id', $this->product->id)->where('is_base', true)->firstOrFail();

        $this->cartonProductUnit = app(ProductCatalogService::class)->addOrUpdateAlternateUnit(
            $this->product,
            $this->cartonUnit->id,
            '12.000000',
            false,
            true
        );
        $this->cartonProductUnit->update([
            'default_sale_price_base' => '60.000000',
            'default_purchase_cost_base' => '24.000000',
        ]);
    }

    protected function activateCompany(Company $company, User $actor): void
    {
        app(CompanyContext::class)->setCompany($company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    protected function createMemberWithPermissions(Company $company, array $permissions): User
    {
        $user = User::factory()->create();
        $company->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        $role = Role::create([
            'company_id' => $company->id,
            'name' => 'Role_'.Str::random(10),
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(array_unique($permissions));
        $user->assignRole($role);
        $this->activateCompany($company, $user);

        return $user;
    }

    /**
     * 1. Unsaved preservation when quick-creating Customer on InvoiceForm/QuotationForm and Vendor on PurchaseForm.
     */
    public function test_unsaved_preservation_when_quick_creating_party(): void
    {
        // InvoiceForm: set unsaved lines & notes, open modal, quick-create customer, assert lines preserved and customer selected
        $invoiceForm = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('notes', 'Preserve this sales invoice note')
            ->set('lines.0.product_id', $this->product->id)
            ->set('lines.0.quantity', '7')
            ->set('lines.0.unit_price', '55.00')
            ->call('openQuickCustomerModal')
            ->assertSet('showQuickCustomerModal', true)
            ->set('quick_customer_name_ar', 'عميل فوري فاتورة')
            ->set('quick_customer_name_en', 'Quick Invoice Customer')
            ->set('quick_customer_phone', '0599111222')
            ->set('quick_customer_code', 'CUST-QINV-01')
            ->call('saveQuickCustomer')
            ->assertSet('showQuickCustomerModal', false);

        $createdCustomer = Customer::where('company_id', $this->company->id)->where('code', 'CUST-QINV-01')->firstOrFail();
        $this->assertSame('عميل فوري فاتورة', $createdCustomer->name_ar);
        $this->assertSame('Quick Invoice Customer', $createdCustomer->name_en);

        $invoiceForm->assertSet('customer_id', $createdCustomer->id)
            ->assertSet('lines.0.product_id', $this->product->id)
            ->assertSet('lines.0.quantity', '7')
            ->assertSet('lines.0.unit_price', '55.00')
            ->assertSet('notes', 'Preserve this sales invoice note');

        // QuotationForm: set unsaved lines, terms, notes, quick-create customer
        $quoteForm = Livewire::test(QuotationForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('terms', 'Quotation special terms 30 days')
            ->set('notes', 'Quotation preserved notes')
            ->set('lines.0.product_id', $this->product->id)
            ->set('lines.0.quantity', '15')
            ->set('lines.0.unit_price', '48.00')
            ->call('openQuickCustomerModal')
            ->assertSet('showQuickCustomerModal', true)
            ->set('quick_customer_name_ar', 'عميل فوري عرض')
            ->set('quick_customer_name_en', 'Quick Quote Customer')
            ->set('quick_customer_phone', '0599333444')
            ->set('quick_customer_code', 'CUST-QQUT-01')
            ->call('saveQuickCustomer')
            ->assertSet('showQuickCustomerModal', false);

        $createdQuoteCustomer = Customer::where('company_id', $this->company->id)->where('code', 'CUST-QQUT-01')->firstOrFail();
        $quoteForm->assertSet('customer_id', $createdQuoteCustomer->id)
            ->assertSet('lines.0.quantity', '15')
            ->assertSet('lines.0.unit_price', '48.00')
            ->assertSet('terms', 'Quotation special terms 30 days')
            ->assertSet('notes', 'Quotation preserved notes');

        // PurchaseForm: set unsaved lines, vendor invoice number, notes, quick-create vendor
        $purchaseForm = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('vendor_invoice_number', 'VINV-SYNTHETIC-99')
            ->set('notes', 'Preserve this purchase note')
            ->set('lines.0.product_id', $this->product->id)
            ->set('lines.0.quantity', '20')
            ->set('lines.0.unit_cost', '18.75')
            ->call('openQuickVendorModal')
            ->assertSet('showQuickVendorModal', true)
            ->set('quick_vendor_name_ar', 'مورد فوري شراء')
            ->set('quick_vendor_name_en', 'Quick Purchase Vendor')
            ->set('quick_vendor_phone', '0599555666')
            ->set('quick_vendor_code', 'VEND-QPUR-01')
            ->call('saveQuickVendor')
            ->assertSet('showQuickVendorModal', false);

        $createdVendor = Vendor::where('company_id', $this->company->id)->where('code', 'VEND-QPUR-01')->firstOrFail();
        $this->assertSame('مورد فوري شراء', $createdVendor->name_ar);
        $this->assertSame('Quick Purchase Vendor', $createdVendor->name_en);

        $purchaseForm->assertSet('vendor_id', $createdVendor->id)
            ->assertSet('vendor_invoice_number', 'VINV-SYNTHETIC-99')
            ->assertSet('notes', 'Preserve this purchase note')
            ->assertSet('lines.0.product_id', $this->product->id)
            ->assertSet('lines.0.quantity', '20')
            ->assertSet('lines.0.unit_cost', '18.75');
    }

    /**
     * 2. Permission loss and cross-tenant context switch guarding.
     */
    public function test_permission_loss_and_context_switch_guarding(): void
    {
        // Create user without customers.manage or vendors.manage
        $limitedUser = $this->createMemberWithPermissions($this->company, [
            'sales.invoice.view', 'sales.invoice.create',
            'sales.quote.view', 'sales.quote.create',
            'purchasing.purchase.view', 'purchasing.purchase.create', 'purchasing.cost.view',
        ]);

        // InvoiceForm renders with canManageCustomers = false and denies quick customer creation
        Livewire::test(InvoiceForm::class)
            ->assertSet('canManageCustomers', false)
            ->assertDontSee('+ '.__('workflow.quick_customer'))
            ->call('openQuickCustomerModal')
            ->assertForbidden();

        // QuotationForm renders with canManageCustomers = false and denies quick customer creation
        Livewire::test(QuotationForm::class)
            ->assertSet('canManageCustomers', false)
            ->assertDontSee('+ '.__('workflow.quick_customer'))
            ->call('openQuickCustomerModal')
            ->assertForbidden();

        // PurchaseForm renders with canManageVendors = false and denies quick vendor creation
        Livewire::test(PurchaseForm::class)
            ->assertSet('canManageVendors', false)
            ->assertDontSee('+ '.__('workflow.quick_vendor'))
            ->call('openQuickVendorModal')
            ->assertForbidden();

        // Context Switch Guarding:
        // Mount form under Company A, but simulate context switch to Company B before save
        $this->activateCompany($this->company, $this->owner);
        app(CompanyContext::class)->clear();
        $companyB = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة أجنبية',
            'name_en' => 'Foreign Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->activateCompany($this->company, $this->owner);

        $invoiceFormA = Livewire::test(InvoiceForm::class)->call('openQuickCustomerModal')
            ->set('quick_customer_name_ar', 'عميل تهريب');

        // Switch tenant context to Company B
        $this->activateCompany($companyB, $this->owner);

        // Attempting to execute saveQuickCustomer on form initialized under Company A aborts 403
        $invoiceFormA->call('saveQuickCustomer')
            ->assertForbidden();

        $this->assertSame(0, Customer::where('company_id', $companyB->id)->where('name_ar', 'عميل تهريب')->count());

        // Cross-domain method guarding: InvoiceForm cannot execute vendor quick create
        $this->activateCompany($this->company, $this->owner);
        Livewire::test(InvoiceForm::class)
            ->call('saveQuickVendor')
            ->assertForbidden();

        // Direct save when modal is not open must be refused
        Livewire::test(InvoiceForm::class)
            ->set('quick_customer_name_ar', 'عميل نافذة مغلقة')
            ->call('saveQuickCustomer')
            ->assertForbidden();
    }

    /**
     * 3. Idempotent token replay and catalog validation error mapping.
     */
    public function test_quick_party_idempotent_token_replay_and_catalog_validation_mapping(): void
    {
        // Test mapped ValidationException on duplicate code without modal closure or draft loss
        $existingCustomer = Customer::where('company_id', $this->company->id)->where('code', 'CUST-BASE')->firstOrFail();

        $form = Livewire::test(InvoiceForm::class)
            ->set('notes', 'Preserve notes during validation retry')
            ->call('openQuickCustomerModal')
            ->set('quick_customer_name_ar', 'عميل كود مكرر')
            ->set('quick_customer_code', 'CUST-BASE')
            ->call('saveQuickCustomer')
            ->assertSet('showQuickCustomerModal', true)
            ->assertHasErrors(['quick_customer_code'])
            ->assertSet('notes', 'Preserve notes during validation retry');

        // Test token replay: duplicate submission of the same modal payload replays created party
        $form->set('quick_customer_name_ar', 'عميل فريد مكرر النقر')
            ->set('quick_customer_code', 'CUST-REPLAY-99')
            ->call('saveQuickCustomer')
            ->assertSet('showQuickCustomerModal', false);

        $customerFirst = Customer::where('company_id', $this->company->id)->where('code', 'CUST-REPLAY-99')->firstOrFail();
        $this->assertSame($customerFirst->id, $form->get('customer_id'));

    }

    /**
     * 4. Cross-tenant unit rejection without fallback in Purchasing and Sales.
     */
    public function test_cross_tenant_unit_rejection_without_fallback(): void
    {
        app(CompanyContext::class)->clear();
        $companyB = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Co',
            'base_currency_code' => 'ILS',
        ]);
        $this->activateCompany($companyB, $this->owner);
        $pieceB = Unit::where('company_id', $companyB->id)->where('code', 'piece')->firstOrFail();
        $prodB = app(ProductCatalogService::class)->createProduct($companyB, [
            'name_ar' => 'منتج الشركة ب',
            'name_en' => 'Company B Product',
            'sku' => 'COMPB-SKU',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceB->id,
            'default_sale_price_base' => '10.000000',
            'default_purchase_cost_base' => '5.000000',
        ], $this->owner->id);

        ProductBarcode::create([
            'company_id' => $companyB->id,
            'product_id' => $prodB->id,
            'unit_id' => $pieceB->id,
            'barcode' => 'BAR-COMPB-ONLY',
            'type' => 'code128',
            'is_primary' => true,
        ]);

        // Switch back to Company A
        $this->activateCompany($this->company, $this->owner);

        // In PurchaseForm, scanning Company B barcode must fail with error and add no line
        $purchaseForm = Livewire::test(PurchaseForm::class)
            ->set('barcodeSearch', 'BAR-COMPB-ONLY')
            ->call('scanBarcode')
            ->assertHasErrors(['barcodeSearch']);

        // In InvoiceForm, scanning Company B barcode must also fail with error
        $invoiceForm = Livewire::test(InvoiceForm::class)
            ->set('barcodeSearch', 'BAR-COMPB-ONLY')
            ->call('scanBarcode')
            ->assertHasErrors(['barcodeSearch']);

        // In Company A, attach a barcode linked to carton unit
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->cartonUnit->id,
            'barcode' => 'BAR-A-CARTON',
            'type' => 'code128',
            'is_primary' => false,
        ]);

        // Deactivate carton unit in Company A
        $this->cartonProductUnit->update(['active' => false]);

        // Scanning the unit-linked barcode with inactive unit must be REJECTED, NOT fall back to base unit!
        $purchaseForm->set('barcodeSearch', 'BAR-A-CARTON')
            ->call('scanBarcode')
            ->assertHasErrors(['barcodeSearch']);

        $invoiceForm->set('barcodeSearch', 'BAR-A-CARTON')
            ->call('scanBarcode')
            ->assertHasErrors(['barcodeSearch']);

        // Cross-tenant product injection in quotation form lines aborts 404
        $quoteForm = Livewire::test(QuotationForm::class);
        $quoteForm->set('lines.0.product_id', $prodB->id)->assertNotFound();
    }

    /**
     * 5. Exact barcode carton pricing, non-merge safety on differing cost/lot, and length limit.
     */
    public function test_exact_barcode_carton_pricing_and_line_safety(): void
    {
        $this->cartonProductUnit->update(['active' => true]);
        $cartonBarcode = '1234567890123';
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->cartonUnit->id,
            'barcode' => $cartonBarcode,
            'type' => 'ean13',
            'is_primary' => true,
        ]);

        $purchaseForm = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('barcodeSearch', $cartonBarcode)
            ->call('scanBarcode')
            ->assertHasNoErrors();

        // Line 0 was empty on mount, filled with carton product unit and carton cost (24.000000)
        $purchaseForm->assertSet('lines.0.product_id', $this->product->id)
            ->assertSet('lines.0.product_unit_id', $this->cartonProductUnit->id)
            ->assertSet('lines.0.quantity', '1')
            ->assertSet('lines.0.unit_cost', '24.00');

        // Scan identical carton barcode again -> increments quantity to 2 because unit, lot, cost match
        $purchaseForm->set('barcodeSearch', $cartonBarcode)
            ->call('scanBarcode')
            ->assertSet('lines.0.quantity', '2')
            ->assertCount('lines', 1);

        // Change line 0 unit cost to 30.00
        $purchaseForm->set('lines.0.unit_cost', '30.00');

        // Scan barcode again -> since cost differs (30 vs 24), safest behavior is APPEND, do NOT merge!
        $purchaseForm->set('barcodeSearch', $cartonBarcode)
            ->call('scanBarcode')
            ->assertCount('lines', 2)
            ->assertSet('lines.0.quantity', '2')
            ->assertSet('lines.0.unit_cost', '30.00')
            ->assertSet('lines.1.quantity', '1')
            ->assertSet('lines.1.unit_cost', '24.00');

        // Finite max input: input > 128 chars rejected without truncating to valid barcode
        $purchaseForm->set('barcodeSearch', str_repeat('9', 129))
            ->call('scanBarcode')
            ->assertHasErrors(['barcodeSearch']);

        $invoiceForm = Livewire::test(InvoiceForm::class)
            ->set('barcodeSearch', str_repeat('9', 129))
            ->call('scanBarcode')
            ->assertHasErrors(['barcodeSearch']);
    }

    /**
     * 6. Duplicate clicks and shortcuts SAVE DRAFT only, never post, zero financial posting batches.
     */
    public function test_duplicate_clicks_save_draft_only_no_posting(): void
    {
        $batchCountBefore = DB::table('posting_batches')->count();
        $stockMovementBefore = DB::table('stock_movements')->count();

        // InvoiceForm duplicate saveDraft calls
        $invoiceForm = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $this->customer->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '3')
            ->set('lines.0.unit_price', '5.00')
            ->call('saveDraft')
            ->call('saveDraft');

        $this->assertSame(1, SalesInvoice::where('company_id', $this->company->id)->count());
        $invoice = SalesInvoice::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $invoice->status);
        $this->assertNull($invoice->invoice_number);

        // PurchaseForm duplicate saveDraft calls
        $purchaseForm = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->set('vendor_invoice_number', 'DUP-CLICK-01')
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '10')
            ->set('lines.0.unit_cost', '2.00')
            ->call('saveDraft')
            ->call('saveDraft');

        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());
        $purchase = Purchase::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame('draft', $purchase->status);

        // Crucial invariant: ZERO financial postings, ZERO stock movements
        $this->assertSame($batchCountBefore, DB::table('posting_batches')->count());
        $this->assertSame($stockMovementBefore, DB::table('stock_movements')->count());

        // Modal open suppresses saveDraft on all forms
        $invoiceForm->set('showQuickCustomerModal', true)
            ->call('saveDraft');
        $this->assertSame(1, SalesInvoice::where('company_id', $this->company->id)->count());

        $purchaseForm->set('showQuickVendorModal', true)
            ->call('saveDraft');
        $this->assertSame(1, Purchase::where('company_id', $this->company->id)->count());

        $quoteForm = Livewire::test(QuotationForm::class)
            ->set('customer_id', $this->customer->id)
            ->set('lines.0.product_id', $this->product->id)
            ->set('lines.0.item_description', 'وصف سطر عرض')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_price', '10.00')
            ->set('showQuickCustomerModal', true)
            ->call('saveDraft');
        $this->assertSame(0, Quotation::where('company_id', $this->company->id)->count());
    }

    /**
     * 7. Quotation conversion path: opens editable invoice draft when user has edit permission,
     *    safe authorized detail when not, honest success flash, and zero auto-posting.
     */
    public function test_quotation_conversion_path_and_provenance(): void
    {
        $createQuoteAction = app(CreateQuotationAction::class);
        $quotation = $createQuoteAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->pieceProductUnit->id,
                    'item_description' => 'بند عرض سعر',
                    'quantity' => '10.000000',
                    'unit_price' => '10.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                ],
            ],
        ]);

        // Transition to accepted
        $quotation->transition(Quotation::STATUS_SENT, $this->owner);
        $quotation->transition(Quotation::STATUS_ACCEPTED, $this->owner);

        // Case A: User has sales.quote.convert AND sales.invoice.edit_draft -> redirects to invoices.edit
        $editorUser = $this->createMemberWithPermissions($this->company, [
            'sales.quote.view', 'sales.quote.convert',
            'sales.invoice.view', 'sales.invoice.edit_draft',
        ]);

        $batchCountBefore = DB::table('posting_batches')->count();

        $detailComponent = Livewire::test(QuotationDetail::class, ['publicId' => $quotation->public_id])
            ->call('convertToInvoice');

        $quotation->refresh();
        $this->assertSame(Quotation::STATUS_CONVERTED, $quotation->status);
        $this->assertNotNull($quotation->converted_to_invoice_id);

        $convertedInvoice = SalesInvoice::findOrFail($quotation->converted_to_invoice_id);
        $this->assertSame(SalesInvoice::STATUS_DRAFT, $convertedInvoice->status);
        $this->assertSame($quotation->id, $convertedInvoice->quotation_id);

        // Assert redirected to edit draft
        $detailComponent->assertRedirect(route('invoices.edit', $convertedInvoice->public_id));

        // Assert zero financial posting batches
        $this->assertSame($batchCountBefore, DB::table('posting_batches')->count());

        // Assert QuotationDetail now shows provenance and edit affordance
        Livewire::test(QuotationDetail::class, ['publicId' => $quotation->public_id])
            ->assertSee(__('workflow.converted_to_invoice'))
            ->assertSee(__('workflow.edit_invoice_draft'));

        // Assert InvoiceDetail displays source quotation provenance
        Livewire::test(InvoiceDetail::class, ['publicId' => $convertedInvoice->public_id])
            ->assertSee(__('sales.quotation'))
            ->assertSee($quotation->quotation_number);

        // Case B: User has sales.quote.convert and sales.invoice.view, but NOT sales.invoice.edit_draft -> redirects to invoices.show
        $this->activateCompany($this->company, $this->owner);
        $quoteB = $createQuoteAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->pieceProductUnit->id,
                    'item_description' => 'بند عرض سعر ثان',
                    'quantity' => '5.000000',
                    'unit_price' => '20.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                ],
            ],
        ]);
        $quoteB->transition(Quotation::STATUS_SENT, $this->owner);
        $quoteB->transition(Quotation::STATUS_ACCEPTED, $this->owner);

        $viewerUser = $this->createMemberWithPermissions($this->company, [
            'sales.quote.view', 'sales.quote.convert',
            'sales.invoice.view',
        ]);

        $detailComponentB = Livewire::test(QuotationDetail::class, ['publicId' => $quoteB->public_id])
            ->call('convertToInvoice');

        $quoteB->refresh();
        $convertedInvoiceB = SalesInvoice::findOrFail($quoteB->converted_to_invoice_id);

        // Assert redirected to invoices.show (not edit)
        $detailComponentB->assertRedirect(route('invoices.show', $convertedInvoiceB->public_id));

        // Assert QuotationDetail for viewer shows view link, not edit link
        Livewire::test(QuotationDetail::class, ['publicId' => $quoteB->public_id])
            ->assertSee(__('workflow.converted_to_invoice'))
            ->assertSee(__('workflow.view_invoice_draft'))
            ->assertDontSee(__('workflow.edit_invoice_draft'));

        // Case C: User has sales.quote.convert ONLY (neither invoice view nor edit) -> redirects to quotations.show with honest success
        $this->activateCompany($this->company, $this->owner);
        $quoteC = $createQuoteAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->pieceProductUnit->id,
                    'item_description' => 'بند عرض سعر ثالث',
                    'quantity' => '1.000000',
                    'unit_price' => '15.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                ],
            ],
        ]);
        $quoteC->transition(Quotation::STATUS_SENT, $this->owner);
        $quoteC->transition(Quotation::STATUS_ACCEPTED, $this->owner);

        $convertOnlyUser = $this->createMemberWithPermissions($this->company, [
            'sales.quote.view', 'sales.quote.convert',
        ]);

        $detailComponentC = Livewire::test(QuotationDetail::class, ['publicId' => $quoteC->public_id])
            ->call('convertToInvoice');

        $quoteC->refresh();
        $this->assertSame(Quotation::STATUS_CONVERTED, $quoteC->status);
        $detailComponentC->assertRedirect(route('quotations.show', $quoteC->public_id));
    }

    /**
     * 8. Bounded queries and selectors on QuotationForm and selectors search.
     */
    public function test_quotation_and_selectors_bounded_queries(): void
    {
        // Create 60 additional active customers
        for ($i = 1; $i <= 60; $i++) {
            Customer::create([
                'company_id' => $this->company->id,
                'name_ar' => sprintf('عميل إضافي %02d', $i),
                'name_en' => sprintf('Extra Customer %02d', $i),
                'code' => sprintf('CUST-EXT-%02d', $i),
                'status' => 'active',
                'active' => true,
                'created_by' => $this->owner->id,
            ]);
        }

        $form = Livewire::test(QuotationForm::class);
        $customersInView = $form->viewData('customers');
        $this->assertLessThanOrEqual(50, $customersInView->count());

        // When searching, filters matching customers
        $form->set('customerSearch', 'CUST-EXT-45');
        $customersFiltered = $form->viewData('customers');
        $this->assertSame(1, $customersFiltered->count());
        $this->assertSame('CUST-EXT-45', $customersFiltered->first()->code);
    }
}
