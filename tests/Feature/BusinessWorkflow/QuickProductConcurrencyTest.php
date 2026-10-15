<?php

declare(strict_types=1);

namespace Tests\Feature\BusinessWorkflow;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Independent connections and DDL require committed fixtures in a separately owned schema. */
final class QuickProductConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    private Company $company;

    private User $owner;

    /** @var array<string,int> */
    private array $primaryCounts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['companies', 'users', 'products', 'product_units', 'product_creation_requests', 'posting_batches', 'stock_movements'] as $table) {
            $this->primaryCounts[$table] = DB::table($table)->count();
        }
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();
        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Synthetic quick product race', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        setPermissionsTeamId($this->company->id);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                $this->assertSame($count, DB::table($table)->count(), 'Owned race fixture leaked into primary schema: '.$table);
            }
        } finally {
            parent::tearDown();
        }
    }

    /** @return array<string,mixed> */
    private function intent(string $name = 'Concurrent product'): array
    {
        return ['key' => 'd8c0dd4a-6740-493c-8fe3-c9f4d1b3ab38', 'data' => ['name_ar' => $name, 'name_en' => '', 'sku' => 'RACE-NEW',
            'base_unit_id' => Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail()->id,
            'track_expiry' => false]];
    }

    public function test_two_connections_retry_same_payload_into_one_product_unit_and_receipt(): void
    {
        $results = $this->race($this->intent(), $this->intent());
        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('ok', $results[1]['status']);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertSame(1, Product::where('sku', 'RACE-NEW')->count());
        $this->assertSame(1, ProductUnit::where('product_id', $results[0]['id'])->count());
        $this->assertSame(1, DB::table('product_creation_requests')->count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_conflicting_payloads_same_key_have_one_success_and_one_conflict(): void
    {
        $results = $this->race($this->intent('First payload'), $this->intent('Conflicting payload'));
        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['conflict', 'ok'], $statuses);
        $this->assertSame(1, Product::where('sku', 'RACE-NEW')->count());
        $this->assertSame(1, DB::table('product_creation_requests')->count());
        $this->assertSame(0, DB::table('posting_batches')->count());
        $this->assertSame(0, DB::table('stock_movements')->count());
    }

    public function test_receipt_migration_round_trip_preserves_posted_purchase_and_stock(): void
    {
        $product = app(ProductCatalogService::class)->createProduct($this->company, ['name_ar' => 'Preserved stock', 'sku' => 'PRESERVE',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => Unit::where('code', 'piece')->firstOrFail()->id], $this->owner->id);
        $vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, ['name_ar' => 'Synthetic vendor']);
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendor->id, 'warehouse_id' => Warehouse::firstOrFail()->id, 'purchase_date' => '2026-10-15',
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'document_locale' => 'ar',
            'lines' => [['product_id' => $product->id, 'quantity' => '2', 'unit_cost' => '12.34']]]);
        app(PostPurchaseAction::class)->execute($draft, $this->owner);
        $before = $this->economicFingerprint();
        $migration = require database_path('migrations/2026_10_16_000001_create_product_creation_requests_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('product_creation_requests'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('product_creation_requests'));
        $this->assertSame($before, $this->economicFingerprint());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function economicFingerprint(): string
    {
        $rows = [];
        foreach (['products', 'product_units', 'purchases', 'purchase_lines', 'posting_batches', 'posting_lines', 'stock_movements',
            'inventory_operations', 'inventory_balances', 'inventory_cost_states', 'inventory_lots', 'inventory_lot_balances', 'document_sequences'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->all();
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $first
     * @param  array<string,mixed>  $second
     * @param  (?callable():void)  $beforeRelease
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function race(array $first, array $second, ?callable $beforeRelease = null): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'quick-product-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned race directory.');
        }
        $release = $directory.DIRECTORY_SEPARATOR.'release';
        $processes = [];
        $files = [];
        $readyFiles = [];
        $attemptFiles = [];

        $appKey = (string) (config('app.key') ?: getenv('APP_KEY'));
        if ($appKey === '') {
            throw new \RuntimeException('A valid APP_KEY is required for the product race harness.');
        }

        $environment = [
            'APP_KEY' => $appKey,
            'DB_URL' => '',
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'COMMAND_API_ENABLED' => 'false',
        ];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        foreach ([
            'PHASE8_ALLOW_DISPOSABLE_DB', 'PHASE8_TEST_DB_HOST', 'PHASE8_TEST_DB_PORT', 'PHASE8_TEST_DB_USERNAME',
            'PHASE8_TEST_DB_EMPTY_PASSWORD', 'PHASE8_DISPOSABLE_DB_PROOF', 'PHASE8_DISPOSABLE_DB_NONCE', 'APP_CONFIG_CACHE',
        ] as $key) {
            $environment[$key] = getenv($key);
        }
        // Credentials stay in process environment, never payload files or reported results.
        $environment['PHASE8_TEST_DB_PASSWORD'] = getenv('PHASE8_TEST_DB_PASSWORD');
        $environment = array_replace($environment, $this->fixtureSchema->environment());
        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();
            foreach ([$first, $second] as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;
                file_put_contents($payloadPath, json_encode($intent + [
                    'company_id' => $this->company->id,
                    'actor_id' => $intent['actor_id'] ?? $this->owner->id,
                    'ready' => $ready,
                    'attempting' => $attempting,
                    'release' => $release,
                ], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process([PHP_BINARY, base_path('tests/Support/quick-product-race-worker.php'), $payloadPath], base_path(), $environment);
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }
            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both independent command attempts must overlap behind the company lock.');
                $this->assertSame('', $process->getOutput());
            }
            if ($beforeRelease !== null) {
                $beforeRelease();
            }
            DB::commit();
            $locked = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            }

            return [$results[0], $results[1]];
        } finally {
            if ($locked) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            foreach (array_merge($files, [$release]) as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
            rmdir($directory);
        }
    }

    /**
     * @param  list<string>  $files
     * @param  list<Process>  $processes
     */
    private function waitForFiles(array $files, array $processes): void
    {
        $deadline = microtime(true) + 20;
        do {
            if (count(array_filter($files, 'is_file')) === count($files)) {
                return;
            }
            foreach ($processes as $process) {
                if (! $process->isRunning()) {
                    $this->fail('Product race child exited before the barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Independent product race barrier timed out.');
    }
}
