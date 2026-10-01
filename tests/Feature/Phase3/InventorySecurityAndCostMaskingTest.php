<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use App\Livewire\Pages\Inventory\StockMovementIndex;
use App\Livewire\Pages\Products\ProductDetail;
use App\Livewire\Pages\Products\ProductForm;
use App\Livewire\Pages\Products\ProductIndex;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class InventorySecurityAndCostMaskingTest extends TestCase
{
    use RefreshDatabase;

    protected User $ownerUser;

    protected User $warehouseUser;

    protected User $foreignUser;

    protected Company $companyA;

    protected Company $companyB;

    protected Warehouse $warehouseA;

    protected Product $productA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownerUser = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($this->ownerUser);

        $creator = app(CreateCompanyAction::class);

        $this->companyA = $creator->execute($this->ownerUser, [
            'name_ar' => 'شركة الأمان أ',
            'base_currency_code' => 'ILS',
        ]);

        $this->foreignUser = User::factory()->create(['locale' => 'ar']);
        $this->companyB = $creator->execute($this->foreignUser, [
            'name_ar' => 'شركة الأمان ب',
            'base_currency_code' => 'ILS',
        ]);

        // Create warehouse operator in Company A (without inventory.cost.view)
        $this->warehouseUser = User::factory()->create(['locale' => 'ar']);
        CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->warehouseUser->id,
            'role' => 'Warehouse',
            'status' => 'active',
        ]);

        // Assign Spatie Warehouse role to warehouseUser in companyA team
        setPermissionsTeamId($this->companyA->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->companyA->id);
        $warehouseRole = Role::where('company_id', $this->companyA->id)->where('name', 'Warehouse')->firstOrFail();
        $this->warehouseUser->assignRole($warehouseRole);

        // Switch to Company A context
        app(CompanyContext::class)->setCompany($this->companyA, $this->ownerUser);

        $this->warehouseA = Warehouse::where('company_id', $this->companyA->id)->firstOrFail();
        $unit = Unit::where('company_id', $this->companyA->id)->where('code', 'piece')->firstOrFail();

        $this->productA = Product::create([
            'company_id' => $this->companyA->id,
            'name_ar' => 'منتج سري التكلفة',
            'sku' => 'SECRET-COST-001',
            'base_unit_id' => $unit->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_purchase_cost_base' => '75.500000',
            'default_sale_price_base' => '120.000000',
            'active' => true,
            'created_by' => $this->ownerUser->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->companyA->id,
            'product_id' => $this->productA->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        // Post opening stock with sensitive cost: 10 @ 75.50 = 755.00 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $this->companyA,
            product: $this->productA,
            warehouse: $this->warehouseA,
            quantity: Quantity::of(10),
            unitCostBase: '75.500000',
            user: $this->ownerUser,
            idempotencyKey: (string) Str::ulid(),
        );
    }

    public function test_product_index_masks_cost_data_from_unauthorized_users(): void
    {
        // 1. Warehouse operator without inventory.cost.view
        app(CompanyContext::class)->setCompany($this->companyA, $this->warehouseUser);
        $this->actingAs($this->warehouseUser);

        $component = Livewire::test(ProductIndex::class);
        $component->assertStatus(200);
        $component->assertViewHas('canViewCost', false);
        // Cost values must NOT be rendered in response HTML
        $component->assertDontSee('75.50');
        $component->assertDontSee('755.00');

        // 2. Owner with inventory.cost.view
        app(CompanyContext::class)->setCompany($this->companyA, $this->ownerUser);
        $this->actingAs($this->ownerUser);

        $componentOwner = Livewire::test(ProductIndex::class);
        $componentOwner->assertStatus(200);
        $componentOwner->assertViewHas('canViewCost', true);
        $componentOwner->assertSee('75.50');
    }

    public function test_product_detail_masks_cost_state_card_from_unauthorized_users(): void
    {
        // 1. Warehouse operator without inventory.cost.view
        app(CompanyContext::class)->setCompany($this->companyA, $this->warehouseUser);
        $this->actingAs($this->warehouseUser);

        $component = Livewire::test(ProductDetail::class, ['publicId' => $this->productA->public_id]);
        $component->assertStatus(200);
        $component->assertViewHas('canViewCost', false);
        $component->assertViewHas('costState', null);
        $component->assertDontSee('75.50');
        $component->assertDontSee('755.00');

        // Check movements tab
        $component->call('setTab', 'movements');
        $component->assertDontSee('75.50');
        $component->assertDontSee('755.00');
        $this->assertStringNotContainsString('75.50', $component->html());

        // 2. Owner with inventory.cost.view
        app(CompanyContext::class)->setCompany($this->companyA, $this->ownerUser);
        $this->actingAs($this->ownerUser);

        $componentOwner = Livewire::test(ProductDetail::class, ['publicId' => $this->productA->public_id]);
        $componentOwner->assertStatus(200);
        $componentOwner->assertViewHas('canViewCost', true);
        $componentOwner->assertViewHas('costState');
        $componentOwner->assertSee('75.50');
    }

    public function test_product_form_does_not_expose_purchase_cost_to_unauthorized_user(): void
    {
        app(CompanyContext::class)->setCompany($this->companyA, $this->warehouseUser);
        $this->actingAs($this->warehouseUser);

        $component = Livewire::test(ProductForm::class, ['publicId' => $this->productA->public_id]);
        $component->assertStatus(200);
        $component->assertViewHas('canViewCost', false);
        // Purchase cost field must be masked/empty for unauthorized user
        $component->assertSet('suggested_purchase_cost', '');
        $component->assertDontSee('75.50');
    }

    public function test_stock_movement_index_masks_unit_cost_from_unauthorized_users(): void
    {
        // 1. Warehouse operator without inventory.cost.view
        app(CompanyContext::class)->setCompany($this->companyA, $this->warehouseUser);
        $this->actingAs($this->warehouseUser);

        $component = Livewire::test(StockMovementIndex::class);
        $component->assertStatus(200);
        $component->assertViewHas('canViewCost', false);
        $component->assertDontSee('75.50');

        // 2. Owner with inventory.cost.view
        app(CompanyContext::class)->setCompany($this->companyA, $this->ownerUser);
        $this->actingAs($this->ownerUser);

        $componentOwner = Livewire::test(StockMovementIndex::class);
        $componentOwner->assertStatus(200);
        $componentOwner->assertViewHas('canViewCost', true);
        $componentOwner->assertSee('75.50');
    }

    public function test_expiry_center_masks_valuation_from_unauthorized_users(): void
    {
        app(CompanyContext::class)->setCompany($this->companyA, $this->warehouseUser);
        $this->actingAs($this->warehouseUser);

        $component = Livewire::test(ExpiryCenter::class);
        $component->assertStatus(200);
        $component->assertViewHas('canViewCost', false);
        $component->assertDontSee('التقييم التقريبي');
    }

    public function test_cross_tenant_isolation_prevents_access_to_other_company_product(): void
    {
        // Foreign user belongs to Company B, trying to access Product A (Company A)
        app(CompanyContext::class)->setCompany($this->companyB, $this->foreignUser);
        $this->actingAs($this->foreignUser);
        $this->foreignUser->update(['last_active_company_id' => $this->companyB->id]);

        $response = $this->get("/products/{$this->productA->public_id}");
        $response->assertNotFound();
    }
}
