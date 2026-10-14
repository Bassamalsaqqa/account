<?php

declare(strict_types=1);

namespace Tests\Feature\ProductExperience;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\UpdateCompanyCurrenciesAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\DisposeExpiredStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Inventory\ExpiryCenter;
use App\Livewire\Pages\Inventory\InventoryOverview;
use App\Livewire\Pages\Inventory\OpeningStockForm;
use App\Livewire\Pages\Inventory\StockAdjustmentForm;
use App\Livewire\Pages\Inventory\StockTransferForm;
use App\Models\Company;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AuditInventoryCompanyDateTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private Product $product;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow(Carbon::parse('2026-12-31 22:30:00', 'UTC'));
        $this->owner = User::factory()->create();
        $this->actingAs($this->owner);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'Synthetic inventory date audit',
            'base_currency_code' => 'ILS',
            'timezone' => 'Asia/Hebron',
        ]);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $unit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->product = Product::create([
            'company_id' => $this->company->id, 'name_ar' => 'Synthetic audit product',
            'sku' => 'AUDIT-DATE', 'base_unit_id' => $unit->id, 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'track_expiry' => true, 'active' => true,
            'created_by' => $this->owner->id,
        ]);
        ProductUnit::create([
            'company_id' => $this->company->id, 'product_id' => $this->product->id,
            'unit_id' => $unit->id, 'conversion_to_base' => '1.000000',
            'is_base' => true, 'is_default_sale' => true, 'is_default_purchase' => true, 'active' => true,
        ]);
        $this->assertSame('2026-12-31', now()->toDateString());
        $this->assertSame('2027-01-01', now($this->company->timezone)->toDateString());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_opening_and_adjustment_default_to_company_date_with_matching_ledger_dates(): void
    {
        $opening = $this->opening();
        $this->assertSame('2027-01-01', $opening['movement']->movement_date->toDateString());
        $this->assertSame('2027-01-01', $opening['batch']->posting_date->toDateString());
        $adjustment = app(AdjustStockAction::class)->execute(
            company: $this->company, product: $this->product, warehouse: $this->warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE, quantity: Quantity::of('1'),
            reason: 'Synthetic midnight check', user: $this->owner, idempotencyKey: 'audit-adjust',
            unitCostBase: '1', lotNumber: 'AUDIT-ADJUST', expiryDate: '2027-02-01',
        );
        $this->assertSame('2027-01-01', $adjustment['movement']->movement_date->toDateString());
        $this->assertSame('2027-01-01', $adjustment['batch']->posting_date->toDateString());
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }

    public function test_disposal_uses_company_date_after_the_lot_last_valid_day(): void
    {
        $opening = $this->opening('2026-12-30', '2026-12-31');
        $result = app(DisposeExpiredStockAction::class)->execute(
            company: $this->company, product: $this->product, warehouse: $this->warehouse,
            lotId: $opening['movement']->lot_id, quantity: Quantity::of('1'),
            reason: 'Synthetic expired lot', user: $this->owner, idempotencyKey: 'audit-dispose',
        );
        $this->assertSame('2027-01-01', $result['movement']->movement_date->toDateString());
        $this->assertSame('2027-01-01', $result['batch']->posting_date->toDateString());
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }

    public function test_explicit_business_date_is_preserved(): void
    {
        $opening = $this->opening('2026-12-29');
        $this->assertSame('2026-12-29', $opening['movement']->movement_date->toDateString());
        $this->assertSame('2026-12-29', $opening['batch']->posting_date->toDateString());
    }

    public function test_inventory_form_and_disposal_defaults_use_company_date(): void
    {
        foreach ([OpeningStockForm::class, StockAdjustmentForm::class, StockTransferForm::class] as $form) {
            Livewire::test($form)->assertSet('movement_date', '2027-01-01');
        }
        Livewire::test(ExpiryCenter::class)->assertSet('disposalDate', '2027-01-01');
    }

    public function test_expiry_filter_uses_company_calendar_and_fractional_valuation_renders(): void
    {
        $this->opening('2026-12-30', '2026-12-31', '0.123456');
        Livewire::test(ExpiryCenter::class)
            ->set('timeframeFilter', 'expired')
            ->assertViewHas('lotBalances', fn ($lots) => $lots->count() === 1)
            ->assertSee(__('inventory.expired'));
        Livewire::test(InventoryOverview::class)->assertViewHas('totalValuation', '0.12');
    }

    public function test_inventory_valuation_uses_jod_three_minor_units_without_changing_history(): void
    {
        $this->company = app(UpdateCompanyCurrenciesAction::class)->execute(
            $this->company, 'JOD', ['ILS' => true, 'JOD' => true], $this->owner,
        );
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $opening = $this->opening('2026-12-30', cost: '0.123456');
        $this->assertSame('0.123456', $opening['movement']->value_delta_base);
        Livewire::test(InventoryOverview::class)->assertViewHas('totalValuation', '0.123');
        Livewire::test(ExpiryCenter::class)->assertViewHas('lotValuations', fn ($values) => array_values($values) === ['0.123']);
        $this->assertSame('0.123456', $opening['movement']->fresh()->value_delta_base);
    }

    /** @return array{movement: StockMovement, batch: PostingBatch|null} */
    private function opening(?string $date = null, string $expiry = '2027-02-01', string $cost = '1'): array
    {
        return app(PostOpeningStockAction::class)->execute(
            company: $this->company, product: $this->product, warehouse: $this->warehouse,
            quantity: Quantity::of('1'), unitCostBase: $cost, user: $this->owner,
            idempotencyKey: 'audit-opening', lotNumber: 'AUDIT-OPEN', expiryDate: $expiry,
            movementDate: $date,
        );
    }
}
