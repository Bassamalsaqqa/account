<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Actions\Purchasing\VoidPurchaseAction;
use App\Actions\Purchasing\VoidPurchaseReturnAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Models\Customer;
use App\Services\Corrections\TradeCorrection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Phase5D\Phase5DTestCase;
use Tests\Support\DisposableMariaDbSchema;

final class CorrectionMigrationPreservationTest extends Phase5DTestCase
{
    private ?DisposableMariaDbSchema $canonicalSchema = null;

    private ?DisposableMariaDbSchema $historicalSchema = null;

    public function refreshDatabase(): void
    {
        DisposableMariaDbSchema::assertPrimarySchema(DB::connection()->getDatabaseName());
        $this->canonicalSchema = DisposableMariaDbSchema::create();
        $this->canonicalSchema->migrate();
        $this->canonicalSchema->switchLaravelConnection();
    }

    protected function tearDown(): void
    {
        try {
            $this->historicalSchema?->drop();
            $this->canonicalSchema?->drop();
        } finally {
            parent::tearDown();
        }
    }

    public function test_000001_preserves_populated_history_and_grants_void_permissions(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->postReturn($this->createReturnDraft($purchase));
        $this->replayHistoricalFixture(1);
        $permissions = ['purchasing.purchase.void', 'purchasing.return.view', 'purchasing.return.create', 'purchasing.return.post', 'purchasing.return.void'];
        $permissionIds = DB::table('permissions')->whereIn('name', $permissions)->pluck('id');
        DB::table('role_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('model_has_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
        $before = $this->fingerprint(['permissions', 'role_has_permissions']);
        $this->assertFalse(Schema::hasColumn('purchase_returns', 'voided_at'));
        $team = getPermissionsTeamId();

        $this->migration(1)->up();

        $this->assertSame($team, getPermissionsTeamId());
        $this->assertSame($before, $this->fingerprint(['permissions', 'role_has_permissions']));
        foreach (['voided_at', 'voided_by', 'void_reason', 'void_posting_batch_id'] as $column) {
            $this->assertTrue(Schema::hasColumn('purchase_returns', $column));
            $this->assertNull(DB::table('purchase_returns')->value($column));
        }
        foreach (['Owner', 'Manager'] as $role) {
            $grants = DB::table('role_has_permissions')->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                ->join('roles', 'roles.id', '=', 'role_has_permissions.role_id')->where('roles.company_id', $this->company->id)
                ->where('roles.name', $role)->whereIn('permissions.name', $permissions)->count();
            $this->assertSame(5, $grants);
        }
    }

    public static function valuations(): array
    {
        return ['journal-backed' => ['10'], 'legitimate-zero-journal' => ['0']];
    }

    #[DataProvider('valuations')]
    public function test_000002_infers_populated_purchase_and_return_dates_without_changing_history(string $unitCost): void
    {
        [$purchase, $return] = $this->voidHistory($unitCost);
        $this->assertSame($unitCost === '0', $purchase->void_posting_batch_id === null);
        $this->assertSame($unitCost === '0', $return->void_posting_batch_id === null);
        $this->replayHistoricalFixture(2);
        $before = $this->fingerprint();
        $this->assertNoBusinessDateColumns();

        $this->migration(2)->up();

        $this->assertSame('2026-11-06', DB::table('purchases')->where('id', $purchase->id)->value('void_business_date'));
        $this->assertSame('2026-11-05', DB::table('purchase_returns')->where('id', $return->id)->value('void_business_date'));
        $this->assertSame($before, $this->fingerprint());
        $this->migration(3)->up();
        $this->migration(4)->up();
        foreach (['purchase', 'sales_invoice', 'sales_return', 'purchase_return'] as $family) {
            $this->assertTrue(Schema::hasTable($family.'_correction_receipts'));
            $this->assertSame(0, DB::table($family.'_correction_receipts')->count());
        }
        $this->assertRestrictiveReceiptKeys();
        $this->assertSame($before, $this->fingerprint());
    }

    public static function conflictingHistory(): array
    {
        return [
            'first-table-journal-disagreement' => ['purchases', '10', 'disagreement'],
            'second-table-journal-disagreement' => ['purchase_returns', '10', 'disagreement'],
            'second-table-zero-journal-ambiguity' => ['purchase_returns', '0', 'ambiguity'],
        ];
    }

    #[DataProvider('conflictingHistory')]
    public function test_000002_preflights_both_tables_before_any_ddl(string $table, string $unitCost, string $fault): void
    {
        [$purchase, $return] = $this->voidHistory($unitCost);
        $this->replayHistoricalFixture(2);
        $source = $table === 'purchases' ? 'purchase_void' : 'purchase_return_void';
        $sourceId = $table === 'purchases' ? $purchase->id : $return->id;
        $movement = DB::table('stock_movements')->where('source_type', $source)->where('source_id', $sourceId)->first();
        $this->assertNotNull($movement);
        if ($fault === 'ambiguity') {
            $duplicate = (array) $movement;
            unset($duplicate['id']);
            $duplicate['public_id'] = (string) Str::ulid();
            $duplicate['idempotency_key'] = 'historical-ambiguity-fixture';
            $duplicate['movement_date'] = '2026-11-04';
            DB::table('stock_movements')->insert($duplicate);
        } else {
            DB::table('stock_movements')->where('id', $movement->id)->update(['movement_date' => '2026-11-04']);
        }
        $before = $this->fingerprint();
        $ddl = $this->ddl();

        try {
            $this->migration(2)->up();
            $this->fail('Ambiguous historical dates were accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ambiguous historical void business date; explicit history review required.', $exception->getMessage());
        }

        $this->assertNoBusinessDateColumns();
        $this->assertSame($ddl, $this->ddl());
        $this->assertSame($before, $this->fingerprint());
    }

    public static function returnFamilies(): array
    {
        return ['sales-return-only' => ['sales_return'], 'purchase-return-only' => ['purchase_return']];
    }

    #[DataProvider('returnFamilies')]
    public function test_000004_refuses_down_with_only_return_receipts_and_retains_restrictive_foreign_keys(string $family): void
    {
        $purchase = $this->createAndPostPurchase();
        if ($family === 'purchase_return') {
            $source = $this->postReturn($this->createReturnDraft($purchase));
        } else {
            $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'زبون', 'active' => true, 'created_by' => $this->owner->id]);
            $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $customer->id, 'warehouse_id' => $this->warehouse->id, 'currency_code' => 'ILS',
                'exchange_rate' => '1', 'issue_date' => '2026-11-01',
                'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->unit->id, 'item_description' => 'زيت', 'quantity' => '2', 'unit_price' => '20']],
            ]);
            $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
            $source = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
                'sales_invoice_id' => $invoice->id, 'issue_date' => '2026-11-02',
                'lines' => [['sales_invoice_line_id' => $invoice->lines()->firstOrFail()->id, 'quantity' => '1']],
            ]);
            $source = app(PostSalesReturnAction::class)->execute($source, $this->owner);
        }
        $service = app(TradeCorrection::class);
        $preview = $service->preview($source, $this->owner, 'edit', 'Migration retention fixture', '2026-11-07');
        $replacement = $service->execute($preview['token'], $this->owner);
        foreach (['sales_invoice', 'sales_return', 'purchase_return'] as $receiptFamily) {
            $this->assertSame($receiptFamily === $family ? 1 : 0, DB::table($receiptFamily.'_correction_receipts')->count());
        }
        $receipt = DB::table($family.'_correction_receipts')->sole();
        $this->assertSame($source->id, $receipt->source_id);
        $this->assertSame($replacement->id, $receipt->replacement_id);
        $this->assertRestrictiveReceiptKeys();
        $before = $this->fingerprint([], false);
        $ddl = $this->ddl();

        try {
            $this->migration(4)->down();
            $this->fail('Protected return correction history was removed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Correction provenance exists; retain this migration.', $exception->getMessage());
        }

        foreach (['sales_invoice', 'sales_return', 'purchase_return'] as $receiptFamily) {
            $this->assertTrue(Schema::hasTable($receiptFamily.'_correction_receipts'));
        }
        $this->assertSame($before, $this->fingerprint([], false));
        $this->assertSame($ddl, $this->ddl());
        $this->assertRestrictiveReceiptKeys();
        foreach ([$source->id, $replacement->id] as $documentId) {
            try {
                DB::table($family === 'sales_return' ? 'sales_returns' : 'purchase_returns')->where('id', $documentId)->delete();
                $this->fail('A correction-linked document was deleted.');
            } catch (QueryException $exception) {
                $this->assertSame('23000', $exception->errorInfo[0]);
                $this->assertSame(1451, $exception->errorInfo[1]);
            }
        }
        $this->assertSame($before, $this->fingerprint([], false));
    }

    public function test_000005_refuses_down_when_populated_and_retains_restrictive_foreign_keys(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->assertRestrictiveConfirmationReceiptKeys();
        $key = (string) Str::uuid();
        DB::table('purchase_confirmation_receipts')->insert([
            'company_id' => $this->company->id,
            'actor_id' => $this->owner->id,
            'purchase_id' => $purchase->id,
            'source_public_id' => null,
            'request_key' => $key,
            'payload_digest' => str_repeat('a', 64),
            'confirmation' => 'encrypted-fixture',
            'created_at' => now(),
        ]);
        $before = $this->fingerprint([], false);
        $ddl = $this->ddl();

        try {
            $this->migration(5)->down();
            $this->fail('Protected purchase confirmation history was removed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Purchase confirmation provenance exists; retain this migration.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('purchase_confirmation_receipts'));
        $this->assertSame($before, $this->fingerprint([], false));
        $this->assertSame($ddl, $this->ddl());
        $this->assertRestrictiveConfirmationReceiptKeys();

        try {
            DB::table('purchases')->where('id', $purchase->id)->delete();
            $this->fail('A confirmation-linked purchase was deleted.');
        } catch (QueryException $exception) {
            $this->assertSame('23000', $exception->errorInfo[0]);
            $this->assertSame(1451, $exception->errorInfo[1]);
        }

        $this->assertSame($before, $this->fingerprint([], false));

        // When empty, migration down must drop table cleanly
        DB::table('purchase_confirmation_receipts')->delete();
        $this->migration(5)->down();
        $this->assertFalse(Schema::hasTable('purchase_confirmation_receipts'));

        // And migration up must recreate it with matching restrictive foreign keys
        $this->migration(5)->up();
        $this->assertTrue(Schema::hasTable('purchase_confirmation_receipts'));
        $this->assertRestrictiveConfirmationReceiptKeys();
    }

    private function voidHistory(string $unitCost): array
    {
        $purchase = $this->createAndPostPurchase(['lines' => [['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => $unitCost]]]);
        $return = $this->postReturn($this->createReturnDraft($purchase));
        $return = app(VoidPurchaseReturnAction::class)->execute($return, $this->owner, 'Historical return', '2026-11-05');
        $purchase = app(VoidPurchaseAction::class)->execute($purchase, $this->owner, 'Historical purchase', '2026-11-06');

        return [$purchase, $return];
    }

    private function replayHistoricalFixture(int $beforeMigration): void
    {
        $rows = [];
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if ($table !== 'migrations') {
                $rows[$table] = DB::table($table)->get()->map(fn ($row): array => (array) $row)->all();
            }
        }
        $this->historicalSchema = DisposableMariaDbSchema::create();
        $this->historicalSchema->switchLaravelConnection();
        $paths = array_filter(glob(database_path('migrations/*.php')), fn (string $path): bool => basename($path) < '2026_11_07_000001');
        $this->assertNotEmpty($paths);
        $this->assertSame(0, Artisan::call('migrate', ['--database' => DB::getDefaultConnection(), '--path' => array_values($paths), '--realpath' => true, '--force' => true]), Artisan::output());
        if ($beforeMigration === 2) {
            $this->migration(1)->up();
        }
        Schema::withoutForeignKeyConstraints(function () use ($rows): void {
            foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
                if ($table === 'migrations') {
                    continue;
                }
                DB::table($table)->delete();
                $columns = array_flip(Schema::getColumnListing($table));
                foreach ($rows[$table] ?? [] as $row) {
                    DB::table($table)->insert(array_intersect_key($row, $columns));
                }
            }
        });
    }

    private function migration(int $number): Migration
    {
        $paths = glob(database_path('migrations/2026_11_07_'.sprintf('%06d', $number).'_*.php'));
        $this->assertCount(1, $paths);

        return require $paths[0];
    }

    private function assertNoBusinessDateColumns(): void
    {
        foreach (['purchases', 'purchase_returns'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'void_business_date'));
        }
    }

    private function fingerprint(array $excluded = [], bool $excludeReceipts = true): string
    {
        $rows = [];
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if (in_array($table, $excluded, true) || ($excludeReceipts && (str_ends_with($table, '_correction_receipts') || $table === 'purchase_confirmation_receipts'))) {
                continue;
            }
            $records = DB::table($table)->get()->map(function ($row): string {
                $record = (array) $row;
                foreach (['void_business_date', 'voided_at', 'voided_by', 'void_reason', 'void_posting_batch_id'] as $column) {
                    if (array_key_exists($column, $record) && ($column === 'void_business_date' || $record[$column] === null)) {
                        unset($record[$column]);
                    }
                }
                ksort($record);

                return json_encode($record, JSON_THROW_ON_ERROR);
            })->all();
            sort($records);
            $rows[$table] = $records;
        }
        ksort($rows);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function ddl(): array
    {
        $definitions = [];
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            $definitions[$table] = (array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
        }
        ksort($definitions);

        return $definitions;
    }

    private function assertRestrictiveReceiptKeys(): void
    {
        foreach (['purchase' => 'purchases', 'sales_invoice' => 'sales_invoices', 'sales_return' => 'sales_returns', 'purchase_return' => 'purchase_returns'] as $family => $sourceTable) {
            $columns = $family === 'purchase' ? ['purchase_id', 'replacement_id'] : ['source_id', 'replacement_id'];
            $keys = Schema::getForeignKeys($family.'_correction_receipts');
            foreach ($columns as $column) {
                $matches = array_values(array_filter($keys, fn (array $key): bool => $key['columns'] === [$column]));
                $this->assertCount(1, $matches);
                $this->assertSame($sourceTable, $matches[0]['foreign_table']);
                $this->assertSame('restrict', strtolower($matches[0]['on_delete']));
            }
        }
    }

    private function assertRestrictiveConfirmationReceiptKeys(): void
    {
        $keys = Schema::getForeignKeys('purchase_confirmation_receipts');
        $expected = [
            'company_id' => 'companies',
            'actor_id' => 'users',
            'purchase_id' => 'purchases',
        ];
        foreach ($expected as $column => $foreignTable) {
            $matches = array_values(array_filter($keys, fn (array $key): bool => $key['columns'] === [$column]));
            $this->assertCount(1, $matches);
            $this->assertSame($foreignTable, $matches[0]['foreign_table']);
            $this->assertSame('restrict', strtolower($matches[0]['on_delete']));
        }
    }
}
