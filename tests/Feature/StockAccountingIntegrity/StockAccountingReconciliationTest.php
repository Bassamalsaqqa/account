<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Customer;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\PostingLine;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\StockAccountingAudit;
use App\Services\Posting\AccountingPostingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Phase8\Phase8TestCase;
use Tests\Support\InventoryPrimitiveFixture;

final class StockAccountingReconciliationTest extends Phase8TestCase
{
    /**
     * Test 1: unknown-source opening2@1 noGL must NOT be healthy and inventory control discrepancy.
     */
    public function test_unknown_source_opening_2_at_1_without_gl_is_not_healthy_and_detects_inventory_control_discrepancy(): void
    {
        $command = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: now()->toDateString(),
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of('2'),
                    unitCostBase: '1.000000',
                ),
            ],
            sourceType: 'unknown_source',
            sourceId: $this->company->id,
            idempotencyKey: 'opening-unknown-'.Str::ulid(),
            createdBy: $this->owner->id,
        );

        InventoryPrimitiveFixture::record(app(InventoryMovementService::class), $command);

        $movements = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $inventoryAccount = DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('system_key', 'inventory')
            ->first();

        $auditIssues = app(StockAccountingAudit::class)->discrepancies(
            (int) $this->company->id,
            $movements,
            $inventoryAccount ? (int) $inventoryAccount->id : null
        );

        $this->assertNotEmpty($auditIssues);
        $this->assertTrue(collect($auditIssues)->contains(
            fn (string $msg): bool => str_contains($msg, 'unrecognized economic source [unknown_source]')
        ));

        $report = $this->assertAuditPreservesSnapshots(
            fn () => app(InventoryReconciliationService::class)->auditCompany($this->company)
        );

        $this->assertFalse($report->isHealthy);
        $this->assertNotEmpty($report->discrepancies);
        $this->assertTrue(collect($report->discrepancies)->contains(
            fn (string $msg): bool => str_contains($msg, 'Inventory GL control account') && str_contains($msg, 'does not match inventory valuation')
        ));
        $this->assertTrue(collect($report->historyCorruptions)->contains(
            fn (string $msg): bool => str_contains($msg, 'unrecognized economic source [unknown_source]')
        ));
    }

    /**
     * Test 2: zero-cost nonexistent sales invoice sale after valid zero-cost opening must orphan evenGLnetzero.
     */
    public function test_zero_cost_nonexistent_sales_invoice_sale_after_valid_zero_cost_opening_orphans_even_with_gl_net_zero(): void
    {
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('5'),
            unitCostBase: '0.000000',
            user: $this->owner,
            idempotencyKey: 'opening-zero-'.Str::ulid(),
        );

        $saleCommand = new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_SALE,
            movementDate: now()->toDateString(),
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of('1'),
                    unitCostBase: '0.000000',
                ),
            ],
            sourceType: 'sales_invoice',
            sourceId: 999999,
            idempotencyKey: 'sale-orphan-'.Str::ulid(),
            createdBy: $this->owner->id,
        );

        InventoryPrimitiveFixture::record(app(InventoryMovementService::class), $saleCommand);

        $movements = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $inventoryAccount = DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('system_key', 'inventory')
            ->first();

        $auditIssues = app(StockAccountingAudit::class)->discrepancies(
            (int) $this->company->id,
            $movements,
            $inventoryAccount ? (int) $inventoryAccount->id : null
        );

        $this->assertNotEmpty($auditIssues);
        $this->assertTrue(collect($auditIssues)->contains(
            fn (string $msg): bool => str_contains($msg, 'orphan or invalid canonical document source [sales_invoice]')
        ));

        $report = $this->assertAuditPreservesSnapshots(
            fn () => app(InventoryReconciliationService::class)->auditCompany($this->company)
        );

        $this->assertFalse($report->isHealthy);
        $this->assertSame('0.000000', $report->totalValuationBase);
        $this->assertTrue(collect($report->historyCorruptions)->contains(
            fn (string $msg): bool => str_contains($msg, 'orphan or invalid canonical document source [sales_invoice]')
        ));
    }

    /**
     * Test 3: valid zero-cost opening healthy.
     */
    public function test_valid_zero_cost_opening_is_healthy(): void
    {
        $result = app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('5'),
            unitCostBase: '0.000000',
            user: $this->owner,
            idempotencyKey: 'opening-zero-valid-'.Str::ulid(),
        );

        $this->assertNull($result['batch']);

        $movements = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $inventoryAccount = DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('system_key', 'inventory')
            ->first();

        $auditIssues = app(StockAccountingAudit::class)->discrepancies(
            (int) $this->company->id,
            $movements,
            $inventoryAccount ? (int) $inventoryAccount->id : null
        );

        $this->assertEmpty($auditIssues);

        $report = $this->assertAuditPreservesSnapshots(
            fn () => app(InventoryReconciliationService::class)->auditCompany($this->company)
        );

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->discrepancies);
        $this->assertEmpty($report->historyCorruptions);
        $this->assertEmpty($report->cacheDiscrepancies);
        $this->assertSame('0.000000', $report->totalValuationBase);
    }

    /**
     * Test 4: ordinary canonical nonzero opening healthy.
     */
    public function test_ordinary_canonical_nonzero_opening_is_healthy(): void
    {
        $result = app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('10'),
            unitCostBase: '12.500000',
            user: $this->owner,
            idempotencyKey: 'opening-nonzero-valid-'.Str::ulid(),
        );

        $this->assertNotNull($result['batch']);

        $movements = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $inventoryAccount = DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('system_key', 'inventory')
            ->first();

        $auditIssues = app(StockAccountingAudit::class)->discrepancies(
            (int) $this->company->id,
            $movements,
            $inventoryAccount ? (int) $inventoryAccount->id : null
        );

        $this->assertEmpty($auditIssues);

        $report = $this->assertAuditPreservesSnapshots(
            fn () => app(InventoryReconciliationService::class)->auditCompany($this->company)
        );

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->discrepancies);
        $this->assertEmpty($report->historyCorruptions);
        $this->assertEmpty($report->cacheDiscrepancies);
        $this->assertSame('125.000000', $report->totalValuationBase);
    }

    /**
     * Test 5: corrupt source_type on stock movement only viaDB update means unhealthy evencontroltotalbalanced.
     */
    public function test_corrupt_source_type_on_stock_movement_via_db_update_is_unhealthy_even_with_control_total_balanced(): void
    {
        $result = app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('10'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'opening-corrupt-type-'.Str::ulid(),
        );

        $movement = $result['movement'];

        DB::table('stock_movements')
            ->where('id', $movement->id)
            ->update(['source_type' => 'corrupted_movement_source']);

        $movements = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $inventoryAccount = DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('system_key', 'inventory')
            ->first();

        $auditIssues = app(StockAccountingAudit::class)->discrepancies(
            (int) $this->company->id,
            $movements,
            $inventoryAccount ? (int) $inventoryAccount->id : null
        );

        $this->assertNotEmpty($auditIssues);
        $this->assertTrue(collect($auditIssues)->contains(
            fn (string $msg): bool => str_contains($msg, 'unrecognized economic source [corrupted_movement_source]')
        ));

        $report = $this->assertAuditPreservesSnapshots(
            fn () => app(InventoryReconciliationService::class)->auditCompany($this->company)
        );

        $this->assertFalse($report->isHealthy);
        $this->assertSame('100.000000', $report->totalValuationBase);
        $this->assertFalse(collect($report->cacheDiscrepancies)->contains(
            fn (string $msg): bool => str_contains($msg, 'Inventory GL control account') && str_contains($msg, 'does not match inventory valuation')
        ));
        $this->assertTrue(collect($report->historyCorruptions)->contains(
            fn (string $msg): bool => str_contains($msg, 'unrecognized economic source [corrupted_movement_source]')
        ));
    }

    /**
     * Test 6: remove GL entries viaDB synthetic injection means unhealthy.
     */
    public function test_remove_gl_entries_via_db_synthetic_injection_means_unhealthy(): void
    {
        $result = app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('10'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'opening-remove-gl-'.Str::ulid(),
        );

        $batch = $result['batch'];
        $this->assertNotNull($batch);

        DB::table('posting_lines')->where('posting_batch_id', $batch->id)->delete();

        $movements = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->orderBy('id')->get();
        $inventoryAccount = DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('system_key', 'inventory')
            ->first();

        $auditIssues = app(StockAccountingAudit::class)->discrepancies(
            (int) $this->company->id,
            $movements,
            $inventoryAccount ? (int) $inventoryAccount->id : null
        );

        $this->assertNotEmpty($auditIssues);
        $this->assertTrue(collect($auditIssues)->contains(
            fn (string $msg): bool => str_contains($msg, 'does not match immutable stock valuation')
        ));

        $report = $this->assertAuditPreservesSnapshots(
            fn () => app(InventoryReconciliationService::class)->auditCompany($this->company)
        );

        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->cacheDiscrepancies)->contains(
            fn (string $msg): bool => str_contains($msg, 'Inventory GL control account') && str_contains($msg, 'balance [0]')
        ));
        $this->assertTrue(collect($report->historyCorruptions)->contains(
            fn (string $msg): bool => str_contains($msg, 'does not match immutable stock valuation')
        ));
    }

    /**
     * Test 7: change inventoryAccount system_key to noninventory for syntheticdata means missingcontrolaccount detected.
     */
    public function test_change_inventory_account_system_key_to_non_inventory_detects_missing_control_account(): void
    {
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('10'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'opening-missing-account-'.Str::ulid(),
        );

        DB::table('ledger_accounts')
            ->where('company_id', $this->company->id)
            ->where('system_key', 'inventory')
            ->update(['system_key' => 'not_inventory']);

        $report = $this->assertAuditPreservesSnapshots(
            fn () => app(InventoryReconciliationService::class)->auditCompany($this->company)
        );

        $this->assertFalse($report->isHealthy);
        $this->assertTrue(collect($report->cacheDiscrepancies)->contains(
            fn (string $msg): bool => str_contains($msg, 'no inventory control account exists')
        ));
    }

    public function test_offsetting_orphan_sales_and_purchase_gl_batches_are_detected_with_zero_control_total(): void
    {
        $accounts = LedgerAccount::where('company_id', $this->company->id)->get()->keyBy('system_key');
        foreach (['sales_invoice', 'purchase_return'] as $i => $source) {
            $debit = $i === 0 ? $accounts['inventory'] : $accounts['opening_balance_equity'];
            $credit = $i === 0 ? $accounts['opening_balance_equity'] : $accounts['inventory'];
            $batch = app(AccountingPostingService::class)->post(new PostingCommand(
                company: $this->company, postingDate: CarbonImmutable::parse('2026-10-01'),
                sourceType: 'manual_test', sourceId: $i + 1, transactionCurrencyCode: 'ILS', baseCurrencyCode: 'ILS',
                exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'offset-orphan-'.$i, postedBy: $this->owner,
                lines: [PostingLineCommand::debit(1, $debit->id, '2'),
                    PostingLineCommand::credit(2, $credit->id, '2')]
            ));
            DB::table('posting_batches')->where('id', $batch->id)->update(['source_type' => $source, 'source_id' => 999999]);
        }
        $report = $this->assertAuditPreservesSnapshots(fn () => app(InventoryReconciliationService::class)->auditCompany($this->company));
        $this->assertFalse($report->isHealthy);
        $this->assertSame('0.000000', $report->totalValuationBase);
        $this->assertEmpty($report->cacheDiscrepancies);
        foreach (['sales_invoice', 'purchase_return'] as $source) {
            $this->assertTrue(collect($report->historyCorruptions)->contains(fn ($message) => str_contains($message, 'orphan or inconsistent canonical document source ['.$source.']')));
        }
    }

    public function test_duplicate_inventory_batches_cannot_hide_behind_offsetting_gl_effects(): void
    {
        $command = new StockMovementCommand($this->company->id, StockMovement::TYPE_OPENING_BALANCE, '2026-10-01',
            [new StockMovementLineCommand($this->product->id, $this->warehouse->id, Quantity::of('2'), unitCostBase: '1')],
            'opening_stock', $this->company->id, 'historical-duplicate-recognition', $this->owner->id);
        $movement = InventoryPrimitiveFixture::record(app(InventoryMovementService::class), $command)[0];
        $accounts = LedgerAccount::where('company_id', $this->company->id)->get()->keyBy('system_key');
        foreach ([['1', false], ['1', true], ['2', false]] as $i => [$amount, $negative]) {
            $debit = $negative ? $accounts['opening_balance_equity'] : $accounts['inventory'];
            $credit = $negative ? $accounts['inventory'] : $accounts['opening_balance_equity'];
            $batch = app(AccountingPostingService::class)->post(new PostingCommand(
                company: $this->company, postingDate: CarbonImmutable::parse('2026-10-01'), sourceType: 'manual_test', sourceId: $i + 1,
                transactionCurrencyCode: 'ILS', baseCurrencyCode: 'ILS', exchangeRate: ExchangeRate::one(),
                idempotencyKey: 'duplicate-recognition-'.$i, postedBy: $this->owner,
                lines: [PostingLineCommand::debit(1, $debit->id, $amount), PostingLineCommand::credit(2, $credit->id, $amount)]));
            DB::table('posting_batches')->where('id', $batch->id)->update(['source_type' => 'opening_stock', 'source_id' => $movement->id]);
        }
        $report = $this->assertAuditPreservesSnapshots(fn () => app(InventoryReconciliationService::class)->auditCompany($this->company));
        $this->assertFalse($report->isHealthy);
        $this->assertSame('2.000000', $report->totalValuationBase);
        $this->assertEmpty($report->cacheDiscrepancies);
        $this->assertTrue(collect($report->historyCorruptions)->contains(fn ($message) => str_contains($message, 'duplicate accounting recognition')));
    }

    public function test_paid_sales_of_zero_cost_stock_still_require_financial_recognition(): void
    {
        app(PostOpeningStockAction::class)->execute($this->company, $this->product, $this->warehouse,
            Quantity::of('2'), '0', $this->owner, 'zero-stock-paid-sale', movementDate: '2026-10-01');
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'Synthetic customer',
            'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'currency_code' => 'ILS',
            'exchange_rate' => '1', 'issue_date' => '2026-10-01', 'lines' => [['product_id' => $this->product->id,
                'product_unit_id' => $this->unit->id, 'item_description' => 'Synthetic zero-cost stock', 'quantity' => '1', 'unit_price' => '10']],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
        DB::table('posting_batches')->where('id', $invoice->posting_batch_id)->update(['source_type' => 'manual_test']);
        $wrongSource = $this->assertAuditPreservesSnapshots(fn () => app(InventoryReconciliationService::class)->auditCompany($this->company));
        $this->assertFalse($wrongSource->isHealthy);
        $this->assertTrue(collect($wrongSource->historyCorruptions)->contains(fn ($message) => str_contains($message, 'inconsistent accounting source or lifecycle')));
        DB::table('posting_batches')->where('id', $invoice->posting_batch_id)->update(['source_type' => 'sales_invoice']);
        DB::table('sales_invoices')->where('id', $invoice->id)->update(['posting_batch_id' => null]);
        DB::table('posting_lines')->where('posting_batch_id', $invoice->posting_batch_id)->delete();
        DB::table('posting_batches')->where('id', $invoice->posting_batch_id)->delete();
        $report = $this->assertAuditPreservesSnapshots(fn () => app(InventoryReconciliationService::class)->auditCompany($this->company));
        $this->assertFalse($report->isHealthy);
        $this->assertSame('0.000000', $report->totalValuationBase);
        $this->assertTrue(collect($report->historyCorruptions)->contains(fn ($message) => str_contains($message, 'missing accounting recognition')));
    }

    /**
     * Assert audit leaves full stock_movements, posting_batches, and posting_lines serialized snapshots unchanged.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    protected function assertAuditPreservesSnapshots(callable $action): mixed
    {
        $movementsBefore = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->get()
            ->toJson();

        $batchesBefore = PostingBatch::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->get()
            ->toJson();

        $linesBefore = PostingLine::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->get()
            ->toJson();

        $result = $action();

        $movementsAfter = StockMovement::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->get()
            ->toJson();

        $batchesAfter = PostingBatch::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->get()
            ->toJson();

        $linesAfter = PostingLine::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->orderBy('id')
            ->get()
            ->toJson();

        $this->assertSame($movementsBefore, $movementsAfter, 'Audit must leave stock_movements serialized snapshots unchanged.');
        $this->assertSame($batchesBefore, $batchesAfter, 'Audit must leave posting_batches serialized snapshots unchanged.');
        $this->assertSame($linesBefore, $linesAfter, 'Audit must leave posting_lines serialized snapshots unchanged.');

        return $result;
    }
}
