<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Catalog\BrandIndex;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Products\ProductIndex;
use App\Models\Brand;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\Feature\Phase5E\Phase5ETestCase;

class BrandManagementTest extends Phase5ETestCase
{
    public function test_product_cost_revocation_redacts_the_existing_livewire_state(): void
    {
        $this->product->update(['default_purchase_cost_base' => '13.57']);
        $page = Livewire::test(ProductForm::class, ['publicId' => $this->product->public_id])->assertSet('suggested_purchase_cost', '13.570000');
        $this->owner->roles()->firstOrFail()->revokePermissionTo('inventory.cost.view');
        $page->call('$refresh')->assertSet('suggested_purchase_cost', '')->assertDontSee('13.57');
        $this->assertSame('13.570000', $this->product->fresh()->default_purchase_cost_base);
    }

    protected function customActor(array $permissions, string $roleName = 'CustomRole', string $membershipStatus = 'active'): User
    {
        $user = User::factory()->create(['locale' => 'ar']);
        $this->company->users()->attach($user->id, [
            'status' => $membershipStatus,
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

    public function test_brand_index_requires_authentication_and_active_company(): void
    {
        auth()->logout();
        app(CompanyContext::class)->clear();

        Livewire::test(BrandIndex::class)->assertForbidden();
    }

    public function test_brand_index_requires_inventory_product_manage_permission_on_mount(): void
    {
        $nonManager = $this->customActor(['inventory.stock.view'], 'StockViewer');
        $this->activate($nonManager);

        Livewire::test(BrandIndex::class)->assertForbidden();
    }

    public function test_inactive_membership_denies_brand_index_and_product_form_inline(): void
    {
        $inactiveUser = $this->customActor(['inventory.product.manage'], 'InactiveManager');
        $this->activate($inactiveUser);
        $brands = Livewire::test(BrandIndex::class);
        $product = Livewire::test(ProductForm::class)->call('openCreateBrandModal');
        $this->company->users()->updateExistingPivot($inactiveUser->id, ['status' => 'inactive']);
        $brands->call('$refresh')->assertForbidden();
        $countBefore = Brand::count();
        $product->call('saveInlineBrand')->assertForbidden();
        $this->assertSame($countBefore, Brand::count());
    }

    public function test_permission_loss_blocks_subsequent_actions_and_hydration(): void
    {
        $component = Livewire::test(BrandIndex::class)
            ->assertSet('lockedCompanyId', $this->company->id);

        // Strip permissions in database without clearing permission cache in PHP memory
        $role = $this->owner->roles()->firstOrFail();
        $role->syncPermissions([]);

        // Fresh reload via SalesActorGuard detects permission loss
        $component->call('openCreateModal')->assertForbidden();
    }

    public function test_tenant_switch_aborts_on_actions(): void
    {
        $component = Livewire::test(BrandIndex::class)
            ->assertSet('lockedCompanyId', $this->company->id);

        // Switch company in active context to Company 2
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'en']);
        $company2 = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Co',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($company2, $otherOwner);
        $this->actingAs($otherOwner);

        $component->call('openCreateModal')->assertForbidden();
    }

    public function test_cross_tenant_brand_access_is_rejected(): void
    {
        // Create brand in Company 2
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'en']);
        $company2 = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Co',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($company2, $otherOwner);
        $this->actingAs($otherOwner);

        $foreignBrand = Brand::create([
            'company_id' => $company2->id,
            'name_ar' => 'علامة أجنبية',
            'name_en' => 'Foreign Brand',
            'active' => true,
        ]);

        // Return to Company 1
        $this->activate($this->owner);

