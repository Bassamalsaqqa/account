<?php

declare(strict_types=1);

namespace Tests\Feature\Phase12;

use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

class AssistantMigrationPreservationTest extends TestCase
{
    use SalesCorrectionFixtures;

    private ?DisposableMariaDbSchema $fixtureSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
        } finally {
            parent::tearDown();
        }
    }

    public function test_additive_audit_migration_round_trip_preserves_business_data_and_all_six_reconciliations(): void
    {
        $this->salesFixtures();
        $posted = $this->invoice('100.125', '3.5000000000');
        $this->assertTrue($posted->isPosted());
        $migration = '2026_10_11_120000_create_assistant_requests_table';
        $this->assertSame($migration, DB::table('migrations')->orderByDesc('migration')->value('migration'));
        $before = $this->fingerprint();
        $tables = count(Schema::getTables(DB::connection()->getDatabaseName()));
        $migrations = DB::table('migrations')->count();
        $this->assertSame(85, $tables);
        $this->assertSame(65, $migrations);
        $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true]), Artisan::output());
        $this->assertFalse(Schema::hasTable('assistant_requests'));
        $this->assertTrue(Schema::hasTable('command_receipts'));
        $this->assertSame($tables - 1, count(Schema::getTables(DB::connection()->getDatabaseName())));
        $this->assertSame($migrations - 1, DB::table('migrations')->count());
        $this->assertSame($before, $this->fingerprint());
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/migrations/'.$migration.'.php', '--force' => true]), Artisan::output());
        $this->assertTrue(Schema::hasTable('assistant_requests'));
        $this->assertSame($tables, count(Schema::getTables(DB::connection()->getDatabaseName())));
        $this->assertSame($migrations, DB::table('migrations')->count());
        $this->assertSame($before, $this->fingerprint());
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['companyPublicId' => $this->company->public_id]), $command.': '.Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $report = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode('; ', $report->violations));
        $this->assertSame($before, $this->fingerprint());
    }

    private function fingerprint(): string
    {
        $rows = [];
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if (in_array($table, ['assistant_requests', 'migrations', 'sessions', 'cache', 'cache_locks'], true)) {
                continue;
            }
            $records = DB::table($table)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($records);
            $rows[$table] = $records;
        }
        ksort($rows);

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
