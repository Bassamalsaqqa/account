<?php

declare(strict_types=1);

namespace Tests\Feature\ManagedSaas;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

final class PlatformSubscriptionMigrationTest extends TestCase
{
    public function test_each_populated_commercial_table_prevents_any_schema_drop(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $company = Company::factory()->create();
            $operator = User::factory()->create();
            $migration = require database_path('migrations/2026_10_29_100000_create_platform_subscription_records.php');
            $tables = ['platform_subscriptions', 'platform_subscription_charges', 'platform_subscription_payments', 'platform_billing_requests'];
            $charge = ['company_id' => $company->id, 'subscription_revision' => 1, 'plan' => 'Synthetic',
                'amount' => '100', 'currency' => 'ILS', 'starts_on' => '2026-10-01', 'ends_before' => '2026-11-01', 'created_at' => now()];
            foreach ($tables as $table) {
                $row = match ($table) {
                    'platform_subscriptions' => ['company_id' => $company->id, 'plan' => 'Synthetic', 'fee' => '100', 'currency' => 'ILS',
                        'starts_on' => '2026-10-01', 'renews_on' => '2026-11-01', 'grace_days' => 7, 'reminder_days' => '[14,7,1]',
                        'created_at' => now(), 'updated_at' => now()],
                    'platform_subscription_charges' => $charge,
                    'platform_subscription_payments' => ['company_id' => $company->id,
                        'charge_id' => DB::table('platform_subscription_charges')->insertGetId($charge),
                        'amount' => '25', 'currency' => 'ILS', 'received_on' => '2026-10-29', 'operator_user_id' => $operator->id, 'created_at' => now()],
                    'platform_billing_requests' => ['company_id' => $company->id, 'operator_user_id' => $operator->id,
                        'request_key' => (string) Str::uuid(), 'operation' => 'configure',
                        'payload_fingerprint' => str_repeat('a', 64), 'payload' => '{}', 'result' => '{}', 'created_at' => now()],
                };
                DB::table($table)->insert($row);
                $before = DB::table($table)->get()->toJson();
                try {
                    $migration->down();
                    $this->fail('Populated commercial table was dropped: '.$table);
                } catch (RuntimeException $exception) {
                    $this->assertStringContainsString('Retain populated commercial history', $exception->getMessage());
                }
                foreach ($tables as $retained) {
                    $this->assertTrue(Schema::hasTable($retained), $retained.' was dropped before preflight completed');
                }
                $this->assertSame($before, DB::table($table)->get()->toJson());
                DB::table($table)->delete();
                if ($table === 'platform_subscription_payments') {
                    DB::table('platform_subscription_charges')->delete();
                }
            }
            $migration->down();
            foreach ($tables as $table) {
                $this->assertFalse(Schema::hasTable($table));
            }
            $migration->up();
            foreach ($tables as $table) {
                $this->assertTrue(Schema::hasTable($table));
            }
            $this->assertDatabaseCount('posting_batches', 0);
        } finally {
            $schema->restoreLaravelConnection();
            $schema->drop();
        }
    }
}
