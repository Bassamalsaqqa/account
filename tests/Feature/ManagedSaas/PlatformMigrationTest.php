<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

class PlatformMigrationTest extends TestCase
{
    public function test_additive_schema_roundtrip_preserves_tenant_rows_and_refuses_populated_history(): void
    {
        // DDL commits in MariaDB. This test owns a separate schema, never the parent transaction.
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
            $migration = require database_path('migrations/2026_10_27_000000_create_platform_control_plane.php');
            $migration->up();
            $migration->down();
            $this->assertSame('preserved', DB::table('companies')->value('sentinel'));
            $this->assertSame('preserved', DB::table('users')->value('sentinel'));
            $migration->up();
            $rows = [
                'platform_operator_grants' => ['user_id' => 1, 'active' => false, 'permissions' => '[]'],
                'platform_provisioning_requests' => ['operator_user_id' => 1, 'request_key' => 'c18ced3e-e7a2-4aab-915e-11a212fb38c2', 'payload_fingerprint' => str_repeat('a', 64)],
                'platform_audit_events' => ['actor_user_id' => 1, 'event_key' => 'synthetic', 'metadata' => '{}', 'created_at' => now()],
                'platform_company_health' => ['company_id' => 1, 'domains' => '{}', 'checked_at' => now()],
            ];
            foreach ($rows as $table => $data) {
                DB::table($table)->insert($data);
                try {
                    $migration->down();
                    $this->fail('Populated platform custody must refuse rollback');
                } catch (RuntimeException $e) {
                    $this->assertStringContainsString('Refusing to remove populated', $e->getMessage());
                }
                foreach (array_keys($rows) as $expected) {
                    $this->assertTrue(Schema::hasTable($expected));
                }
                $this->assertSame(1, DB::table($table)->count());
                DB::table($table)->delete();
            }
            $migration->down();
            $this->assertSame('preserved', DB::table('companies')->value('sentinel'));
        } finally {
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }
}
