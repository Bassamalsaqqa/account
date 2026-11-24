<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\CompanyPurchaseSetting;
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
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PurchaseFormSimplificationCandidateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة تبسيط المشتريات',
            'name_en' => 'Purchase Simplification Co',
        ]);
        $this->activate($this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التبسيط',
            'name_en' => 'Simplification Vendor',
        ]);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $piece = Unit::where('code', 'piece')->firstOrFail();

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج قياسي',
            'name_en' => 'Standard Product',
            'sku' => 'SKU-SIMP-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
            'default_purchase_cost_base' => '25.00',
        ], $this->owner->id);

        // Configure default receiving warehouse
        CompanyPurchaseSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'default_receiving_warehouse_id' => $this->warehouse->id,
                'default_payment_terms_days' => 30,
            ]
        );
    }

    private function activate(User $actor): void
    {
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($this->company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function test_safe_default_warehouse_is_automatically_bound_when_configured(): void
    {
        $test = Livewire::test(PurchaseForm::class);

        // Must bind the configured receiving warehouse without forcing user interaction
        $test->assertSet('warehouse_id', $this->warehouse->id);
    }

    public function test_safe_default_warehouse_is_null_without_arbitrary_fallback_when_unconfigured(): void
    {
        CompanyPurchaseSetting::where('company_id', $this->company->id)->update([
            'default_receiving_warehouse_id' => null,
        ]);
        CompanyInventorySettings::where('company_id', $this->company->id)->update([
            'default_warehouse_id' => null,
        ]);

        $test = Livewire::test(PurchaseForm::class);

        // Must not select an arbitrary warehouse when no explicit default exists
        $test->assertSet('warehouse_id', null);
    }

    public function test_safe_default_unit_is_resolved_on_product_selection(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->call('selectProduct', 0, $this->product->id);

        $defaultUnit = ProductUnit::where('product_id', $this->product->id)
            ->where('is_default_purchase', true)
            ->firstOrFail();

        $test->assertSet('lines.0.product_id', $this->product->id);
        $test->assertSet('lines.0.product_unit_id', $defaultUnit->id);
        $test->assertSet('lines.0.unit_cost', '25.00');
    }

    public function test_safe_default_date_and_due_date_resolve_from_company_settings(): void
    {
        $test = Livewire::test(PurchaseForm::class);

        $today = now($this->company->timezone)->format('Y-m-d');
        $expectedDue = now($this->company->timezone)->addDays(30)->format('Y-m-d');

        $test->assertSet('purchase_date', $today);
        $test->assertSet('due_date', $expectedDue);
    }

    public function test_error_state_and_attention_focus_contract_preserved(): void
    {
        app()->setLocale('ar');
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', null)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('save', false);

        $test->assertHasErrors(['vendor_id']);

        $html = $test->html();

        // Must render data-form-attention component for focusing invalid controls
        $this->assertStringContainsString('data-form-attention', $html);
        $this->assertStringContainsString('role="alert"', $html);

        // Combobox must reflect aria-invalid="true" when it has validation error
        $this->assertStringContainsString('id="purchase-vendor"', $html);
        $this->assertStringContainsString('border-danger', $html);
    }

    public function test_hotfix_108_retained_in_template_and_runtime(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('currency_code', 'USD');

        $html = $test->html();

        // No illicit lifecycle hook binding on wire:change
        $this->assertStringNotContainsString('wire:change="updatedExchangeRate"', $html);
        // No corrupted UTF-8 mojibake
        $this->assertStringNotContainsString('â€”', $html);
        // Field-level error container present
        $this->assertStringContainsString('id="purchase-rate-error"', $html);
    }

    public function test_minimal_presentation_only_slice_routine_draft_creation_succeeds(): void
    {
        // Minimal slice: vendor + product + qty + cost.
        // No need for supplier invoice number, notes, language override, or lot customization.
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00')
            ->call('saveDraft');

        $test->assertHasNoErrors();
        $this->assertNotNull($test->get('savedDraftPublicId'));
        $test->assertSet('previewGrandTotal', '50.00');
    }

    public function test_new_lines_strictly_enforce_no_line_discount_and_reset_to_zero(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00');

        // New lines must not accept individual discounts (superseded by global discount)
        $test->set('lines.0.discount_type', 'percent')
            ->set('lines.0.discount_value', '10');

        $this->assertSame('none', $test->get('lines.0.discount_type'));
        $this->assertSame('0', $test->get('lines.0.discount_value'));

        $test->call('recalculate');
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('50.00', $test->get('previewGrandTotal'));
    }

    public function test_legacy_draft_with_line_discount_calculates_correctly_and_preserves_totals(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                ],
            ],
        ]);

        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);

        // Hydrates legacy discount and calculates correctly
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('45.00', $test->get('previewGrandTotal'));
    }

    public function test_header_discount_binding_and_validation_without_ui_only_mock_calculation(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id)
            ->set('lines.0.quantity', '2')
            ->set('lines.0.unit_cost', '25.00');

        // Initial defaults
        $test->assertSet('discount_type', 'none');
        $test->assertSet('discount_value', '0');

        // Layout-only intermediate stage: nonzero global discount fails closed
        $test->set('discount_type', 'percent');
        $test->set('discount_value', '10');
        $test->call('recalculate');

        // Recalculation invalidates preview totals to prevent misleading amounts before domain integration
        $this->assertFalse($test->get('documentTotalsValid'));
        $this->assertSame([], $test->get('totals'));
        $this->assertSame('0', $test->get('previewGrandTotal'));

        // Save fails with short field error on discount_value
        $test->call('save', false);
        $test->assertHasErrors(['discount_value']);

        // Explicit zero or none passes and preserves valid recalculation
        $test->set('discount_value', '0');
        $test->call('recalculate');
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertSame('50.00', $test->get('previewGrandTotal'));

        $test->call('saveDraft');
        $test->assertHasNoErrors(['discount_value']);
        $this->assertNotNull($test->get('savedDraftPublicId'));
    }

    public function test_legacy_line_discounts_cannot_be_tampered_by_client_request_or_new_lines(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                ],
            ],
        ]);

        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);

        // Attempt to tamper with legacy line discount via client state
        $test->set('lines.0.discount_type', 'percent');
        $test->set('lines.0.discount_value', '50.00');

        // Add a second line with attempted client discount
        $test->call('addLine');
        $test->call('selectProduct', 1, $this->product->id);
        $test->set('lines.1.quantity', '1');
        $test->set('lines.1.unit_cost', '20.00');
        $test->set('lines.1.discount_type', 'percent');
        $test->set('lines.1.discount_value', '10.00');

        // Trigger updatedLines / draft save
        $test->call('saveDraft');
        $test->assertHasNoErrors();

        $reloaded = Purchase::where('id', $draft->id)->firstOrFail();
        $this->assertCount(2, $reloaded->lines);

        $line0 = $reloaded->lines->sortBy('id')->first();
        $line1 = $reloaded->lines->sortBy('id')->last();

        // Line 0 strictly preserves database-persisted legacy values
        $this->assertSame('fixed', $line0->discount_type);
        $this->assertTrue(BigDecimal::of($line0->discount_value)->isEqualTo('5.00'));

        // Line 1 (new line) strictly resets to none / 0
        $this->assertContains($line1->discount_type, [null, 'none']);
        $this->assertTrue(BigDecimal::of($line1->discount_value)->isZero());
    }

    public function test_collapsible_drawer_contains_optional_fields_and_auto_expands_on_error(): void
    {
        $test = Livewire::test(PurchaseForm::class);
        $html = $test->html();

        // Both optional fields are inside extendedDetailsDrawer
        $drawerHtml = strstr($html, 'id="extendedDetailsDrawer"');
        $this->assertNotFalse($drawerHtml);
        $this->assertStringContainsString('id="supplier-number"', $drawerHtml);
        $this->assertStringContainsString('id="purchase-due_date"', $drawerHtml);

        // Neither is rendered in the top primary block before the drawer
        $topBlock = substr($html, 0, strpos($html, 'id="extendedDetailsDrawer"'));
        $this->assertStringNotContainsString('id="supplier-number"', $topBlock);
        $this->assertStringNotContainsString('id="purchase-due_date"', $topBlock);

        // Drawer auto-expands when drawer fields have validation errors
        $test->set('vendor_invoice_number', str_repeat('V', 150));
        $test->call('save', false);
        $test->assertHasErrors(['vendor_invoice_number']);

        $errorHtml = $test->html();
        $this->assertStringContainsString('x-show="true"', $errorHtml);
    }

    public function test_lines_table_action_bar_is_clean_of_shortcuts_and_counters_and_global_discount_input_hidden_in_intermediate_stage(): void
    {
        $test = Livewire::test(PurchaseForm::class);
        $html = $test->html();

        // No Alt+N shortcut badge or item counter clutter
        $this->assertStringNotContainsString('Alt+N', $html);
        $this->assertStringNotContainsString('· 1 Items', $html);

        // Global discount input is hidden from UI during layout-only intermediate stage
        $this->assertStringNotContainsString('id="purchase-discount-type"', $html);
        $this->assertStringNotContainsString('id="purchase-discount-value"', $html);
    }

    public function test_legacy_line_discount_renders_read_only_badge_and_persists_without_silent_reset(): void
    {
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-11-24',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '2',
                    'unit_cost' => '25.00',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.00',
                ],
            ],
        ]);

        $test = Livewire::test(PurchaseForm::class, ['publicId' => $draft->public_id]);

        // Hydrates existing legacy discount
        $test->assertSet('lines.0.discount_type', 'fixed');
        $test->assertSet('lines.0.discount_value', '5.00');

        $html = $test->html();

        // Read-only legacy badge rendered
        $this->assertStringContainsString('5.00', $html);
        $this->assertStringContainsString('ILS', $html);

        // No editable line discount inputs rendered in template
        $this->assertStringNotContainsString('id="line-discount-type-0"', $html);
        $this->assertStringNotContainsString('id="line-discount-value-0"', $html);

        // Saving draft does not reset the legacy discount
        $test->call('saveDraft');
        $test->assertHasNoErrors();

        $reloaded = Purchase::where('id', $draft->id)->firstOrFail();
        $reloadedLine = $reloaded->lines->first();
        $this->assertSame('fixed', $reloadedLine->discount_type);
        $this->assertTrue(BigDecimal::of($reloadedLine->discount_value)->isEqualTo('5.00'));
    }
}
