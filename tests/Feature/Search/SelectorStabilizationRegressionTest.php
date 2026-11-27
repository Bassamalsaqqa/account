<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Livewire\Pages\Inventory\StockTransferForm;
use App\Livewire\Pages\Purchasing\PaymentForm as VendorPaymentForm;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Sales\InvoiceForm;
use App\Livewire\Pages\Sales\QuotationForm;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyLanguage;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\SalesInvoiceLine;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Sales\SalesActorGuard;
use App\Services\Search\ArabicSearchNormalizer;
use App\Services\Search\SearchableSelectorService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class SelectorStabilizationRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private Unit $unit;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = Company::factory()->create([
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);
        $this->owner->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => true]);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->owner->assignRole('Owner');

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        CompanyCurrency::create([
            'company_id' => $this->company->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'is_base' => true,
            'enabled' => true,
        ]);

        CompanyLanguage::create([
            'company_id' => $this->company->id,
            'locale' => 'ar',
            'enabled' => true,
        ]);

        CompanyLanguage::create([
            'company_id' => $this->company->id,
            'locale' => 'en',
            'enabled' => true,
        ]);

        $this->warehouse = Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-MAIN',
            'name_ar' => 'المستودع الرئيسي',
            'name_en' => 'Main Warehouse',
            'active' => true,
            'is_default' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->unit = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'PCS',
            'name_ar' => 'حبة',
            'name_en' => 'Piece',
            'allows_fraction' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    public function test_arabic_normalization_matches_hamza_ya_taa_marbuta_diacritics_and_ranks_exact_first(): void
    {
        $vendorBare = Vendor::create([
            'company_id' => $this->company->id,
            'code' => 'V-01',
            'name_ar' => 'اسعد الصباح',
            'name_en' => 'Asaad Morning',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $vendorHamza = Vendor::create([
            'company_id' => $this->company->id,
            'code' => 'V-02',
            'name_ar' => 'أسعد التجاري',
            'name_en' => 'Asaad Trading',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $customerTaaDiacritics = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-01',
            'name_ar' => 'مَكْتَبَةُ أَسْعَدْ',
            'name_en' => 'Asaad Bookstore',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $customerHaaExact = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-02',
            'name_ar' => 'مكتبه النور',
            'name_en' => 'Nour Library',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $customerMaqsura = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-03',
            'name_ar' => 'سلمى علي للقرطاسية',
            'name_en' => 'Salma Stationery',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $prodStockExact = $this->createProduct('P-EX', 'زيت أسعد', 'ZZZ Exact Second Alpha', Product::TYPE_STOCK, true, '15.00', '22.00');
        $prodStockPrefix = $this->createProduct('P-PR', 'زيت أسعد المطور', 'AAA Prefix First Alpha', Product::TYPE_STOCK, true, '15.50', '23.00');
        $prodStockFolded = $this->createProduct('P-FD', 'زيت اسعد الصافي', 'MMM Folded Mid Alpha', Product::TYPE_STOCK, true, '16.00', '24.00');

        Warehouse::create([
            'company_id' => $this->company->id,
            'code' => 'WH-ALF',
            'name_ar' => 'مخزن إمداد المركزي',
            'name_en' => 'Imdad Central',
            'active' => true,
            'is_default' => false,
            'created_by' => $this->owner->id,
        ]);

        $svc = app(SearchableSelectorService::class);

        // Searching with hamza 'أسعد' finds BOTH 'اسعد الصباح' and 'أسعد التجاري', ranking exact prefix first
        $vendorRes = $svc->search($this->company, $this->owner, 'vendor', 'أسعد', null, 25, ['context' => 'purchasing', 'locale' => 'ar']);
        $vendorIds = array_column($vendorRes['items'], 'id');
        $this->assertSame([(string) $vendorHamza->id, (string) $vendorBare->id], $vendorIds);

        // Searching bare 'اسعد' flips exact match priority cleanly
        $vendorBareRes = $svc->search($this->company, $this->owner, 'vendor', 'اسعد', null, 25, ['context' => 'purchasing', 'locale' => 'ar']);
        $this->assertSame([(string) $vendorBare->id, (string) $vendorHamza->id], array_column($vendorBareRes['items'], 'id'));

        // Taa marbuta + diacritics vs Haa equivalence and exact ranking
        $custHaaRes = $svc->search($this->company, $this->owner, 'customer', 'مكتبه', null, 25, ['context' => 'sales', 'locale' => 'ar']);
        $this->assertSame([(string) $customerHaaExact->id, (string) $customerTaaDiacritics->id], array_column($custHaaRes['items'], 'id'));

        $custTaaRes = $svc->search($this->company, $this->owner, 'customer', 'مَكْتَبَةُ', null, 25, ['context' => 'sales', 'locale' => 'ar']);
        $this->assertSame([(string) $customerTaaDiacritics->id, (string) $customerHaaExact->id], array_column($custTaaRes['items'], 'id'));

        // Alef maqsura / yaa + tatweel equivalence
        $maqsuraRes = $svc->search($this->company, $this->owner, 'customer', 'سلمي على', null, 25, ['context' => 'sales', 'locale' => 'ar']);
        $this->assertSame([(string) $customerMaqsura->id], array_column($maqsuraRes['items'], 'id'));

        // Product Arabic folded search + exact match strictly ahead of prefix and folded variants (even under EN locale tie-break)
        $prodRes = $svc->search($this->company, $this->owner, 'product', 'زيت أسعد', null, 25, ['context' => 'purchasing', 'locale' => 'en']);
        $this->assertSame([(string) $prodStockExact->id, (string) $prodStockPrefix->id, (string) $prodStockFolded->id], array_column($prodRes['items'], 'id'));

        // Warehouse folded search with bare alef finds 'إمداد'
        $whRes = $svc->search($this->company, $this->owner, 'warehouse', 'امداد', null, 25, ['context' => 'inventory', 'locale' => 'ar']);
        $this->assertCount(1, $whRes['items']);
        $this->assertStringContainsString('إمداد', $whRes['items'][0]['label']);
    }

    public function test_business_name_ar_en_search_for_customers_and_vendors_is_tenant_isolated(): void
    {
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'code' => '366852',
            'name_ar' => 'سعيد خليل',
            'business_name_ar' => 'شركة أفق الإمداد',
            'business_name_en' => 'Horizon Supply Co',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-BIZ',
            'name_ar' => 'ناصر أحمد',
            'business_name_ar' => 'مؤسسة الياسمين',
            'business_name_en' => 'Jasmine Enterprise',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        // Foreign company with matching business name must never leak
        $otherOwner = User::factory()->create();
        $otherCompany = Company::factory()->create(['base_currency_code' => 'ILS', 'status' => 'active']);
        $otherOwner->companies()->attach($otherCompany->id, ['status' => 'active', 'is_owner' => true]);
        app(CompanyContext::class)->setCompany($otherCompany, $otherOwner);
        Vendor::create([
            'company_id' => $otherCompany->id,
            'code' => 'F-VND',
            'name_ar' => 'مورد غريب',
            'business_name_ar' => 'شركة افق الامداد الخاصة',
            'business_name_en' => 'Horizon Foreign Ltd',
            'status' => 'active',
            'created_by' => $otherOwner->id,
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        setPermissionsTeamId($this->company->id);
        $this->actingAs($this->owner);

        $svc = app(SearchableSelectorService::class);

        $vAr = $svc->search($this->company, $this->owner, 'vendor', 'افق الامداد', null, 25, ['context' => 'purchasing', 'locale' => 'ar']);
        $this->assertSame([(string) $vendor->id], array_column($vAr['items'], 'id'));
        $this->assertStringContainsString('شركة أفق الإمداد', (string) $vAr['items'][0]['sublabel']);

        $vEn = $svc->search($this->company, $this->owner, 'vendor', 'Horizon Supply', null, 25, ['context' => 'purchasing', 'locale' => 'en']);
        $this->assertSame([(string) $vendor->id], array_column($vEn['items'], 'id'));

        $cEn = $svc->search($this->company, $this->owner, 'customer', 'Jasmine Enterprise', null, 25, ['context' => 'sales', 'locale' => 'en']);
        $this->assertSame([(string) $customer->id], array_column($cEn['items'], 'id'));
        $this->assertStringContainsString('Jasmine Enterprise', (string) $cEn['items'][0]['sublabel']);
    }

    public function test_code_barcode_numeric_literal_and_like_wildcards_are_safely_escaped(): void
    {
        $literalPct = $this->createProduct('SKU-100%', 'خصم 100% ممتاز', 'Promo 100%', Product::TYPE_STOCK, true, '10.00', '15.00');
        $otherProd = $this->createProduct('SKU-1000', 'منتج عادي', 'Plain Product', Product::TYPE_STOCK, true, '10.00', '15.00');
        $underscoreProd = $this->createProduct('ITEM_50', 'قطعة _50', 'Part _50', Product::TYPE_STOCK, true, '5.00', '8.00');

        $pu = ProductUnit::where('product_id', $literalPct->id)->firstOrFail();
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $literalPct->id,
            'unit_id' => $this->unit->id,
            'barcode' => '36685299',
        ]);

        $svc = app(SearchableSelectorService::class);

        // '%' literal must not match 'SKU-1000'
        $pctRes = $svc->search($this->company, $this->owner, 'product', '100%', null, 25, ['context' => 'sales', 'locale' => 'ar']);
        $this->assertSame([(string) $literalPct->id], array_column($pctRes['items'], 'id'));
        $this->assertNotContains((string) $otherProd->id, array_column($pctRes['items'], 'id'));

        // '_' literal must match ITEM_50 only
        $underRes = $svc->search($this->company, $this->owner, 'product', '_50', null, 25, ['context' => 'sales', 'locale' => 'ar']);
        $this->assertSame([(string) $underscoreProd->id], array_column($underRes['items'], 'id'));

        // Exact barcode preserves matched_product_unit_id metadata
        $barRes = $svc->search($this->company, $this->owner, 'product', '36685299', null, 25, ['context' => 'purchasing', 'locale' => 'ar']);
        $this->assertSame([(string) $literalPct->id], array_column($barRes['items'], 'id'));
        $this->assertSame($pu->id, $barRes['items'][0]['meta']['matched_product_unit_id'] ?? null);
        $this->assertSame('100%', ArabicSearchNormalizer::fold('100%'));
    }

    public function test_missing_cost_permission_fails_closed_without_500_and_preserves_workflow_stock_eligibility(): void
    {
        $stockProd = $this->createProduct('STK-1', 'مادة مخزنية', 'Stock Item', Product::TYPE_STOCK, true, '45.00', '70.00');
        $serviceProd = $this->createProduct('SRV-1', 'خدمة توصيل', 'Delivery Service', Product::TYPE_SERVICE, false, '5.00', '20.00');

        $svc = app(SearchableSelectorService::class);

        // Stock eligibility: purchasing & inventory see stock-only; sales sees both
        $purch = $svc->search($this->company, $this->owner, 'product', '', null, 25, ['context' => 'purchasing', 'locale' => 'ar']);
        $this->assertSame([(string) $stockProd->id], array_column($purch['items'], 'id'));
        $this->assertSame('45.000000', $purch['items'][0]['meta']['default_purchase_cost_base'] ?? null);

        $sales = $svc->search($this->company, $this->owner, 'product', '', null, 25, ['context' => 'sales', 'locale' => 'ar']);
        $this->assertCount(2, $sales['items']);
        $this->assertArrayNotHasKey('default_purchase_cost_base', $sales['items'][0]['meta']);
        $this->assertContains((string) $serviceProd->id, array_column($sales['items'], 'id'));

        // Delete cost permission rows to reproduce PermissionDoesNotExist edge case
        Permission::whereIn('name', ['purchasing.cost.view', 'inventory.cost.view'])->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');

        $purchNoPermRow = $svc->search($this->company, $this->owner, 'product', 'مخزنية', $stockProd->id, 25, ['context' => 'purchasing', 'locale' => 'ar']);
        $this->assertSame([(string) $stockProd->id], array_column($purchNoPermRow['items'], 'id'));
        $this->assertArrayNotHasKey('default_purchase_cost_base', $purchNoPermRow['items'][0]['meta']);

        $invNoPermRow = $svc->search($this->company, $this->owner, 'product', '', $stockProd->id, 25, ['context' => 'inventory', 'locale' => 'ar']);
        $this->assertSame([(string) $stockProd->id], array_column($invNoPermRow['items'], 'id'));
        $this->assertArrayNotHasKey('default_purchase_cost_base', $invNoPermRow['items'][0]['meta']);
        $this->assertArrayNotHasKey('default_purchase_cost_base', $invNoPermRow['selected_item']['meta'] ?? []);
    }

    public function test_top_quick_add_combobox_works_on_purchase_invoice_quotation_and_avoids_wrong_row_after_delete(): void
    {
        $stockA = $this->createProduct('PR-A', 'معجون أسنان أ', 'Toothpaste A', Product::TYPE_STOCK, true, '10.00', '15.00');
        $stockB = $this->createProduct('PR-B', 'فرشاة أسنان ب', 'Brush B', Product::TYPE_STOCK, true, '12.00', '18.00');
        $stockC = $this->createProduct('PR-C', 'غسول فم ج', 'Rinse C', Product::TYPE_STOCK, true, '20.00', '30.00');
        $serviceProd = $this->createProduct('SRV-Q', 'خدمة تركيب', 'Install Service', Product::TYPE_SERVICE, false, '0.00', '50.00');

        // 1. PurchaseForm: top box uses searchable-combobox quickAddProduct (never wire:model.live="productSearch")
        $purchase = Livewire::test(PurchaseForm::class);
        $purchaseHtml = htmlspecialchars_decode($purchase->html(false), ENT_QUOTES);
        $this->assertStringContainsString('id="purchase-product-search"', $purchaseHtml);
        $this->assertStringContainsString("onSelect: 'quickAddProduct'", $purchaseHtml);
        $this->assertStringContainsString('resetAfterSelect: true', $purchaseHtml);
        $this->assertStringNotContainsString('wire:model.live.debounce.300ms="productSearch"', $purchaseHtml);

        $firstRowKey = $purchase->get('lines.0.row_key');
        $purchase->call('quickAddProduct', $stockA->id)
            ->assertReturned(true)
            ->assertSet('lines.0.product_id', $stockA->id)
            ->assertSet('lines.0.row_key', $firstRowKey);

        // Quick-adding a second product appends cleanly; deleting first row does not overwrite survivor
        $purchase->call('quickAddProduct', $stockB->id)->assertReturned(true);
        $secondRowKey = $purchase->get('lines.1.row_key');
        $purchase->call('removeLine', $firstRowKey);
        $purchase->call('quickAddProduct', $stockC->id)->assertReturned(true);

        $purchase->assertCount('lines', 2)
            ->assertSet('lines.0.product_id', $stockB->id)
            ->assertSet('lines.0.row_key', $secondRowKey)
            ->assertSet('lines.1.product_id', $stockC->id);

        // PurchaseForm quick-add enforces stock-only eligibility
        $purchase->call('quickAddProduct', $serviceProd->id)->assertReturned(false);

        // Persisted free-text draft line (non-empty public_id) must NOT be treated as empty or overwritten
        $persistedForm = Livewire::test(PurchaseForm::class);
        $persistedForm->set('lines.0.public_id', '01JTESTPERSISTED0000000001');
        $persistedForm->call('quickAddProduct', $stockA->id)->assertReturned(true)
            ->assertCount('lines', 2)
            ->assertSet('lines.0.public_id', '01JTESTPERSISTED0000000001')
            ->assertSet('lines.0.product_id', null)
            ->assertSet('lines.1.product_id', $stockA->id);

        // 2. InvoiceForm & QuotationForm: top quick-add works identically & permits service items
        foreach ([InvoiceForm::class => 'invoice-product-search', QuotationForm::class => 'quotation-product-search'] as $component => $domId) {
            $form = Livewire::test($component);
            $html = htmlspecialchars_decode($form->html(false), ENT_QUOTES);
            $this->assertStringContainsString("id=\"{$domId}\"", $html);
            $this->assertStringContainsString("onSelect: 'quickAddProduct'", $html);
            $this->assertStringNotContainsString('wire:model.live.debounce.300ms="productSearch"', $html);

            $row0Key = $form->get('lines.0.row_key');
            $form->call('quickAddProduct', $stockA->id)->assertReturned(true)->assertSet('lines.0.row_key', $row0Key);
            $form->call('quickAddProduct', $serviceProd->id)->assertReturned(true)->assertSet('lines.1.product_id', $serviceProd->id);
            $form->call('removeLine', $row0Key);
            $form->call('quickAddProduct', $stockB->id)->assertReturned(true)
                ->assertCount('lines', 2)
                ->assertSet('lines.0.product_id', $serviceProd->id)
                ->assertSet('lines.1.product_id', $stockB->id);
        }
        // Foreign currency with empty exchange_rate returns false + field error instead of 500 MathException
        $fxInvoice = Livewire::test(InvoiceForm::class);
        $fxInvoice->set('currency_code', 'USD')
            ->set('exchange_rate', '')
            ->call('quickAddProduct', $stockA->id)
            ->assertReturned(false)
            ->assertHasErrors(['quickProduct']);
    }

    public function test_read_only_render_and_selector_skip_for_update_locks_while_enforcing_live_revocation(): void
    {
        $limitedUser = User::factory()->create(['locale' => 'ar']);
        $limitedUser->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $limitedRole = Role::create(['name' => 'SelectorTester', 'company_id' => $this->company->id]);
        $limitedRole->givePermissionTo([
            'purchasing.purchase.create',
            'purchasing.cost.view',
            'vendors.view',
            'sales.invoice.create',
            'customers.view',
            'sales.quote.create',
            'money.vendor_payment.create',
            'inventory.stock.transfer',
            'inventory.stock.adjust',
            'inventory.cost.view',
            'inventory.stock.view',
        ]);
        $limitedUser->assignRole($limitedRole);
        app(CompanyContext::class)->setCompany($this->company, $limitedUser);
        $this->actingAs($limitedUser);

        foreach ([
            PurchaseForm::class,
            InvoiceForm::class,
            QuotationForm::class,
            VendorPaymentForm::class,
            StockTransferForm::class,
            StockAdjustmentForm::class,
            OpeningStockForm::class,
        ] as $component) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $test = Livewire::test($component);
            $test->call('searchSelector', 'product', '', null, ['limit' => 5]);
            $queries = DB::getQueryLog();
            DB::disableQueryLog();

            $forUpdateQueries = array_filter($queries, static fn (array $q): bool => stripos($q['query'], 'for update') !== false);
            $this->assertSame([], array_values($forUpdateQueries), "{$component} read/selector should not execute FOR UPDATE queries.");
        }

        // Revoking membership immediately takes effect on read/selector calls (no permission/membership cache)
        $purchaseMembershipLive = Livewire::test(PurchaseForm::class);
        $purchasePermLive = Livewire::test(PurchaseForm::class);
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $limitedUser->id)->update(['status' => 'inactive']);
        $purchaseMembershipLive->call('searchSelector', 'vendor', '', null, ['limit' => 5])->assertForbidden();

        // Restoring membership then revoking role permission also immediately blocks without cache
        CompanyUser::where('company_id', $this->company->id)->where('user_id', $limitedUser->id)->update(['status' => 'active']);
        $limitedRole->revokePermissionTo('purchasing.purchase.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $purchasePermLive->call('searchSelector', 'vendor', '', null, ['limit' => 5])->assertForbidden();

        // Canonical write lockAndAuthorize continues to require active transaction + FOR UPDATE
        $this->expectException(AuthorizationException::class);
        app(SalesActorGuard::class)->lockAndAuthorize($this->company->id, $this->owner, 'purchasing.purchase.create');
    }

    public function test_direct_searchable_selector_service_respects_permission_and_cost_revocation_without_stale_cache(): void
    {
        $this->createProduct('DIRECT-01', 'منتج مباشر', 'Direct Product', Product::TYPE_STOCK, true, '42.50', '60.00');

        $user = User::factory()->create(['locale' => 'en']);
        $user->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);

        setPermissionsTeamId($this->company->id);
        /** @var Role $role */
        $role = Role::firstOrCreate([
            'name' => 'Direct Selector Stale Cache Tester',
            'guard_name' => 'web',
            'company_id' => $this->company->id,
        ]);
        $role->syncPermissions([
            Permission::findByName('purchasing.purchase.create', 'web'),
            Permission::findByName('purchasing.cost.view', 'web'),
        ]);
        $user->assignRole($role);

        $service = app(SearchableSelectorService::class);
        $withCost = $service->search($this->company, $user, 'product', 'DIRECT-01', null, 25, ['context' => 'purchasing']);
        $this->assertCount(1, $withCost['items']);
        $this->assertSame('42.500000', $withCost['items'][0]['meta']['default_purchase_cost_base'] ?? null);

        // Revoke cost permission WITHOUT clearing $user->roles relation in caller code.
        $role->revokePermissionTo('purchasing.cost.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $redacted = $service->search($this->company, $user, 'product', 'DIRECT-01', null, 25, ['context' => 'purchasing']);
        $this->assertCount(1, $redacted['items']);
        $this->assertArrayNotHasKey('default_purchase_cost_base', $redacted['items'][0]['meta']);

        // Revoke selector access permission WITHOUT clearing $user->roles relation in caller code.
        $role->revokePermissionTo('purchasing.purchase.create');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $service->search($this->company, $user, 'product', 'DIRECT-01', null, 25, ['context' => 'purchasing']);
    }

    public function test_direct_selector_requires_fresh_membership_and_company_and_restores_team_context(): void
    {
        $this->createProduct('SCOPE-01', 'منتج', 'Scoped Product', Product::TYPE_STOCK, true, '42.50', '60.00');
        $service = app(SearchableSelectorService::class);
        $previousTeam = getPermissionsTeamId();
        setPermissionsTeamId(null);
        try {
            $result = $service->search($this->company, $this->owner, 'product', 'SCOPE-01', null, 25, ['context' => 'purchasing']);
            $this->assertCount(1, $result['items']);
            $this->assertNull(getPermissionsTeamId());
            $service->authorize($this->company, $this->owner, 'product', ['context' => 'purchasing']);
            $this->assertNull(getPermissionsTeamId());

            CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'inactive']);
            foreach (['search', 'authorize'] as $method) {
                try {
                    $method === 'search'
                        ? $service->search($this->company, $this->owner, 'product', 'SCOPE-01', null, 25, ['context' => 'purchasing'])
                        : $service->authorize($this->company, $this->owner, 'product', ['context' => 'purchasing']);
                    $this->fail('Revoked membership must be denied.');
                } catch (AuthorizationException) {
                    $this->assertNull(getPermissionsTeamId());
                }
            }
            CompanyUser::where('company_id', $this->company->id)->where('user_id', $this->owner->id)->update(['status' => 'active']);
            Company::whereKey($this->company->id)->update(['status' => 'inactive']);
            try {
                $service->search($this->company, $this->owner, 'product', 'SCOPE-01', null, 25, ['context' => 'purchasing']);
                $this->fail('Inactive company must be denied despite stale model status.');
            } catch (AuthorizationException) {
                $this->assertNull(getPermissionsTeamId());
            }
        } finally {
            setPermissionsTeamId($previousTeam);
        }
    }

    public function test_quick_add_product_preserves_unsaved_free_text_row_on_all_three_forms(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $product = $this->createProduct('QA-FREE-1', 'صنف مضاف', 'Quick Item', Product::TYPE_STOCK, true, '25.00', '40.00');
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'code' => 'V-FREE',
            'name_ar' => 'مورد اختبار',
            'name_en' => 'Test Vendor',
            'status' => 'active',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-FREE',
            'name_ar' => 'عميل اختبار',
            'name_en' => 'Test Customer',
            'status' => 'active',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $purchase = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $vendor->id)
            ->set('lines.0.item_description', 'شحن يدوي غير محفوظ')
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '15.00')
            ->call('quickAddProduct', $product->id);

        $purchase->assertHasNoErrors();
        $purchase->assertReturned(true);
        /** @var array<int, array<string, mixed>> $purchaseLines */
        $purchaseLines = $purchase->get('lines');
        $this->assertCount(2, $purchaseLines);
        $this->assertNull($purchaseLines[0]['product_id'] ?: null);
        $this->assertSame('شحن يدوي غير محفوظ', $purchaseLines[0]['item_description']);
        $this->assertSame('15.00', (string) $purchaseLines[0]['unit_cost']);
        $this->assertSame($product->id, (int) $purchaseLines[1]['product_id']);

        $invoice = Livewire::test(InvoiceForm::class)
            ->set('customer_id', $customer->id)
            ->set('lines.0.item_description', 'تركيب يدوي غير محفوظ')
            ->set('lines.0.quantity', '3')
            ->set('lines.0.unit_price', '55.00')
            ->call('quickAddProduct', $product->id);

        $invoice->assertHasNoErrors();
        $invoice->assertReturned(true);
        /** @var array<int, array<string, mixed>> $invoiceLines */
        $invoiceLines = $invoice->get('lines');
        $this->assertCount(2, $invoiceLines);
        $this->assertNull($invoiceLines[0]['product_id'] ?: null);
        $this->assertSame('تركيب يدوي غير محفوظ', $invoiceLines[0]['item_description']);
        $this->assertSame('55.00', (string) $invoiceLines[0]['unit_price']);
        $this->assertSame($product->id, (int) $invoiceLines[1]['product_id']);

        $quotation = Livewire::test(QuotationForm::class)
            ->set('customer_id', $customer->id)
            ->set('lines.0.item_description', 'بند عرض سعر يدوي')
            ->set('lines.0.quantity', '4')
            ->set('lines.0.unit_price', '75.00')
            ->call('quickAddProduct', $product->id);

        $quotation->assertHasNoErrors();
        $quotation->assertReturned(true);
        /** @var array<int, array<string, mixed>> $quotationLines */
        $quotationLines = $quotation->get('lines');
        $this->assertCount(2, $quotationLines);
        $this->assertNull($quotationLines[0]['product_id'] ?: null);
        $this->assertSame('بند عرض سعر يدوي', $quotationLines[0]['item_description']);
        $this->assertSame('75.00', (string) $quotationLines[0]['unit_price']);
        $this->assertSame($product->id, (int) $quotationLines[1]['product_id']);
    }

    public function test_barcode_lookup_and_quick_add_preserve_non_base_product_unit_price_and_cost(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $product = $this->createProduct('SKU-MULTI-U', 'زيت زيتون', 'Olive Oil', Product::TYPE_STOCK, true, '10.00', '15.00');
        $boxUnit = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'BOX12',
            'name_ar' => 'كرتونة 12',
            'name_en' => 'Box 12',
            'symbol_ar' => 'كرتونة',
            'symbol_en' => 'BOX',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);
        $boxProductUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'conversion_to_base' => '12',
            'is_base' => false,
            'is_default_purchase' => false,
            'is_default_sale' => false,
            'default_purchase_price_base' => '95.00',
            'default_sale_price_base' => '140.00',
            'active' => true,
        ]);
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $boxUnit->id,
            'barcode' => '6259990001122',
            'type' => 'ean13',
            'is_primary' => false,
        ]);

        /** @var SearchableSelectorService $selector */
        $selector = app(SearchableSelectorService::class);
        $search = $selector->search($this->company, $this->owner, 'product', '6259990001122', null, 10, ['context' => 'sales']);
        $this->assertCount(1, $search['items']);
        $this->assertSame((string) $product->id, $search['items'][0]['id']);
        $this->assertSame($boxProductUnit->id, (int) $search['items'][0]['meta']['matched_product_unit_id']);

        $purchase = Livewire::test(PurchaseForm::class)
            ->call('quickAddProduct', $product->id, $boxProductUnit->id);
        $purchase->assertHasNoErrors();
        $purchase->assertReturned(true);
        /** @var array<int, array<string, mixed>> $pLines */
        $pLines = $purchase->get('lines');
        $this->assertSame($boxProductUnit->id, (int) $pLines[0]['product_unit_id']);
        $this->assertTrue(BigDecimal::of((string) $pLines[0]['unit_cost'])->isEqualTo('95.00'));

        $invoice = Livewire::test(InvoiceForm::class)
            ->call('quickAddProduct', $product->id, $boxProductUnit->id);
        $invoice->assertHasNoErrors();
        $invoice->assertReturned(true);
        /** @var array<int, array<string, mixed>> $iLines */
        $iLines = $invoice->get('lines');
        $this->assertSame($boxProductUnit->id, (int) $iLines[0]['product_unit_id']);
        $this->assertTrue(BigDecimal::of((string) $iLines[0]['unit_price'])->isEqualTo('140.00'));

        $quotation = Livewire::test(QuotationForm::class)
            ->call('quickAddProduct', $product->id, $boxProductUnit->id);
        $quotation->assertHasNoErrors();
        $quotation->assertReturned(true);
        /** @var array<int, array<string, mixed>> $qLines */
        $qLines = $quotation->get('lines');
        $this->assertSame($boxProductUnit->id, (int) $qLines[0]['product_unit_id']);
        $this->assertTrue(BigDecimal::of((string) $qLines[0]['unit_price'])->isEqualTo('140.00'));
    }

    public function test_foreign_company_product_is_denied_on_search_and_quick_add_across_all_three_forms(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $foreignCompany = Company::factory()->create(['base_currency_code' => $this->company->base_currency_code]);
        [$foreignProduct, $foreignUnitRow] = CompanyScope::executeWithoutScope(function () use ($foreignCompany): array {
            $foreignUnit = Unit::create([
                'company_id' => $foreignCompany->id,
                'code' => 'FPCS',
                'name_ar' => 'قطعة أجنبية',
                'name_en' => 'Foreign Piece',
                'symbol_ar' => 'قطعة',
                'symbol_en' => 'PCS',
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]);
            $product = Product::create([
                'company_id' => $foreignCompany->id,
                'sku' => 'FOREIGN-SECRET-SKU',
                'name_ar' => 'صنف شركة أخرى',
                'name_en' => 'Foreign Company Product',
                'base_unit_id' => $foreignUnit->id,
                'product_type' => Product::TYPE_STOCK,
                'track_stock' => true,
                'default_purchase_cost_base' => '10.00',
                'default_sale_price_base' => '20.00',
                'active' => true,
                'created_by' => $this->owner->id,
            ]);
            $unitRow = ProductUnit::create([
                'company_id' => $foreignCompany->id,
                'product_id' => $product->id,
                'unit_id' => $foreignUnit->id,
                'conversion_to_base' => '1',
                'is_base' => true,
                'is_default_sale' => true,
                'is_default_purchase' => true,
                'active' => true,
            ]);

            return [$product, $unitRow];
        });

        /** @var SearchableSelectorService $selector */
        $selector = app(SearchableSelectorService::class);
        $search = $selector->search($this->company, $this->owner, 'product', 'FOREIGN-SECRET-SKU', $foreignProduct->id, 10, ['context' => 'sales']);
        $this->assertSame([], $search['items']);

        foreach ([PurchaseForm::class, InvoiceForm::class, QuotationForm::class] as $componentClass) {
            $component = Livewire::test($componentClass)
                ->call('quickAddProduct', $foreignProduct->id, $foreignUnitRow->id);

            $component->assertReturned(false);
            $component->assertHasErrors(['quickProduct']);
            /** @var array<int, array<string, mixed>> $lines */
            $lines = $component->get('lines');
            $this->assertCount(1, $lines);
            $this->assertNull($lines[0]['product_id'] ?: null);
        }
    }

    public function test_posted_invoice_edit_refuses_quick_add_and_purchase_missing_fx_reports_inline_quick_product_error(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $existingProduct = $this->createProduct('INV-ORIG', 'صنف الفاتورة الأصلي', 'Original Line', 'service', false, '10.00', '100.00');
        $extraProduct = $this->createProduct('INV-NEW', 'صنف إضافي مرفوض', 'Rejected Extra Line', 'service', false, '20.00', '50.00');
        $basePuId = (int) ProductUnit::where('product_id', $existingProduct->id)->value('id');

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-POST-GUARD',
            'name_ar' => 'عميل فاتورة مرحلة',
            'name_en' => 'Posted Guard Customer',
            'status' => 'active',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $invoice = SalesInvoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => $this->company->base_currency_code,
            'exchange_rate' => '1',
            'issue_date' => '2026-11-26',
            'status' => SalesInvoice::STATUS_DRAFT,
            'subtotal_base' => '100.00',
            'discount_total_base' => '0.00',
            'tax_total_base' => '0.00',
            'grand_total_base' => '100.00',
            'cogs_total_base' => '0.00',
            'subtotal_currency' => '100.00',
            'discount_total_currency' => '0.00',
            'tax_total_currency' => '0.00',
            'grand_total_currency' => '100.00',
            'document_locale' => 'ar',
            'created_by' => $this->owner->id,
        ]);
        SalesInvoiceLine::create([
            'company_id' => $this->company->id,
            'sales_invoice_id' => $invoice->id,
            'line_number' => 1,
            'product_id' => $existingProduct->id,
            'product_unit_id' => $basePuId,
            'item_description' => 'صنف الفاتورة الأصلي',
            'quantity' => '1',
            'unit_price' => '100.00',
            'discount_type' => 'none',
            'discount_value' => '0',
            'manual_discount_amount' => '0.00',
            'allocated_document_discount' => '0.00',
            'tax_inclusive' => false,
            'line_subtotal' => '100.00',
            'line_discount' => '0.00',
            'line_tax' => '0.00',
            'line_total' => '100.00',
            'line_subtotal_base' => '100.00',
            'line_discount_base' => '0.00',
            'line_tax_base' => '0.00',
            'line_total_base' => '100.00',
            'cogs_total_base' => '0.00',
            'cogs_unit_base' => '0.00',
            'unit_conversion_ratio' => '1',
            'quantity_base' => '1',
        ]);

        $invoiceComponent = Livewire::test(InvoiceForm::class, ['publicId' => $invoice->public_id]);

        DB::table('sales_invoices')
            ->where('id', $invoice->id)
            ->update(['status' => SalesInvoice::STATUS_POSTED]);

        $invoiceComponent->call('quickAddProduct', $extraProduct->id);
        $invoiceComponent->assertReturned(false);
        $invoiceComponent->assertHasErrors(['quickProduct']);

        /** @var array<int, array<string, mixed>> $invLines */
        $invLines = $invoiceComponent->get('lines');
        $this->assertCount(1, $invLines);
        $this->assertSame($existingProduct->id, (int) $invLines[0]['product_id']);
        $this->assertSame(
            1,
            DB::table('sales_invoice_lines')->where('sales_invoice_id', $invoice->id)->count()
        );

        $purchasableProduct = $this->createProduct('PUR-FX', 'صنف شراء بسعر صرف مطلوب', 'Purchase FX Product', Product::TYPE_STOCK, true, '20.00', '50.00');
        $foreignCurrency = strtoupper((string) $this->company->base_currency_code) === 'USD' ? 'JOD' : 'USD';
        $purchaseComponent = Livewire::test(PurchaseForm::class)
            ->set('currency_code', $foreignCurrency)
            ->set('exchange_rate', '')
            ->set('lines.0.item_description', 'مسودة شراء محفوظة')
            ->call('quickAddProduct', $purchasableProduct->id);

        $purchaseComponent->assertReturned(false);
        $purchaseComponent->assertHasErrors(['exchange_rate', 'quickProduct']);
        $this->assertSame('', (string) $purchaseComponent->get('exchange_rate'));
        /** @var array<int, array<string, mixed>> $purchLines */
        $purchLines = $purchaseComponent->get('lines');
        $this->assertCount(1, $purchLines);
        $this->assertSame('مسودة شراء محفوظة', $purchLines[0]['item_description']);
    }

    public function test_escape_like_and_mariadb_search_treat_backslash_percent_and_underscore_literally(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $this->assertSame('100\\%\\_off\\\\box', ArabicSearchNormalizer::escapeLike('100%_off\\box'));

        $literal = $this->createProduct('SKU-100%_off\\box', 'صندوق خصم 100%_off\\box', 'Box 100%_off\\box', Product::TYPE_STOCK, true, '5.00', '10.00');
        $this->createProduct('SKU-1000XoffZbox', 'صندوق 1000XoffZbox', 'Decoy 1', Product::TYPE_STOCK, true, '5.00', '10.00');
        $this->createProduct('SKU-100X_off_box', 'صندوق 100X_off_box', 'Decoy 2', Product::TYPE_STOCK, true, '5.00', '10.00');

        /** @var SearchableSelectorService $selector */
        $selector = app(SearchableSelectorService::class);
        $result = $selector->search($this->company, $this->owner, 'product', '100%_off\\box', null, 15, ['context' => 'sales']);

        $this->assertSame([(string) $literal->id], array_column($result['items'], 'id'));
    }

    public function test_party_selector_returns_distinct_business_name_meta_and_preserves_sublabel(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-AR-900',
            'name_ar' => 'سالم المصري',
            'name_en' => 'Salem Masri',
            'business_name_ar' => 'مؤسسة سالم للتوريدات الغذائية والمستلزمات التجارية الدولية',
            'business_name_en' => 'Salem Trading Supplies Est.',
            'phone' => '0599123456',
            'status' => 'active',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $sameBusinessCustomer = Customer::create([
            'company_id' => $this->company->id,
            'code' => 'C-SAME-1',
            'name_ar' => 'شركة النور',
            'name_en' => 'Al Noor Co',
            'business_name_ar' => 'شركة النور',
            'status' => 'active',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        /** @var SearchableSelectorService $selector */
        $selector = app(SearchableSelectorService::class);
        $res1 = $selector->search($this->company, $this->owner, 'customer', 'C-AR-900', null, 10, ['context' => 'sales']);
        $this->assertCount(1, $res1['items']);
        $this->assertSame('C-AR-900', $res1['items'][0]['meta']['code']);
        $this->assertSame('0599123456', $res1['items'][0]['meta']['phone']);
        $this->assertNotEmpty($res1['items'][0]['meta']['business_name']);
        $this->assertStringContainsString((string) $res1['items'][0]['meta']['business_name'], (string) $res1['items'][0]['sublabel']);

        $res2 = $selector->search($this->company, $this->owner, 'customer', 'C-SAME-1', null, 10, ['context' => 'sales']);
        $this->assertCount(1, $res2['items']);
        $this->assertNull($res2['items'][0]['meta']['business_name'] ?? null);
        $this->assertSame('C-SAME-1', $res2['items'][0]['sublabel']);
        $this->assertNotSame($customer->id, $sameBusinessCustomer->id);
    }

    public function test_payment_vendor_selector_allows_soft_deleted_vendor_only_with_posted_purchase(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $vendorPosted = Vendor::create([
            'company_id' => $this->company->id,
            'code' => 'V-SOFT-POSTED',
            'name_ar' => 'مورد محذوف بمرحلة',
            'name_en' => 'Deleted Posted Vendor',
            'status' => 'active',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $vendorDraftOnly = Vendor::create([
            'company_id' => $this->company->id,
            'code' => 'V-SOFT-DRAFT',
            'name_ar' => 'مورد محذوف بمسودة',
            'name_en' => 'Deleted Draft Vendor',
            'status' => 'active',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $postedPurchase = Purchase::create([
            'company_id' => $this->company->id,
            'vendor_id' => $vendorPosted->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => Purchase::STATUS_DRAFT,
            'purchase_date' => '2026-11-26',
            'currency_code' => $this->company->base_currency_code,
            'base_currency_code' => $this->company->base_currency_code,
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'subtotal_currency' => '100.00',
            'discount_total_currency' => '0.00',
            'tax_total_currency' => '0.00',
            'grand_total_currency' => '100.00',
            'subtotal_base' => '100.00',
            'discount_total_base' => '0.00',
            'tax_total_base' => '0.00',
            'grand_total_base' => '100.00',
            'created_by' => $this->owner->id,
        ]);
        DB::table('purchases')
            ->where('id', $postedPurchase->id)
            ->update(['status' => Purchase::STATUS_POSTED]);

        Purchase::create([
            'company_id' => $this->company->id,
            'vendor_id' => $vendorDraftOnly->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => Purchase::STATUS_DRAFT,
            'purchase_date' => '2026-11-26',
            'currency_code' => $this->company->base_currency_code,
            'base_currency_code' => $this->company->base_currency_code,
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'subtotal_currency' => '100.00',
            'discount_total_currency' => '0.00',
            'tax_total_currency' => '0.00',
            'grand_total_currency' => '100.00',
            'subtotal_base' => '100.00',
            'discount_total_base' => '0.00',
            'tax_total_base' => '0.00',
            'grand_total_base' => '100.00',
            'created_by' => $this->owner->id,
        ]);

        $vendorPosted->delete();
        $vendorDraftOnly->delete();

        /** @var SearchableSelectorService $selector */
        $selector = app(SearchableSelectorService::class);
        $paymentSearch = $selector->search($this->company, $this->owner, 'vendor', 'V-SOFT-', null, 15, ['context' => 'payment']);
        $paymentIds = array_column($paymentSearch['items'], 'id');
        $this->assertContains((string) $vendorPosted->id, $paymentIds);
        $this->assertNotContains((string) $vendorDraftOnly->id, $paymentIds);

        $purchasingSearch = $selector->search($this->company, $this->owner, 'vendor', 'V-SOFT-', null, 15, ['context' => 'purchasing']);
        $purchasingIds = array_column($purchasingSearch['items'], 'id');
        $this->assertSame([], $purchasingIds);
    }

    private function createProduct(
        string $sku,
        string $nameAr,
        string $nameEn,
        string $type,
        bool $trackStock,
        string $cost,
        string $price
    ): Product {
        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => $sku,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'base_unit_id' => $this->unit->id,
            'product_type' => $type,
            'track_stock' => $trackStock,
            'default_purchase_cost_base' => $cost,
            'default_sale_price_base' => $price,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unit->id,
            'conversion_to_base' => '1',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        return $product;
    }
}
