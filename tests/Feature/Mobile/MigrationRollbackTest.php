<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        $this->assertFalse(Schema::hasColumn('products', 'tax_rate_id'));
        $this->assertFalse(Schema::hasColumn('products', 'barcode'));

        $this->assertTrue(Schema::hasColumn('mobile_devices', 'user_id'));
        $targetMigration = '2026_11_11_000002_harden_mobile_device_ownership_and_remove_unapproved_catalog_fields';
        $steps = DB::table('migrations')->where('migration', '>=', $targetMigration)->count();
        $status = Artisan::call('migrate:rollback', ['--step' => $steps > 0 ? $steps : 1]);
        $this->assertSame(0, $status);
        $this->assertFalse(Schema::hasColumn('mobile_devices', 'user_id'));
        $this->assertTrue(Schema::hasColumn('products', 'tax_rate_id'));
        $this->assertTrue(Schema::hasColumn('products', 'barcode'));

        // 3. Re-migrate forward
        $reStatus = Artisan::call('migrate');
        $this->assertSame(0, $reStatus);

        $this->assertTrue(Schema::hasTable('mobile_devices'));
        $this->assertTrue(Schema::hasTable('mobile_auth_challenges'));
        $this->assertTrue(Schema::hasTable('mobile_access_tokens'));
        $this->assertTrue(Schema::hasTable('company_mobile_settings'));
        $this->assertFalse(Schema::hasColumn('products', 'tax_rate_id'));
        $this->assertFalse(Schema::hasColumn('products', 'barcode'));
    }
}
