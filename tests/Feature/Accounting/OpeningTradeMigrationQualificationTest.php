<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\OpeningTradeItem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Phase8\TradeTestCase;

final class OpeningTradeMigrationQualificationTest extends TradeTestCase
{
    private Migration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->migration = require database_path('migrations/2026_11_21_000001_qualify_opening_trade_status_and_defaults.php');
    }

    public function test_migration_empty_round_trip(): void
    {
        // Table is empty in disposable test
        DB::table('opening_trade_items')->delete();

        // 1. Down on empty table succeeds cleanly
        $this->migration->down();

        // Verify down restored default and dropped constraint
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'opening_trade_items')
                ->where('CONSTRAINT_NAME', 'oti_status_valid')
                ->count();
            $this->assertSame(0, $constraints);
        }

        // 2. Up on empty table succeeds cleanly
        $this->migration->up();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'opening_trade_items')
                ->where('CONSTRAINT_NAME', 'oti_status_valid')
                ->count();
            $this->assertSame(1, $constraints);
        }
    }

    public function test_migration_populated_down_refuses_and_preserves_schema(): void
    {
        // Insert a record into opening_trade_items
        DB::table('opening_trade_items')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->defaultCustomer->id,
            'opening_date' => '2026-01-01',
            'currency_code' => 'ILS',
            'amount_currency' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'carrying_value_base' => '100.000000',
            'status' => 'posted',
            'party_snapshot' => json_encode(['id' => $this->defaultCustomer->id]),
            'company_snapshot' => json_encode(['id' => $this->company->id]),
            'control_route' => json_encode([]),
            'created_by' => $this->owner->id,
            'source_identity' => 'test',
            'idempotency_key' => 'migration-pop-test',
            'idempotency_payload_hash' => hash('sha256', 'pop'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->migration->down();
            $this->fail('Down on populated opening trade history must throw RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Cannot downgrade populated opening trade history', $e->getMessage());
        }

        // Schema must be preserved
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'opening_trade_items')
                ->where('CONSTRAINT_NAME', 'oti_status_valid')
                ->count();
            $this->assertSame(1, $constraints);
        }

        // Clean up
        DB::table('opening_trade_items')->where('idempotency_key', 'migration-pop-test')->delete();
    }

    public function test_migration_incompatible_data_up_refuses_and_preserves_prior_schema(): void
    {
        DB::table('opening_trade_items')->delete();

        // Put schema into prior state
        $this->migration->down();

        // Insert incompatible record (status = 'draft')
        DB::table('opening_trade_items')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->defaultCustomer->id,
            'opening_date' => '2026-01-01',
            'currency_code' => 'ILS',
            'amount_currency' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'carrying_value_base' => '100.000000',
            'status' => 'draft',
            'party_snapshot' => json_encode(['id' => $this->defaultCustomer->id]),
            'company_snapshot' => json_encode(['id' => $this->company->id]),
            'control_route' => json_encode([]),
            'created_by' => $this->owner->id,
            'source_identity' => 'test',
            'idempotency_key' => 'migration-incompatible-test',
            'idempotency_payload_hash' => hash('sha256', 'incompatible'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->migration->up();
            $this->fail('Up on incompatible opening trade status must throw RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('incompatible record(s) found. Prior schema preserved.', $e->getMessage());
        }

        // Prior schema must be preserved (no check constraint)
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'opening_trade_items')
                ->where('CONSTRAINT_NAME', 'oti_status_valid')
                ->count();
            $this->assertSame(0, $constraints);
        }

        // Clean up incompatible record and migrate up cleanly
        DB::table('opening_trade_items')->where('idempotency_key', 'migration-incompatible-test')->delete();
        $this->migration->up();
    }

    public function test_migration_case_insensitive_status_refuses_prevalidation_and_enforces_binary_constraint(): void
    {
        DB::table('opening_trade_items')->delete();

        // 1. Put schema into prior state
        $this->migration->down();

        // Insert uppercase POSTED record
        DB::table('opening_trade_items')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->defaultCustomer->id,
            'opening_date' => '2026-01-01',
            'currency_code' => 'ILS',
            'amount_currency' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'carrying_value_base' => '100.000000',
            'status' => 'POSTED',
            'party_snapshot' => json_encode(['id' => $this->defaultCustomer->id]),
            'company_snapshot' => json_encode(['id' => $this->company->id]),
            'control_route' => json_encode([]),
            'created_by' => $this->owner->id,
            'source_identity' => 'test',
            'idempotency_key' => 'migration-uppercase-test',
            'idempotency_payload_hash' => hash('sha256', 'uppercase'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $this->migration->up();
            $this->fail('Up on case-insensitive POSTED status must throw RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('incompatible record(s) found. Prior schema preserved.', $e->getMessage());
        }

        // Clean up incompatible record and migrate up cleanly
        DB::table('opening_trade_items')->where('idempotency_key', 'migration-uppercase-test')->delete();
        $this->migration->up();

        // After migration, attempting to insert uppercase 'POSTED' must fail CHECK constraint in MariaDB/MySQL
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            try {
                DB::table('opening_trade_items')->insert([
                    'public_id' => (string) Str::ulid(),
                    'company_id' => $this->company->id,
                    'side' => OpeningTradeItem::SIDE_CUSTOMER,
                    'customer_id' => $this->defaultCustomer->id,
                    'opening_date' => '2026-01-01',
                    'currency_code' => 'ILS',
                    'amount_currency' => '100.000000',
                    'exchange_rate' => '1.0000000000',
                    'carrying_value_base' => '100.000000',
                    'status' => 'POSTED',
                    'party_snapshot' => json_encode(['id' => $this->defaultCustomer->id]),
                    'company_snapshot' => json_encode(['id' => $this->company->id]),
                    'control_route' => json_encode([]),
                    'created_by' => $this->owner->id,
                    'source_identity' => 'test',
                    'idempotency_key' => 'migration-post-constraint-test',
                    'idempotency_payload_hash' => hash('sha256', 'post-constraint'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->fail('Inserting uppercase POSTED status must be rejected by check constraint.');
            } catch (QueryException $e) {
                $this->assertStringContainsString('oti_status_valid', $e->getMessage());
            }
        }
    }
}
