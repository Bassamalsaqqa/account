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
use ReleaseAudit\Fingerprint;
use ReleaseAudit\Guard;
use Symfony\Component\Process\Process;
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

        // 3. Verify server identity mismatch refuses
        $wrongControlPath = $this->tempDir.'/control_wrong.json';
        $wrongControlPayload = array_replace($controlPayload, ['server_hostname' => 'unauthorized-hostname']);
        file_put_contents($wrongControlPath, json_encode($wrongControlPayload, JSON_THROW_ON_ERROR));
        chmod($wrongControlPath, 0600);
        $wrongControlPin = hash_file('sha256', $wrongControlPath);

        $out3 = $this->tempDir.'/audit-'.bin2hex(random_bytes(12)).'.json';
        $proc3 = new Process([
            PHP_BINARY,
            $root.'/tools/release-audit/audit.php',
            '--disposable-test',
            $wrongControlPath,
            $wrongControlPin,
            $head,
            $out3,
        ], $root, getenv());
        $proc3->run();

        self::assertSame(1, $proc3->getExitCode());
        self::assertSame("AUDIT_REFUSED\n", $proc3->getErrorOutput());
        self::assertFileDoesNotExist($out3);
    }
}
