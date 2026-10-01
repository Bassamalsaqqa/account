<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\HistoricalConversionLockedException;
use App\Domain\Inventory\Exceptions\InvalidQuantityException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\Services\UnitConversionService;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
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
use Tests\TestCase;

class UnitConversionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Unit $unitCarton;

    protected Product $product;

    protected UnitConversionService $conversionService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب التحويل',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->unitCarton = Unit::where('company_id', $this->company->id)->where('code', 'carton')->firstOrFail();

        $this->actingAs($this->user);

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'زيت زيتون عبوات',
            'sku' => 'OIL-CARTON-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        $this->conversionService = app(UnitConversionService::class);
    }

    public function test_carton_conversion_to_and_from_base(): void
    {
        // 1 carton = 12 pieces
        $productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitCarton->id,
            'conversion_to_base' => '12.000000',
            'is_default_sale' => true,
            'is_default_purchase' => true,
        ]);

        // 20 cartons = 240 pieces
        $cartonsQty = Quantity::of(20);
        $basePieces = $this->conversionService->toBase($cartonsQty, $productUnit);
        $this->assertSame('240.000000', $basePieces->toScale());

        // Reverse: 240 pieces = 20 cartons
        $convertedBack = $this->conversionService->fromBase($basePieces, $productUnit);
        $this->assertSame('20.000000', $convertedBack->toScale());

        // Issue 3 cartons + 4 pieces = (3 * 12) + 4 = 40 pieces base
        $threeCartons = $this->conversionService->toBase(Quantity::of(3), $productUnit);
        $fourPieces = Quantity::of(4);
        $totalIssuedBase = $threeCartons->add($fourPieces);
        $this->assertSame('40.000000', $totalIssuedBase->toScale());
    }

    public function test_fractional_unit_validation_rejects_fractions_when_not_allowed(): void
    {
        // Piece does not allow fractions by default (allows_fraction = false)
        $this->assertFalse($this->unitPiece->allows_fraction);

        $validQty = Quantity::of(5);
        $validQty->validateUnitConstraints($this->unitPiece); // Should not throw

        $invalidQty = Quantity::of('5.5');
        $this->expectException(InvalidQuantityException::class);
        $invalidQty->validateUnitConstraints($this->unitPiece);
    }

    public function test_zero_or_negative_conversion_factor_is_strictly_rejected(): void
    {
        $productUnitZero = new ProductUnit([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitCarton->id,
            'conversion_to_base' => '0.000000',
        ]);

        $this->expectException(InvalidUnitConversionException::class);
        $this->conversionService->toBase(Quantity::of(10), $productUnitZero);
    }

    public function test_negative_conversion_factor_is_strictly_rejected(): void
    {
        $productUnitNegative = new ProductUnit([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitCarton->id,
            'conversion_to_base' => '-5.000000',
        ]);

        $this->expectException(InvalidUnitConversionException::class);
        $this->conversionService->toBase(Quantity::of(10), $productUnitNegative);
    }

    public function test_historical_conversion_factor_is_locked_once_stock_movements_exist(): void
    {
        $productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->unitCarton->id,
            'conversion_to_base' => '12.000000',
            'is_default_sale' => true,
            'is_default_purchase' => true,
        ]);

        // Before any movements, mutation assertion succeeds without exception
        $this->conversionService->assertCanMutateConversion($this->product, $this->unitCarton->id);

        // Record a stock movement for this product
        $cmd = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: '2026-10-01',
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of(100),
                    unitCostBase: '5.000000',
                ),
            ],
            sourceType: 'manual_test',
            sourceId: 1,
            idempotencyKey: (string) Str::ulid(),
            createdBy: $this->user->id,
        );
        app(InventoryMovementService::class)->record($cmd);

        // Now mutating conversion factor must throw HistoricalConversionLockedException
        $this->expectException(HistoricalConversionLockedException::class);
        $this->conversionService->assertCanMutateConversion($this->product, $this->unitCarton->id);
    }
}
