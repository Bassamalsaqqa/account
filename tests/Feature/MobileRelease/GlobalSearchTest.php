<?php

declare(strict_types=1);

namespace Tests\Feature\MobileRelease;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\GlobalSearch as GlobalSearchComponent;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Search\GlobalSearch;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionClass;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;
    use SalesCorrectionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->salesFixtures();
    }

    protected function createProduct(Company $company, string $sku, string $nameAr, string $nameEn): Product
    {
        $piece = Unit::firstOrCreate(
            ['company_id' => $company->id, 'code' => 'piece'],
            ['name_ar' => 'قطعة', 'name_en' => 'Piece', 'symbol' => 'pc', 'allow_decimal' => false, 'active' => true]
        );

        return Product::create([
            'company_id' => $company->id,
            'sku' => $sku,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'base_unit_id' => $piece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function createRestrictedActor(array $permissions, ?Company $company = null): User
    {
        $targetCompany = $company ?? $this->company;
        $actor = User::factory()->create(['locale' => 'ar']);
        $targetCompany->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        setPermissionsTeamId($targetCompany->id);
        $role = Role::create([
            'company_id' => $targetCompany->id,
            'name' => 'perm_role_'.$actor->id.'_'.uniqid(),
            'guard_name' => 'web',
        ]);
        $role->syncPermissions($permissions);
        $actor->assignRole($role);

        return $actor;
    }

    public function test_logged_out_context_actor_rejected(): void
    {
        $search = app(GlobalSearch::class);

        // When user logs out, context must not fall back to ambient context actor
        Auth::logout();
        $this->assertNull(auth()->user());

        $results = $search->search('عميل');
        $this->assertEmpty($results, 'Logged out actor must be rejected immediately');

        // Mismatched actor ID between Auth and CompanyContext
        $otherUser = User::factory()->create();
        $this->actingAs($otherUser);
        // Context still points to $this->owner
        $resultsMismatch = $search->search('عميل');
        $this->assertEmpty($resultsMismatch, 'Mismatched context user and authenticated user must be rejected');
    }

    public function test_freshly_deactivated_company_returns_empty(): void
    {
        $search = app(GlobalSearch::class);

        $resultsBefore = $search->search('عميل');
        $this->assertNotEmpty($resultsBefore);

        // Deactivate company directly in database to test fresh DB check vs cached model
        $this->company->update(['status' => 'inactive']);

        $resultsAfter = $search->search('عميل');
        $this->assertEmpty($resultsAfter, 'Freshly deactivated company in database must return empty results');
    }

    public function test_service_empty_or_short_term_handling(): void
    {
        $search = app(GlobalSearch::class);

        $this->assertEmpty($search->search(''));
        $this->assertEmpty($search->search(' '));
        $this->assertEmpty($search->search('a'));
        $this->assertEmpty($search->search('  x  '));
        $this->assertEmpty($search->search(str_repeat('z', 101)));
    }

    public function test_tenant_isolation_prevents_cross_company_results_and_barcodes(): void
    {
        // Company A: existing $this->company
        $productA = $this->createProduct($this->company, 'SKU-AAA', 'منتج الشركة الأولى', 'Product Company A');
        ProductBarcode::create([
            'company_id' => $this->company->id,
            'product_id' => $productA->id,
            'barcode' => 'CODE-11111',
            'type' => 'code128',
            'is_primary' => true,
        ]);

        // Company B: isolated second company
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Company',
            'base_currency_code' => 'USD',
        ]);
        app(CompanyContext::class)->setCompany($otherCompany, $this->owner);
        $productB = $this->createProduct($otherCompany, 'SKU-BBB', 'منتج الشركة الثانية', 'Product Company B');
        ProductBarcode::create([
            'company_id' => $otherCompany->id,
            'product_id' => $productB->id,
            'barcode' => 'CODE-SHARED-999',
            'type' => 'code128',
            'is_primary' => true,
        ]);
        Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل الشركة الثانية',
            'name_en' => 'Customer Company B',
            'code' => 'CUST-B',
            'created_by' => $this->owner->id,
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $search = app(GlobalSearch::class);

        // Searching for terms that exist only in Company B
        $resultsB = $search->search('الشركة الثانية');
        $this->assertEmpty($resultsB, 'Company A context must not return any records from Company B');

        // Searching for barcode in Company B
        $barcodeResults = $search->search('CODE-SHARED-999');
        $this->assertEmpty($barcodeResults, 'Company B barcode must not be returned in Company A');

        // Searching for Company A product
        $resultsA = $search->search('الشركة الأولى');
        $this->assertArrayHasKey('product', $resultsA);
        $this->assertSame($productA->public_id, $resultsA['product'][0]['public_id']);
    }

    public function test_category_permissions_strictly_enforced(): void
    {
        $this->createProduct($this->company, 'SKU-TEST', 'شاي أحمر فاخر', 'Luxury Red Tea');
        $this->invoice();
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد الشاي',
            'name_en' => 'Tea Vendor',
            'code' => 'VEND-TEA',
            'created_by' => $this->owner->id,
        ]);

        // Actor with only customers.view
        $customerViewer = $this->createRestrictedActor(['customers.view']);
        app(CompanyContext::class)->setCompany($this->company, $customerViewer);
        $this->actingAs($customerViewer);

        $search = app(GlobalSearch::class);
        $results = $search->search('الاختبار');

        $this->assertArrayHasKey('customer', $results);
        $this->assertArrayNotHasKey('invoice', $results);
        $this->assertArrayNotHasKey('product', $results);
        $this->assertArrayNotHasKey('vendor', $results);

        // Revoke permission dynamically
        setPermissionsTeamId($this->company->id);
        $customerViewer->roles()->firstOrFail()->revokePermissionTo('customers.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $revokedResults = $search->search('الاختبار');
        $this->assertEmpty($revokedResults, 'Revoked permissions must immediately clear category output');
    }

    public function test_malicious_select_result_method_removed(): void
    {
        $ref = new ReflectionClass(GlobalSearchComponent::class);
        $this->assertFalse(
            $ref->hasMethod('selectResult'),
            'GlobalSearch Livewire component must not expose an open selectResult(string $url) redirect surface'
        );
    }

    public function test_same_company_switch_clears_hydrated_query(): void
    {
        $component = Livewire::test(GlobalSearchComponent::class)
            ->call('open')
            ->set('query', 'عميل');

        $this->assertTrue($component->get('isOpen'));
        $this->assertSame('عميل', $component->get('query'));

        // Switch to a new company
        app(CompanyContext::class)->clear();
        $newCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة التبديل',
            'name_en' => 'Switched Company',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($newCompany, $this->owner);

        // Re-render the Livewire component in the new context
        $component->call('$refresh');

        $this->assertSame('', $component->get('query'), 'Switching company context must reset active search query');
        $this->assertFalse($component->get('isOpen'), 'Switching company context must close search dialog');
    }

    public function test_results_capped_at_five_per_category_deterministically(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            Customer::create([
                'company_id' => $this->company->id,
                'name_ar' => sprintf('زبون %02d تجريبي', $i),
                'name_en' => sprintf('Customer Test %02d', $i),
                'code' => sprintf('CUST-%02d', $i),
                'created_by' => $this->owner->id,
            ]);
        }

        $search = app(GlobalSearch::class);
        $results = $search->search('زبون');

        $this->assertArrayHasKey('customer', $results);
        $this->assertCount(5, $results['customer'], 'Category results must be capped at 5 items max');
        $this->assertSame('زبون 01 تجريبي', $results['customer'][0]['title']);
        $this->assertSame('زبون 05 تجريبي', $results['customer'][4]['title']);
    }

    public function test_no_sensitive_fields_serialized(): void
    {
        $this->invoice('9999.00');
        $this->customer->update([
            'phone' => '+970599123456',
            'notes' => 'Confidential VIP customer notes',
        ]);

        $search = app(GlobalSearch::class);
        $results = $search->search('عميل');

        $this->assertArrayHasKey('customer', $results);
        $item = $results['customer'][0];

        $expectedKeys = ['category', 'public_id', 'title', 'subtitle', 'status', 'url'];
        $this->assertEquals($expectedKeys, array_keys($item));

        $serialized = json_encode($results);
        $this->assertStringNotContainsString('9999', $serialized);
        $this->assertStringNotContainsString('+970599123456', $serialized);
        $this->assertStringNotContainsString('Confidential', $serialized);
    }

    public function test_query_failure_is_sanitized_and_does_not_break_the_application_shell(): void
    {
        $component = Livewire::test(GlobalSearchComponent::class)->call('open');
        DB::listen(static function ($event): void {
            if (str_contains($event->sql, 'from `customers`')) {
                throw new \RuntimeException('Private database diagnostic must not reach the browser');
            }
        });
        $component->set('query', 'customer')
            ->assertSee(__('search.error_occurred'))
            ->assertDontSee('Private database diagnostic')
            ->assertDontSee(__('search.no_results'));
    }

    public function test_phone_lookup_is_authorized_tenant_scoped_and_never_projects_phone(): void
    {
        $phone = '+970599876543';
        $this->customer->update(['phone' => $phone]);
        $vendor = Vendor::create(['company_id' => $this->company->id, 'name_ar' => 'Phone Vendor',
            'name_en' => 'Phone Vendor', 'phone' => $phone, 'code' => 'PHONE-VENDOR', 'created_by' => $this->owner->id]);
        app(CompanyContext::class)->clear();
        $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Other', 'name_en' => 'Other', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($other, $this->owner);
        $this->customer->replicate(['public_id'])->fill(['company_id' => $other->id, 'code' => 'FOREIGN-PHONE'])->save();
        $vendor->replicate(['public_id'])->fill(['company_id' => $other->id, 'code' => 'FOREIGN-VENDOR'])->save();
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $search = app(GlobalSearch::class);
        $results = $search->search('599876');
        $this->assertSame($this->customer->public_id, $results['customer'][0]['public_id']);
        $this->assertCount(1, $results['customer']);
        $this->assertSame($vendor->public_id, $results['vendor'][0]['public_id']);
        $this->assertCount(1, $results['vendor']);
        $this->assertStringNotContainsString($phone, json_encode($results));
        $this->assertEmpty($search->search('599%876'));
        foreach (['customers.view' => 'customer', 'vendors.view' => 'vendor'] as $permission => $category) {
            $actor = $this->createRestrictedActor([$permission]);
            app(CompanyContext::class)->setCompany($this->company, $actor);
            $this->actingAs($actor);
            $restricted = $search->search($phone);
            $this->assertSame([$category], array_keys($restricted));
            $this->assertStringNotContainsString($phone, json_encode($restricted));
            $actor->roles()->firstOrFail()->revokePermissionTo($permission);
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            $this->assertEmpty($search->search($phone));
        }
    }
}
