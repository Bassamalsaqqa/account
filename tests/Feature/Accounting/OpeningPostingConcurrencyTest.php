<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Feature\Phase8\TradeTestCase;
use Tests\Support\DisposableMariaDbSchema;

final class OpeningPostingConcurrencyTest extends TradeTestCase
{
    private ?DisposableMariaDbSchema $fixture = null;

    public function refreshDatabase(): void
    {
        $this->fixture = DisposableMariaDbSchema::createFromSource(DB::connection()->getDatabaseName());
        $this->fixture->switchLaravelConnection();
    }

    protected function tearDown(): void
    {
        try {
            $this->fixture?->restoreLaravelConnection();
            $this->fixture?->drop();
        } finally {
            parent::tearDown();
        }
    }

    public function test_empty_opening_migration_round_trip_preserves_legacy_allocation_tables(): void
    {
        $migration = require database_path('migrations/2026_11_20_000001_create_opening_trade_items_and_extend_allocations.php');
        // Legacy financial allocations must survive the schema round trip exactly.
        $invoice = $this->createAndPostSalesInvoice(['lines' => [['product_id' => null, 'item_description' => 'Legacy service', 'quantity' => '1', 'unit_price' => '100.00']]]);
        $purchase = $this->createAndPostPurchase();
        foreach (['customer', 'vendor'] as $side) {
            $customer = $side === 'customer';
            app($customer ? PostCustomerPaymentAction::class : PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
                $customer ? 'customer_id' : 'vendor_id' => $customer ? $this->defaultCustomer->id : $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-10', 'payment_method' => 'cash',
                'currency_code' => 'ILS', 'exchange_rate' => '1.0000000000', 'amount' => '12.34',
                'idempotency_key' => 'legacy-round-trip-'.$side,
                'allocations' => [[$customer ? 'sales_invoice_id' : 'purchase_id' => $customer ? $invoice->id : $purchase->id, 'allocated_amount' => '12.34']],
            ]);
        }
        $tables = ['customer_payment_allocations', 'vendor_payment_allocations', 'customer_payments', 'vendor_payments', 'posting_batches', 'posting_lines'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
        $before = $snapshot();
        $schemaBefore = [];
        foreach (array_slice($tables, 0, 2) as $table) {
            $schemaBefore[$table] = DB::select('SHOW CREATE TABLE '.$table)[0]->{'Create Table'};
        }
        $migration->down();
        $this->assertFalse(Schema::hasTable('opening_trade_items'));
        $this->assertTrue(Schema::hasColumn('customer_payment_allocations', 'sales_invoice_id'));
        $this->assertTrue(Schema::hasColumn('vendor_payment_allocations', 'purchase_id'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('opening_trade_items'));
        $this->assertSame($before, $snapshot());
        foreach ($schemaBefore as $table => $definition) {
            $this->assertSame($definition, DB::select('SHOW CREATE TABLE '.$table)[0]->{'Create Table'});
        }
    }

    public function test_generic_and_trade_openings_cannot_both_commit_for_ar_or_ap(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $workers = [];
            $markers = [];
            DB::beginTransaction();
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();
            try {
                foreach (['trade', 'generic'] as $mode) {
                    $marker = tempnam(sys_get_temp_dir(), 'opening-race-');
                    unlink($marker);
                    $markers[] = $marker;
                    $worker = new Process([PHP_BINARY, 'tests/Support/opening-race-worker.php', (string) $this->company->id, (string) $this->owner->id, $side, (string) ($side === 'customer' ? $this->defaultCustomer->id : $this->vendor->id), $mode, $marker], base_path(), $this->fixture->environment());
                    $worker->setTimeout(45)->start();
                    $workers[] = $worker;
                }
                $deadline = microtime(true) + 15;
                while ((! is_file($markers[0]) || ! is_file($markers[1])) && microtime(true) < $deadline) {
                    usleep(10000);
                }
                foreach ($markers as $marker) {
                    $this->assertFileExists($marker);
                }
            } finally {
                DB::rollBack();
            }
            try {
                $outcomes = [];
                foreach ($workers as $worker) {
                    $worker->wait();
                    $this->assertTrue($worker->isSuccessful(), $worker->getErrorOutput());
                    $outcomes[] = str_starts_with($worker->getOutput(), 'blocked:') ? 'blocked' : $worker->getOutput();
                }
                sort($outcomes);
                $this->assertSame(['blocked', 'posted'], $outcomes);
            } finally {
                foreach ($workers as $worker) {
                    if ($worker->isRunning()) {
                        $worker->stop();
                    }
                }
                foreach ($markers as $marker) {
                    @unlink($marker);
                }
            }
        }
    }
}
