<?php

declare(strict_types=1);

namespace Tests\Feature\Diagnostics;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class BugReportMigrationTest extends TestCase
{
    public function test_forward_bug_reports_migration_roundtrip_indexes_no_cascade_and_populated_refusal(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->switchLaravelConnection();

            foreach (['users', 'companies'] as $table) {
                Schema::create($table, static function (Blueprint $blueprint): void {
                    $blueprint->id();
                    $blueprint->string('sentinel');
                });
                DB::table($table)->insert(['id' => 1, 'sentinel' => 'preserved']);
            }

            $migration = require database_path('migrations/2026_11_26_000001_create_bug_reports_table.php');
            $migration->up();
            $migration->down();

            $this->assertSame('preserved', DB::table('users')->value('sentinel'));
            $this->assertSame('preserved', DB::table('companies')->value('sentinel'));

            $migration->up();
            $this->assertTrue(Schema::hasTable('bug_reports'));

            $indexes = collect(DB::select('SHOW INDEX FROM `bug_reports`'))->pluck('Key_name')->unique()->all();
            $this->assertContains('bug_reports_submitter_idempotency_unique', $indexes);
            $this->assertContains('bug_reports_status_created_idx', $indexes);
            $this->assertContains('bug_reports_company_created_idx', $indexes);
            $this->assertContains('bug_reports_request_id_idx', $indexes);
            $this->assertContains('bug_reports_failed_request_id_idx', $indexes);

            DB::table('bug_reports')->insert([
                'public_ref' => '01J00000000000000000000001',
                'reporter_user_id' => 1,
                'effective_user_id' => 1,
                'company_id' => 1,
                'is_impersonated' => false,
                'source' => 'web',
                'category' => 'other',
                'locale' => 'ar',
                'include_diagnostics' => false,
                'note' => 'Synthetic migration custody row',
                'idempotency_key' => '11111111-1111-4111-8111-111111111111',
                'payload_fingerprint' => str_repeat('a', 64),
                'status' => 'new',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // Foreign keys must restrict deletion (no cascade delete).
            try {
                DB::table('users')->where('id', 1)->delete();
                $this->fail('Deleting a user referenced by bug_reports must be restricted');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('1451', $e->getMessage());
            }

            try {
                DB::table('companies')->where('id', 1)->delete();
                $this->fail('Deleting a company referenced by bug_reports must be restricted');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('1451', $e->getMessage());
            }

            try {
                $migration->down();
                $this->fail('Populated bug_reports table must refuse rollback');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('Refusing to remove populated', $e->getMessage());
            }

            $this->assertSame(1, DB::table('bug_reports')->count());

            DB::table('bug_reports')->delete();
            $migration->down();
            $this->assertFalse(Schema::hasTable('bug_reports'));
            $this->assertSame('preserved', DB::table('users')->value('sentinel'));
        } finally {
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }
}
