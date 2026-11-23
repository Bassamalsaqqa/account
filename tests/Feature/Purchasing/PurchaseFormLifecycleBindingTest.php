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
        $this->assertStringContainsString('wire:model.blur="exchange_rate"', $html);
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
}
