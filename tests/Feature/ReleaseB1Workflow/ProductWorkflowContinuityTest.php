<?php

declare(strict_types=1);

namespace Tests\Feature\ReleaseB1Workflow;

use App\Actions\Company\CreateCompanyAction;
use App\Livewire\Pages\Products\ProductForm;
use App\Models\Product;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Livewire\Livewire;
use Tests\Feature\Phase5E\Phase5ETestCase;

class ProductWorkflowContinuityTest extends Phase5ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:6CUe2hhgD04d5Aic4q6P5q8n3mQ/v3Z9Dk4e2vW+1o8=']);
    }

    public function test_failed_validation_no_redirect_and_no_product_created(): void
    {
        $this->activate($this->owner);
        $initialCount = Product::where('company_id', $this->company->id)->count();

        Livewire::test(ProductForm::class)
            ->set('name_ar', '')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->call('save', true)
            ->assertHasErrors(['name_ar'])
            ->assertNoRedirect();

        $this->assertSame($initialCount, Product::where('company_id', $this->company->id)->count());
    }

    public function test_successful_save_and_add_another_redirects_and_creates_consecutive_products(): void
    {
        $this->activate($this->owner);

        // Product 1 creation with Save & Add Another
        $form1 = Livewire::test(ProductForm::class)
            ->set('name_ar', 'منتج متتالي أول')
            ->set('name_en', 'Consecutive Product 1')
            ->set('sku', 'CONSEC-001')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->set('track_stock', true)
            ->set('track_expiry', false)
            ->set('active', true)
            ->set('suggested_sale_price', '50.00')
            ->call('save', true)
            ->assertHasNoErrors()
            ->assertRedirect(route('products.create'));

        $product1 = Product::where('company_id', $this->company->id)->where('sku', 'CONSEC-001')->first();
        $this->assertNotNull($product1);
        $this->assertSame('منتج متتالي أول', $product1->name_ar);

        // Simulate subsequent page load after redirect with flashed message
        session()->flash('product_saved_success', __('inventory.product_saved_success'));

        $form2 = Livewire::test(ProductForm::class);
        $this->assertSame(__('inventory.product_saved_success'), $form2->get('successMessage'));
        $this->assertFalse($form2->get('isEditing'));

        // Product 2 creation using normal Save
        $form2->set('name_ar', 'منتج متتالي ثانٍ')
            ->set('name_en', 'Consecutive Product 2')
            ->set('sku', 'CONSEC-002')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->set('track_stock', true)
            ->set('track_expiry', false)
            ->set('active', true)
            ->set('suggested_sale_price', '75.00')
            ->call('save', false)
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $product2 = Product::where('company_id', $this->company->id)->where('sku', 'CONSEC-002')->first();
        $this->assertNotNull($product2);
        $this->assertSame('منتج متتالي ثانٍ', $product2->name_ar);
    }

    public function test_normal_save_retains_page_in_editing_mode_with_alternate_units_and_actions(): void
    {
        $this->activate($this->owner);

        $boxUnit = Unit::where('company_id', $this->company->id)
            ->where('id', '!=', $this->product->base_unit_id)
            ->firstOrFail();

        $component = Livewire::test(ProductForm::class)
            ->set('name_ar', 'منتج حفظ عادي')
            ->set('name_en', 'Normal Save Product')
            ->set('sku', 'NORM-001')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->set('track_stock', true)
            ->set('track_expiry', false)
            ->set('active', true)
            ->call('save', false)
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertTrue($component->get('isEditing'));
        $createdProduct = $component->get('product');
        $this->assertInstanceOf(Product::class, $createdProduct);
        $this->assertSame(__('inventory.product_saved_success'), $component->get('successMessage'));

        // View assertions: contains View Product and Add New links
        $component->assertSee(__('release_b1_workflow.view_product'))
            ->assertSee(__('release_b1_workflow.add_new_product'))
            ->assertSee(route('products.show', $createdProduct->public_id))
            ->assertSee(route('products.create'));

        // Alternate units section is active and accessible
        $component->set('new_alt_unit_id', $boxUnit->id)
            ->set('new_alt_conversion', '12')
            ->call('addAlternateUnit')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('product_units', [
            'product_id' => $createdProduct->id,
            'unit_id' => $boxUnit->id,
            'conversion_to_base' => '12.000000',
        ]);
    }

    public function test_repeated_action_does_not_create_duplicate_product(): void
    {
        $this->activate($this->owner);

        $component = Livewire::test(ProductForm::class)
            ->set('name_ar', 'منتج منع التكرار')
            ->set('sku', 'NODUP-001')
            ->set('base_unit_id', $this->product->base_unit_id)
            ->set('product_type', Product::TYPE_STOCK)
            ->set('track_stock', true)
            ->set('track_expiry', false)
            ->set('active', true)
            ->call('save', false)
            ->assertHasNoErrors();

        $initialCount = Product::where('company_id', $this->company->id)->where('sku', 'NODUP-001')->count();
        $this->assertSame(1, $initialCount);

        // Repeated save call on the same component instance updates rather than duplicates
        $component->set('name_ar', 'منتج منع التكرار المعدل')
            ->call('save', false)
            ->assertHasNoErrors();

        $this->assertSame(1, Product::where('company_id', $this->company->id)->where('sku', 'NODUP-001')->count());
        $this->assertSame('منتج منع التكرار المعدل', Product::where('company_id', $this->company->id)->where('sku', 'NODUP-001')->value('name_ar'));

        // Repeated save call via saveAndAddAnother
        $component->call('saveAndAddAnother')
            ->assertHasNoErrors()
            ->assertRedirect(route('products.create'));

        $this->assertSame(1, Product::where('company_id', $this->company->id)->where('sku', 'NODUP-001')->count());
    }

    public function test_permission_and_revoked_membership_and_cross_company(): void
    {
        // 1. User without inventory.product.manage cannot access/create product form
        $noManageUser = $this->customActor(['inventory.stock.view']);
        $this->activate($noManageUser);

        Livewire::actingAs($noManageUser)
            ->test(ProductForm::class)
            ->assertForbidden();

        // 2. User with revoked membership (inactive status) is forbidden
        $manageUser = $this->customActor(['inventory.product.manage', 'inventory.stock.view']);
        $this->activate($manageUser);
        $this->company->users()->updateExistingPivot($manageUser->id, ['status' => 'inactive']);

        Livewire::actingAs($manageUser)
            ->test(ProductForm::class)
            ->assertForbidden();

        // 3. Cross-company context: user in Company B cannot access Company A's product form
        app(CompanyContext::class)->clear();
        $otherOwner = User::factory()->create(['locale' => 'ar']);
        $companyB = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Co',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($companyB, $otherOwner);
        $this->actingAs($otherOwner);
        setPermissionsTeamId($companyB->id);

        Livewire::actingAs($otherOwner)
            ->test(ProductForm::class, ['publicId' => $this->product->public_id])
            ->assertNotFound();
    }
}
