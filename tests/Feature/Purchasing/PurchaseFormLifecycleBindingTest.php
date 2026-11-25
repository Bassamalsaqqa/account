<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Purchasing\PurchaseForm;
use App\Models\Company;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLifecycleHooks\DirectlyCallingLifecycleHooksNotAllowedException;
use Livewire\Livewire;
use Tests\TestCase;

final class PurchaseFormLifecycleBindingTest extends TestCase
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
            'name_ar' => 'شركة فحص دورة الحياة',
            'name_en' => 'Lifecycle Test Co',
        ]);
        $this->activate($this->owner);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد الاختبار',
            'name_en' => 'Test Vendor',
        ]);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();

        $piece = Unit::where('code', 'piece')->firstOrFail();

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج تجريبي',
            'name_en' => 'Test Product',
            'sku' => 'SKU-TEST',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $piece->id,
            'default_purchase_cost_base' => '10.00',
        ], $this->owner->id);
    }

    private function activate(User $actor): void
    {
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($this->company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function test_client_calling_lifecycle_hook_directly_throws_directly_calling_lifecycle_hooks_not_allowed(): void
    {
        $test = Livewire::test(PurchaseForm::class);

        $this->expectException(DirectlyCallingLifecycleHooksNotAllowedException::class);
        $test->call('updatedExchangeRate');
    }

    public function test_purchase_form_blade_does_not_bind_lifecycle_hook_to_wire_change(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('currency_code', 'JOD');

        $html = $test->html();

        $this->assertStringContainsString('id="purchase-rate"', $html);
        $this->assertStringContainsString('wire:model="exchange_rate" data-purchase-preview-input', $html);
        $this->assertStringNotContainsString('wire:change="updatedExchangeRate"', $html,
            'The exchange_rate input must not bind updatedExchangeRate to wire:change because calling lifecycle hooks directly causes HTTP 500 DirectlyCallingLifecycleHooksNotAllowedException.'
        );
    }

    public function test_exchange_rate_property_sync_invokes_lifecycle_hook_cleanly_without_direct_action(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->set('currency_code', 'JOD')
            ->call('selectProduct', 0, $this->product->id);

        // Setting exchange_rate automatically triggers updatedExchangeRate via Livewire lifecycle
        $test->set('exchange_rate', '0.2800000000');
        $test->assertSet('exchange_rate', '0.2800000000');
        $test->assertHasNoErrors('exchange_rate');
    }

    public function test_purchase_form_rendered_view_does_not_contain_mojibake_encoding(): void
    {
        $test = Livewire::test(PurchaseForm::class);

        $html = $test->html();

        $this->assertStringNotContainsString('â€”', $html,
            'The rendered purchase form must not contain the corrupted UTF-8 byte sequence â€”.'
        );
    }

    public function test_no_blade_template_contains_illicit_lifecycle_method_bindings(): void
    {
        $viewsDir = resource_path('views');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($viewsDir));
        $illicitPatterns = [
            '/wire:(?:change|click|input|blur|submit|keydown|keyup)\s*=\s*["\']\s*(?:updated[A-Z0-9_]|mount|hydrate|boot|rendering|rendered|dehydrate)/i',
        ];

        $violations = [];
        foreach ($files as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $content = file_get_contents($file->getPathname());
                foreach ($illicitPatterns as $pattern) {
                    if (preg_match($pattern, $content, $matches)) {
                        $violations[] = $file->getPathname().' matched '.$matches[0];
                    }
                }
            }
        }

        $this->assertSame([], $violations, 'Found illegal direct lifecycle hook bindings in blade templates: '.implode(', ', $violations));
    }

    public function test_empty_line_validation_displays_human_readable_attribute_names_without_raw_dot_paths(): void
    {
        app()->setLocale('ar');
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('save', false);

        $test->assertHasErrors(['lines.0.product_id']);

        $errors = $test->errors();
        $productError = $errors->first('lines.0.product_id');

        // Assert error message uses localized human readable attribute instead of raw dot-path
        $this->assertStringContainsString('البند', $productError);
        $this->assertStringContainsString('الصنف', $productError);
        $this->assertStringNotContainsString('lines.0.product_id', $productError);

        $html = $test->html();
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('id="purchase-vendor"', $html);
    }

    public function test_currency_switch_clears_fx_rate_and_requires_rate_before_auto_cost_population_without_500(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id);

        // Initial base currency
        $test->assertSet('currency_code', 'ILS');
        $test->assertSet('exchange_rate', '1.0000000000');
        $test->call('selectProduct', 0, $this->product->id);
        $test->assertSet('lines.0.unit_cost', '10.00');

        // Switch to foreign currency JOD
        $test->set('currency_code', 'JOD');
        $test->assertSet('exchange_rate', '');
        $test->assertSet('lines.0.unit_cost', '');

        // Trying to auto-populate product cost without exchange rate must not throw 500
        $test->call('selectProduct', 0, $this->product->id);
        $test->assertHasErrors(['exchange_rate']);
        $this->assertSame('', $test->get('lines.0.unit_cost'));

        // Supplying valid exchange rate allows auto-cost population
        $test->set('exchange_rate', '0.2800000000');
        $test->call('selectProduct', 0, $this->product->id);
        $test->assertHasNoErrors('exchange_rate');

        // Switch to USD: again clears exchange_rate and unit costs safely
        $test->set('currency_code', 'USD');
        $test->assertSet('exchange_rate', '');
        $test->assertSet('lines.0.unit_cost', '');
    }

    public function test_immediate_settlement_mode_rendered_with_accessible_cards_and_parity_with_sales(): void
    {
        $test = Livewire::test(PurchaseForm::class);

        $html = $test->html();

        // 3-mode card radio group matching sales layout
        $this->assertStringContainsString('grid gap-2 sm:grid-cols-3', $html);
        $this->assertStringContainsString('id="purchase-settlement-mode-unpaid"', $html);
        $this->assertStringContainsString('id="purchase-settlement-mode-full"', $html);
        $this->assertStringContainsString('id="purchase-settlement-mode-partial"', $html);
        $this->assertStringContainsString('rounded-control border border-border bg-surface px-3 py-2 text-sm', $html);
    }

    public function test_empty_or_missing_cost_or_quantity_clears_totals_and_fails_validation_while_explicit_zero_cost_is_allowed(): void
    {
        $test = Livewire::test(PurchaseForm::class)
            ->set('vendor_id', $this->vendor->id)
            ->set('warehouse_id', $this->warehouse->id)
            ->call('selectProduct', 0, $this->product->id);

        // Explicit string '0' is valid policy (ADR 0013 zero-cost purchase)
        $test->set('lines.0.unit_cost', '0');
        $test->call('recalculate');
        $this->assertTrue($test->get('documentTotalsValid'));
        $this->assertTrue(BigDecimal::of($test->get('totals.total'))->isZero());

        // Empty string cost must mark preview totals unavailable and clear totals
        $test->set('lines.0.unit_cost', '');
        $test->call('recalculate');
        $this->assertFalse($test->get('documentTotalsValid'));
        $this->assertSame([], $test->get('totals'));

        // Saving draft with empty cost fails validation with short field error
        $test->call('save', false);
        $test->assertHasErrors(['lines.0.unit_cost']);

        // Empty string quantity also marks preview unavailable and fails validation
        $test->set('lines.0.unit_cost', '10.00');
        $test->set('lines.0.quantity', '');
        $test->call('recalculate');
        $this->assertFalse($test->get('documentTotalsValid'));
        $test->call('save', false);
        $test->assertHasErrors(['lines.0.quantity']);
    }
}
