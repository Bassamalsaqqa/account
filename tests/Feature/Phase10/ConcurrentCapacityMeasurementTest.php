<?php

declare(strict_types=1);

namespace Tests\Feature\Phase10;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Committed synthetic fixtures are required for independent child connections. */
final class ConcurrentCapacityMeasurementTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    /** @var array<string,int> */
    private array $primaryCounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['companies', 'users', 'posting_batches', 'stock_movements'] as $table) {
            $this->primaryCounts[$table] = DB::table($table)->count();
        }
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), 'Committed capacity fixtures must not leak into the primary test schema.');
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_independent_local_kernel_workers_measure_widths_one_two_and_four_without_economic_mutation(): void
    {
        app(CompanyContext::class)->clear();
        $owner = User::factory()->create(['locale' => 'en']);
        $creator = app(CreateCompanyAction::class);
        $control = $creator->execute($owner, ['name_ar' => 'Capacity control', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->clear();
        $company = $creator->execute($owner, ['name_ar' => 'Capacity synthetic S', 'name_en' => 'Capacity synthetic S',
            'base_currency_code' => 'ILS', 'default_locale' => 'en']);
        $this->actingAs($owner);
        app(CompanyContext::class)->setCompany($company, $owner);
        setPermissionsTeamId($company->id);
        $fixtureStart = hrtime(true);
        $invoice = $this->buildProfileS($company, $owner);
        $fixtureMs = round((hrtime(true) - $fixtureStart) / 1_000_000, 3);
        $counts = ['products' => DB::table('products')->where('company_id', $company->id)->count(),
            'purchases' => DB::table('purchases')->where('company_id', $company->id)->where('status', 'posted')->count(),
            'invoices' => DB::table('sales_invoices')->where('company_id', $company->id)->where('status', 'posted')->count(),
            'receipts' => DB::table('customer_payments')->where('company_id', $company->id)->whereNotNull('posting_batch_id')->count()];
        $this->assertSame(['products' => 10, 'purchases' => 20, 'invoices' => 20, 'receipts' => 10], $counts);
        $this->assertSame(50, $counts['purchases'] + $counts['invoices'] + $counts['receipts']);
        $this->assertSame(50, DB::table('posting_batches')->where('company_id', $company->id)->count());
        $this->assertSame(10, DB::table('customer_payment_allocations')->where('company_id', $company->id)->count());
        $this->assertTrue(BigDecimal::of((string) DB::table('inventory_cost_states')->where('company_id', $company->id)->sum('quantity_base'))->isEqualTo('360'));
        $this->assertTrue(BigDecimal::of((string) DB::table('inventory_cost_states')->where('company_id', $company->id)->sum('inventory_value_base'))->isEqualTo('3600'));
        $this->assertSame(0, DB::transactionLevel());
        $before = $this->fingerprint($company);
        $controlBefore = $this->fingerprint($control);
        $metrics = ['profile' => 'S', 'timestamp_utc' => gmdate('Y-m-d\TH:i:s\Z'), 'fixture_counts' => $counts,
            'fixture_generation_ms' => $fixtureMs, 'execution' => 'Independent local CLI Laravel HTTP kernels; no listening HTTP server',
            'environment' => ['php_version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os_family' => PHP_OS_FAMILY,
                'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
                'database_version' => DB::selectOne('SELECT VERSION() AS version')->version,
                'database_port' => (string) config('database.connections.mysql.port')],
            'limits' => ['hostinger_lsapi_quotas' => 'NOT VERIFIED', 'provider_capacity_or_sla' => 'NOT VERIFIED',
                'web_server_overhead' => 'NOT VERIFIED (direct kernel, no web ingress)',
                'profile_m_l_concurrency' => 'NOT RUN (this finite harness measures S only)',
                'cpu' => PHP_OS_FAMILY === 'Windows' ? 'NOT VERIFIED (unsupported getrusage)' : 'MEASURED WHERE SUPPORTED'],
            'widths' => []];
        foreach ([1, 2, 4] as $width) {
            $metrics['widths'][(string) $width] = $this->measureWidth($width, $company, $owner, $invoice);
            $this->assertSame($before, $this->fingerprint($company), 'Read workloads must preserve all economic history.');
            $this->assertSame($controlBefore, $this->fingerprint($control), 'The control company must remain unchanged.');
        }
        $metrics['reconciliations'] = $this->reconcile($company, $owner);
        $this->assertSame($before, $this->fingerprint($company));
        $this->assertSame($controlBefore, $this->fingerprint($control));
        $metrics['economic_fingerprint_before'] = $before;
        $metrics['economic_fingerprint_after'] = $this->fingerprint($company);
        $metrics['control_fingerprint_before'] = $controlBefore;
        $metrics['control_fingerprint_after'] = $this->fingerprint($control);
        $metrics['temporary_worker_cleanup'] = 'COMPLETE';
        $directory = base_path('.ai/phase10-capacity');
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        $path = $directory.'/concurrent-metrics.json';
        file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        chmod($path, 0600);
        $this->assertFileExists($path);
    }

    private function buildProfileS(Company $company, User $owner): SalesInvoice
    {
        $unit = Unit::where('company_id', $company->id)->where('code', 'piece')->firstOrFail();
        $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();
        $vendor = app(VendorCatalogService::class)->save($company, $owner, ['name_ar' => 'Capacity vendor', 'default_currency_code' => 'ILS']);
        $customer = Customer::create(['company_id' => $company->id, 'name_ar' => 'Capacity customer',
            'name_en' => 'Capacity customer', 'active' => true, 'created_by' => $owner->id]);
        $cash = MoneyAccount::where('company_id', $company->id)->where('account_type', MoneyAccount::TYPE_CASH)
            ->where('currency_code', 'ILS')->where('is_active', true)->firstOrFail();
        $products = [];
        $units = [];
        for ($index = 0; $index < 10; $index++) {
            $product = app(ProductCatalogService::class)->createProduct($company, ['name_ar' => 'Capacity product '.$index,
                'name_en' => 'Capacity product '.$index, 'sku' => 'CAP-S-'.$index, 'product_type' => Product::TYPE_STOCK,
                'track_stock' => true, 'track_expiry' => false, 'base_unit_id' => $unit->id], $owner->id);
            $products[] = $product;
            $units[] = ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->where('is_base', true)->sole();
        }
        for ($index = 0; $index < 20; $index++) {
            $selected = $index % 10;
            $purchase = app(CreatePurchaseDraftAction::class)->execute($company, $owner, [
                'vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id, 'purchase_date' => '2026-10-01',
                'currency_code' => 'ILS', 'exchange_rate' => '1', 'lines' => [['product_id' => $products[$selected]->id,
                    'product_unit_id' => $units[$selected]->id, 'quantity' => '20', 'unit_cost' => '10']],
            ]);
            app(PostPurchaseAction::class)->execute($purchase, $owner);
        }
        $invoices = [];
        for ($index = 0; $index < 20; $index++) {
            $selected = $index % 10;
            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($company, $owner, [
                'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'issue_date' => '2026-10-02',
                'currency_code' => 'ILS', 'exchange_rate' => '1', 'document_locale' => 'en',
                'lines' => [['product_id' => $products[$selected]->id, 'product_unit_id' => $units[$selected]->id,
                    'item_description' => 'Capacity product '.$selected, 'quantity' => '2', 'unit_price' => '15']],
            ]);
            $invoices[] = app(PostSalesInvoiceAction::class)->execute($draft, $owner);
        }
        for ($index = 0; $index < 10; $index++) {
            app(PostCustomerPaymentAction::class)->execute($company, $owner, ['customer_id' => $customer->id,
                'money_account_id' => $cash->id, 'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '10',
                'exchange_rate' => '1', 'idempotency_key' => 'capacity-receipt-'.$index,
                'allocations' => [['sales_invoice_id' => $invoices[$index]->id, 'allocated_amount' => '10']],
            ]);
        }

        return $invoices[0];
    }

    /** @return array<string,mixed> */
    private function measureWidth(int $width, Company $company, User $owner, SalesInvoice $invoice): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'phase10-capacity-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned capacity directory.');
        }
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $files = [$release];
        $readyFiles = [];
        $processes = [];
        $environment = ['APP_KEY' => (string) config('app.key'), 'APP_URL' => 'http://127.0.0.1', 'DB_URL' => '',
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array'];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach (['PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD', 'PHASE8_TEST_DB_PASSWORD', 'PHASE8_DISPOSABLE_DB_PROOF',
            'PHASE8_DISPOSABLE_DB_NONCE', 'APP_CONFIG_CACHE'] as $key) {
            $environment[$key] = getenv($key);
        }
        $environment = array_replace($environment, $this->fixtureSchema->environment());
        try {
            for ($index = 0; $index < $width; $index++) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $files = array_merge($files, [$payloadPath, $ready]);
                $readyFiles[] = $ready;
                file_put_contents($payloadPath, json_encode(['company_id' => $company->id, 'actor_id' => $owner->id,
                    'invoice_public_id' => $invoice->public_id, 'ready' => $ready, 'release' => $release], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process([PHP_BINARY, base_path('tests/Support/Phase10/capacity-worker.php'), $payloadPath], base_path(), $environment);
                $process->setTimeout(120)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 60;
            while (count(array_filter($readyFiles, 'is_file')) !== $width) {
                foreach ($processes as $process) {
                    if (! $process->isRunning()) {
                        $this->fail('Capacity child exited before readiness: '.$process->getErrorOutput());
                    }
                }
                if (microtime(true) > $deadline) {
                    $this->fail('Capacity readiness barrier timed out.');
                }
                usleep(10000);
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Each independent child must be alive before release.');
                $this->assertSame('', $process->getOutput());
            }
            $batchStart = hrtime(true);
            file_put_contents($release, 'release');
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().' '.$process->getOutput());
                $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame('measured', $result['status']);
                $this->assertGreaterThan(0, $result['query_count']);
                $this->assertGreaterThan(0, $result['process_peak_memory_bytes']);
                foreach (['dashboard' => 'HTML', 'sales_report' => 'HTML', 'invoice_pdf' => 'PDF'] as $name => $signature) {
                    $operation = $result['operations'][$name];
                    $this->assertSame(200, $operation['status'], $name.' must use the real authenticated HTTP kernel successfully.');
                    $this->assertSame($signature, $operation['signature']);
                    $this->assertGreaterThan(0, $operation['response_bytes']);
                    $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $operation['response_sha256']);
                    $this->assertGreaterThan(0, $operation['query_count']);
                    $this->assertGreaterThan($operation['started_unix'], $operation['ended_unix']);
                }
                $results[] = $result;
            }
            $this->assertCount($width, array_unique(array_column($results, 'pid')), 'Workers must be independent operating-system processes.');
            $commonOverlapMs = round((min(array_column($results, 'ended_unix')) - max(array_column($results, 'started_unix'))) * 1000, 3);
            if ($width > 1) {
                $this->assertGreaterThan(0, $commonOverlapMs, 'Measured workload intervals must actually overlap; no serial-loop substitution.');
            }
            $operationOverlaps = [];
            foreach (['dashboard', 'sales_report', 'invoice_pdf'] as $name) {
                $operations = array_map(fn (array $result) => $result['operations'][$name], $results);
                $operationOverlaps[$name] = max(0, round((min(array_column($operations, 'ended_unix')) - max(array_column($operations, 'started_unix'))) * 1000, 3));
            }

            return ['width' => $width, 'wall_ms_after_release' => round((hrtime(true) - $batchStart) / 1_000_000, 3),
                'all_worker_workload_overlap_ms' => $commonOverlapMs, 'all_worker_same_operation_overlap_ms' => $operationOverlaps,
                'workers' => $results, 'cleanup' => 'COMPLETE AFTER FINALLY',
                'performance_acceptance' => 'MEASURED; no production threshold or SLA inferred'];
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach ($files as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /** @return array<string,string> */
    private function reconcile(Company $company, User $owner): array
    {
        $results = [];
        foreach (['accounting:reconcile', 'inventory:reconcile', 'sales:reconcile', 'money:reconcile', 'phase7:reconcile'] as $command) {
            $code = Artisan::call($command, ['companyPublicId' => $company->public_id]);
            $this->assertSame(0, $code, $command.': '.Artisan::output());
            $results[$command] = 'HEALTHY';
        }
        $this->actingAs($owner);
        app(CompanyContext::class)->setCompany($company, $owner);
        $payables = app(PayablesReconciliationService::class)->reconcile($company);
        $this->assertTrue($payables->isHealthy, implode('; ', $payables->violations));
        $results['payables_service'] = 'HEALTHY';

        return $results;
    }

    private function fingerprint(Company $company): string
    {
        $rows = [];
        foreach (['posting_batches', 'posting_lines', 'stock_movements', 'inventory_operations', 'inventory_lots',
            'inventory_balances', 'inventory_lot_balances', 'inventory_cost_states', 'purchases', 'purchase_lines',
            'purchase_line_lots', 'purchase_returns', 'purchase_return_lines', 'vendor_payments', 'vendor_payment_allocations',
            'sales_invoices', 'sales_invoice_lines', 'sales_invoice_lot_allocations', 'sales_returns', 'sales_return_lines',
            'customer_payments', 'customer_payment_allocations', 'document_sequences', 'audit_events'] as $table) {
            $rows[$table] = DB::table($table)->where('company_id', $company->id)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }
}