        // Attempting to edit foreign brand fails with 404
        Livewire::test(BrandIndex::class)
            ->call('openEditModal', $foreignBrand->id)
            ->assertNotFound();
    }

    public function test_whitespace_only_name_cannot_save(): void
    {
        Livewire::test(BrandIndex::class)
            ->call('openCreateModal')
            ->set('name_ar', '   ')
            ->set('name_en', '   ')
            ->call('save')
            ->assertHasErrors(['name_ar'])
            ->assertSet('showModal', true);

        $this->assertSame(0, Brand::where('company_id', $this->company->id)->count());
    }

    public function test_bilingual_search_and_pagination(): void
    {
        Brand::create(['company_id' => $this->company->id, 'name_ar' => 'سامسونج', 'name_en' => 'Samsung', 'active' => true]);
        Brand::create(['company_id' => $this->company->id, 'name_ar' => 'أبل', 'name_en' => 'Apple', 'active' => true]);

        // Search Arabic
        Livewire::test(BrandIndex::class)
            ->set('search', 'سامسونج')
            ->assertViewHas('brands', function ($brands) {
                return $brands->count() === 1 && $brands->first()->name_ar === 'سامسونج';
            });

        // Search English
        Livewire::test(BrandIndex::class)
            ->set('search', 'Apple')
            ->assertViewHas('brands', function ($brands) {
                return $brands->count() === 1 && $brands->first()->name_en === 'Apple';
            });

        // Non matching
        Livewire::test(BrandIndex::class)
            ->set('search', 'NonExistentBrand')
            ->assertViewHas('brands', function ($brands) {
                return $brands->isEmpty();
            });

        // Test pagination at 15 items
        for ($i = 1; $i <= 16; $i++) {
            Brand::create([
                'company_id' => $this->company->id,
                'name_ar' => sprintf('علامة %02d', $i),
                'name_en' => sprintf('Brand %02d', $i),
                'active' => true,
            ]);
        }

        Livewire::test(BrandIndex::class)
            ->assertViewHas('brands', function ($brands) {
                return $brands->count() === 15 && $brands->total() === 18;
            });
    }

    public function test_creation_edit_and_active_toggle(): void
    {
        $test = Livewire::test(BrandIndex::class)
            ->call('openCreateModal')
            ->assertSet('showModal', true)
            ->set('name_ar', 'توشيبا')
            ->set('name_en', 'Toshiba')
            ->set('active', true)
            ->call('save')
            ->assertSet('showModal', false)
            ->assertSet('successMessage', __('inventory.brand_saved_success'));

        $brand = Brand::where('company_id', $this->company->id)->where('name_ar', 'توشيبا')->first();
        $this->assertNotNull($brand);
        $this->assertSame('Toshiba', $brand->name_en);
        $this->assertTrue($brand->active);

        // Edit
        $test->call('openEditModal', $brand->id)
            ->assertSet('showModal', true)
            ->assertSet('editingId', $brand->id)
            ->assertSet('name_ar', 'توشيبا')
            ->assertSet('name_en', 'Toshiba')
            ->set('name_en', 'Toshiba Japan')
            ->call('save')
            ->assertSet('showModal', false);

        $brand->refresh();
        $this->assertSame('Toshiba Japan', $brand->name_en);

        // Toggle active
        $test->call('toggleActive', $brand->id);
        $brand->refresh();
        $this->assertFalse($brand->active);

        $test->call('toggleActive', $brand->id);
        $brand->refresh();
        $this->assertTrue($brand->active);
    }

    public function test_products_count_is_eagerly_computed(): void
    {
        $brand = Brand::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سوني',
            'name_en' => 'Sony',
            'active' => true,
        ]);

        $this->product->update(['brand_id' => $brand->id]);

        Livewire::test(BrandIndex::class)
            ->assertViewHas('brands', function ($brands) use ($brand) {
                $found = $brands->firstWhere('id', $brand->id);

                return $found && (int) $found->products_count === 1;
            });
    }

    public function test_product_index_filters_by_brand_and_includes_inactive_brands(): void
    {
        $brandActive = Brand::create(['company_id' => $this->company->id, 'name_ar' => 'علامة نشطة', 'name_en' => 'Active Brand', 'active' => true]);
        $brandInactive = Brand::create(['company_id' => $this->company->id, 'name_ar' => 'علامة قديمة', 'name_en' => 'Old Brand', 'active' => false]);

        $productA = $this->product;
        $productA->update(['brand_id' => $brandActive->id]);

        $productInactiveBrand = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صنف قديم',
            'name_en' => 'Old Item',
            'sku' => 'OLD-SKU',
            'brand_id' => $brandInactive->id,
            'base_unit_id' => $productA->base_unit_id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
        ], $this->owner->id);

        Livewire::test(ProductIndex::class)
            ->assertViewHas('brands', function ($brands) use ($brandActive, $brandInactive) {
                return $brands->contains('id', $brandActive->id) && $brands->contains('id', $brandInactive->id);
            })
            ->set('brandFilter', (string) $brandActive->id)
            ->assertViewHas('products', function ($products) use ($productA) {
                return $products->count() === 1 && $products->first()->id === $productA->id;
            })
            ->set('brandFilter', (string) $brandInactive->id)
            ->assertViewHas('products', function ($products) use ($productInactiveBrand) {
                return $products->count() === 1 && $products->first()->id === $productInactiveBrand->id;
            })
            ->set('brandFilter', '')
            ->assertViewHas('products', function ($products) {
                return $products->count() >= 2;
            });
    }

    public function test_product_form_inline_brand_creation_preserves_unsaved_fields(): void
    {
        $component = Livewire::test(ProductForm::class)
            ->set('name_ar', 'منتج غير محفوظ')
            ->set('name_en', 'Unsaved Product')
            ->set('sku', 'UNSAVED-SKU-999')
            ->set('suggested_sale_price', '249.99')
            // Open modal
            ->call('openCreateBrandModal')
            ->assertSet('showBrandModal', true)
            // Enter brand details
            ->set('new_brand_name_ar', 'علامة تم إنشاؤها فوراً')
            ->set('new_brand_name_en', 'Instant Brand')
            ->call('saveInlineBrand')
            ->assertSet('showBrandModal', false)
            ->assertSet('successMessage', __('inventory.brand_saved_success'));

        $newBrand = Brand::where('company_id', $this->company->id)->where('name_ar', 'علامة تم إنشاؤها فوراً')->first();
        $this->assertNotNull($newBrand);

        // Verify newly created brand is selected
        $component->assertSet('brand_id', $newBrand->id);

        // Verify all unsaved product fields were preserved intact
        $component->assertSet('name_ar', 'منتج غير محفوظ')
            ->assertSet('name_en', 'Unsaved Product')
            ->assertSet('sku', 'UNSAVED-SKU-999')
            ->assertSet('suggested_sale_price', '249.99');
    }

    public function test_product_form_inline_brand_deduplication_on_retry(): void
    {
        $brandExisting = Brand::create([
            'company_id' => $this->company->id,
            'name_ar' => 'ماركة موجودة',
            'name_en' => 'Existing Brand',
            'active' => true,
        ]);

        $countBefore = Brand::count();

        Livewire::test(ProductForm::class)
            ->call('openCreateBrandModal')
            ->set('new_brand_name_ar', '  ماركة موجودة  ')
            ->set('new_brand_name_en', '  Existing Brand  ')
            ->call('saveInlineBrand')
            ->assertSet('brand_id', $brandExisting->id);

        // Deduplicated: no duplicate brand row inserted
        $this->assertSame($countBefore, Brand::count());
    }

    public function test_product_form_inline_company_switch_denies_and_prevents_insertion(): void
    {
        $component = Livewire::test(ProductForm::class)
            ->set('name_ar', 'منتج قيد الكتابة')
            ->set('new_brand_name_ar', 'علامة أثناء التبديل')
            ->set('new_brand_name_en', 'Switch Brand');

        // Switch company in active context to Company 2
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'en']);
        $company2 = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة ثانية',
            'name_en' => 'Second Co',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($company2, $otherOwner);
        $this->actingAs($otherOwner);

        $countBefore = Brand::count();

        $component->call('saveInlineBrand')->assertForbidden();

        $this->assertSame($countBefore, Brand::count());
        $this->assertSame(0, Brand::where('name_ar', 'علامة أثناء التبديل')->count());
    }

    public function test_product_form_brand_selection_includes_current_inactive_brand_when_editing(): void
    {
        $inactiveBrand = Brand::create([
            'company_id' => $this->company->id,
            'name_ar' => 'علامة متوقفة',
            'name_en' => 'Discontinued Brand',
            'active' => false,
        ]);

        $this->product->update(['brand_id' => $inactiveBrand->id]);

        Livewire::test(ProductForm::class, ['publicId' => $this->product->public_id])
            ->assertSet('brand_id', $inactiveBrand->id)
            ->assertViewHas('brands', function ($brands) use ($inactiveBrand) {
                return $brands->contains('id', $inactiveBrand->id);
            });
    }
}
