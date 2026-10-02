<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\HistoricalConversionLockedException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Catalog\UnitIndex;
use App\Livewire\Pages\Catalog\WarehouseIndex;
use App\Livewire\Pages\Products\ProductForm;
use App\Models\Company;
use App\Models\CompanyInventorySettings;
use App\Models\InventoryLot;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogInvariantsAndDefaultWarehouseTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Unit $unitBox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب الأصناف والمستودعات',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->unitBox = Unit::where('company_id', $this->company->id)->where('code', 'box')->firstOrFail();
    }

    public function test_product_creation_provisions_base_unit_row_with_factor_one(): void
    {
        Livewire::test(ProductForm::class)
            ->set('name_ar', 'مسحوق غسيل 3 كجم')
            ->set('sku', 'WASH-3KG')
            ->set('base_unit_id', $this->unitPiece->id)
            ->set('product_type', 'stock')
            ->set('track_stock', true)
            ->set('track_expiry', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee(__('inventory.product_saved_success'));

        /** @var Product $product */
        $product = Product::where('sku', 'WASH-3KG')->firstOrFail();

        // Exactly one ProductUnit base row must exist
        $baseUnitRow = ProductUnit::where('product_id', $product->id)
            ->where('unit_id', $this->unitPiece->id)
            ->first();

        $this->assertNotNull($baseUnitRow);
        $this->assertSame('1.000000', $baseUnitRow->conversion_to_base);
        $this->assertTrue((bool) $baseUnitRow->is_base);
        $this->assertTrue((bool) $baseUnitRow->active);
    }

    public function test_cannot_mutate_conversion_factor_when_movements_exist(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'شوكولاتة بالحليب',
            'sku' => 'CHOC-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'active' => true,
        ]);

        $boxUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitBox->id,
            'conversion_to_base' => '12.000000',
            'is_base' => false,
            'active' => true,
        ]);

        // Record a stock movement using this product
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(10),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 201,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id
        ));

        // Attempting to mutate conversion factor must throw HistoricalConversionLockedException
        $this->expectException(HistoricalConversionLockedException::class);
        app(UnitConversionService::class)->assertCanMutateConversion($product, $this->unitBox->id, '24.000000');
    }

    public function test_cannot_remove_alternate_unit_with_movement_history(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'بسكويت ويفر',
            'sku' => 'WAFER-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'active' => true,
        ]);

        $boxUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitBox->id,
            'conversion_to_base' => '20.000000',
            'is_base' => false,
            'active' => true,
        ]);

        // Record a movement referencing this alternate unit
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(5),
                    unitCostBase: '50.000000',
                    unitId: $this->unitBox->id,
                ),
            ],
            sourceType: 'opening_balance',
            sourceId: 202,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id
        ));

        // Removing via ProductForm must show error and preserve the unit
        Livewire::test(ProductForm::class, ['publicId' => $product->public_id])
            ->call('removeAlternateUnit', $boxUnit->id)
            ->assertSee('لا يمكن حذف وحدة قياس لها حركات مخزنية مسجلة');

        $this->assertDatabaseHas('product_units', [
            'id' => $boxUnit->id,
        ]);
    }

    public function test_cannot_disable_track_expiry_when_lots_exist(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'حليب طويل الأجل',
            'sku' => 'MILK-UHT-01',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'active' => true,
        ]);

        InventoryLot::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'lot_number' => 'LOT-2026-001',
            'expiry_date' => '2027-06-30',
            'received_date' => '2026-10-01',
            'source_type' => 'opening_stock',
            'source_id' => 1,
        ]);

        // Attempt to disable track_expiry in ProductForm
        Livewire::test(ProductForm::class, ['publicId' => $product->public_id])
            ->set('track_expiry', false)
            ->call('save')
            ->assertSee('لا يمكن إلغاء تتبع الصلاحية لوجود دفعات مسجلة مسبقاً');

        $product->refresh();
        $this->assertTrue((bool) $product->track_expiry);
    }

    public function test_cannot_deactivate_or_unset_sole_default_warehouse(): void
    {
        // Sole default warehouse is $this->warehouse
        Livewire::test(WarehouseIndex::class)
            ->call('openEditModal', $this->warehouse->id)
            ->set('active', false)
            ->set('is_default', false)
            ->call('save')
            ->assertSee('لا يمكن');

        $this->warehouse->refresh();
        $this->assertTrue((bool) $this->warehouse->is_default);
        $this->assertTrue((bool) $this->warehouse->active);
    }

    public function test_setting_new_default_warehouse_atomically_updates_previous_and_settings(): void
    {
        // Create second warehouse
        $warehouse2 = Warehouse::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مستودع الخليل الجديد',
            'code' => 'WH-HEB',
            'is_default' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        // Edit warehouse2 and set is_default = true
        Livewire::test(WarehouseIndex::class)
            ->call('openEditModal', $warehouse2->id)
            ->set('is_default', true)
            ->call('save')
            ->assertHasNoErrors();

        $this->warehouse->refresh();
        $warehouse2->refresh();

        $this->assertFalse((bool) $this->warehouse->is_default);
        $this->assertTrue((bool) $warehouse2->is_default);

        $settings = CompanyInventorySettings::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($warehouse2->id, $settings->default_warehouse_id);
    }

    public function test_pre_history_base_unit_change_reconciles_existing_alternate_unit(): void
    {
        $product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'صنف تعديل الوحدة',
            'sku' => 'UNIT-SWAP-01',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'active' => true,
        ]);

        $altUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $this->unitBox->id,
            'conversion_to_base' => '12.000000',
            'is_base' => false,
            'active' => true,
        ]);

        // 1. Must reject when alternate units exist
        Livewire::test(ProductForm::class, ['publicId' => $product->public_id])
            ->set('base_unit_id', $this->unitBox->id)
            ->call('save')
            ->assertSet('errorMessage', "Cannot change base unit: product [{$product->id}] has alternate unit configurations. Alternate units must be removed first.");

        $product->refresh();
        $this->assertSame($this->unitPiece->id, $product->base_unit_id);

        // 2. Remove alternate unit -> Product is now pristine -> Base change succeeds
        $altUnit->delete();

        Livewire::test(ProductForm::class, ['publicId' => $product->public_id])
            ->set('base_unit_id', $this->unitBox->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('errorMessage', null);

        $product->refresh();
        $this->assertSame($this->unitBox->id, $product->base_unit_id);

        $baseRows = ProductUnit::where('product_id', $product->id)->where('is_base', true)->get();
        $this->assertCount(1, $baseRows, 'Pristine base unit change must leave exactly one base row.');
        $this->assertSame($this->unitBox->id, $baseRows->first()->unit_id);
        $this->assertSame('1.000000', $baseRows->first()->conversion_to_base);
    }

    public function test_unit_index_correctly_renders_allows_fraction_status_in_arabic_and_english(): void
    {
        // 1. Arabic locale
        app()->setLocale('ar');
        $arTest = Livewire::test(UnitIndex::class);
        $arHtml = $arTest->html();

        // Fraction units show Yes (نعم)
        $this->assertStringContainsString('كيلوغرام', $arHtml);
        $this->assertStringContainsString('لتر', $arHtml);
        $this->assertStringContainsString('متر', $arHtml);
        $this->assertStringContainsString('غرام', $arHtml);
        $this->assertStringContainsString('نعم', $arHtml);

        // Discrete units show No (لا)
        $this->assertStringContainsString('قطعة', $arHtml);
        $this->assertStringContainsString('كرتونة', $arHtml);
        $this->assertStringContainsString('صندوق', $arHtml);
        $this->assertStringContainsString('لا', $arHtml);

        // 2. English locale
        app()->setLocale('en');
        $enTest = Livewire::test(UnitIndex::class);
        $enHtml = $enTest->html();

        // Fraction units show Yes
        $this->assertStringContainsString('Kilogram', $enHtml);
        $this->assertStringContainsString('Liter', $enHtml);
        $this->assertStringContainsString('Meter', $enHtml);
        $this->assertStringContainsString('Yes', $enHtml);

        // Discrete units show No
        $this->assertStringContainsString('Piece', $enHtml);
        $this->assertStringContainsString('Carton', $enHtml);
        $this->assertStringContainsString('No', $enHtml);
    }
}
