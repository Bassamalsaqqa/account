<?php

declare(strict_types=1);

namespace Tests\Feature\Phase11;

use App\Services\Purchasing\PayablesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\SalesCorrectionFixtures;
use Tests\TestCase;

/** Round trips only a separately owned, disposable schema with committed business history. */
class CommandMigrationPreservationTest extends TestCase
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

    public function test_forward_and_rollback_preserve_existing_financial_history_and_configuration(): void
    {
        $this->salesFixtures();
        $posted = $this->invoice('100.125', '3.5000000000');
        $this->assertTrue($posted->isPosted());
        $before = $this->businessFingerprint();
        $migration = '2026_10_11_100000_create_command_receipts_table';
        $this->assertTrue(DB::table('migrations')->where('migration', $migration)->exists());
        $migrationCount = DB::table('migrations')->count();
        $tableCount = count(Schema::getTables(DB::connection()->getDatabaseName()));

        $this->assertSame(0, Artisan::call('migrate:rollback', [
            '--database' => DB::getDefaultConnection(), '--step' => $migrationCount, '--force' => true,
            '--path' => 'database/migrations/'.$migration.'.php',
        ]), Artisan::output());
        $this->assertFalse(Schema::hasTable('command_receipts'));
        $this->assertSame($migrationCount - 1, DB::table('migrations')->count());
        $this->assertSame($tableCount - 1, count(Schema::getTables(DB::connection()->getDatabaseName())));
        $this->assertSame($before, $this->businessFingerprint());

        $this->assertSame(0, Artisan::call('migrate', [
            '--database' => DB::getDefaultConnection(), '--force' => true,
            '--path' => 'database/migrations/'.$migration.'.php',
        ]), Artisan::output());
        $this->assertTrue(Schema::hasTable('command_receipts'));
        $this->assertSame($migrationCount, DB::table('migrations')->count());
        $this->assertSame($tableCount, count(Schema::getTables(DB::connection()->getDatabaseName())));
        $this->assertSame(0, DB::table('command_receipts')->count());
        $this->assertSame($before, $this->businessFingerprint());

        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $this->assertSame(0, Artisan::call($command, ['companyPublicId' => $this->company->public_id]), $command.': '.Artisan::output());
        }
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $report = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($report->isHealthy, implode('; ', $report->violations));
        $this->assertSame($before, $this->businessFingerprint());
    }

    private function businessFingerprint(): string
    {
        $rows = [];
        foreach (['companies', 'users', 'company_user', 'company_currencies', 'customers',
            'ledger_accounts', 'document_sequences', 'sales_invoices', 'sales_invoice_lines',
            'posting_batches', 'posting_lines', 'stock_movements', 'money_accounts',
            'permissions', 'roles', 'role_has_permissions', 'model_has_roles', 'model_has_permissions'] as $table) {
            $records = DB::table($table)->get()->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($records);
            $rows[$table] = $records;
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
