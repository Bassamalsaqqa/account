<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5B;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/**
 * Concurrency proof between DeletePurchaseDraftAction and PostPurchaseAction
 * using two independent MariaDB processes with strict held-row barriers.
 * Intentionally no RefreshDatabase because independent processes require committed fixtures.
 */
class PurchaseDraftDeletionConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    /** @var array<string, int> */
    private array $primaryCounts = [];

    private Company $company;

    private Company $controlCompany;

    private User $owner;

    private Vendor $vendor;

    private Product $product;

    private ProductUnit $carton;

    private string $controlFingerprint;

    protected function setUp(): void
    {
        parent::setUp();

        if (! config('app.key')) {
            config(['app.key' => 'base64:VHEDqS0eK6A+vPgmWfUYk7lE44lioPBuuJS4nAGdEeE=']);
        }

        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);

        foreach (['companies', 'users', 'purchases', 'purchase_lines', 'posting_batches', 'stock_movements', 'audit_events'] as $table) {
            if (Schema::hasTable($table)) {
                $this->primaryCounts[$table] = DB::table($table)->count();
            }
        }

        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();

        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        // Control tenant: baseline for proving no cross-tenant corruption or uncontrolled side effects.
        $this->controlCompany = $creator->execute($this->owner, [
            'name_ar' => 'شركة ضبط سباق الشراء',
            'name_en' => 'Purchase Race Control',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->controlCompany);
        $controlVendor = app(VendorCatalogService::class)->save($this->controlCompany, $this->owner, [
            'name_ar' => 'مورد الضبط',
            'name_en' => 'Control Vendor',
        ]);
        $controlUnit = Unit::where('company_id', $this->controlCompany->id)->where('code', 'piece')->firstOrFail();
        $controlProduct = app(ProductCatalogService::class)->createProduct($this->controlCompany, [
            'name_ar' => 'منتج الضبط', 'name_en' => 'Control Product', 'sku' => 'CTRL-P-DEL',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => $controlUnit->id, 'default_purchase_cost_base' => '1.000000',
        ], $this->owner->id);
        $controlProductUnit = ProductUnit::where('company_id', $this->controlCompany->id)
            ->where('product_id', $controlProduct->id)->firstOrFail();
        $controlDraft = app(CreatePurchaseDraftAction::class)->execute($this->controlCompany, $this->owner, [
            'vendor_id' => $controlVendor->id,
            'warehouse_id' => Warehouse::where('company_id', $this->controlCompany->id)->firstOrFail()->id,
            'purchase_date' => '2026-10-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $controlProduct->id,
                'product_unit_id' => $controlProductUnit->id,
                'quantity' => '1',
                'unit_cost' => '10',
                'lots' => [],
            ]],
        ]);
        app(PostPurchaseAction::class)->execute($controlDraft, $this->owner);
        $this->controlFingerprint = $this->fingerprint($this->controlCompany);

        // Main trading company for races
        app(CompanyContext::class)->clear();
        $this->company = $creator->execute($this->owner, [
            'name_ar' => 'شركة سباق مسودة الشراء',
            'name_en' => 'Purchase Draft Race Co',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->company);
        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد السباق',
            'name_en' => 'Race Vendor',
        ]);
        $pieceUnit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'صابون السباق', 'name_en' => 'Race Soap', 'sku' => 'SOAP-RACE-DEL',
            'product_type' => Product::TYPE_STOCK, 'track_stock' => true, 'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id, 'default_purchase_cost_base' => '2.000000',
        ], $this->owner->id);
        $cartonUnit = Unit::where('company_id', $this->company->id)->where('code', 'carton')->firstOrFail();
        $this->carton = app(ProductCatalogService::class)->addOrUpdateAlternateUnit($this->product, $cartonUnit->id, '12', false, true);

        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }
            $this->fixtureSchema?->restoreLaravelConnection();
            $this->fixtureSchema?->drop();
            foreach ($this->primaryCounts as $table => $count) {
                if (Schema::hasTable($table)) {
                    $this->assertSame($count, DB::table($table)->count(), "Race fixtures must not leak into primary test schema [{$table}].");
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    private function activate(Company $company): void
    {
        app(CompanyContext::class)->setCompany($company, $this->owner);
        $this->actingAs($this->owner);
        setPermissionsTeamId($company->id);
        $this->owner->unsetRelation('roles')->unsetRelation('permissions');
    }

    private function createDraft(string $invoiceNumber): Purchase
    {
        $this->activate($this->company);

        return app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => Warehouse::where('company_id', $this->company->id)->firstOrFail()->id,
            'vendor_invoice_number' => $invoiceNumber,
            'purchase_date' => '2026-10-15',
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $this->carton->id,
                'quantity' => '2',
                'unit_cost' => '24',
                'lots' => [],
            ]],
        ]);
    }

    private function fingerprint(Company $company): string
    {
        $rows = [];
        foreach ([
            'purchases', 'purchase_lines', 'posting_batches', 'posting_lines',
            'stock_movements', 'inventory_operations', 'inventory_balances',
            'audit_events',
        ] as $table) {
            if (Schema::hasTable($table)) {
                $rows[$table] = DB::table($table)->where('company_id', $company->id)->orderBy('id')->get()->all();
            }
        }

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function assertHealthyAndControlUnchanged(): void
    {
        $this->activate($this->company);
        foreach (['accounting:reconcile', 'inventory:reconcile', 'money:reconcile'] as $command) {
            $code = Artisan::call($command, ['companyPublicId' => $this->company->public_id]);
            $this->assertSame(0, $code, $command.': '.Artisan::output());
        }
        $payablesReport = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesReport->isHealthy, implode('; ', $payablesReport->violations));
        $this->assertSame([], $payablesReport->violations);
        $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accountingReport->isHealthy, implode('; ', $accountingReport->violations));
        $this->assertSame([], $accountingReport->violations);
        $this->assertSame($this->controlFingerprint, $this->fingerprint($this->controlCompany));
        $this->assertSame(0, DB::transactionLevel());
        app(CompanyContext::class)->clear();
        auth()->forgetUser();
        setPermissionsTeamId(null);
    }

    /**
     * Executes a real two-process race with strict barrier synchronization.
     * Worker 0 acquires and holds the purchase row lock, Worker 1 attempts and blocks
     * behind the held row, and Worker 0 commits before Worker 1 is allowed to proceed.
     *
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function executeRace(Purchase $draft, string $op0, string $op1): array
    {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'purchase-delete-race-'.bin2hex(random_bytes(8));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('Could not create owned race directory.');
        }

        $appKey = (string) (config('app.key') ?: getenv('APP_KEY'));
        $settings = DisposableMariaDbSchema::testSettings();

        $environment = [
            'APP_KEY' => $appKey,
            'DB_URL' => '',
            'APP_ENV' => 'testing',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'MAIL_MAILER' => 'array',
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => $settings['HOST'],
            'PHASE8_TEST_DB_PORT' => $settings['PORT'],
            'PHASE8_TEST_DB_USERNAME' => $settings['USERNAME'],
            'PHASE8_TEST_DB_PASSWORD' => $settings['PASSWORD'],
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => $settings['PASSWORD'] === '' ? '1' : '0',
            'PHASE8_DISPOSABLE_DB_PROOF' => getenv('PHASE8_DISPOSABLE_DB_PROOF'),
            'PHASE8_DISPOSABLE_DB_NONCE' => getenv('PHASE8_DISPOSABLE_DB_NONCE'),
        ];
        foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
            $environment['DB_'.strtoupper($key)] = (string) config('database.connections.mysql.'.$key);
        }
        $environment = array_replace($environment, $this->fixtureSchema->environment());

        $processes = [];
        $files = [];

        $ready0 = $directory.DIRECTORY_SEPARATOR.'ready-0';
        $attempt0 = $directory.DIRECTORY_SEPARATOR.'attempting-0';
        $release0 = $directory.DIRECTORY_SEPARATOR.'release-0';
        $locked0 = $directory.DIRECTORY_SEPARATOR.'locked-0';
        $proceed0 = $directory.DIRECTORY_SEPARATOR.'proceed-0';
        $payload0 = $directory.DIRECTORY_SEPARATOR.'payload-0.json';

        $ready1 = $directory.DIRECTORY_SEPARATOR.'ready-1';
        $attempt1 = $directory.DIRECTORY_SEPARATOR.'attempting-1';
        $release1 = $directory.DIRECTORY_SEPARATOR.'release-1';
        $payload1 = $directory.DIRECTORY_SEPARATOR.'payload-1.json';

        $files = [$ready0, $attempt0, $release0, $locked0, $proceed0, $payload0, $ready1, $attempt1, $release1, $payload1];

        try {
            file_put_contents($payload0, json_encode([
                'operation' => $op0,
                'company_id' => $this->company->id,
                'actor_id' => $this->owner->id,
                'purchase_id' => $draft->id,
                'ready' => $ready0,
                'attempting' => $attempt0,
                'release' => $release0,
                'hold_before_commit' => true,
                'locked_barrier' => $locked0,
                'proceed_barrier' => $proceed0,
            ], JSON_THROW_ON_ERROR));
            chmod($payload0, 0600);

            file_put_contents($payload1, json_encode([
                'operation' => $op1,
                'company_id' => $this->company->id,
                'actor_id' => $this->owner->id,
                'purchase_id' => $draft->id,
                'ready' => $ready1,
                'attempting' => $attempt1,
                'release' => $release1,
                'hold_before_commit' => false,
            ], JSON_THROW_ON_ERROR));
            chmod($payload1, 0600);

            $p0 = new Process([PHP_BINARY, base_path('tests/Support/Purchasing/purchase-delete-vs-post-worker.php'), $payload0], base_path(), $environment);
            $p0->setTimeout(45)->start();
            $processes[] = $p0;

            $p1 = new Process([PHP_BINARY, base_path('tests/Support/Purchasing/purchase-delete-vs-post-worker.php'), $payload1], base_path(), $environment);
            $p1->setTimeout(45)->start();
            $processes[] = $p1;

            // Wait for both workers to reach ready barrier
            $deadline = microtime(true) + 20;
            while (! is_file($ready0) || ! is_file($ready1)) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Workers failed to reach ready barrier.');
                }
                usleep(5000);
            }

            // Release Worker 0 so it acquires the purchase row lock
            file_put_contents($release0, 'release');

            // Wait until Worker 0 acquires the row lock
            $deadline = microtime(true) + 20;
            while (! is_file($locked0)) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Worker 0 failed to acquire row lock: '.$p0->getErrorOutput());
                }
                usleep(5000);
            }

            // Now release Worker 1 (the contender) while Worker 0 holds the lock
            file_put_contents($release1, 'release');

            // Wait until Worker 1 attempts to query/lock the row
            $deadline = microtime(true) + 20;
            while (! is_file($attempt1)) {
                if (microtime(true) > $deadline) {
                    throw new \RuntimeException('Worker 1 failed to reach attempt barrier.');
                }
                usleep(5000);
            }

            // Allow Worker 1 time to block on the held row lock
            usleep(100000);

            // Assert Worker 1 is running and blocked waiting for Worker 0's lock
            $this->assertTrue($p1->isRunning(), 'Worker 1 must be running and blocked waiting for the row lock.');
            $blockedOutput = $p1->getOutput();
            $this->assertSame('', $blockedOutput, 'Worker 1 must produce no output while blocked.');

            // Release Worker 0 to complete its action and commit
            file_put_contents($proceed0, 'proceed');

            $p0->wait();
            $this->assertSame(0, $p0->getExitCode(), 'Worker 0 failed: '.$p0->getErrorOutput());

            $p1->wait();
            $this->assertSame(0, $p1->getExitCode(), 'Worker 1 failed: '.$p1->getErrorOutput());

            $out0 = json_decode($p0->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $out1 = json_decode($p1->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertIsArray($out0);
            $this->assertIsArray($out1);

            return [$out0, $out1];
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
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function test_concurrency_race_post_wins_delete_blocked(): void
    {
        $draft = $this->createDraft('INV-RACE-POST-WINS');
        $initialLineCount = $draft->lines()->count();

        // Worker 0: Post, Worker 1: Delete
        [$resultPost, $resultDelete] = $this->executeRace($draft, 'post', 'delete');

        $this->assertSame('posted', $resultPost['status']);
        $this->assertSame('validation_blocked', $resultDelete['status']);
        $this->assertArrayHasKey('deleteDraft', $resultDelete['errors']);

        // Assert posted purchase survives intact
        $posted = Purchase::where('company_id', $this->company->id)->findOrFail($draft->id);
        $this->assertSame(Purchase::STATUS_POSTED, $posted->status);
        $this->assertNotNull($posted->purchase_number);
        $this->assertNotNull($posted->posting_batch_id);
        $this->assertSame($initialLineCount, $posted->lines()->count());

        // Stock movements and posting batches must exist for this purchase
        $this->assertDatabaseHas('stock_movements', ['source_type' => 'purchase', 'source_id' => $draft->id]);
        $this->assertDatabaseHas('posting_batches', ['source_type' => 'purchase', 'source_id' => $draft->id]);

        // No draft deleted audit event
        $this->assertDatabaseMissing('audit_events', [
            'company_id' => $this->company->id,
            'event_key' => 'purchase.draft.deleted',
        ]);

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrency_race_delete_wins_post_aborts_not_found(): void
    {
        $draft = $this->createDraft('INV-RACE-DEL-WINS');

        // Worker 0: Delete, Worker 1: Post
        [$resultDelete, $resultPost] = $this->executeRace($draft, 'delete', 'post');

        $this->assertSame('deleted', $resultDelete['status']);
        $this->assertSame('not_found', $resultPost['status']);

        // Assert purchase and lines are completely deleted
        $this->assertDatabaseMissing('purchases', ['id' => $draft->id]);
        $this->assertDatabaseMissing('purchase_lines', ['purchase_id' => $draft->id]);

        // No partial stock movements or posting batches created for this purchase
        $this->assertDatabaseMissing('stock_movements', ['source_type' => 'purchase', 'source_id' => $draft->id]);
        $this->assertDatabaseMissing('posting_batches', ['source_type' => 'purchase', 'source_id' => $draft->id]);

        // Exactly one delete audit event recorded
        $this->assertSame(1, DB::table('audit_events')
            ->where('company_id', $this->company->id)
            ->where('event_key', 'purchase.draft.deleted')
            ->count());

        $this->assertHealthyAndControlUnchanged();
    }
}
