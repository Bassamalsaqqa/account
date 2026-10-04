<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5C;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\Exceptions\IdempotencyConflictException;
use App\Domain\Inventory\Exceptions\InvalidInventoryMovementException;
use App\Domain\Inventory\Exceptions\InvalidUnitConversionException;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Livewire\Pages\Purchasing\PurchaseDetail;
use App\Livewire\Pages\Purchasing\PurchaseIndex;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\InventoryCostState;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryRebuildService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PurchaseAcquisitionValue;
use App\Services\Purchasing\PurchaseReadModel;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchasePostingTest extends TestCase
{
    use RefreshDatabase;

    protected Company $company;

    protected User $owner;

    protected Vendor $vendor;

    protected Product $product;

    protected ProductUnit $unit;

    protected Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة الترحيل', 'name_en' => 'Posting Company']);
        $this->activate($this->owner);
        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'مورد الاختبار', 'name_en' => 'Posting Vendor']);
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون', 'name_en' => 'Soap', 'sku' => 'POST-SOAP', 'product_type' => Product::TYPE_STOCK,
            'track_stock' => true, 'track_expiry' => false, 'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $this->owner->id);
        $this->unit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
    }

    protected function activate(User $actor): void
    {
        app(CompanyContext::class)->setCompany($this->company, $actor);
        $this->actingAs($actor);
        setPermissionsTeamId($this->company->id);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
    }

    protected function create(array $changes = []): Purchase
    {
        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, array_replace([
            'vendor_id' => $this->vendor->id, 'warehouse_id' => $this->warehouse->id, 'purchase_date' => '2026-10-04',
            'due_date' => null, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'document_locale' => 'ar',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10']],
        ], $changes));
    }

    protected function postPurchase(Purchase $purchase, ?User $actor = null): Purchase
    {
        return app(PostPurchaseAction::class)->execute($purchase, $actor ?? $this->owner);
    }

    protected function state(): array
    {
        $result = [];
        foreach (['purchases', 'purchase_lines', 'purchase_line_lots', 'posting_batches', 'posting_lines', 'stock_movements',
            'inventory_operations', 'inventory_lots', 'inventory_balances', 'inventory_lot_balances', 'inventory_cost_states', 'document_sequences', 'audit_events'] as $table) {
            $result[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $result;
    }

    /** @return list<StockMovement> */
    protected function receiptInTransaction(StockMovementCommand $command): array
    {
        return DB::transaction(fn () => app(InventoryMovementService::class)->recordPurchaseReceipt($command));
    }

    protected function exactExpiryReceipt(Purchase $draft): StockMovementCommand
    {
        $line = $draft->lines()->with('lots', 'productUnit')->sole();
        $valuation = app(PurchaseAcquisitionValue::class);
        $value = $valuation->line($line, null);
        $cost = $valuation->unitCost($line, $value);
        $values = $valuation->lots($line, $value);
        $parts = [];
        foreach ($line->lots as $index => $lot) {
            $parts[] = new StockMovementLineCommand($line->product_id, $draft->warehouse_id,
                Quantity::of($lot->quantity), $line->productUnit->unit_id, $cost,
                lotNumber: $lot->lot_number, expiryDate: $lot->expiry_date?->format('Y-m-d'), valueDeltaBase: (string) $values[$index]);
        }

        return new StockMovementCommand($draft->company_id, StockMovement::TYPE_PURCHASE,
            $draft->purchase_date->format('Y-m-d'), $parts, 'purchase', $draft->id,
            'purchase_'.$draft->id.'_line_'.$line->id.'_stock', $this->owner->id, $line->id);
    }

    public function test_exact_draft_receipt_cannot_be_committed_through_generic_inventory_record(): void
    {
        $draft = $this->expiryDraft();
        $command = $this->exactExpiryReceipt($draft);
        $this->assertTrue($this->owner->hasPermissionTo('purchasing.purchase.post'));
        $this->assertTrue($this->owner->hasPermissionTo('purchasing.cost.view'));
        $before = $this->state();
        try {
            app(InventoryMovementService::class)->record($command);
            $this->fail('Standalone Purchase receipt accepted.');
        } catch (InvalidInventoryMovementException $exception) {
            $this->assertStringContainsString('canonical Purchase posting', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
        // The very same persisted intent succeeds only through complete Purchase POST.
        $posted = $this->postPurchase($draft);
        $this->assertSame('posted', $posted->status);
        $this->assertDatabaseCount('stock_movements', 3);
        $this->assertDatabaseCount('inventory_lots', 3);
        $this->assertDatabaseCount('posting_batches', 1);
        $this->assertSame('1.000000', $this->accountNet($posted, 'inventory'));
        $this->assertSame('-1.000000', $this->accountNet($posted, 'accounts_payable'));
    }

    public function test_purchase_receipt_and_completion_require_an_existing_outer_transaction(): void
    {
        $draft = $this->expiryDraft();
        $command = $this->exactExpiryReceipt($draft);
        $line = $draft->lines->first();
        $lot = $line->lots->first();
        $before = $this->state();
        $connection = DB::getDefaultConnection();
        // RefreshDatabase owns the fixture transaction. A second real connection
        // has no transaction; the boundary must reject before any database reads.
        config(['database.connections.purchase_boundary' => config('database.connections.'.$connection)]);
        DB::setDefaultConnection('purchase_boundary');
        try {
            $this->assertSame(0, DB::transactionLevel());
            try {
                app(InventoryMovementService::class)->recordPurchaseReceipt($command);
                $this->fail('Receipt opened an independent transaction.');
            } catch (InvalidInventoryMovementException $exception) {
                $this->assertStringContainsString('existing outer', $exception->getMessage());
            }
            foreach ([
                fn () => $line->completeCanonicalReceipt(new StockMovement, null, $this->owner),
                fn () => $lot->completeCanonicalReceipt(new StockMovement, $this->owner),
                fn () => $draft->completeCanonicalPost(new PostingBatch, 'PUR-INVALID', $this->owner),
            ] as $complete) {
                try {
                    $complete();
                    $this->fail('Completion opened an independent transaction.');
                } catch (ImmutableRecordException $exception) {
                    $this->assertStringContainsString('existing outer', $exception->getMessage());
                }
            }
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::setDefaultConnection($connection);
            DB::purge('purchase_boundary');
        }
        $this->assertSame($before, $this->state());
    }

    #[DataProvider('invalidOverrides')]
    public function test_dedicated_purchase_receipt_rejects_other_movement_types(string $type): void
    {
        $before = $this->state();
        $command = new StockMovementCommand($this->company->id, $type, '2026-10-04',
            [new StockMovementLineCommand($this->product->id, $this->warehouse->id, Quantity::of('1'), $this->unit->unit_id, '1')],
            'opening', 1, 'not-a-purchase', $this->owner->id);
        try {
            $this->receiptInTransaction($command);
            $this->fail('Ordinary movement accepted through Purchase API.');
        } catch (InvalidInventoryMovementException $exception) {
            $this->assertStringContainsString('only Purchase', $exception->getMessage());
        }
        $this->assertSame($before, $this->state());
    }

    protected function tax(?int $account = null, string $mode = 'exclusive', string $code = 'VAT16'): TaxRate
    {
        return TaxRate::create(['company_id' => $this->company->id, 'code' => $code, 'name_ar' => 'ضريبة',
            'rate' => '16.000000', 'calculation' => $mode, 'purchase_tax_account_id' => $account, 'active' => true]);
    }

    protected function account(string $key): LedgerAccount
    {
        return LedgerAccount::where('company_id', $this->company->id)->where('system_key', $key)->firstOrFail();
    }

    protected function accountNet(Purchase $purchase, string $key): string
    {
        $lines = $purchase->postingBatch->lines()->where('ledger_account_id', $this->account($key)->id)->get();
        $amount = BigDecimal::zero();
        foreach ($lines as $line) {
            $amount = $amount->plus($line->debit_base)->minus($line->credit_base);
        }

        return (string) $amount->toScale(6);
    }

    public function test_purchase_posts_stock_gl_number_and_immutable_provenance_exactly_once(): void
    {
        $purchase = $this->create();
        $this->assertNull($purchase->purchase_number);
        $this->assertDatabaseCount('stock_movements', 0);
        $posted = $this->postPurchase($purchase);
        $this->assertSame('posted', $posted->status);
        $this->assertSame('PUR-2026-0001', $posted->purchase_number);
        $this->assertSame('100.000000', $this->accountNet($posted, 'inventory'));
        $this->assertSame('-100.000000', $this->accountNet($posted, 'accounts_payable'));
        $movement = StockMovement::sole();
        $this->assertSame('purchase', $movement->movement_type);
        $this->assertSame('10.000000', $movement->quantity_delta_base);
        $this->assertSame('100.000000', $movement->value_delta_base);
        $this->assertSame($posted->lines->sole()->id, $movement->source_line_id);
        $this->assertSame($movement->id, $posted->lines->sole()->stock_movement_id);
        $this->assertSame('10.000000', InventoryCostState::sole()->average_cost_base);
        $before = $this->state();
        $this->assertSame($posted->id, $this->postPurchase($purchase)->id);
        $this->assertSame($before, $this->state());
        $this->assertDatabaseHas('audit_events', ['event_key' => 'purchase.posted']);
    }

    public static function taxes(): array
    {
        return [['exclusive', true, '100', '100.000000', '16.000000'],
            ['inclusive', true, '116', '100.000000', '16.000000'],
            ['exclusive', false, '100', '116.000000', '0.000000'],
            ['inclusive', false, '116', '116.000000', '0.000000']];
    }

    #[DataProvider('taxes')]
    public function test_tax_recoverability_never_changes_supplier_liability(string $mode, bool $recoverable, string $cost, string $inventory, string $taxValue): void
    {
        $tax = $this->tax($recoverable ? $this->account('tax_input')->id : null, $mode);
        $draft = $this->create(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => $cost, 'tax_rate_id' => $tax->id]]]);
        // Current percentage/mode may evolve; agreed Draft economics use the snapshot.
        $tax->update(['rate' => '20.000000', 'calculation' => $mode === 'exclusive' ? 'inclusive' : 'exclusive']);
        $posted = $this->postPurchase($draft);
        $this->assertSame('116.000000', $posted->grand_total_currency);
        $this->assertSame($inventory, $this->accountNet($posted, 'inventory'));
        $this->assertSame($taxValue, $this->accountNet($posted, 'tax_input'));
        $this->assertSame('-116.000000', $this->accountNet($posted, 'accounts_payable'));
        $line = $posted->lines->sole();
        $this->assertSame('16.000000', $line->tax_rate_snapshot);
        $this->assertSame($recoverable ? $this->account('tax_input')->id : null, $line->purchase_tax_account_id);
        $this->assertSame($inventory, StockMovement::sole()->value_delta_base);
        $tax->update(['purchase_tax_account_id' => null]);
        $this->assertSame($recoverable ? $this->account('tax_input')->id : null, $line->fresh()->purchase_tax_account_id);
        $this->assertSame($posted->id, $this->postPurchase($draft)->id);
    }

    protected function expiryDraft(string $quantity = '3', array $lotQuantities = ['1', '1', '1'], array $changes = []): Purchase
    {
        $this->product->update(['track_expiry' => true]);
        $lots = [];
        foreach ($lotQuantities as $qty) {
            $lots[] = ['product_unit_id' => $this->unit->id, 'quantity' => $qty, 'lot_number' => 'SAME-LOT', 'expiry_date' => null];
        }

        return $this->create(array_replace(['lines' => [['product_id' => $this->product->id, 'quantity' => $quantity, 'unit_cost' => '0.333333', 'lots' => $lots]]], $changes));
    }

    public function test_expiry_receipt_assigns_final_micro_residual_and_reconciles_exactly(): void
    {
        $draft = $this->expiryDraft();
        $posted = $this->postPurchase($draft);
        $this->assertSame(['0.333333', '0.333333', '0.333334'], StockMovement::orderBy('id')->pluck('value_delta_base')->all());
        $this->assertSame('1.000000', $this->accountNet($posted, 'inventory'));
        $this->assertSame('1.000000', (string) BigDecimal::of(StockMovement::sum('value_delta_base'))->toScale(6));
        foreach ($posted->lines->sole()->lots as $intent) {
            $movement = StockMovement::findOrFail($intent->stock_movement_id);
            $this->assertSame($intent->created_inventory_lot_id, $movement->lot_id);
            $this->assertSame('purchase', $movement->lot->source_type);
            $this->assertSame($posted->id, $movement->lot->source_id);
            $this->assertSame($posted->lines->sole()->id, $movement->lot->source_line_id);
            $this->assertSame('2026-10-04', $movement->lot->received_date->format('Y-m-d'));
        }
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report));
        app(InventoryRebuildService::class)->rebuildForCompany($this->company);
        $this->assertSame('1.000000', InventoryCostState::sole()->inventory_value_base);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }

    public function test_partial_lot_intent_is_a_valid_draft_but_post_fails_atomically(): void
    {
        $draft = $this->expiryDraft('3', ['1']);
        $before = $this->state();
        try {
            $this->postPurchase($draft);
            $this->fail('Partial receipt posted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_weighted_average_multiple_lines_and_existing_stock_remain_company_wide(): void
    {
        $this->postPurchase($this->create()); // 10 @ 10
        $posted = $this->postPurchase($this->create(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '20'],
            ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'],
        ]]));
        $this->assertSame('150.000000', $this->accountNet($posted, 'inventory'));
        $this->assertSame('20.000000', InventoryCostState::sole()->quantity_base);
        $this->assertSame('250.000000', InventoryCostState::sole()->inventory_value_base);
        $this->assertSame('12.500000', InventoryCostState::sole()->average_cost_base);
        $this->assertDatabaseCount('inventory_operations', 3);
    }

    public static function currencies(): array
    {
        return [['USD', '3.5500000000'], ['JOD', '5.0000000000'], ['USD', '3.3333333333']];
    }

    #[DataProvider('currencies')]
    public function test_foreign_purchase_reconciles_exact_base_and_transaction_currency(string $currency, string $fx): void
    {
        $tax = $this->tax($this->account('tax_input')->id);
        $posted = $this->postPurchase($this->create(['currency_code' => $currency, 'exchange_rate' => $fx, 'lines' => [
            ['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '0.333333', 'tax_rate_id' => $tax->id],
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '0.01', 'tax_rate_id' => $tax->id],
        ]]));
        $inventory = BigDecimal::of($this->accountNet($posted, 'inventory'));
        $input = BigDecimal::of($this->accountNet($posted, 'tax_input'));
        $this->assertTrue($inventory->plus($input)->isEqualTo($posted->grand_total_base));
        $this->assertTrue($inventory->isEqualTo(StockMovement::sum('value_delta_base')));
        $this->assertTrue(BigDecimal::of($this->accountNet($posted, 'accounts_payable'))->negated()->isEqualTo($posted->grand_total_base));
        $credits = $posted->postingBatch->lines()->where('ledger_account_id', $this->account('accounts_payable')->id)->whereNotNull('transaction_amount')->sum('transaction_amount');
        $debits = $posted->postingBatch->lines()->where('ledger_account_id', '!=', $this->account('accounts_payable')->id)->whereNotNull('transaction_amount')->sum('transaction_amount');
        $this->assertTrue(BigDecimal::of($credits)->isEqualTo($posted->grand_total_currency));
        $this->assertTrue(BigDecimal::of($credits)->isEqualTo($debits));
    }

    public static function invalidDrafts(): array
    {
        return array_map(fn ($case) => [$case], ['vendor', 'warehouse', 'product', 'unit active', 'unit conversion', 'tax inactive',
            'tax account inactive', 'tax account foreign', 'tax account control', 'tax account liability', 'tax account normal',
            'tax account hierarchy', 'quantity', 'base quantity', 'line money', 'header money', 'base fx', 'lot base', 'lot exceeds', 'draft provenance']);
    }

    #[DataProvider('invalidDrafts')]
    public function test_drifted_persisted_draft_rejects_atomically(string $case): void
    {
        $tax = $this->tax($this->account('tax_input')->id);
        $draft = $this->expiryDraft('3', ['1', '1', '1'], ['lines' => [[
            'product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '10', 'tax_rate_id' => $tax->id,
            'lots' => [['product_unit_id' => $this->unit->id, 'quantity' => '3', 'lot_number' => 'INTENT', 'expiry_date' => '2025-01-01']],
        ]]]);
        $line = $draft->lines->sole();
        $account = $this->account('tax_input');
        match ($case) {
            'vendor' => DB::table('vendors')->where('id', $this->vendor->id)->update(['status' => 'inactive']),
            'warehouse' => DB::table('warehouses')->where('id', $this->warehouse->id)->update(['active' => false]),
            'product' => DB::table('products')->where('id', $this->product->id)->update(['active' => false]),
            'unit active' => DB::table('product_units')->where('id', $this->unit->id)->update(['active' => false]),
            'unit conversion' => DB::table('product_units')->where('id', $this->unit->id)->update(['conversion_to_base' => '2']),
            'tax inactive' => DB::table('tax_rates')->where('id', $tax->id)->update(['active' => false]),
            'tax account inactive' => DB::table('ledger_accounts')->where('id', $account->id)->update(['active' => false]),
            'tax account control' => DB::table('ledger_accounts')->where('id', $account->id)->update(['is_control' => true]),
            'tax account liability' => DB::table('ledger_accounts')->where('id', $account->id)->update(['account_type' => 'liability']),
            'tax account normal' => DB::table('ledger_accounts')->where('id', $account->id)->update(['normal_balance' => 'credit']),
            'tax account hierarchy' => DB::table('tax_rates')->where('id', $tax->id)->update(['purchase_tax_account_id' => $this->account('inventory')->id]),
            'quantity' => DB::table('purchase_lines')->where('id', $line->id)->update(['quantity' => '-1']),
            'base quantity' => DB::table('purchase_lines')->where('id', $line->id)->update(['quantity_base' => '4']),
            'line money' => DB::table('purchase_lines')->where('id', $line->id)->update(['line_total' => '1']),
            'header money' => DB::table('purchases')->where('id', $draft->id)->update(['grand_total_base' => '1']),
            'base fx' => DB::table('purchases')->where('id', $draft->id)->update(['exchange_rate' => '2']),
            'lot base' => DB::table('purchase_line_lots')->where('purchase_line_id', $line->id)->update(['quantity_base' => '2']),
            'lot exceeds' => DB::table('purchase_line_lots')->where('purchase_line_id', $line->id)->update(['quantity' => '4', 'quantity_base' => '4']),
            'draft provenance' => DB::table('purchase_lines')->where('id', $line->id)->update(['inventory_unit_cost_base' => '10']),
            default => null,
        };
        if ($case === 'tax account foreign') {
            app(CompanyContext::class)->clear();
            $other = app(CreateCompanyAction::class)->execute(User::factory()->create(), ['name_ar' => 'أخرى']);
            $foreign = LedgerAccount::withoutGlobalScopes()->where('company_id', $other->id)->where('system_key', 'tax_input')->firstOrFail();
            $this->activate($this->owner);
            DB::table('tax_rates')->where('id', $tax->id)->update(['purchase_tax_account_id' => $foreign->id]);
        }
        $before = $this->state();
        try {
            $this->postPurchase($draft);
            $this->fail('Invalid persisted Draft posted: '.$case);
        } catch (\InvalidArgumentException|ModelNotFoundException|InvalidUnitConversionException $exception) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_accounting_failure_rolls_back_receipts_lots_provenance_and_sequence(): void
    {
        $draft = $this->expiryDraft();
        $before = $this->state();
        $this->mock(AccountingPostingService::class, fn ($mock) => $mock->shouldReceive('post')->once()->andReturnUsing(function () {
            $this->assertDatabaseCount('stock_movements', 3);
            $this->assertDatabaseCount('inventory_lots', 3);
            $this->assertDatabaseCount('inventory_operations', 1);
            $this->assertGreaterThan(0, DB::transactionLevel());
            throw new \RuntimeException('Accounting failed');
        }));
        try {
            $this->postPurchase($draft);
            $this->fail('Posting succeeded after accounting failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Accounting failed', $exception->getMessage());
            $this->assertSame($before, $this->state());
        }
    }

    public function test_inventory_failure_on_later_line_rolls_back_earlier_receipt_and_number(): void
    {
        $draft = $this->expiryDraft(changes: ['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '10', 'lots' => [['product_unit_id' => $this->unit->id, 'quantity' => '1', 'lot_number' => 'FIRST']]],
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '20', 'lots' => [['product_unit_id' => $this->unit->id, 'quantity' => '1', 'lot_number' => 'SECOND']]],
        ]]);
        $before = $this->state();
        $real = new InventoryMovementService;
        $calls = 0;
        $this->mock(InventoryMovementService::class, function ($mock) use ($real, &$calls): void {
            $mock->shouldReceive('recordPurchaseReceipt')->twice()->andReturnUsing(function ($command) use ($real, &$calls) {
                if (++$calls === 2) {
                    $this->assertDatabaseCount('stock_movements', 1);
                    $this->assertDatabaseCount('inventory_lots', 1);
                    $this->assertGreaterThan(0, DB::transactionLevel());
                    throw new \RuntimeException('Second receipt failed');
                }

                return $real->recordPurchaseReceipt($command);
            });
        });
        try {
            $this->postPurchase($draft);
            $this->fail('Partial posting accepted.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Second receipt failed', $exception->getMessage());
            $this->assertSame($before, $this->state());
        }
    }

    public static function bypasses(): array
    {
        return [['header status'], ['header number'], ['header poster'], ['line movement'], ['line cost'], ['line tax'], ['lot movement'], ['lot inventory']];
    }

    #[DataProvider('bypasses')]
    public function test_ordinary_updates_cannot_inject_posting_provenance(string $case): void
    {
        $draft = $this->expiryDraft();
        $line = $draft->lines->sole();
        $lot = $line->lots->first();
        $before = $this->state();
        try {
            match ($case) {
                'header status' => $draft->update(['status' => 'posted']),
                'header number' => $draft->update(['purchase_number' => 'FORGED']),
                'header poster' => $draft->update(['posted_by' => $this->owner->id]),
                'line movement' => $line->update(['stock_movement_id' => 999]),
                'line cost' => $line->update(['inventory_unit_cost_base' => '1']),
                'line tax' => $line->update(['purchase_tax_account_id' => $this->account('tax_input')->id]),
                'lot movement' => $lot->update(['stock_movement_id' => 999]),
                'lot inventory' => $lot->update(['created_inventory_lot_id' => 999]),
            };
            $this->fail('Direct bypass accepted.');
        } catch (ImmutableRecordException) {
            $this->assertSame($before, $this->state());
        }
    }

    public static function immutableRecords(): array
    {
        return [['header'], ['line'], ['lot'], ['header delete'], ['line delete'], ['lot delete'], ['stale header']];
    }

    #[DataProvider('immutableRecords')]
    public function test_posted_history_is_immutable_including_stale_draft_models(string $case): void
    {
        $draft = $this->expiryDraft();
        $posted = $this->postPurchase($draft);
        $line = $posted->lines->sole();
        $lot = $line->lots->first();
        $before = $this->state();
        try {
            match ($case) {
                'header' => $posted->update(['notes' => 'Changed']),
                'line' => $line->update(['unit_cost' => '999']),
                'lot' => $lot->update(['lot_number' => 'Changed']),
                'header delete' => $posted->delete(),
                'line delete' => $line->delete(),
                'lot delete' => $lot->delete(),
                'stale header' => $draft->update(['notes' => 'Stale']),
            };
            $this->fail('Posted history changed.');
        } catch (ImmutableRecordException) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_purchase_poster_needs_no_inventory_adjustment_or_inventory_cost_permission(): void
    {
        $draft = $this->create();
        $actor = User::factory()->create();
        $this->company->users()->attach($actor->id, ['status' => 'active', 'is_owner' => false, 'joined_at' => now()]);
        $role = Role::create(['company_id' => $this->company->id, 'name' => 'PurchasePoster', 'guard_name' => 'web']);
        $role->syncPermissions(['purchasing.purchase.post', 'purchasing.cost.view']);
        $actor->assignRole($role);
        $this->activate($actor);
        $this->assertFalse($actor->hasPermissionTo('inventory.stock.adjust'));
        $this->assertFalse($actor->hasPermissionTo('inventory.cost.view'));
        $posted = $this->postPurchase($draft, $actor);
        $this->assertSame($actor->id, $posted->posted_by);
    }

    public static function rejectedActors(): array
    {
        return [['permission'], ['cost'], ['membership'], ['guest'], ['tenant'], ['mismatched actor']];
    }

    #[DataProvider('rejectedActors')]
    public function test_canonical_post_reauthorizes_actor_and_tenant(string $case): void
    {
        $draft = $this->create();
        if ($case === 'permission' || $case === 'cost') {
            $role = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
            $role->revokePermissionTo($case === 'permission' ? 'purchasing.purchase.post' : 'purchasing.cost.view');
        } elseif ($case === 'membership') {
            DB::table('company_user')->where('company_id', $this->company->id)->update(['status' => 'inactive']);
        } elseif ($case === 'guest') {
            auth()->forgetUser();
        } elseif ($case === 'tenant') {
            app(CompanyContext::class)->clear();
        } else {
            $this->actingAs(User::factory()->create());
        }
        $before = $this->state();
        try {
            $this->postPurchase($draft);
            $this->fail('Unauthorized post succeeded.');
        } catch (AuthorizationException) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_posted_reader_omits_sensitive_cost_and_provenance_and_stale_component_cannot_post(): void
    {
        $draft = $this->create();
        $component = Livewire::test(PurchaseDetail::class, ['publicId' => $draft->public_id])->assertSee(__('purchasing.post_purchase'));
        $role = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $role->revokePermissionTo('purchasing.purchase.post');
        $component->call('post')->assertForbidden();
        $role->givePermissionTo('purchasing.purchase.post');
        $posted = $this->postPurchase($draft);
        $role->revokePermissionTo('purchasing.cost.view');
        $data = app(PurchaseReadModel::class)->detail($posted, true);
        foreach (['unit_cost', 'inventory_unit_cost_base', 'line_tax', 'line_total', 'grand_total_currency', 'grand_total_base', 'posting_batch_id', 'stock_movement_id', 'exchange_rate'] as $field) {
            $this->assertStringNotContainsString('"'.$field.'"', json_encode($data));
        }
        Livewire::test(PurchaseDetail::class, ['publicId' => $posted->public_id])->assertSee($posted->purchase_number)->assertDontSee(__('purchasing.post_purchase'))->assertDontSee(__('purchasing.edit_purchase'));
        Livewire::test(PurchaseIndex::class)->assertSee($posted->purchase_number)->assertDontSee('100.00');
    }

    public function test_posted_audit_is_non_monetary_and_all_reconciliations_are_healthy(): void
    {
        $this->postPurchase($this->create());
        $event = AuditEvent::where('event_key', 'purchase.posted')->sole();
        foreach (['unit_cost', 'total', 'tax', 'exchange_rate', 'inventory_unit_cost', 'value_base'] as $field) {
            $this->assertStringNotContainsString($field, json_encode($event->only(['before_json', 'after_json', 'meta_json', 'summary'])));
        }
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_mixed_tax_accounts_group_exactly_and_nonrecoverable_tax_stays_in_inventory(): void
    {
        $input = $this->account('tax_input');
        $child = LedgerAccount::create(['company_id' => $this->company->id, 'code' => 'INPUT-2', 'name_ar' => 'مدخلات ثانية',
            'account_type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $input->id, 'active' => true, 'is_control' => false]);
        $first = $this->tax($input->id);
        $second = $this->tax($child->id, 'exclusive', 'VAT16B');
        $capitalized = $this->tax(null, 'exclusive', 'VAT16C');
        $lines = [];
        foreach ([$first, $first, $second, $capitalized] as $tax) {
            $lines[] = ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '100', 'tax_rate_id' => $tax->id];
        }
        $posted = $this->postPurchase($this->create(['lines' => $lines]));
        $this->assertSame('416.000000', $this->accountNet($posted, 'inventory'));
        $this->assertSame('32.000000', $this->accountNet($posted, 'tax_input'));
        $this->assertSame('16.000000', $posted->postingBatch->lines()->where('ledger_account_id', $child->id)->sole()->debit_base);
        $this->assertSame('-464.000000', $this->accountNet($posted, 'accounts_payable'));
        $this->assertSame([$input->id, $input->id, $child->id, null], $posted->lines->pluck('purchase_tax_account_id')->all());
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
    }

    public function test_selected_purchase_unit_receives_original_quantity_and_exact_base_conversion(): void
    {
        $carton = app(ProductCatalogService::class)->addOrUpdateAlternateUnit($this->product, Unit::where('code', 'carton')->sole()->id, '12', false, true);
        $posted = $this->postPurchase($this->create(['lines' => [['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '24']]]));
        $movement = StockMovement::sole();
        $this->assertSame($carton->unit_id, $movement->unit_id);
        $this->assertSame('2.000000', $movement->source_quantity);
        $this->assertSame('24.000000', $movement->quantity_delta_base);
        $this->assertSame('2.000000', $posted->lines->sole()->inventory_unit_cost_base);
        $this->assertSame('48.000000', $movement->value_delta_base);
    }

    public static function conflictingReceipts(): array
    {
        return array_map(fn ($field) => [$field], ['quantity', 'cost', 'value', 'product', 'warehouse', 'unit', 'lot', 'expiry', 'source line', 'reason']);
    }

    #[DataProvider('conflictingReceipts')]
    public function test_purchase_inventory_retry_hash_rejects_changed_intent_without_receiving_twice(string $field): void
    {
        $posted = $this->postPurchase($this->create());
        $line = $posted->lines->sole();
        $stockLine = new StockMovementLineCommand($this->product->id, $this->warehouse->id, Quantity::of('10'), $this->unit->unit_id, '10.000000', valueDeltaBase: '100.000000');
        $key = 'purchase_'.$posted->id.'_line_'.$line->id.'_stock';
        $original = new StockMovementCommand($this->company->id, 'purchase', '2026-10-04', [$stockLine], 'purchase', $posted->id, $key, $this->owner->id, $line->id);
        $before = $this->state();
        $this->assertSame($line->stock_movement_id, $this->receiptInTransaction($original)[0]->id);
        $changed = new StockMovementLineCommand(
            $field === 'product' ? 999 : $this->product->id,
            $field === 'warehouse' ? 999 : $this->warehouse->id,
            Quantity::of($field === 'quantity' ? '11' : '10'),
            $field === 'unit' ? 999 : $this->unit->unit_id,
            $field === 'cost' ? '11' : '10.000000',
            lotNumber: $field === 'lot' ? 'NEW-LOT' : null,
            expiryDate: $field === 'expiry' ? '2028-01-01' : null,
            valueDeltaBase: $field === 'value' ? '100.000001' : '100.000000');
        try {
            $this->receiptInTransaction(new StockMovementCommand($this->company->id, 'purchase', '2026-10-04', [$changed], 'purchase', $posted->id, $key, $this->owner->id,
                $field === 'source line' ? 999 : $line->id, $field === 'reason' ? 'Changed' : null));
            $this->fail('Changed receipt intent accepted.');
        } catch (IdempotencyConflictException) {
            $this->assertSame($before, $this->state());
        }
    }

    public static function invalidOverrides(): array
    {
        return [['opening_balance'], ['adjustment_increase'], ['adjustment_decrease']];
    }

    #[DataProvider('invalidOverrides')]
    public function test_exact_purchase_values_cannot_be_used_for_arbitrary_adjustments(string $type): void
    {
        $before = $this->state();
        try {
            app(InventoryMovementService::class)->record(new StockMovementCommand($this->company->id, $type, '2026-10-04',
                [new StockMovementLineCommand($this->product->id, $this->warehouse->id, Quantity::of('1'), $this->unit->unit_id, '1', valueDeltaBase: '2')],
                'purchase', 1, 'bad-override', $this->owner->id, 1));
            $this->fail('Arbitrary exact override allowed.');
        } catch (InvalidInventoryMovementException) {
            $this->assertSame($before, $this->state());
        }
    }

    public static function invalidPurchaseCommands(): array
    {
        return [['missing cost'], ['missing value'], ['historical original'], ['wrong source'], ['missing source line']];
    }

    #[DataProvider('invalidPurchaseCommands')]
    public function test_purchase_movement_requires_explicit_cost_value_and_source_line(string $case): void
    {
        $before = $this->state();
        try {
            $this->receiptInTransaction(new StockMovementCommand($this->company->id, 'purchase', '2026-10-04',
                [new StockMovementLineCommand($this->product->id, $this->warehouse->id, Quantity::of('1'), $this->unit->unit_id,
                    $case === 'missing cost' ? null : '1', valueDeltaBase: $case === 'missing value' ? null : '1', originalMovementId: $case === 'historical original' ? 1 : null)],
                $case === 'wrong source' ? 'opening' : 'purchase', 1, 'bad-purchase', $this->owner->id, $case === 'missing source line' ? null : 1));
            $this->fail('Invalid Purchase movement accepted.');
        } catch (InvalidInventoryMovementException) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_purchase_inventory_path_checks_purchase_authority_even_for_adjustment_user(): void
    {
        $role = Role::where('company_id', $this->company->id)->where('name', 'Owner')->sole();
        $role->revokePermissionTo('purchasing.purchase.post');
        $this->expectException(AuthorizationException::class);
        $this->receiptInTransaction(new StockMovementCommand($this->company->id, 'purchase', '2026-10-04',
            [new StockMovementLineCommand($this->product->id, $this->warehouse->id, Quantity::of('1'), $this->unit->unit_id, '1', valueDeltaBase: '1')],
            'purchase', 1, 'unauthorized-purchase', $this->owner->id, 1));
    }

    public static function incoherentPosted(): array
    {
        return [['number'], ['batch'], ['movement'], ['lot'], ['value']];
    }

    #[DataProvider('incoherentPosted')]
    public function test_post_retry_fails_closed_on_incoherent_history_and_never_repairs_it(string $case): void
    {
        $posted = $this->postPurchase($this->expiryDraft());
        match ($case) {
            'number' => DB::table('purchases')->where('id', $posted->id)->update(['purchase_number' => null]),
            'batch' => DB::table('purchases')->where('id', $posted->id)->update(['posting_batch_id' => null]),
            'movement' => DB::table('purchase_lines')->where('purchase_id', $posted->id)->update(['stock_movement_id' => null]),
            'lot' => DB::table('purchase_line_lots')->where('purchase_line_id', $posted->lines->sole()->id)->update(['created_inventory_lot_id' => null]),
            'value' => DB::table('stock_movements')->where('source_id', $posted->id)->update(['value_delta_base' => '0.333333']),
        };
        $before = $this->state();
        try {
            $this->postPurchase($posted);
            $this->fail('Incoherent retry accepted.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_inventory_reconciliation_detects_purchase_value_corruption_and_refuses_rebuild(): void
    {
        $posted = $this->postPurchase($this->expiryDraft());
        DB::table('stock_movements')->where('id', $posted->lines->sole()->stock_movement_id)->update(['value_delta_base' => '0.333334']);
        $before = $this->state();
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertFalse($report->isHealthy);
        $this->assertTrue($report->hasHistoryCorruption());
        try {
            app(InventoryRebuildService::class)->rebuildForCompany($this->company);
            $this->fail('Corrupt receipt rebuilt.');
        } catch (\RuntimeException) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_purchase_ui_posts_and_removes_draft_edit_action_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            app()->setLocale($locale);
            $purchase = $this->create();
            Livewire::test(PurchaseDetail::class, ['publicId' => $purchase->public_id])
                ->assertSee(__('purchasing.post_confirmation'))->assertSee(__('purchasing.post_purchase'))
                ->call('post')->assertHasNoErrors()->assertSee('PUR-2026-')->assertSee(__('purchasing.posted'))
                ->assertDontSee(__('purchasing.edit_purchase'))->assertDontSee('wire:click="post"', false);
        }
    }

    public function test_positive_foreign_tax_that_rounds_to_zero_base_cannot_disappear_from_gl_metadata(): void
    {
        $tax = $this->tax($this->account('tax_input')->id);
        $tax->update(['rate' => '0.010000']);
        $draft = $this->create(['currency_code' => 'USD', 'exchange_rate' => '0.000001',
            'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '100', 'tax_rate_id' => $tax->id]]]);
        $this->assertSame('0.010000', $draft->lines->sole()->line_tax);
        $this->assertSame('0.000000', $draft->lines->sole()->line_tax_base);
        $before = $this->state();
        try {
            $this->postPurchase($draft);
            $this->fail('Transaction-currency Input Tax silently disappeared.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($before, $this->state());
        }
    }

    public function test_zero_value_receipt_line_is_valid_with_a_positive_supplier_document(): void
    {
        $posted = $this->postPurchase($this->create(['lines' => [
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '0'],
            ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '10'],
        ]]));
        $this->assertSame('0.000000', StockMovement::orderBy('id')->firstOrFail()->value_delta_base);
        $this->assertSame('10.000000', $this->accountNet($posted, 'inventory'));
        $this->assertSame('5.000000', InventoryCostState::sole()->average_cost_base);
    }

    public function test_entirely_zero_value_supplier_document_fails_without_fake_gl_or_partial_receipt(): void
    {
        $draft = $this->create(['lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '0']]]);
        $before = $this->state();
        try {
            $this->postPurchase($draft);
            $this->fail('Fake zero-value PostingBatch created.');
        } catch (\InvalidArgumentException) {
            $this->assertSame($before, $this->state());
        }
    }
}
