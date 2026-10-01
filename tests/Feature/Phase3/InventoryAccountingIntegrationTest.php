<?php

declare(strict_types=1);

namespace Tests\Feature\Phase3;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\AdjustStockAction;
use App\Actions\Inventory\DisposeExpiredStockAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\InventoryLot;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAccountingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Warehouse $warehouse;

    protected Unit $unitPiece;

    protected Product $productStandard;

    protected Product $productExpiry;

    protected LedgerAccount $inventoryAccount;

    protected LedgerAccount $equityAccount;

    protected LedgerAccount $lossAccount;

    protected LedgerAccount $expiryLossAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $this->actingAs($this->user);

        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة تجارب تكامل المحاسبة والمخزون',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->productStandard = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'أرز بسمتي 5 كجم',
            'sku' => 'RICE-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->productStandard->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        $this->productExpiry = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'دجاج مجمد',
            'sku' => 'CHICKEN-001',
            'base_unit_id' => $this->unitPiece->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => true,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->productExpiry->id,
            'unit_id' => $this->unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
        ]);

        $this->inventoryAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory')->firstOrFail();
        $this->equityAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'opening_balance_equity')->firstOrFail();
        $this->lossAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'inventory_loss')->firstOrFail();
        $this->expiryLossAccount = LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'expiry_loss')->firstOrFail();
    }

    public function test_opening_stock_posts_dr_inventory_cr_opening_balance_equity(): void
    {
        $action = app(PostOpeningStockAction::class);
        $result = $action->execute(
            company: $this->company,
            product: $this->productStandard,
            warehouse: $this->warehouse,
            quantity: Quantity::of(100),
            unitCostBase: '25.000000',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
        );

        $movement = $result['movement'];
        $batch = $result['batch'];

        $this->assertInstanceOf(StockMovement::class, $movement);
        $this->assertInstanceOf(PostingBatch::class, $batch);
        $this->assertSame(StockMovement::TYPE_OPENING_BALANCE, $movement->movement_type);
        $this->assertSame('100.000000', $movement->quantity_delta_base);
        $this->assertSame('2500.000000', $movement->value_delta_base);

        // Check GL Entries
        $entries = PostingLine::where('posting_batch_id', $batch->id)->orderBy('line_number')->get();
        $this->assertCount(2, $entries);

        // Line 1: Debit Inventory (1301)
        $this->assertSame($this->inventoryAccount->id, $entries[0]->ledger_account_id);
        $this->assertSame('2500.000000', $entries[0]->debit_base);
        $this->assertSame('0.000000', $entries[0]->credit_base);

        // Line 2: Credit Opening Balance Equity (3101)
        $this->assertSame($this->equityAccount->id, $entries[1]->ledger_account_id);
        $this->assertSame('0.000000', $entries[1]->debit_base);
        $this->assertSame('2500.000000', $entries[1]->credit_base);
    }

    public function test_stock_adjustment_increase_posts_dr_inventory_cr_inventory_loss(): void
    {
        // First post opening stock so product has valuation
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->productStandard,
            warehouse: $this->warehouse,
            quantity: Quantity::of(10),
            unitCostBase: '20.000000',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
        );

        $adjustAction = app(AdjustStockAction::class);
        $result = $adjustAction->execute(
            company: $this->company,
            product: $this->productStandard,
            warehouse: $this->warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_INCREASE,
            quantity: Quantity::of(5),
            reason: 'فروقات جرد فائض',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
            unitCostBase: '20.000000',
        );

        $movement = $result['movement'];
        $batch = $result['batch'];

        $this->assertInstanceOf(StockMovement::class, $movement);
        $this->assertInstanceOf(PostingBatch::class, $batch);
        $this->assertSame('5.000000', $movement->quantity_delta_base);
        $this->assertSame('100.000000', $movement->value_delta_base);

        // Check GL Entries: Dr Inventory (1301) / Cr Inventory Loss (5301)
        $entries = PostingLine::where('posting_batch_id', $batch->id)->orderBy('line_number')->get();
        $this->assertCount(2, $entries);

        $this->assertSame($this->inventoryAccount->id, $entries[0]->ledger_account_id);
        $this->assertSame('100.000000', $entries[0]->debit_base);

        $this->assertSame($this->lossAccount->id, $entries[1]->ledger_account_id);
        $this->assertSame('100.000000', $entries[1]->credit_base);
    }

    public function test_stock_adjustment_decrease_and_damage_loss_posts_dr_loss_cr_inventory(): void
    {
        // 1. Initial opening stock: 50 @ 10 ILS = 500 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->productStandard,
            warehouse: $this->warehouse,
            quantity: Quantity::of(50),
            unitCostBase: '10.000000',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
        );

        // 2. Adjust decrease / shortage: 5 units @ 10 = 50 ILS
        $adjustAction = app(AdjustStockAction::class);
        $resDecrease = $adjustAction->execute(
            company: $this->company,
            product: $this->productStandard,
            warehouse: $this->warehouse,
            type: StockMovement::TYPE_ADJUSTMENT_DECREASE,
            quantity: Quantity::of(5),
            reason: 'عجز جردي',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
        );

        $batchDecrease = $resDecrease['batch'];
        $this->assertNotNull($batchDecrease);
        $entriesDec = PostingLine::where('posting_batch_id', $batchDecrease->id)->orderBy('line_number')->get();
        $this->assertCount(2, $entriesDec);

        // Line 1: Dr Inventory Loss (5301)
        $this->assertSame($this->lossAccount->id, $entriesDec[0]->ledger_account_id);
        $this->assertSame('50.000000', $entriesDec[0]->debit_base);

        // Line 2: Cr Inventory (1301)
        $this->assertSame($this->inventoryAccount->id, $entriesDec[1]->ledger_account_id);
        $this->assertSame('50.000000', $entriesDec[1]->credit_base);

        // 3. Loss/Damage: 3 units @ 10 = 30 ILS
        $resDamage = $adjustAction->execute(
            company: $this->company,
            product: $this->productStandard,
            warehouse: $this->warehouse,
            type: StockMovement::TYPE_DAMAGE_OR_LOSS,
            quantity: Quantity::of(3),
            reason: 'تلف كرتون أثناء التخزين',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
        );

        $batchDamage = $resDamage['batch'];
        $this->assertNotNull($batchDamage);
        $entriesDam = PostingLine::where('posting_batch_id', $batchDamage->id)->orderBy('line_number')->get();
        $this->assertCount(2, $entriesDam);

        $this->assertSame($this->lossAccount->id, $entriesDam[0]->ledger_account_id);
        $this->assertSame('30.000000', $entriesDam[0]->debit_base);
        $this->assertSame($this->inventoryAccount->id, $entriesDam[1]->ledger_account_id);
        $this->assertSame('30.000000', $entriesDam[1]->credit_base);
    }

    public function test_expiry_disposal_posts_dr_expiry_loss_cr_inventory(): void
    {
        // Inbound 20 units of chicken @ 15 ILS with lot EXP-CHICK-1
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->productExpiry,
            warehouse: $this->warehouse,
            quantity: Quantity::of(20),
            unitCostBase: '15.000000',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
            lotNumber: 'EXP-CHICK-1',
            expiryDate: '2026-09-01',
        );

        $lot = InventoryLot::where('lot_number', 'EXP-CHICK-1')->firstOrFail();

        // Dispose 8 expired units: 8 * 15 = 120 ILS
        $disposeAction = app(DisposeExpiredStockAction::class);
        $result = $disposeAction->execute(
            company: $this->company,
            product: $this->productExpiry,
            warehouse: $this->warehouse,
            lotId: $lot->id,
            quantity: Quantity::of(8),
            reason: 'إتلاف بضاعة منتهية الصلاحية',
            user: $this->user,
            idempotencyKey: (string) Str::ulid(),
        );

        $movement = $result['movement'];
        $batch = $result['batch'];

        $this->assertInstanceOf(StockMovement::class, $movement);
        $this->assertInstanceOf(PostingBatch::class, $batch);
        $this->assertSame(StockMovement::TYPE_EXPIRY_DISPOSAL, $movement->movement_type);
        $this->assertSame('-8.000000', $movement->quantity_delta_base);
        $this->assertSame('-120.000000', $movement->value_delta_base);

        // Check GL Entries: Dr Expiry Loss (5302) / Cr Inventory (1301)
        $entries = PostingLine::where('posting_batch_id', $batch->id)->orderBy('line_number')->get();
        $this->assertCount(2, $entries);

        $this->assertSame($this->expiryLossAccount->id, $entries[0]->ledger_account_id);
        $this->assertSame('120.000000', $entries[0]->debit_base);

        $this->assertSame($this->inventoryAccount->id, $entries[1]->ledger_account_id);
        $this->assertSame('120.000000', $entries[1]->credit_base);
    }

    public function test_atomic_transaction_rollback_when_action_fails(): void
    {
        $initialMovementsCount = StockMovement::count();
        $initialBatchesCount = PostingBatch::count();

        $action = app(AdjustStockAction::class);

        try {
            // Attempt decrease with empty reason (validation fail before DB write)
            $action->execute(
                company: $this->company,
                product: $this->productStandard,
                warehouse: $this->warehouse,
                type: StockMovement::TYPE_ADJUSTMENT_DECREASE,
                quantity: Quantity::of(10),
                reason: '   ', // Invalid empty reason
                user: $this->user,
                idempotencyKey: (string) Str::ulid(),
            );
            $this->fail('Expected exception was not thrown.');
        } catch (InvalidInventoryMovementException) {
            // Expected
        }

        $this->assertSame($initialMovementsCount, StockMovement::count());
        $this->assertSame($initialBatchesCount, PostingBatch::count());
    }
}
