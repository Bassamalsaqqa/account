<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MigrationRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_v1_migration_rolls_back_and_re_migrates_cleanly(): void
    {
        // 1. Verify tables exist after initial migration
        $this->assertTrue(Schema::hasTable('mobile_devices'));
        $this->assertTrue(Schema::hasTable('mobile_auth_challenges'));
        $this->assertTrue(Schema::hasTable('mobile_access_tokens'));
        $this->assertTrue(Schema::hasTable('company_mobile_settings'));
        $this->assertFalse(Schema::hasColumn('products', 'tax_rate_id'));
        $this->assertFalse(Schema::hasColumn('products', 'barcode'));

        $this->assertTrue(Schema::hasColumn('mobile_devices', 'user_id'));
        $beforeProducts = Schema::getColumns('products');
        $migration = require database_path('migrations/2026_11_17_000001_create_mobile_read_api_tables.php');
        // Roll back the dependent forward migration first; applied history stays frozen.
        $receiptMigration = require database_path('migrations/2026_11_18_000001_create_mobile_event_receipts_table.php');
        $receiptMigration->down();
        $migration->down();
        foreach (['mobile_devices', 'mobile_auth_challenges', 'mobile_access_tokens', 'company_mobile_settings'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertSame($beforeProducts, Schema::getColumns('products'));
        $migration->up();
        $receiptMigration->up();
        $this->assertTrue(Schema::hasTable('mobile_event_receipts'));
        $this->assertSame($beforeProducts, Schema::getColumns('products'));

        $this->assertTrue(Schema::hasTable('mobile_devices'));
        $this->assertTrue(Schema::hasTable('mobile_auth_challenges'));
        $this->assertTrue(Schema::hasTable('mobile_access_tokens'));
        $this->assertTrue(Schema::hasTable('company_mobile_settings'));
        $this->assertFalse(Schema::hasColumn('products', 'tax_rate_id'));
        $this->assertFalse(Schema::hasColumn('products', 'barcode'));
    }

    public function test_release_migration_refuses_existing_mobile_tables_without_mutation(): void
    {
        $beforeProducts = Schema::getColumns('products');
        $beforeDevices = Schema::getColumns('mobile_devices');
        $migration = require database_path('migrations/2026_11_17_000001_create_mobile_read_api_tables.php');
        try {
            $migration->up();
            $this->fail('Existing installations must require explicit reconciliation.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reviewed legacy reconciliation', $exception->getMessage());
        }
        $this->assertSame($beforeProducts, Schema::getColumns('products'));
        $this->assertSame($beforeDevices, Schema::getColumns('mobile_devices'));
    }
}
