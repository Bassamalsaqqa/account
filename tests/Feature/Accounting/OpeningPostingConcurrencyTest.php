<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

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
        $before = [DB::table('customer_payment_allocations')->count(), DB::table('vendor_payment_allocations')->count()];
        $migration->down();
        $this->assertFalse(Schema::hasTable('opening_trade_items'));
        $this->assertTrue(Schema::hasColumn('customer_payment_allocations', 'sales_invoice_id'));
        $this->assertTrue(Schema::hasColumn('vendor_payment_allocations', 'purchase_id'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('opening_trade_items'));
        $this->assertSame($before, [DB::table('customer_payment_allocations')->count(), DB::table('vendor_payment_allocations')->count()]);
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
