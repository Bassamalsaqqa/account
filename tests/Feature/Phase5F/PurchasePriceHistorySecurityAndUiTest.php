<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5F;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Domain\Sales\Formatters\SalesMoneyFormatter;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Livewire\Pages\Purchasing\VendorDetail;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PurchasePriceHistorySecurityAndUiTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Vendor $vendor;

    protected Product $product;

    protected ProductUnit $baseUnit;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة أمان وتاريخ الأسعار',
            'name_en' => 'Security and Price History Co',
        ]);
        $this->activate($this->company, $this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التجهيزات الإلكترونية',
            'name_en' => 'Electronic Supplies Vendor',
            'code' => 'VEND-SEC-1',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $piece = Unit::where('code', 'piece')->firstOrFail();

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'شاشة مكتبية',
            'name_en' => 'Desktop Monitor',
            'sku' => 'MON-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
        ], $this->owner->id);

        $this->baseUnit = ProductUnit::where('product_id', $this->product->id)
            ->where('unit_id', $piece->id)
            ->firstOrFail();
    }

    protected function activate(Company $company, User $actor): void
    {
        app(CompanyContext::class)->setCompany($company, $actor);
        $this->actingAs($actor);
    }

    protected function customActor(array $permissions, string $roleName = 'CustomRole'): User
    {
        $user = User::factory()->create(['locale' => 'ar']);
        $this->company->users()->attach($user->id, [
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        setPermissionsTeamId($this->company->id);
        $role = Role::firstOrCreate([
            'company_id' => $this->company->id,
            'name' => $roleName.'_'.$user->id,
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);
        $user->assignRole($role);

        return $user;
    }

    protected function postPurchase(array $lineOverrides = [], array $purchaseOverrides = []): Purchase
    {
        $lineData = array_merge([
            'product_id' => $this->product->id,
            'product_unit_id' => $this->baseUnit->id,
            'quantity' => '10.000000',
            'unit_cost' => '12.500000',
            'discount_type' => 'none',
            'discount_value' => '0',
            'tax_rate_id' => null,
            'lots' => [],
        ], $lineOverrides);

        $purchaseData = array_merge([
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-10-06',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [$lineData],
        ], $purchaseOverrides);

        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, $purchaseData);

        return app(PostPurchaseAction::class)->execute($draft, $this->owner);
    }

    public function test_product_detail_shows_purchase_history_tab_when_authorized(): void
    {
        $purchase = $this->postPurchase(['unit_cost' => '12.500000']);

        $actor = $this->customActor([
            'inventory.stock.view',
            'purchasing.cost.view',
            'purchasing.purchase.view',
            'vendors.view',
        ], 'FullHistoryViewer');

        $this->activate($this->company, $actor);

        $component = Livewire::actingAs($actor)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->assertOk()
            ->assertSee(__('purchasing.purchase_price_history'))
            ->call('setTab', 'purchase_history')
            ->assertSet('activeTab', 'purchase_history')
            ->assertSee($purchase->purchase_number)
            ->assertSee($this->vendor->name_ar)
            ->assertSee('12.50')
            ->assertSee(__('purchasing.net_commercial_price_per_base_unit'))
            ->assertSee(route('purchases.show', $purchase->public_id))
            ->assertSee(route('vendors.show', $this->vendor->public_id));
    }

    public function test_product_detail_renders_purchase_number_as_plain_text_without_purchase_view(): void
    {
        $purchase = $this->postPurchase(['unit_cost' => '15.000000']);

        $actor = $this->customActor([
            'inventory.stock.view',
            'purchasing.cost.view',
            'vendors.view',
        ], 'NoPurchaseLinkViewer');

        $this->activate($this->company, $actor);

        Livewire::actingAs($actor)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->assertOk()
            ->call('setTab', 'purchase_history')
            ->assertSee($purchase->purchase_number)
            ->assertDontSee(route('purchases.show', $purchase->public_id))
            ->assertSee(route('vendors.show', $this->vendor->public_id));
    }

    public function test_product_detail_renders_vendor_name_as_plain_text_without_vendor_view(): void
    {
        $purchase = $this->postPurchase(['unit_cost' => '18.000000']);

        $actor = $this->customActor([
            'inventory.stock.view',
            'purchasing.cost.view',
            'purchasing.purchase.view',
        ], 'NoVendorLinkViewer');

        $this->activate($this->company, $actor);

        Livewire::actingAs($actor)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->assertOk()
            ->call('setTab', 'purchase_history')
            ->assertSee($this->vendor->name_ar)
            ->assertDontSee(route('vendors.show', $this->vendor->public_id))
            ->assertSee(route('purchases.show', $purchase->public_id));
    }

    public function test_product_detail_completely_hides_tab_and_redacts_prices_without_cost_view(): void
    {
        $this->postPurchase(['unit_cost' => '999.000000']);

        $actor = $this->customActor([
            'inventory.stock.view',
        ], 'NoCostViewer');

        $this->activate($this->company, $actor);

        Livewire::actingAs($actor)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->assertOk()
            ->assertDontSee(__('purchasing.purchase_price_history'))
            ->call('setTab', 'purchase_history')
            ->assertSet('activeTab', 'overview')
            ->assertDontSee('999.00');
    }

    public function test_vendor_detail_shows_products_tab_and_filters_when_authorized(): void
    {
        $piece = Unit::where('code', 'piece')->firstOrFail();
        $product2 = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'لوحة مفاتيح',
            'name_en' => 'Keyboard',
            'sku' => 'KEY-002',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
        ], $this->owner->id);
        $unit2 = ProductUnit::where('product_id', $product2->id)->where('unit_id', $piece->id)->firstOrFail();

        $purchase1 = $this->postPurchase(['unit_cost' => '20.000000']);
        $purchase2 = $this->postPurchase([
            'product_id' => $product2->id,
            'product_unit_id' => $unit2->id,
            'unit_cost' => '50.000000',
        ]);

        $actor = $this->customActor([
            'vendors.view',
            'purchasing.cost.view',
            'purchasing.purchase.view',
            'inventory.stock.view',
        ], 'VendorProductsViewer');

        $this->activate($this->company, $actor);

        Livewire::actingAs($actor)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()
            ->assertSee(__('purchasing.products_and_prices'))
            ->set('activeTab', 'products')
            ->assertSee($this->product->name_ar)
            ->assertSee($product2->name_ar)
            ->assertSee('20.00')
            ->assertSee('50.00')
            ->assertSee(route('products.show', $this->product->public_id))
            ->assertSee(route('purchases.show', $purchase1->public_id))
            ->call('selectProductFilter', $this->product->id)
            ->assertSet('selectedProductId', $this->product->id)
            ->call('selectProductFilter', null)
            ->assertSet('selectedProductId', null);
    }

    public function test_vendor_detail_hides_products_tab_and_redacts_without_cost_view(): void
    {
        $this->postPurchase(['unit_cost' => '888.000000']);

        $actor = $this->customActor([
            'vendors.view',
        ], 'VendorNoCostViewer');

        $this->activate($this->company, $actor);

        Livewire::actingAs($actor)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()
            ->assertDontSee(__('purchasing.products_and_prices'))
            ->set('activeTab', 'products')
            ->assertSet('activeTab', 'overview')
            ->assertDontSee('888.00');
    }

    public function test_vendor_detail_respects_purchase_and_product_view_for_links(): void
    {
        $purchase = $this->postPurchase(['unit_cost' => '45.000000']);

        $actor = $this->customActor([
            'vendors.view',
            'purchasing.cost.view',
        ], 'VendorCostNoLinksViewer');

        $this->activate($this->company, $actor);

        Livewire::actingAs($actor)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->assertOk()
            ->set('activeTab', 'products')
            ->assertSee('45.00')
            ->assertDontSee(route('purchases.show', $purchase->public_id))
            ->assertDontSee(route('products.show', $this->product->public_id));
    }

    public function test_purchase_form_displays_hint_without_mutating_draft_line_cost(): void
    {
        $purchase = $this->postPurchase(['unit_cost' => '35.000000']);

        $actor = $this->customActor([
            'purchasing.purchase.create',
            'purchasing.cost.view',
            'inventory.stock.view',
        ], 'PurchaseCreator');

        $this->activate($this->company, $actor);

        Livewire::actingAs($actor)
            ->test(PurchaseForm::class)
            ->assertOk()
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->assertSee(__('purchasing.last_purchased_hint', [
                'price' => SalesMoneyFormatter::format('35.000000', 'ILS'),
                'currency' => 'ILS',
                'unit' => $this->baseUnit->unit->name(),
                'date' => $purchase->purchase_date->toDateString(),
            ]))
            ->assertSet('lines.0.unit_cost', '0.00');
    }

    public function test_tenant_isolation_in_ui(): void
    {
        $this->postPurchase(['unit_cost' => '111.000000']);

        // Create Company B
        app(CompanyContext::class)->clear();
        $ownerB = User::factory()->create(['locale' => 'ar']);
        $companyB = app(CreateCompanyAction::class)->execute($ownerB, [
            'name_ar' => 'شركة أجنبية',
            'name_en' => 'Foreign Company B',
        ]);
        $this->activate($companyB, $ownerB);

        $vendorB = app(VendorCatalogService::class)->save($companyB, $ownerB, [
            'name_ar' => 'مورد شركة ب',
            'code' => 'VEND-B',
        ]);
        $piece = Unit::where('code', 'piece')->firstOrFail();
        $productB = app(ProductCatalogService::class)->createProduct($companyB, [
            'name_ar' => 'منتج شركة ب',
            'sku' => 'PROD-B',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
        ], $ownerB->id);
        $unitB = ProductUnit::where('product_id', $productB->id)->where('unit_id', $piece->id)->firstOrFail();
        $whB = Warehouse::where('company_id', $companyB->id)->firstOrFail();

        $draftB = app(CreatePurchaseDraftAction::class)->execute($companyB, $ownerB, [
            'vendor_id' => $vendorB->id,
            'warehouse_id' => $whB->id,
            'purchase_date' => '2026-10-06',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $productB->id,
                'product_unit_id' => $unitB->id,
                'quantity' => '5.000000',
                'unit_cost' => '9999.000000',
                'discount_type' => 'none',
                'discount_value' => '0',
                'tax_rate_id' => null,
                'lots' => [],
            ]],
        ]);
        app(PostPurchaseAction::class)->execute($draftB, $ownerB);

        // Switch back to Company A
        $this->activate($this->company, $this->owner);

        Livewire::actingAs($this->owner)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->call('setTab', 'purchase_history')
            ->assertSee('111.00')
            ->assertDontSee('9999.00')
            ->assertDontSee($vendorB->name_ar);

        Livewire::actingAs($this->owner)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->set('activeTab', 'products')
            ->assertSee('111.00')
            ->assertDontSee('9999.00')
            ->assertDontSee($productB->name_ar);
    }

    public function test_ui_rendering_has_zero_side_effects(): void
    {
        $this->postPurchase(['unit_cost' => '75.000000']);

        $captureState = function (): array {
            $state = [];
            foreach ([
                'purchases', 'purchase_lines', 'posting_batches', 'posting_lines',
                'stock_movements', 'inventory_balances', 'inventory_cost_states',
            ] as $table) {
                $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }

            return $state;
        };

        $beforeState = $captureState();

        // Render ProductDetail
        Livewire::actingAs($this->owner)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->call('setTab', 'purchase_history')
            ->assertOk();

        // Render VendorDetail
        Livewire::actingAs($this->owner)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->set('activeTab', 'products')
            ->assertOk();

        // Render PurchaseForm
        Livewire::actingAs($this->owner)
            ->test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->assertOk();

        $afterState = $captureState();

        $this->assertSame($beforeState, $afterState, 'UI rendering must have zero database mutations or side effects.');
    }

    public function test_smoke_matrix_renders_cleanly_in_both_arabic_and_english(): void
    {
        $purchase = $this->postPurchase(['unit_cost' => '50.000000']);

        // 1. Arabic Smoke (RTL)
        app()->setLocale('ar');
        Livewire::actingAs($this->owner)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->call('setTab', 'purchase_history')
            ->assertOk()
            ->assertSee('تاريخ أسعار الشراء')
            ->assertSee('السعر التجاري الصافي / الوحدة الأساسية')
            ->assertSee('سعر الوحدة الأصلي');

        Livewire::actingAs($this->owner)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->set('activeTab', 'products')
            ->assertOk()
            ->assertSee('المنتجات وتاريخ الأسعار')
            ->assertSee('المنتجات الموردة');

        Livewire::actingAs($this->owner)
            ->test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->assertOk()
            ->assertSee('آخر شراء:');

        // 2. English Smoke (LTR)
        app()->setLocale('en');
        Livewire::actingAs($this->owner)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->call('setTab', 'purchase_history')
            ->assertOk()
            ->assertSee('Purchase Price History')
            ->assertSee('Net Commercial Price / Base Unit')
            ->assertSee('Raw Unit Cost');

        Livewire::actingAs($this->owner)
            ->test(VendorDetail::class, ['publicId' => $this->vendor->public_id])
            ->set('activeTab', 'products')
            ->assertOk()
            ->assertSee('Products & Price History')
            ->assertSee('Supplied Products');

        Livewire::actingAs($this->owner)
            ->test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->assertOk()
            ->assertSee('Last purchased:');

        // Reset locale
        app()->setLocale('ar');
    }

    public function test_regression_stale_cost_revocation_redacts_livewire_component(): void
    {
        $this->postPurchase(['unit_cost' => '50.000000']);
        $actor = $this->customActor(['inventory.stock.view', 'purchasing.cost.view']);
        $this->activate($this->company, $actor);

        $component = Livewire::actingAs($actor)->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->call('setTab', 'purchase_history')
            ->assertSet('activeTab', 'purchase_history');

        $role = $actor->roles->first();
        $role->revokePermissionTo('purchasing.cost.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $component->call('$refresh')
            ->assertSet('activeTab', 'overview');
    }

    public function test_regression_payload_denial_when_cost_permission_missing(): void
    {
        $this->postPurchase(['unit_cost' => '77.770000']);
        $actor = $this->customActor(['inventory.stock.view']);
        $this->activate($this->company, $actor);

        $response = Livewire::actingAs($actor)
            ->test(ProductDetail::class, ['publicId' => $this->product->public_id]);

        $response->assertDontSee('77.770000');
        $response->assertDontSee('تاريخ أسعار الشراء');

        // Verify call setTab with unauthorized tab does not activate or reveal data
        $response->call('setTab', 'purchase_history')
            ->assertSet('activeTab', 'overview')
            ->assertDontSee('77.770000');
    }

    public function test_regression_r5_fractional_quantity_and_saved_precision_formatting(): void
    {
        $this->baseUnit->unit->update(['allows_fraction' => true, 'decimal_places' => 3]);
        $this->postPurchase([
            'quantity' => '1.234',
            'unit_cost' => '12.345678',
        ]);

        $component = Livewire::actingAs($this->owner)->test(ProductDetail::class, ['publicId' => $this->product->public_id])
            ->call('setTab', 'purchase_history');

        $dom = new \DOMDocument;
        @$dom->loadHTML($component->html());
        $xpath = new \DOMXPath($dom);
        $quantity = $xpath->query('//table/tbody/tr/td[5]')->item(0);
        $this->assertNotNull($quantity);
        $this->assertSame('1.234', trim($quantity->textContent));

        // Exact 6-decimal raw unit cost and comparative price must be present
        $component->assertSee('12.345678');
    }

    public function test_regression_purchase_form_hint_shows_currency_and_unit_context(): void
    {
        $this->postPurchase([
            'quantity' => '5.000000',
            'unit_cost' => '25.000000',
        ]);

        Livewire::actingAs($this->owner)
            ->test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id)
            ->assertOk()
            ->assertSee('25.00')
            ->assertSee('ILS')
            ->assertSee('قطعة');
    }
}
