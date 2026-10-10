<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Reporting\EnsureReportingFoundationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Application\Reporting\DTO\ReportFilters;
use App\Application\Reporting\DTO\ReportPeriod;
use App\Application\Reporting\DTO\ReportResult;
use App\Application\Reporting\Queries\MoneyBalanceReportQuery;
use App\Application\Reporting\Queries\SalesSummaryReportQuery;
use App\Application\Reporting\Security\ReportingGuard;
use App\Application\Reporting\Support\CsvReportWriter;
use App\Http\Controllers\ReportCsvController;
use App\Models\Company;
use App\Models\Customer;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\DisposableMariaDbSchema;

class Correction02CsvSnapshotTest extends Phase8TestCase
{
    protected function beforeRefreshingDatabase(): void
    {
        DisposableMariaDbSchema::assertPrimarySchema((string) config('database.connections.mysql.database'));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createMemberWithPermissions(array $permissions): User
    {
        $user = User::factory()->create();
        $this->company->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);

        $role = Role::create([
            'company_id' => $this->company->id,
            'name' => 'Role_'.Str::random(10),
            'guard_name' => 'web',
        ]);
        $role->givePermissionTo(array_unique($permissions));
        $user->assignRole($role);
        $this->activateUser($user);

        return $user;
    }

    public function test_nested_export_period_remains_fixed_across_rollover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-31 12:00:00', $this->company->timezone));
        $query = new class
        {
            public array $dates = [];

            public function execute(Company $company, array $input): ReportResult
            {
                app(ReportingGuard::class)->authorize($company, null, ['reports.sales.view', 'sales.invoice.view']);
                $period = ReportFilters::fromArray($company, $input)->period;
                $this->dates[] = [$period->startDate, $period->endDate];
                if (count($this->dates) === 1) {
                    Carbon::setTestNow(Carbon::parse('2026-11-01 12:00:00', $company->timezone));
                }

                return new ReportResult('sales.summary', $input, [], [['document_number' => 'INV-SNAPSHOT', 'business_date' => $period->endDate, 'currency_code' => 'ILS', 'gross_sales_base' => '10.000000', 'net_sales_base' => '10.000000']], ['base_currency_code' => 'ILS']);
            }
        };
        app()->instance(SalesSummaryReportQuery::class, $query);
        try {
            $this->get(route('reports.export', ['reportKey' => 'sales.summary', 'filters' => ['period' => ['preset' => 'this_month']]]))->assertOk()->streamedContent();
            $this->assertSame([['2026-10-01', '2026-10-31'], ['2026-10-01', '2026-10-31']], $query->dates);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_current_export_cutoff_remains_fixed_across_rollover(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-09 12:00:00', $this->company->timezone));
        $query = new class
        {
            public array $dates = [];

            public function execute(Company $company, array $input): ReportResult
            {
                app(ReportingGuard::class)->authorize($company, null, ['reports.money.view', 'money.cash.view']);
                $period = ReportFilters::fromArray($company, $input, ReportPeriod::fromPreset(ReportPeriod::PRESET_TODAY, $company))->period;
                $this->dates[] = [$period->startDate, $period->endDate];
                if (count($this->dates) === 1) {
                    Carbon::setTestNow(Carbon::parse('2026-10-10 12:00:00', $company->timezone));
                }

                return new ReportResult('money.balances', $input, [], [['name' => 'Cash snapshot', 'account_type' => 'cash', 'currency_code' => 'ILS', 'balance_currency' => '10.000000', 'balance_base' => '10.000000']], ['base_currency_code' => 'ILS']);
            }
        };
        app()->instance(MoneyBalanceReportQuery::class, $query);
        try {
            $this->get(route('reports.export', ['reportKey' => 'money.balances']))->assertOk()->streamedContent();
            $this->assertSame([['2026-10-09', '2026-10-09'], ['2026-10-09', '2026-10-09']], $query->dates);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_real_exporter_multipage_snapshot_with_concurrent_backdated_event(): void
    {
        $originalDb = config('database.connections.mysql.database');
        $disposable = DisposableMariaDbSchema::createFromSource($originalDb);
        $disposable->switchLaravelConnection();

        try {
            $owner = User::create([
                'public_id' => (string) Str::ulid(),
                'name' => 'Snapshot Owner',
                'email' => 'snap-owner@example.com',
                'password' => 'secret',
                'locale' => 'ar',
            ]);

            app(CompanyContext::class)->clear();
            $company = app(CreateCompanyAction::class)->execute($owner, [
                'name_ar' => 'شركة لقطة التصدير الحقيقي',
                'base_currency_code' => 'ILS',
            ]);

            app(CompanyContext::class)->setCompany($company, $owner);
            setPermissionsTeamId($company->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

            app(EnsureReportingFoundationAction::class)->execute($company);
            $owner->unsetRelation('roles')->unsetRelation('permissions');
            auth()->login($owner);

            $customer = Customer::create([
                'company_id' => $company->id,
                'code' => 'CUST-SNAP-01',
                'name_ar' => 'عميل اللقطة',
                'name_en' => 'Snapshot Customer',
                'status' => 'active',
                'created_by' => $owner->id,
            ]);

            // Seed 105 canonical sales invoice rows using real canonical posting actions
            $createAction = app(CreateSalesInvoiceDraftAction::class);
            $postAction = app(PostSalesInvoiceAction::class);
            $originalInvoices = [];

            for ($i = 1; $i <= 105; $i++) {
                $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $draft = $createAction->execute($company, $owner, [
                    'customer_id' => $customer->id,
                    'currency_code' => 'ILS',
                    'exchange_rate' => '1.0000000000',
                    'issue_date' => '2026-10-05',
                    'lines' => [
                        [
                            'product_id' => null,
                            'item_description' => "Item {$pad}",
                            'quantity' => '1.000000',
                            'unit_price' => '100.000000',
                        ],
                    ],
                ]);
                $posted = $postAction->execute($draft, $owner);
                $originalInvoices[] = $posted->invoice_number;
            }

            $concurrentInserted = false;
            $compKey = (int) $company->id;
            $ownerKey = (int) $owner->id;
            $custKey = (int) $customer->id;

            DB::listen(function ($query) use (&$concurrentInserted, $disposable, $compKey, $ownerKey, $custKey): void {
                if (! $concurrentInserted && str_contains($query->sql, 'sales_invoices') && (str_contains($query->sql, 'limit 100') || str_contains($query->sql, 'LIMIT 100'))) {
                    $concurrentInserted = true;
                    // Concurrent independent application process creates and posts earlier-sorted backdated canonical invoice!
                    $disposable->runConcurrentCanonicalPost([
                        'company_id' => $compKey,
                        'owner_id' => $ownerKey,
                        'customer_id' => $custKey,
                        'product_id' => null,
                        'item_description' => 'Concurrent Backdated Item',
                        'issue_date' => '2026-10-01',
                        'quantity' => '1.000000',
                        'unit_price' => '100.000000',
                    ]);
                }
            });

            $request = Request::create('/reports/export', 'GET', [
                'reportKey' => 'sales.summary',
                'filters' => [
                    'from' => '2026-10-01',
                    'to' => '2026-10-31',
                ],
            ]);

            $controller = app(ReportCsvController::class);
            $response = $controller($request, 'sales.summary');

            ob_start();
            $response->sendContent();
            $csvContent = (string) ob_get_clean();

            $lines = array_filter(explode("\n", trim($csvContent)));
            // Exactly 1 header row + 105 data rows = 106 lines
            $this->assertCount(106, $lines);

            // Exclusion of post-snapshot record
            $this->assertStringNotContainsString('Concurrent Backdated Item', $csvContent);

            // Every original invoice identifier exactly once
            foreach ($originalInvoices as $invNum) {
                $this->assertSame(1, substr_count($csvContent, $invNum));
            }

            // Release of snapshot before delivery confirmed by fresh post-snapshot read
            $this->assertTrue(DB::table('sales_invoice_lines')->where('item_description', 'Concurrent Backdated Item')->exists());
        } finally {
            $disposable->drop();
        }
    }

    public function test_canonical_export_with_database_permission_cache_remains_read_only(): void
    {
        $previousStore = config('permission.cache.store');
        $configured = false;
        DB::listen(function ($query) use (&$configured): void {
            if (! $configured && str_starts_with((string) $query->connection->getDatabaseName(), 'accounting_p8_tmp_')) {
                $configured = true;
                config(['permission.cache.store' => 'database']);
                app(PermissionRegistrar::class)->initializeCache();
            }
        });
        try {
            $this->test_real_exporter_multipage_snapshot_with_concurrent_backdated_event();
            $this->assertTrue($configured);
        } finally {
            config(['permission.cache.store' => $previousStore]);
            app(PermissionRegistrar::class)->initializeCache();
            app(PermissionRegistrar::class)->clearPermissionsCollection();
        }
    }

    public function test_mid_preparation_authority_revocation_denies_export(): void
    {
        $originalDb = config('database.connections.mysql.database');
        $disposable = DisposableMariaDbSchema::createFromSource($originalDb);
        $disposable->switchLaravelConnection();

        try {
            $owner = User::create([
                'public_id' => (string) Str::ulid(),
                'name' => 'Owner Revoke',
                'email' => 'snap-revoke@example.com',
                'password' => 'secret',
                'locale' => 'ar',
            ]);

            app(CompanyContext::class)->clear();
            $company = app(CreateCompanyAction::class)->execute($owner, [
                'name_ar' => 'شركة لقطة سحب الصلاحية',
                'base_currency_code' => 'ILS',
            ]);

            $member = User::create([
                'public_id' => (string) Str::ulid(),
                'name' => 'Member Revoke',
                'email' => 'snap-member@example.com',
                'password' => 'secret',
                'locale' => 'ar',
            ]);

            $company->memberships()->create([
                'user_id' => $member->id,
                'status' => 'active',
                'is_owner' => false,
            ]);

            setPermissionsTeamId($company->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
            app(EnsureReportingFoundationAction::class)->execute($company);

            $role = Role::create([
                'company_id' => $company->id,
                'name' => 'SalesExporterRole',
                'guard_name' => 'web',
            ]);
            $role->givePermissionTo(['reports.sales.view', 'sales.invoice.view']);
            $member->assignRole($role);

            app(CompanyContext::class)->setCompany($company, $member);
            auth()->login($member);

            // Independent connection 2
            $pdo2 = $disposable->createSeparatePdo();

            $revoked = false;
            $memberKey = (int) $member->id;
            $compKey = (int) $company->id;

            DB::listen(function ($query) use (&$revoked, $pdo2, $memberKey, $compKey): void {
                if (! $revoked && str_contains($query->sql, 'sales_invoices')) {
                    $revoked = true;
                    // Revoke membership mid-preparation from outside connection!
                    $pdo2->exec("UPDATE company_user SET status = 'suspended' WHERE user_id = {$memberKey} AND company_id = {$compKey}");
                }
            });

            $request = Request::create('/reports/export', 'GET', [
                'reportKey' => 'sales.summary',
                'filters' => [
                    'from' => '2026-10-01',
                    'to' => '2026-10-31',
                ],
            ]);

            $controller = app(ReportCsvController::class);

            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Authority changed or revoked during export preparation.');
            $controller($request, 'sales.summary');
        } finally {
            $disposable->drop();
        }
    }

    public function test_two_connection_mariadb_repeatable_read_snapshot_isolation(): void
    {
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        $primaryCounts = [];
        foreach (['users', 'companies', 'customers'] as $table) {
            $primaryCounts[$table] = DB::table($table)->count();
        }
        $disposable = DisposableMariaDbSchema::createFromSource($primary);
        $pdo1 = $pdo2 = null;
        try {
            // Committed fixture rows belong only to this freshly owned auxiliary schema.
            $pdo1 = $disposable->createSeparatePdo();
            $pdo2 = $disposable->createSeparatePdo();
            $now = now()->toDateTimeString();

            // 1. Create committed user on pdo1 (for foreign key created_by)
            $userPublicId = (string) Str::ulid();
            $stmtUser = $pdo1->prepare('INSERT INTO users (public_id, name, email, password, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmtUser->execute([$userPublicId, 'مستخدم تجريبي للعزل', 'snap-'.Str::random(8).'@example.com', 'secret', 'ar', $now, $now]);
            $testUserId = (int) $pdo1->lastInsertId();

            // 2. Create committed company on pdo1 (the migrated schema contains standard currencies).
            $compPublicId = (string) Str::ulid();
            $stmtComp = $pdo1->prepare('INSERT INTO companies (public_id, name_ar, base_currency_code, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            $stmtComp->execute([$compPublicId, 'شركة اختبار العزل', 'ILS', 'active', $now, $now]);
            $testCompanyId = (int) $pdo1->lastInsertId();

            // 3. Seed 105 rows on pdo1
            for ($i = 1; $i <= 105; $i++) {
                $pad = str_pad((string) $i, 3, '0', STR_PAD_LEFT);
                $stmt = $pdo1->prepare('INSERT INTO customers (public_id, company_id, code, name_ar, name_en, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([
                    (string) Str::ulid(),
                    $testCompanyId,
                    "SNAP-{$pad}",
                    "عميل لقطة {$pad}",
                    "Snapshot Customer {$pad}",
                    'active',
                    $testUserId,
                    $now,
                    $now,
                ]);
            }

            // 4. Connection 1 starts repeatable read snapshot
            $pdo1->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo1->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            // 5. Connection 1 reads page 1 (first 100 rows, ordered by code ASC)
            $stmtPage1 = $pdo1->prepare('SELECT code FROM customers WHERE company_id = ? AND code LIKE "SNAP-%" ORDER BY code ASC LIMIT 100 OFFSET 0');
            $stmtPage1->execute([$testCompanyId]);
            $page1Codes = $stmtPage1->fetchAll(\PDO::FETCH_COLUMN);
            $this->assertCount(100, $page1Codes);
            $this->assertSame('SNAP-001', $page1Codes[0]);
            $this->assertSame('SNAP-100', $page1Codes[99]);

            // 6. Connection 2 inserts an earlier-sorted record (SNAP-000) and commits immediately
            $stmtConn2 = $pdo2->prepare('INSERT INTO customers (public_id, company_id, code, name_ar, name_en, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmtConn2->execute([
                (string) Str::ulid(),
                $testCompanyId,
                'SNAP-000',
                'عميل متداخل 000',
                'Concurrent Customer 000',
                'active',
                $testUserId,
                $now,
                $now,
            ]);

            // 7. Verify Connection 2 sees SNAP-000
            $stmtCheck2 = $pdo2->prepare('SELECT COUNT(*) FROM customers WHERE company_id = ? AND code = "SNAP-000"');
            $stmtCheck2->execute([$testCompanyId]);
            $this->assertSame(1, (int) $stmtCheck2->fetchColumn());

            // 8. Under repeatable read, Connection 1 reads page 2 (remaining 5 rows, offset 100)
            $stmtPage2 = $pdo1->prepare('SELECT code FROM customers WHERE company_id = ? AND code LIKE "SNAP-%" ORDER BY code ASC LIMIT 100 OFFSET 100');
            $stmtPage2->execute([$testCompanyId]);
            $page2Codes = $stmtPage2->fetchAll(\PDO::FETCH_COLUMN);

            // Page 2 must have exactly 5 rows (SNAP-101 to SNAP-105) and NOT be shifted by SNAP-000!
            $this->assertCount(5, $page2Codes);
            $this->assertSame('SNAP-101', $page2Codes[0]);
            $this->assertSame('SNAP-105', $page2Codes[4]);
            $this->assertNotContains('SNAP-000', $page2Codes);

            // 9. End snapshot transaction on Connection 1
            $pdo1->exec('COMMIT');

            // 10. After commit, Connection 1 with a new query can now see SNAP-000
            $stmtPostCommit = $pdo1->prepare('SELECT COUNT(*) FROM customers WHERE company_id = ? AND code = "SNAP-000"');
            $stmtPostCommit->execute([$testCompanyId]);
            $this->assertSame(1, (int) $stmtPostCommit->fetchColumn());
        } finally {
            try {
                if ($pdo1?->inTransaction()) {
                    $pdo1->rollBack();
                }
            } finally {
                $pdo1 = $pdo2 = null;
                $disposable->drop();
                foreach ($primaryCounts as $table => $count) {
                    $this->assertSame($count, DB::table($table)->count(), 'Snapshot fixtures must not alter the primary test schema.');
                }
            }
        }
    }

    public function test_csv_constants_and_bounds_defined(): void
    {
        $this->assertSame(50_000, CsvReportWriter::MAX_ROWS);
        $this->assertSame(50 * 1024 * 1024, CsvReportWriter::MAX_BYTES);
        $this->assertSame(30, CsvReportWriter::MAX_SECONDS);
    }

    public function test_export_preserves_no_financial_or_inventory_mutations(): void
    {
        $this->activateUser($this->owner);

        $initialBatches = DB::table('posting_batches')->count();
        $initialMovements = DB::table('stock_movements')->count();

        $response = $this->get(route('reports.export', ['reportKey' => 'sales.summary']));
        $response->assertOk();

        $this->assertSame($initialBatches, DB::table('posting_batches')->count());
        $this->assertSame($initialMovements, DB::table('stock_movements')->count());
    }

    public function test_authorization_revocation_delivers_no_csv_bytes(): void
    {
        $user = $this->createMemberWithPermissions(['reports.sales.view', 'sales.invoice.view']);
        $this->activateUser($user);

        // Revoke reports.sales.view
        $role = Role::where('company_id', $this->company->id)->where('name', $user->roles->first()->name)->firstOrFail();
        $role->revokePermissionTo('reports.sales.view');

        $response = $this->get(route('reports.export', ['reportKey' => 'sales.summary']));
        $response->assertForbidden();
    }

    public function test_mid_preparation_optional_cost_permission_revocation_denies_export(): void
    {
        $originalDb = config('database.connections.mysql.database');
        $disposable = DisposableMariaDbSchema::createFromSource($originalDb);
        $disposable->switchLaravelConnection();

        try {
            $owner = User::create([
                'public_id' => (string) Str::ulid(),
                'name' => 'Owner Revoke',
                'email' => 'snap-revoke-cost-owner@example.com',
                'password' => 'secret',
                'locale' => 'ar',
            ]);

            app(CompanyContext::class)->clear();
            $company = app(CreateCompanyAction::class)->execute($owner, [
                'name_ar' => 'شركة لقطة سحب تكلفة الشراء',
                'base_currency_code' => 'ILS',
            ]);

            $member = User::create([
                'public_id' => (string) Str::ulid(),
                'name' => 'Member Revoke Cost',
                'email' => 'snap-revoke-cost-member@example.com',
                'password' => 'secret',
                'locale' => 'ar',
            ]);

            $company->memberships()->create([
                'user_id' => $member->id,
                'status' => 'active',
                'is_owner' => false,
            ]);

            setPermissionsTeamId($company->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
            app(EnsureReportingFoundationAction::class)->execute($company);

            $role = Role::create([
                'company_id' => $company->id,
                'name' => 'SalesAndCostExporterRole',
                'guard_name' => 'web',
            ]);
            $role->givePermissionTo(['reports.sales.view', 'sales.invoice.view', 'purchasing.cost.view']);
            $member->assignRole($role);

            app(CompanyContext::class)->setCompany($company, $owner);
            auth()->login($owner);

            $customer = Customer::create([
                'company_id' => $company->id,
                'code' => 'CUST-COST-01',
                'name_ar' => 'عميل اختبار التكلفة',
                'status' => 'active',
                'created_by' => $owner->id,
            ]);

            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
                'customer_id' => $customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => '2026-10-05',
                'lines' => [
                    [
                        'product_id' => null,
                        'item_description' => 'Test Item',
                        'quantity' => '1.000000',
                        'unit_price' => '100.000000',
                    ],
                ],
            ]);
            app(PostSalesInvoiceAction::class)->execute($draft, $owner);

            app(CompanyContext::class)->setCompany($company, $member);
            auth()->login($member);

            // Independent connection 2
            $pdo2 = $disposable->createSeparatePdo();

            $revoked = false;
            $roleKey = (int) $role->id;

            DB::listen(function ($query) use (&$revoked, $pdo2, $roleKey): void {
                if (! $revoked && str_contains($query->sql, 'sales_invoices')) {
                    $revoked = true;
                    // Revoke purchasing.cost.view mid-preparation from independent committed connection!
                    $pdo2->exec("DELETE FROM role_has_permissions WHERE role_id = {$roleKey} AND permission_id = (SELECT id FROM permissions WHERE name = 'purchasing.cost.view' LIMIT 1)");
                }
            });

            $request = Request::create('/reports/export', 'GET', [
                'reportKey' => 'sales.summary',
                'filters' => [
                    'from' => '2026-10-01',
                    'to' => '2026-10-31',
                ],
            ]);

            $controller = app(ReportCsvController::class);

            $this->expectException(HttpException::class);
            $this->expectExceptionMessage('Authority changed or revoked during export preparation.');
            $controller($request, 'sales.summary');
        } finally {
            $disposable->drop();
        }
    }

    public function test_spool_and_settings_cleanup_on_limit_failure(): void
    {
        $originalDb = config('database.connections.mysql.database');
        $disposable = DisposableMariaDbSchema::createFromSource($originalDb);
        $disposable->switchLaravelConnection();

        try {
            $owner = User::create([
                'public_id' => (string) Str::ulid(),
                'name' => 'Owner Limit Test',
                'email' => 'snap-limit-owner@example.com',
                'password' => 'secret',
                'locale' => 'ar',
            ]);

            app(CompanyContext::class)->clear();
            $company = app(CreateCompanyAction::class)->execute($owner, [
                'name_ar' => 'شركة اختبار الحدود',
                'base_currency_code' => 'ILS',
            ]);

            app(CompanyContext::class)->setCompany($company, $owner);
            setPermissionsTeamId($company->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);
            app(EnsureReportingFoundationAction::class)->execute($company);
            auth()->login($owner);

            DB::statement('SET SESSION max_statement_time = 0.75');
            $initialTimeout = (string) DB::scalar('SELECT @@session.max_statement_time');

            // Real CsvReportWriter is retained in the container!
            // Mock query returns paginated rows exceeding MAX_ROWS (50,001 rows total)
            $largeQuery = new class
            {
                public function execute(Company $company, array $input): ReportResult
                {
                    app(ReportingGuard::class)->authorize($company, null, ['reports.sales.view', 'sales.invoice.view']);
                    $page = (int) ($input['page'] ?? 1);
                    $rows = [];
                    // Return 25,000 rows on page 1, 25,000 on page 2, and 1 on page 3 -> total 50,001 rows!
                    $count = ($page === 3) ? 1 : 25000;
                    for ($i = 1; $i <= $count; $i++) {
                        $rows[] = [
                            'document_number' => "INV-{$page}-{$i}",
                            'business_date' => '2026-10-08',
                            'currency_code' => 'ILS',
                            'gross_sales_base' => '1.000000',
                            'net_sales_base' => '1.000000',
                        ];
                    }

                    return new ReportResult(
                        'sales.summary',
                        $input,
                        [],
                        $rows,
                        ['base_currency_code' => 'ILS'],
                        ['current_page' => $page, 'per_page' => 25000, 'total' => 50001, 'last_page' => 3]
                    );
                }
            };
            app()->instance(SalesSummaryReportQuery::class, $largeQuery);

            $request = Request::create('/reports/export', 'GET', [
                'reportKey' => 'sales.summary',
                'filters' => [
                    'from' => '2026-10-01',
                    'to' => '2026-10-31',
                ],
            ]);

            $caught = false;
            try {
                app(ReportCsvController::class)($request, 'sales.summary');
            } catch (HttpException $e) {
                $caught = true;
                $this->assertSame(422, $e->getStatusCode());
                $this->assertSame('Export exceeded maximum row limit.', $e->getMessage());
            }
            $this->assertTrue($caught, 'Controller must abort 422 on row limit failure.');

            // Verify clean release of transaction snapshot and exact restoration of session timeout
            $this->assertSame(0, DB::transactionLevel(), 'Transaction must be closed cleanly.');
            $currentTimeout = (string) DB::scalar('SELECT @@session.max_statement_time');
            $this->assertSame($initialTimeout, $currentTimeout, 'Session statement timeout must be restored exactly.');
        } finally {
            $disposable->drop();
        }
    }
}
