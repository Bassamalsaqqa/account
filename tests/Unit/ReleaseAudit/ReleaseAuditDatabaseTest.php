<?php

declare(strict_types=1);

namespace Tests\Unit\ReleaseAudit;

use App\Actions\Accounting\EnsurePhase7FoundationAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use PDOException;
use ReleaseAudit\Budget;
use ReleaseAudit\Fingerprint;
use ReleaseAudit\Guard;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\EnforcesDisposableDatabaseOwnership;
use Tests\TestCase;

require_once dirname(__DIR__, 3).'/tools/release-audit/Guard.php';
require_once dirname(__DIR__, 3).'/tools/release-audit/Fingerprint.php';

final class ReleaseAuditDatabaseTest extends TestCase
{
    use EnforcesDisposableDatabaseOwnership;

    private ?string $tempDir = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOwnedDisposableDatabase();

        $this->tempDir = sys_get_temp_dir().'/rel-audit-db-'.bin2hex(random_bytes(12));
        mkdir($this->tempDir, 0700);
    }

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $files = glob($this->tempDir.'/*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_empty_company_scope_is_not_a_healthy_audit(): void
    {
        $owned = DisposableMariaDbSchema::createFromSource(DB::connection()->getDatabaseName());
        try {
            $owned->switchLaravelConnection();
            self::assertSame(0, DB::table('companies')->count());
            $identity = DB::selectOne('SELECT @@hostname AS hostname, @@server_id AS server_id');
            $control = $this->tempDir.'/empty-control.json';
            file_put_contents($control, json_encode([
                'mode' => 'disposable-test', 'host' => '127.0.0.1', 'port' => 3342,
                'user' => 'test_runner', 'password' => '', 'schema' => $owned->schemaName(),
                'server_hostname' => $identity->hostname, 'server_id' => (int) $identity->server_id,
            ], JSON_THROW_ON_ERROR));
            chmod($control, 0600);
            $root = dirname(__DIR__, 3);
            $head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD'));
            $output = $this->tempDir.'/audit-'.bin2hex(random_bytes(12)).'.json';
            $process = new Process([PHP_BINARY, $root.'/tools/release-audit/audit.php', '--disposable-test', $control, hash_file('sha256', $control), $head, $output], $root, array_replace(getenv(), $owned->environment()));
            $process->setTimeout(60)->run();
            self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            $report = json_decode((string) file_get_contents($output), true, flags: JSON_THROW_ON_ERROR);
            self::assertFalse($report['healthy']);
            self::assertTrue($report['scope_empty']);
            self::assertSame(0, $report['companies']);
            self::assertSame([], $report['domains']);
        } finally {
            $owned->restoreLaravelConnection();
            $owned->drop();
        }
    }

    public function test_engine_readonly_snapshot_enforces_no_dml(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

        try {
            $pdo->exec("INSERT INTO companies (public_id, name, code, currency_code, active, created_at, updated_at) VALUES ('01TESTFORBIDDEN000000000', 'Illegal Corp', 'ILL', 'USD', 1, NOW(), NOW())");
            self::fail('Database engine permitted DML execution inside a READ ONLY consistent snapshot');
        } catch (PDOException $e) {
            self::assertStringContainsString('READ ONLY', $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_fingerprint_deterministic_and_classifies_tables(): void
    {
        $pdo = DB::connection()->getPdo();
        $fingerprint = Fingerprint::capture($pdo);

        self::assertNotEmpty($fingerprint);
        self::assertArrayHasKey('companies', $fingerprint);
        self::assertArrayHasKey('ledger_accounts', $fingerprint);

        foreach (['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens'] as $mutableTable) {
            if (isset($fingerprint[$mutableTable])) {
                self::assertSame('operational_mutable', $fingerprint[$mutableTable]['classification']);
            }
        }

        self::assertSame('preservation', $fingerprint['companies']['classification']);
        self::assertSame('preservation', $fingerprint['ledger_accounts']['classification']);
        self::assertArrayHasKey('normalized_schema_sha256', $fingerprint['companies']);
        self::assertArrayHasKey('auto_increment', $fingerprint['companies']);

        // Assert determinism
        $fingerprint2 = Fingerprint::capture($pdo);
        self::assertSame($fingerprint, $fingerprint2);
    }

    public function test_audit_and_compare_cli_lifecycle_on_canonical_fixtures(): void
    {
        $root = dirname(__DIR__, 3);
        $head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
        $dirty = shell_exec('git -C '.escapeshellarg($root).' status --porcelain --untracked-files=normal 2>/dev/null') ?? '';
        if (trim($dirty) !== '') {
            self::markTestSkipped('Working directory has untracked or modified files; CLI audit requires clean commit.');
        }

        // Setup canonical company and actors committed to disposable MariaDB
        $owner = User::factory()->create(['locale' => 'ar']);
        $company = app(CreateCompanyAction::class)->execute($owner, [
            'name_ar' => 'شركة التدقيق البرمجية',
            'name_en' => 'Release Audit Co',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyContext::class)->setCompany($company, $owner);
        $this->actingAs($owner);
        setPermissionsTeamId($company->id);

        app(EnsurePhase7FoundationAction::class)->execute($company);

        $vendor = app(VendorCatalogService::class)->save($company, $owner, [
            'name_ar' => 'مورد التوريدات الرئيسي',
            'name_en' => 'Main Vendor',
            'default_currency_code' => 'ILS',
        ]);

        $warehouse = Warehouse::where('company_id', $company->id)->firstOrFail();
        $product = app(ProductCatalogService::class)->createProduct($company, [
            'name_ar' => 'سلعة غذائية',
            'name_en' => 'Food Item',
            'sku' => 'ITM-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id,
        ], $owner->id);
        $unit = ProductUnit::where('product_id', $product->id)->firstOrFail();

        $moneyAccountAction = app(CreateMoneyAccountAction::class);
        $cashAccount = $moneyAccountAction->execute($company, $owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق الشيكل الرئيسي',
            'name_en' => 'Main ILS Cash Box',
        ]);

        $operatingCategory = ExpenseCategory::where('company_id', $company->id)
            ->where('code', 'electricity')
            ->firstOrFail();

        $employee = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => (int) $company->id,
            'code' => 'EMP-001',
            'name' => 'أحمد العامل',
            'job_title' => 'محاسب',
            'hire_date' => '2026-01-01',
            'default_salary' => '1000.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => (int) $owner->id,
        ]);

        // Sales Invoice
        $customer = Customer::create(['company_id' => $company->id, 'name_ar' => 'عميل التدقيق', 'name_en' => 'Audit Customer', 'active' => true, 'created_by' => $owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'issue_date' => '2026-10-01',
            'lines' => [['product_id' => null, 'item_description' => 'Audit Service', 'quantity' => '1', 'unit_price' => '50']],
        ]);
        app(PostSalesInvoiceAction::class)->execute($draft, $owner);

        // Operating Expense
        app(PostExpenseAction::class)->execute($company, $owner, [
            'category_id' => $operatingCategory->id,
            'expense_date' => '2026-10-01',
            'classification' => Expense::CLASSIFICATION_OPERATING,
            'description' => 'Test operating expense',
            'currency_code' => 'ILS',
            'amount' => '10',
            'exchange_rate' => '1',
            'payment_method' => 'cash',
            'money_account_id' => $cashAccount->id,
            'idempotency_key' => 'test-expense-'.bin2hex(random_bytes(6)),
        ]);

        // Salary Entry
        app(PostSalaryEntryAction::class)->execute($company, $owner, [
            'employee_id' => $employee->id,
            'recognition_date' => '2026-10-05',
            'period_start' => '2026-10-01',
            'period_end' => '2026-10-31',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'base_salary' => '100',
            'bonus' => '0',
            'deduction' => '0',
            'advances' => [],
            'idempotency_key' => 'test-salary-'.bin2hex(random_bytes(6)),
        ]);

        // Purchase and Payment
        $purchase = app(CreatePurchaseDraftAction::class)->execute($company, $owner, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $warehouse->id,
            'purchase_date' => '2026-10-01',
            'due_date' => null,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $product->id,
                    'quantity' => '10',
                    'unit_cost' => '10',
                ],
            ],
        ]);
        $postedPurchase = app(PostPurchaseAction::class)->execute($purchase, $owner);

        app(PostVendorPaymentAction::class)->execute($company, $owner, [
            'vendor_id' => $vendor->id,
            'money_account_id' => $cashAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'amount' => '1',
            'idempotency_key' => 'test-payment-'.bin2hex(random_bytes(6)),
            'allocations' => [['purchase_id' => $postedPurchase->id, 'allocated_amount' => '1']],
        ]);

        // Clear ambient context so system reconciliation operates context-free
        app(CompanyContext::class)->clear();

        $schema = DB::connection()->getDatabaseName();
        $pdo = DB::connection()->getPdo();
        $identity = $pdo->query('SELECT @@hostname, @@server_id')->fetch(PDO::FETCH_NUM);
        self::assertIsArray($identity);
        $serverHostname = (string) $identity[0];
        $serverId = (int) $identity[1];

        // Prepare control file
        $controlPath = $this->tempDir.'/control.json';
        $controlPayload = [
            'mode' => 'disposable-test',
            'host' => '127.0.0.1',
            'port' => 3342,
            'user' => 'test_runner',
            'password' => '',
            'schema' => $schema,
            'server_hostname' => $serverHostname,
            'server_id' => $serverId,
        ];
        file_put_contents($controlPath, json_encode($controlPayload, JSON_THROW_ON_ERROR));
        chmod($controlPath, 0600);
        $controlPin = hash_file('sha256', $controlPath);

        // 1. Run audit on healthy state
        $out1 = $this->tempDir.'/audit-'.bin2hex(random_bytes(12)).'.json';
        $proc1 = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/audit.php',
            '--disposable-test',
            $controlPath,
            $controlPin,
            $head,
            $out1,
        ], $root, getenv());
        $proc1->run();

        self::assertSame(0, $proc1->getExitCode(), 'Healthy audit exited with non-zero: '.$proc1->getErrorOutput());
        self::assertFileExists($out1);
        self::assertSame(0600, fileperms($out1) & 0777);

        $report1 = json_decode((string) file_get_contents($out1), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(1, $report1['version']);
        self::assertTrue($report1['healthy']);
        self::assertSame($head, $report1['code']);
        self::assertNotEmpty($report1['domains']);
        self::assertCount(6, $report1['domains']);
        foreach ($report1['domains'] as $domainReport) {
            self::assertTrue($domainReport['healthy'], "Domain {$domainReport['domain']} failed");
            self::assertSame(0, $domainReport['count']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $domainReport['digest']);
        }

        // Compare report1 with report1 -> FINGERPRINTS_EQUAL
        $cmpProc1 = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/compare.php',
            $out1,
            $out1,
            Guard::implementationDigest(),
        ], $root);
        $cmpProc1->run();
        self::assertSame(0, $cmpProc1->getExitCode());
        self::assertSame("FINGERPRINTS_EQUAL\n", $cmpProc1->getOutput());

        // 2. Corrupt ledger balance to verify unhealthy detection
        DB::table('posting_lines')->insert([
            'posting_batch_id' => 1,
            'company_id' => $company->id,
            'ledger_account_id' => $cashAccount->ledger_account_id,
            'line_number' => 999,
            'debit_base' => '99999.000000',
            'credit_base' => '0.000000',
            'created_at' => now(),
        ]);

        $out2 = $this->tempDir.'/audit-'.bin2hex(random_bytes(12)).'.json';
        $proc2 = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/audit.php',
            '--disposable-test',
            $controlPath,
            $controlPin,
            $head,
            $out2,
        ], $root, getenv());
        $proc2->run();

        self::assertSame(2, $proc2->getExitCode(), 'Corrupted audit did not exit with code 2');
        self::assertFileExists($out2);

        $report2 = json_decode((string) file_get_contents($out2), true, flags: JSON_THROW_ON_ERROR);
        self::assertFalse($report2['healthy']);
        self::assertNotSame($report1['tables'], $report2['tables']);

        // Compare report1 with report2 -> FINGERPRINTS_DIFFER
        $cmpProc2 = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/compare.php',
            $out1,
            $out2,
            Guard::implementationDigest(),
        ], $root);
        $cmpProc2->run();
        self::assertSame(2, $cmpProc2->getExitCode());
        self::assertSame("FINGERPRINTS_DIFFER\n", $cmpProc2->getOutput());

        // Financial record mutation can never be ignored even with explicit operational disposition
        $cmpProc2Disp = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/compare.php',
            $out1,
            $out2,
            Guard::implementationDigest(),
            '--accept-operational-volatility',
        ], $root);
        $cmpProc2Disp->run();
        self::assertSame(2, $cmpProc2Disp->getExitCode());
        self::assertSame("FINGERPRINTS_DIFFER\n", $cmpProc2Disp->getOutput());

        // 3. Operational churn on sessions table
        // Clean the corrupted posting line first and restore its auto_increment counter
        DB::table('posting_lines')->where('line_number', 999)->delete();
        $origAi = (int) ($report1['tables']['posting_lines']['auto_increment'] ?? 1);
        DB::statement("ALTER TABLE `posting_lines` AUTO_INCREMENT = {$origAi}");

        DB::table('sessions')->insert([
            'id' => 'test_session_churn_'.bin2hex(random_bytes(8)),
            'user_id' => $owner->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestRunner',
            'payload' => base64_encode('test-session-payload'),
            'last_activity' => time(),
        ]);

        $outChurn = $this->tempDir.'/audit-'.bin2hex(random_bytes(12)).'.json';
        $procChurn = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/audit.php',
            '--disposable-test',
            $controlPath,
            $controlPin,
            $head,
            $outChurn,
        ], $root, getenv());
        $procChurn->run();
        self::assertSame(0, $procChurn->getExitCode(), 'Audit with operational churn exited with: '.$procChurn->getErrorOutput());

        // Default compare on operational churn -> exit 3
        $cmpChurn = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/compare.php',
            $out1,
            $outChurn,
            Guard::implementationDigest(),
        ], $root);
        $cmpChurn->run();
        self::assertSame(3, $cmpChurn->getExitCode());
        self::assertSame("OPERATIONAL_VOLATILITY_ONLY\n", $cmpChurn->getOutput());
        self::assertStringContainsString('sessions', $cmpChurn->getErrorOutput());

        // With explicit operator disposition -> exit 0
        $cmpChurnAccepted = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/compare.php',
            $out1,
            $outChurn,
            Guard::implementationDigest(),
            '--accept-operational-volatility',
        ], $root);
        $cmpChurnAccepted->run();
        self::assertSame(0, $cmpChurnAccepted->getExitCode());
        self::assertSame("OPERATIONAL_VOLATILITY_ACCEPTED\n", $cmpChurnAccepted->getOutput());

        // 4. Verify server identity mismatch refuses
        $wrongControlPath = $this->tempDir.'/control_wrong.json';
        $wrongControlPayload = array_replace($controlPayload, ['server_hostname' => 'unauthorized-hostname']);
        file_put_contents($wrongControlPath, json_encode($wrongControlPayload, JSON_THROW_ON_ERROR));
        chmod($wrongControlPath, 0600);
        $wrongControlPin = hash_file('sha256', $wrongControlPath);

        $out4 = $this->tempDir.'/audit-'.bin2hex(random_bytes(12)).'.json';
        $proc4 = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/audit.php',
            '--disposable-test',
            $wrongControlPath,
            $wrongControlPin,
            $head,
            $out4,
        ], $root, getenv());
        $proc4->run();

        self::assertSame(1, $proc4->getExitCode());
        self::assertSame("AUDIT_REFUSED\n", $proc4->getErrorOutput());
        self::assertFileDoesNotExist($out4);
    }

    public function test_unbuffered_streaming_and_row_budget_exceeded_refusal(): void
    {
        $root = dirname(__DIR__, 3);
        $head = trim((string) shell_exec('git -C '.escapeshellarg($root).' rev-parse HEAD 2>/dev/null'));
        $schema = DB::connection()->getDatabaseName();
        $pdo = DB::connection()->getPdo();
        $identity = $pdo->query('SELECT @@hostname, @@server_id')->fetch(PDO::FETCH_NUM);
        self::assertIsArray($identity);

        // Control payload with strict row limit (e.g. max_total_rows = 5, when database has more)
        $strictControlPath = $this->tempDir.'/control_strict_rows.json';
        $strictControlPayload = [
            'mode' => 'disposable-test',
            'host' => '127.0.0.1',
            'port' => 3342,
            'user' => 'test_runner',
            'password' => '',
            'schema' => $schema,
            'server_hostname' => (string) $identity[0],
            'server_id' => (int) $identity[1],
            'limits' => [
                'max_total_rows' => 5,
            ],
        ];
        file_put_contents($strictControlPath, json_encode($strictControlPayload, JSON_THROW_ON_ERROR));
        chmod($strictControlPath, 0600);
        $strictControlPin = hash_file('sha256', $strictControlPath);

        $outStrict = $this->tempDir.'/audit-'.bin2hex(random_bytes(12)).'.json';
        $procStrict = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/audit.php',
            '--disposable-test',
            $strictControlPath,
            $strictControlPin,
            $head,
            $outStrict,
        ], $root, getenv());
        $procStrict->run();

        // Must refuse deterministically and emit NO partial output
        self::assertSame(1, $procStrict->getExitCode());
        self::assertSame("AUDIT_REFUSED\n", $procStrict->getErrorOutput());
        self::assertFileDoesNotExist($outStrict, 'Interrupted or budget-exceeded audit must not create output file');

        // Test unbuffered streaming metrics directly on PDO
        $startMemory = memory_get_usage(true);
        $fingerprint = Fingerprint::capture($pdo, ['max_total_rows' => 100000]);
        $metrics = Fingerprint::lastMetrics();

        self::assertNotNull($metrics);
        self::assertGreaterThan(0, $metrics['total_rows']);
        self::assertGreaterThan(0, $metrics['total_bytes']);
        self::assertGreaterThan(0, $metrics['duration_seconds']);
        // Memory remains tightly bounded
        self::assertLessThan(134217728, $metrics['peak_memory_bytes']);

        // Verify PDO buffered mode was restored: canonical query executes without error 2014
        $testQuery = DB::select('SELECT count(*) as count FROM users');
        self::assertNotEmpty($testQuery);
    }

    public function test_prior_buffering_attribute_is_preserved_and_cumulative_budget_enforced(): void
    {
        $pdo = DB::connection()->getPdo();

        // 1. Prior buffering attribute false is preserved
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        Fingerprint::capture($pdo, ['max_total_rows' => 100000]);
        self::assertFalse((bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));

        // 2. Prior buffering attribute true is preserved
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        Fingerprint::capture($pdo, ['max_total_rows' => 100000]);
        self::assertTrue((bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));

        // 3. Shared cumulative budget across two captures with single budget instance
        $budget = new Budget(['max_total_rows' => 100000]);
        $fp1 = Fingerprint::capture($pdo, $budget);
        $rowsAfterFirst = $budget->totalRows();
        self::assertGreaterThan(0, $rowsAfterFirst);

        $fp2 = Fingerprint::capture($pdo, $budget);
        $rowsAfterSecond = $budget->totalRows();
        self::assertSame($rowsAfterFirst * 2, $rowsAfterSecond, 'Cumulative budget must accumulate rows across captures without reset');
        self::assertSame($fp1, $fp2);
    }

    public function test_phase_a_aggregate_query_observer_proves_no_payload_fetch_on_oversized_cell(): void
    {
        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port = (string) config('database.connections.mysql.port', '3342');
        $db = (string) config('database.connections.mysql.database');
        $user = (string) config('database.connections.mysql.username', 'test_runner');
        $pass = (string) config('database.connections.mysql.password', '');

        // Query observer PDO subclass to capture exact queries executed by Fingerprint::capture
        $observedQueries = [];
        $observerPdo = new class("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO
        {
            /** @var list<string> */
            public array $executedQueries = [];

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $this->executedQueries[] = $query;

                return parent::query($query, ...($fetchMode !== null ? [$fetchMode, ...$fetchModeArgs] : []));
            }
        };

        // Strict budget: max_cell_bytes = 100000 (100KB)
        $strictBudget = new Budget([
            'max_cell_bytes' => 100000,
        ]);

        // Insert a payload larger than 256KB into an operational table (sessions)
        $oversizedData = str_repeat('X', 300000);
        $sessionId = 'test_oversized_blob_'.bin2hex(random_bytes(8));
        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => null,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestRunner',
            'payload' => $oversizedData,
            'last_activity' => time(),
        ]);

        $refused = false;
        try {
            Fingerprint::capture($observerPdo, $strictBudget);
        } catch (\RuntimeException $e) {
            $refused = ($e->getMessage() === 'AUDIT_REFUSED');
        } finally {
            DB::table('sessions')->where('id', $sessionId)->delete();
        }

        self::assertTrue($refused, 'Phase A numeric aggregate must refuse oversized cell/row before payload query executes');

        // Verify with query observer:
        // 1. Phase A aggregate query for sessions WAS executed
        $aggregateQueries = array_filter($observerPdo->executedQueries, fn ($q) => str_contains($q, 'SELECT COUNT(*)') && str_contains($q, 'FROM `sessions`'));
        self::assertNotEmpty($aggregateQueries, 'Phase A aggregate query for sessions must be executed');

        // 2. NO payload streaming query (SELECT `id`, ... FROM `sessions` ORDER BY ...) was ever executed
        $payloadQueries = array_filter($observerPdo->executedQueries, fn ($q) => str_contains($q, 'FROM `sessions`') && str_contains($q, 'ORDER BY'));
        self::assertEmpty($payloadQueries, 'Phase B payload streaming query must NEVER be executed when Phase A detects oversized cell');
    }

    public function test_phase_a_and_phase_b_handle_null_empty_unsigned_overflow_and_large_fixtures(): void
    {
        $pdo = DB::connection()->getPdo();

        // 1. Empty table with nullable columns: tests COUNT(*)=0 and COALESCE(MAX/SUM)=0
        $pdo->exec('CREATE TABLE `test_empty_fixture` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `nullable_str` VARCHAR(255) NULL,
            `nullable_blob` TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        // 2. Table with BIGINT UNSIGNED exceeding PHP_INT_MAX and large valid string payload
        $pdo->exec('CREATE TABLE `test_unsigned_overflow_fixture` (
            `id` BIGINT UNSIGNED PRIMARY KEY,
            `nullable_val` VARCHAR(100) NULL,
            `bounded_large_data` MEDIUMTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        try {
            // Insert normal, NULL, and unsigned overflow values (18446744073709551614 > PHP_INT_MAX)
            $largeString = str_repeat('A', 50000); // 50KB, well within default 16MB limit
            $pdo->exec("INSERT INTO `test_unsigned_overflow_fixture` (`id`, `nullable_val`, `bounded_large_data`) VALUES
                (1, NULL, 'small_entry'),
                (9223372036854775807, 'max_signed_int', '{$largeString}'),
                (18446744073709551614, NULL, 'overflow_unsigned_entry')");

            // Execute capture with budget
            $budget = new Budget([
                'max_memory_bytes' => 134217728,
                'max_total_bytes' => 104857600,
            ]);
            $fingerprint = Fingerprint::capture($pdo, $budget);

            // Assert empty fixture captured cleanly
            self::assertArrayHasKey('test_empty_fixture', $fingerprint);
            self::assertSame(0, $fingerprint['test_empty_fixture']['rows']);
            self::assertNotEmpty($fingerprint['test_empty_fixture']['rows_sha256']);
            self::assertNotEmpty($fingerprint['test_empty_fixture']['normalized_schema_sha256']);

            // Assert unsigned overflow fixture captured cleanly with exact 3 rows and valid hashes
            self::assertArrayHasKey('test_unsigned_overflow_fixture', $fingerprint);
            self::assertSame(3, $fingerprint['test_unsigned_overflow_fixture']['rows']);
            self::assertNotEmpty($fingerprint['test_unsigned_overflow_fixture']['rows_sha256']);
            self::assertNotEmpty($fingerprint['test_unsigned_overflow_fixture']['normalized_schema_sha256']);
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS `test_empty_fixture`');
            $pdo->exec('DROP TABLE IF EXISTS `test_unsigned_overflow_fixture`');
        }
    }

    public function test_budget_deadline_math_unit_expiration_and_peak_memory_tracking(): void
    {
        $pdo = DB::connection()->getPdo();

        // Budget with 1 second execution deadline
        $budget = new Budget(['max_execution_seconds' => 1]);
        self::assertGreaterThanOrEqual(1, $budget->remainingExecutionSeconds());

        // Wait to exhaust budget
        usleep(1100000);

        // Assert remainingExecutionSeconds refuses immediately with AUDIT_REFUSED
        $refused = false;
        try {
            $budget->remainingExecutionSeconds();
        } catch (\RuntimeException $e) {
            $refused = ($e->getMessage() === 'AUDIT_REFUSED');
        }
        self::assertTrue($refused, 'Expired budget deadline must trigger AUDIT_REFUSED immediately');

        // Assert memory usage before vs peak is tracked accurately
        $peak = $budget->peakMemoryBytes();
        self::assertGreaterThan(0, $peak);
        self::assertLessThan(134217728, $peak);
    }

    public function test_multiquery_service_deadline_enforced_across_engine_queries_via_before_executing_hook(): void
    {
        $pdo = DB::connection()->getPdo();

        // Shared budget with 1 second max_execution_seconds
        $budget = new Budget(['max_execution_seconds' => 1]);

        $executedQueries = [];
        $hook = static function (string $query, array $bindings, $conn) use ($budget, $pdo, &$executedQueries): void {
            $executedQueries[] = $query;
            $budget->assertWithinLimits();
            Fingerprint::configureStatementTimeout($pdo, $budget->remainingExecutionSeconds());
        };

        // Capture existing callbacks to restore faithfully rather than wiping
        $beforeExecutingProperty = (new \ReflectionClass(DB::connection()))->getProperty('beforeExecutingCallbacks');
        $origCallbacks = $beforeExecutingProperty->getValue(DB::connection());

        // Register Laravel query hook as used in audit.php
        DB::beforeExecuting($hook);

        $refused = false;
        $queriesCompleted = 0;

        try {
            // Sequence of real engine queries, each under statement timeout (0.35s < 1s)
            // Under variable host load, the 1.0s budget will be exhausted after 1 to 3 queries.
            for ($i = 1; $i <= 5; $i++) {
                DB::select("SELECT {$i} AS q, SLEEP(0.35)");
                $queriesCompleted++;
            }
        } catch (\RuntimeException $e) {
            $refused = ($e->getMessage() === 'AUDIT_REFUSED');
        } finally {
            $beforeExecutingProperty->setValue(DB::connection(), $origCallbacks);
        }

        self::assertTrue($refused, 'Service deadline must trigger AUDIT_REFUSED via beforeExecuting hook before issuing query that exceeds cumulative budget');
        // Timing headroom: bounded between 1 and 3 queries completed before 1.0s cumulative budget expires
        self::assertGreaterThanOrEqual(1, $queriesCompleted, 'At least 1 query must complete within the initial budget');
        self::assertLessThanOrEqual(3, $queriesCompleted, 'Cumulative deadline must halt loop before 4th query completes under 1s budget');
        self::assertCount($queriesCompleted + 1, $executedQueries, 'The failing query must be intercepted by beforeExecuting hook before completing');
    }

    public function test_forced_child_process_termination_engine_pruning_negative_control(): void
    {
        $schema = DB::connection()->getDatabaseName();
        $observerPdo = DB::connection()->getPdo();

        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port = (string) config('database.connections.mysql.port', '3342');
        $user = (string) config('database.connections.mysql.username', 'test_runner');
        $pass = (string) config('database.connections.mysql.password', '');

        // Write private 0600 owned script file to avoid PHP -r parse error and shell escaping
        $scriptPath = $this->tempDir.'/forced_abort_child_'.bin2hex(random_bytes(6)).'.php';
        $childCode = <<<'PHP'
<?php
declare(strict_types=1);
$host = $argv[1];
$port = (int) $argv[2];
$schema = $argv[3];
$user = $argv[4];
$pass = $argv[5];

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
]);
$cid = (int) $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
fwrite(STDOUT, "CID:{$cid}\n");
fflush(STDOUT);
// Engine long sleep to test forced external kill negative control
$stmt = $pdo->query("SELECT SLEEP(15)");
fwrite(STDOUT, "COMPLETED\n");
PHP;

        file_put_contents($scriptPath, $childCode);
        chmod($scriptPath, 0600);

        $child = null;
        $childConnectionId = null;

        try {
            $child = new Process([PHP_BINARY, $scriptPath, $host, $port, $schema, $user, $pass], dirname(__DIR__, 3), null, null, 20.0);
            $child->start();

            $startTime = microtime(true);
            while ($child->isRunning() && microtime(true) - $startTime < 4.0) {
                $output = $child->getIncrementalOutput();
                if (preg_match('/CID:(\d+)/', $output, $matches)) {
                    $childConnectionId = (int) $matches[1];
                    break;
                }
                usleep(20000);
            }

            self::assertNotNull($childConnectionId, 'Child process must establish connection and emit owned CONNECTION_ID');

            // Observer verifies connection is active in MariaDB
            $checkStmt = $observerPdo->prepare('SELECT ID, USER, DB, COMMAND, TIME, INFO FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = ?');
            $checkStmt->execute([$childConnectionId]);
            $activeRow = $checkStmt->fetch(PDO::FETCH_ASSOC);

            self::assertIsArray($activeRow, 'Child connection must be visible in MariaDB processlist while running query');
            self::assertSame($schema, $activeRow['DB'], 'Child connection must be bound to the exact owned nonce schema');
            self::assertStringContainsString('SLEEP', (string) $activeRow['INFO'], 'Child query must be actively executing in MariaDB engine');

            // Force kill child process (SIGKILL to ensure definitive external termination)
            $child->stop(0.2, SIGKILL);

            self::assertFalse($child->isRunning(), 'Supervised child process must be terminated');
            self::assertStringNotContainsString('COMPLETED', $child->getOutput(), 'Aborted child must never reach completion output');

            // Poll PROCESSLIST with realistic timeout (up to 5s) to verify MariaDB socket-close pruning
            $isGone = false;
            for ($i = 0; $i < 50; $i++) {
                $checkStmt->execute([$childConnectionId]);
                if ($checkStmt->fetch(PDO::FETCH_ASSOC) === false) {
                    $isGone = true;
                    break;
                }
                usleep(100000);
            }

            self::assertTrue($isGone, 'MariaDB engine must prune connection and terminate query upon socket closure from client abort');
        } finally {
            if ($child !== null && $child->isRunning()) {
                $child->stop(0.1, SIGKILL);
            }
            if (is_file($scriptPath)) {
                unlink($scriptPath);
            }
        }
    }

    public function test_budget_refusal_unbuffered_streaming_child_aborts_cleanly_with_exact_cid(): void
    {
        $schema = DB::connection()->getDatabaseName();
        $observerPdo = DB::connection()->getPdo();

        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port = (string) config('database.connections.mysql.port', '3342');
        $user = (string) config('database.connections.mysql.username', 'test_runner');
        $pass = (string) config('database.connections.mysql.password', '');

        $scriptPath = $this->tempDir.'/budget_refusal_child_'.bin2hex(random_bytes(6)).'.php';
        $childCode = <<<'PHP'
<?php
declare(strict_types=1);
require dirname(__DIR__, 2).'/tools/release-audit/Guard.php';
require dirname(__DIR__, 2).'/tools/release-audit/Fingerprint.php';

use ReleaseAudit\Budget;
use ReleaseAudit\Fingerprint;

$host = $argv[1];
$port = (int) $argv[2];
$schema = $argv[3];
$user = $argv[4];
$pass = $argv[5];

$pdo = new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$cid = (int) $pdo->query("SELECT CONNECTION_ID()")->fetchColumn();
fwrite(STDOUT, "CID:{$cid}\n");
fflush(STDOUT);

// Exceedingly tight row budget to force immediate Phase A refusal on existing tables
$budget = new Budget([
    'max_total_rows' => 1,
    'max_total_bytes' => 1024,
]);

try {
    Fingerprint::capture($pdo, $budget);
    fwrite(STDOUT, "COMPLETED_UNEXPECTEDLY\n");
    exit(0);
} catch (\RuntimeException $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
PHP;

        file_put_contents($scriptPath, $childCode);
        chmod($scriptPath, 0600);

        $child = null;
        $childConnectionId = null;

        try {
            $child = new Process([PHP_BINARY, $scriptPath, $host, $port, $schema, $user, $pass], dirname(__DIR__, 3), null, null, 15.0);
            $child->start();

            $startTime = microtime(true);
            while ($child->isRunning() && microtime(true) - $startTime < 4.0) {
                $output = $child->getIncrementalOutput();
                if (preg_match('/CID:(\d+)/', $output, $matches)) {
                    $childConnectionId = (int) $matches[1];
                    break;
                }
                usleep(20000);
            }

            self::assertNotNull($childConnectionId, 'Child process must establish connection and emit owned CONNECTION_ID');

            // Wait for tool's own budget refusal to complete
            $child->wait();

            self::assertSame(1, $child->getExitCode(), 'Child process must exit with code 1 upon AUDIT_REFUSED');
            self::assertStringContainsString('AUDIT_REFUSED', $child->getErrorOutput(), 'Child stderr must report AUDIT_REFUSED');
            self::assertStringNotContainsString('COMPLETED_UNEXPECTEDLY', $child->getOutput(), 'Refused child must not emit completed payload');

            // Verify connection was closed cleanly by child upon refusal exit
            $checkStmt = $observerPdo->prepare('SELECT ID FROM INFORMATION_SCHEMA.PROCESSLIST WHERE ID = ?');
            $checkStmt->execute([$childConnectionId]);
            self::assertFalse($checkStmt->fetch(PDO::FETCH_ASSOC), 'Refused child connection must be absent from MariaDB processlist');
        } finally {
            if ($child !== null && $child->isRunning()) {
                $child->stop(0.1, SIGKILL);
            }
            if (is_file($scriptPath)) {
                unlink($scriptPath);
            }
        }
    }

    public function test_representative_bounded_multi_mb_streaming_with_memory_ceiling_and_phase_a_refusal(): void
    {
        $schema = DB::connection()->getDatabaseName();
        $pdo = DB::connection()->getPdo();

        self::assertStringStartsWith('accounting_p8_tmp_', $schema, 'Fixture must strictly reside in owned nonce schema');

        // Measure baseline rows and bytes across existing canonical tables before adding fixture
        $baselineBudget = new Budget([
            'max_total_rows' => 100000,
            'max_total_bytes' => 104857600,
        ]);
        Fingerprint::capture($pdo, $baselineBudget);
        $baselineRows = $baselineBudget->totalRows();
        $baselineBytes = $baselineBudget->totalBytes();

        $tableName = 'test_bounded_streaming_fixture';
        $pdo->exec("CREATE TABLE `{$tableName}` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `payload` MEDIUMTEXT NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $host = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port = (string) config('database.connections.mysql.port', '3342');
        $user = (string) config('database.connections.mysql.username', 'test_runner');
        $pass = (string) config('database.connections.mysql.password', '');

        try {
            // Insert 3,000 rows with 1KB string payload (~3.0MB table payload)
            $chunkString = str_repeat('B', 1000);
            $values = implode(',', array_fill(0, 500, "('{$chunkString}')"));
            for ($c = 0; $c < 6; $c++) {
                $pdo->exec("INSERT INTO `{$tableName}` (`payload`) VALUES {$values}");
            }

            $totalCount = (int) $pdo->query("SELECT COUNT(*) FROM `{$tableName}`")->fetchColumn();
            self::assertSame(3000, $totalCount);

            // PART 1: Successful bounded streaming within budget
            // Limits accommodating baseline + 3,000 rows / 3MB with generous headroom
            $validBudget = new Budget([
                'max_total_rows' => $baselineRows + 10000,
                'max_total_bytes' => $baselineBytes + 15000000,
                'max_memory_bytes' => 134217728,
            ]);

            $fp = Fingerprint::capture($pdo, $validBudget);

            self::assertArrayHasKey($tableName, $fp);
            self::assertSame(3000, $fp[$tableName]['rows']);
            self::assertNotEmpty($fp[$tableName]['rows_sha256']);
            // Verify total rows equals baseline + 3000 fixture rows
            self::assertSame($baselineRows + 3000, $validBudget->totalRows());
            // Verify total bytes captures baseline + ~3MB fixture bytes
            self::assertGreaterThanOrEqual($baselineBytes + 3000000, $validBudget->totalBytes());
            // Verify transient peak memory usage tracked by Budget remains strictly under ceiling
            self::assertLessThan(134217728, $validBudget->peakMemoryBytes(), 'Peak memory during unbuffered streaming must remain under memory ceiling');

            // PART 2: Phase A numeric aggregate refusal proof on total payload bytes limit
            // Strict budget: allow baseline + 1MB, while fixture alone is 3MB
            $strictBudget = new Budget([
                'max_total_rows' => $baselineRows + 10000,
                'max_total_bytes' => $baselineBytes + 1048576,
                'max_memory_bytes' => 134217728,
            ]);

            $observerPdo = new class("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]) extends PDO
            {
                /** @var list<string> */
                public array $executedQueries = [];

                public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
                {
                    $this->executedQueries[] = $query;

                    return parent::query($query, ...($fetchMode !== null ? [$fetchMode, ...$fetchModeArgs] : []));
                }
            };

            $refused = false;
            try {
                Fingerprint::capture($observerPdo, $strictBudget);
            } catch (\RuntimeException $e) {
                $refused = ($e->getMessage() === 'AUDIT_REFUSED');
            }

            self::assertTrue($refused, 'Phase A numeric aggregate must refuse when cumulative byte sum exceeds budget');

            $aggQueries = array_filter($observerPdo->executedQueries, fn ($q) => str_contains($q, 'SELECT COUNT(*)') && str_contains($q, "`{$tableName}`"));
            self::assertNotEmpty($aggQueries, 'Phase A aggregate query for table must be executed');

            $payloadQueries = array_filter($observerPdo->executedQueries, fn ($q) => str_contains($q, "FROM `{$tableName}`") && str_contains($q, 'ORDER BY'));
            self::assertEmpty($payloadQueries, 'Phase B payload streaming query must NEVER be executed when Phase A aggregate detects exceeded budget');
        } finally {
            $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
        }
    }
}
