<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $this->assertTrue(Schema::hasTable('mobile_offline_sale_leases'));
        $this->assertTrue(Schema::hasColumn('users', 'mobile_authority_revision'));
        $this->assertFalse(Schema::hasColumn('products', 'tax_rate_id'));
        $this->assertFalse(Schema::hasColumn('products', 'barcode'));

        $this->assertTrue(Schema::hasColumn('mobile_devices', 'user_id'));
        $beforeProducts = Schema::getColumns('products');
        $migration = require database_path('migrations/2026_11_17_000001_create_mobile_read_api_tables.php');
        // Roll back dependent forward migrations in reverse chronological order; applied history stays frozen.
        $authorityMigration = require database_path('migrations/2026_11_25_100002_track_mobile_authority_and_offline_policy_revocation.php');
        $authorityMigration->down();
        $leaseMigration = require database_path('migrations/2026_11_25_100001_create_mobile_offline_sale_leases_table.php');
        $leaseMigration->down();
        $shiftClosureMigration = require database_path('migrations/2026_11_18_000002_create_mobile_shift_closures_table.php');
        $shiftClosureMigration->down();
        $receiptMigration = require database_path('migrations/2026_11_18_000001_create_mobile_event_receipts_table.php');
        $receiptMigration->down();
        $migration->down();
        foreach ([
            'mobile_devices',
            'mobile_auth_challenges',
            'mobile_access_tokens',
            'company_mobile_settings',
            'mobile_event_receipts',
            'mobile_shift_closures',
            'mobile_offline_sale_leases',
        ] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertFalse(Schema::hasColumn('users', 'mobile_authority_revision'));
        $this->assertSame($beforeProducts, Schema::getColumns('products'));
        $migration->up();
        $receiptMigration->up();
        $shiftClosureMigration->up();
        $leaseMigration->up();
        $authorityMigration->up();
        $this->assertTrue(Schema::hasTable('mobile_event_receipts'));
        $this->assertTrue(Schema::hasTable('mobile_shift_closures'));
        $this->assertTrue(Schema::hasTable('mobile_offline_sale_leases'));
        $this->assertTrue(Schema::hasColumn('users', 'mobile_authority_revision'));
        $this->assertTrue(Schema::hasColumn('mobile_offline_sale_leases', 'revoked_at'));
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

    public function test_populated_offline_lease_and_authority_history_refuse_rollback_before_ddl(): void
    {
        $userId = DB::table('users')->insertGetId([
            'public_id' => '01TESTUSER0000000000000001',
            'name' => 'Rollback Guard User',
            'email' => 'rollback-guard@example.com',
            'password' => bcrypt('secret123'),
            'locale' => 'ar',
            'mobile_authority_revision' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $authorityMigration = require database_path('migrations/2026_11_25_100002_track_mobile_authority_and_offline_policy_revocation.php');
        try {
            $authorityMigration->down();
            $this->fail('Populated mobile_authority_revision must refuse rollback before DDL.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('forward correction', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('users', 'mobile_authority_revision'));
        $this->assertTrue(Schema::hasColumn('mobile_offline_sale_leases', 'revoked_at'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $userId)->value('mobile_authority_revision'));

        $triggers = DB::select(
            'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN (?, ?, ?)',
            [
                'users_advance_mobile_authority',
                'company_mobile_settings_revoke_leases_on_update',
                'company_mobile_settings_revoke_leases_on_delete',
            ]
        );
        $this->assertCount(3, $triggers);
    }
}
