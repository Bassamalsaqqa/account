<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Models\OpeningTradeItem;
use App\Models\Vendor;
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

    public function test_migration_populated_up_with_valid_rows_succeeds_and_preserves_links_snapshots_and_rows(): void
    {
        DB::table('opening_trade_items')->delete();

        // 1. Put schema into prior state
        $this->migration->down();

        // 2. Create posting batches to link
        $batchId1 = (int) DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'opening_trade_item',
            'source_id' => 1001,
            'posting_date' => '2026-01-01',
            'status' => 'posted',
            'transaction_currency_code' => 'ILS',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pb-mig-1-'.Str::ulid(),
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batchId2Orig = (int) DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'opening_trade_item',
            'source_id' => 1002,
            'posting_date' => '2026-01-01',
            'status' => 'reversed',
            'transaction_currency_code' => 'USD',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '3.5000000000',
            'idempotency_key' => 'pb-mig-2-'.Str::ulid(),
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $batchId2Rev = (int) DB::table('posting_batches')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'source_type' => 'reversal',
            'source_id' => $batchId2Orig,
            'reversal_of_id' => $batchId2Orig,
            'posting_date' => '2026-01-15',
            'status' => 'posted',
            'transaction_currency_code' => 'USD',
            'base_currency_code' => 'ILS',
            'exchange_rate' => '3.5000000000',
            'idempotency_key' => 'pb-mig-rev-'.Str::ulid(),
            'posted_by' => $this->owner->id,
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('posting_batches')->where('id', $batchId2Orig)->update(['reversed_by_batch_id' => $batchId2Rev]);

        $partySnapshot1 = [
            'id' => $this->defaultCustomer->id,
            'name_ar' => 'عميل افتراضي',
            'name_en' => 'Default Customer',
            'currency_code' => 'ILS',
            'code' => 'CUST-001',
        ];
        $companySnapshot1 = [
            'id' => $this->company->id,
            'name_ar' => 'شركة تجريبية',
            'name_en' => 'Test Company',
            'base_currency_code' => 'ILS',
        ];
        $controlRoute1 = [
            'control_account_id' => 10,
            'control_account_key' => 'accounts_receivable',
            'offset_account_id' => 20,
            'offset_account_key' => 'opening_balance_equity',
            'form' => [
                'form_key' => 'mig-form-key',
                'row_index' => 0,
                'total_rows' => 1,
                'form_hash' => hash('sha256', 'form-mig'),
                'member_hash' => hash('sha256', 'row-mig'),
            ],
        ];

        // Insert valid posted customer record under prior schema
        $row1Id = DB::table('opening_trade_items')->insertGetId([
            'id' => 1001,
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_CUSTOMER,
            'customer_id' => $this->defaultCustomer->id,
            'opening_date' => '2026-01-01',
            'due_date' => '2026-01-31',
            'currency_code' => 'ILS',
            'amount_currency' => '250.500000',
            'exchange_rate' => '1.0000000000',
            'carrying_value_base' => '250.500000',
            'status' => 'posted',
            'posting_batch_id' => $batchId1,
            'party_snapshot' => json_encode($partySnapshot1),
            'company_snapshot' => json_encode($companySnapshot1),
            'control_route' => json_encode($controlRoute1),
            'created_by' => $this->owner->id,
            'source_identity' => 'migration_fixture',
            'idempotency_key' => 'mig-posted-row-1',
            'idempotency_payload_hash' => hash('sha256', 'mig-1'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد افتراضي',
            'name_en' => 'Default Vendor',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // Insert valid reversed vendor record under prior schema
        $row2Id = DB::table('opening_trade_items')->insertGetId([
            'id' => 1002,
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'side' => OpeningTradeItem::SIDE_VENDOR,
            'vendor_id' => $vendor->id,
            'opening_date' => '2026-01-01',
            'due_date' => null,
            'currency_code' => 'USD',
            'amount_currency' => '100.000000',
            'exchange_rate' => '3.5000000000',
            'carrying_value_base' => '350.000000',
            'status' => 'reversed',
            'posting_batch_id' => $batchId2Orig,
            'reversal_posting_batch_id' => $batchId2Rev,
            'reversed_at' => '2026-01-15 10:00:00',
            'reversed_by' => $this->owner->id,
            'correction_reason' => 'Migration test reversal reason',
            'party_snapshot' => json_encode(['id' => $vendor->id, 'name_ar' => 'مورد افتراضي']),
            'company_snapshot' => json_encode($companySnapshot1),
            'control_route' => json_encode([]),
            'created_by' => $this->owner->id,
            'source_identity' => 'migration_fixture',
            'idempotency_key' => 'mig-reversed-row-2',
            'idempotency_payload_hash' => hash('sha256', 'mig-2'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 3. Upgrade schema with populated rows
        $this->migration->up();

        // 4. Verify constraint exists on MySQL/MariaDB
        $driver = DB::getDriverName();
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $constraints = DB::table('information_schema.TABLE_CONSTRAINTS')
                ->where('TABLE_SCHEMA', DB::connection()->getDatabaseName())
                ->where('TABLE_NAME', 'opening_trade_items')
                ->where('CONSTRAINT_NAME', 'oti_status_valid')
                ->count();
            $this->assertSame(1, $constraints);
        }

        // 5. Verify records and all snapshots, links, and economics are perfectly preserved
        $preservedRows = DB::table('opening_trade_items')->whereIn('id', [$row1Id, $row2Id])->orderBy('id')->get();
        $this->assertCount(2, $preservedRows);

        $row1 = $preservedRows[0];
        $this->assertSame('posted', $row1->status);
        $this->assertSame($batchId1, (int) $row1->posting_batch_id);
        $this->assertNull($row1->reversal_posting_batch_id);
        $this->assertSame('250.500000', (string) $row1->amount_currency);
        $this->assertSame('250.500000', (string) $row1->carrying_value_base);
        $this->assertSame($partySnapshot1, json_decode((string) $row1->party_snapshot, true));
        $this->assertSame($companySnapshot1, json_decode((string) $row1->company_snapshot, true));
        $this->assertSame($controlRoute1, json_decode((string) $row1->control_route, true));

        $row2 = $preservedRows[1];
        $this->assertSame('reversed', $row2->status);
        $this->assertSame($batchId2Orig, (int) $row2->posting_batch_id);
        $this->assertSame($batchId2Rev, (int) $row2->reversal_posting_batch_id);
        $this->assertSame((int) $this->owner->id, (int) $row2->reversed_by);
        $this->assertSame('Migration test reversal reason', $row2->correction_reason);
        $this->assertSame('100.000000', (string) $row2->amount_currency);
        $this->assertSame('350.000000', (string) $row2->carrying_value_base);

        // Clean up
        DB::table('opening_trade_items')->whereIn('id', [$row1Id, $row2Id])->delete();
        DB::table('posting_batches')->whereIn('id', [$batchId1, $batchId2Orig, $batchId2Rev])->delete();
    }
}
