<?php

declare(strict_types=1);

namespace Tests\Feature\StockAccountingIntegrity;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\InventoryBalance;
use App\Models\InventoryCostState;
use App\Models\InventoryOperation;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\TestCase;

/** Independent connections require committed fixtures, so intentionally no RefreshDatabase. */
class StockEventConcurrencyTest extends TestCase
{
    private ?DisposableMariaDbSchema $fixtureSchema = null;

    /** @var array<string,int> */
    private array $primaryCounts = [];

    private Company $company;

    private Company $controlCompany;

    private User $owner;

    private Product $product;

    private Warehouse $warehouse;

    private Unit $pieceUnit;

    private string $controlFingerprint;

    protected function setUp(): void
    {
        parent::setUp();
        $primary = DB::connection()->getDatabaseName();
        DisposableMariaDbSchema::assertPrimarySchema($primary);
        foreach (['companies', 'users', 'posting_batches', 'posting_lines', 'stock_movements', 'inventory_operations', 'inventory_balances'] as $table) {
            if (Schema::hasTable($table)) {
                $this->primaryCounts[$table] = DB::table($table)->count();
            }
        }
        $this->fixtureSchema = DisposableMariaDbSchema::createFromSource($primary);
        $this->fixtureSchema->switchLaravelConnection();

        app(CompanyContext::class)->clear();
        $this->owner = User::factory()->create();
        $creator = app(CreateCompanyAction::class);

        // Control tenant: isolated opening stock establishes a meaningful invariant fingerprint.
        $this->controlCompany = $creator->execute($this->owner, [
            'name_ar' => 'شركة ضبط المخزون',
            'name_en' => 'Stock Race Control',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->controlCompany);
        $controlUnit = Unit::where('company_id', $this->controlCompany->id)->where('code', 'piece')->firstOrFail();
        $controlWarehouse = Warehouse::where('company_id', $this->controlCompany->id)->firstOrFail();
        $controlProduct = app(ProductCatalogService::class)->createProduct($this->controlCompany, [
            'name_ar' => 'منتج الضبط',
            'name_en' => 'Control Product',
            'sku' => 'CTRL-STK',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $controlUnit->id,
            'default_purchase_cost_base' => '1.000000',
        ], $this->owner->id);
        app(PostOpeningStockAction::class)->execute(
            company: $this->controlCompany,
            product: $controlProduct,
            warehouse: $controlWarehouse,
            quantity: Quantity::of(1),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: (string) Str::uuid(),
        );
        $this->controlFingerprint = $this->fingerprint($this->controlCompany);

        // Main trading company for stock event race regressions.
        app(CompanyContext::class)->clear();
        $this->company = $creator->execute($this->owner, [
            'name_ar' => 'شركة سباق المخزون',
            'name_en' => 'Stock Race Main',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->company);
        $this->pieceUnit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج سباق المخزون',
            'name_en' => 'Stock Race Product',
            'sku' => 'RACE-STOCK-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $this->pieceUnit->id,
            'default_purchase_cost_base' => '1.000000',
        ], $this->owner->id);
        $this->assertSame(0, DB::transactionLevel());
    }

    protected function tearDown(): void
    {
        try {
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

    public function test_concurrent_identical_opening_stock_yields_same_movement_and_batch(): void
    {
        $key = (string) Str::uuid();
        $intent = [
            'quantity' => '2',
            'unit_cost' => '1.000000',
            'idempotency_key' => $key,
            'actor_id' => $this->owner->id,
        ];

        $results = $this->race($intent, $intent);

        $this->assertSame('ok', $results[0]['status']);
        $this->assertSame('ok', $results[1]['status']);
        $this->assertSame($results[0]['movement_id'], $results[1]['movement_id']);
        $this->assertSame($results[0]['batch_id'], $results[1]['batch_id']);

        // Exactly one InventoryOperation
        $this->assertSame(1, InventoryOperation::where('company_id', $this->company->id)->count());

        // Exactly 1 movement
        $movements = StockMovement::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $movements);
        $movement = $movements->first();
        $this->assertNotNull($movement);
        $this->assertSame((int) $results[0]['movement_id'], (int) $movement->id);
        $this->assertSame((int) $this->product->id, (int) $movement->product_id);
        $this->assertSame((int) $this->warehouse->id, (int) $movement->warehouse_id);
        $this->assertSame(StockMovement::TYPE_OPENING_BALANCE, $movement->movement_type);
        $this->assertSame('2.000000', (string) BigDecimal::of((string) $movement->quantity_delta_base)->toScale(6));
        $this->assertSame('1.000000', (string) BigDecimal::of((string) $movement->unit_cost_base)->toScale(6));
        $this->assertSame('2.000000', (string) BigDecimal::of((string) $movement->value_delta_base)->toScale(6));

        // Exactly 1 GL batch with 2 lines
        $batches = PostingBatch::where('company_id', $this->company->id)->get();
        $this->assertCount(1, $batches);
        $batch = $batches->first();
        $this->assertNotNull($batch);
        $this->assertSame((int) $results[0]['batch_id'], (int) $batch->id);
        $this->assertSame(PostingBatch::STATUS_POSTED, $batch->status);
        $this->assertSame('opening_stock', $batch->source_type);
        $this->assertSame((int) $movement->id, (int) $batch->source_id);

        $lines = $batch->lines()->orderBy('line_number')->get();
        $this->assertCount(2, $lines);
        $this->assertSame('2.000000', (string) BigDecimal::of((string) $lines[0]->debit_base)->toScale(6));
        $this->assertSame('0.000000', (string) BigDecimal::of((string) $lines[0]->credit_base)->toScale(6));
        $this->assertSame('0.000000', (string) BigDecimal::of((string) $lines[1]->debit_base)->toScale(6));
        $this->assertSame('2.000000', (string) BigDecimal::of((string) $lines[1]->credit_base)->toScale(6));

        // Value 2 reflected in moving average cost state and inventory balances
        $costState = InventoryCostState::where('company_id', $this->company->id)->where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame('2.000000', (string) BigDecimal::of((string) $costState->inventory_value_base)->toScale(6));
        $this->assertSame('2.000000', (string) BigDecimal::of((string) $costState->quantity_base)->toScale(6));
        $this->assertSame('1.000000', (string) BigDecimal::of((string) $costState->average_cost_base)->toScale(6));

        $balance = InventoryBalance::where('company_id', $this->company->id)
            ->where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->firstOrFail();
        $this->assertSame('2.000000', (string) BigDecimal::of((string) $balance->quantity_base)->toScale(6));

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_concurrent_conflicting_cost_same_key_yields_one_ok_one_conflict_without_overwrite(): void
    {
        $key = (string) Str::uuid();
        $intentA = [
            'quantity' => '2',
            'unit_cost' => '1.000000',
            'idempotency_key' => $key,
            'actor_id' => $this->owner->id,
        ];
        $intentB = [
            'quantity' => '2',
            'unit_cost' => '2.000000',
            'idempotency_key' => $key,
            'actor_id' => $this->owner->id,
        ];

        $results = $this->race($intentA, $intentB);

        $statuses = array_column($results, 'status');
        sort($statuses);
        $this->assertSame(['idempotency_conflict', 'ok'], $statuses);

        // Exactly one InventoryOperation, 1 movement, 1 batch
        $this->assertSame(1, InventoryOperation::where('company_id', $this->company->id)->count());
        $this->assertSame(1, StockMovement::where('company_id', $this->company->id)->count());
        $this->assertSame(1, PostingBatch::where('company_id', $this->company->id)->count());

        // Determine winner and verify winning cost/value is not overwritten
        $winnerIdx = $results[0]['status'] === 'ok' ? 0 : 1;
        $winningCost = $winnerIdx === 0 ? '1.000000' : '2.000000';
        $winningVal = $winnerIdx === 0 ? '2.000000' : '4.000000';

        $movement = StockMovement::where('company_id', $this->company->id)->firstOrFail();
        $this->assertSame($winningCost, (string) BigDecimal::of((string) $movement->unit_cost_base)->toScale(6));
        $this->assertSame($winningVal, (string) BigDecimal::of((string) $movement->value_delta_base)->toScale(6));

        $costState = InventoryCostState::where('company_id', $this->company->id)->where('product_id', $this->product->id)->firstOrFail();
        $this->assertSame($winningCost, (string) BigDecimal::of((string) $costState->average_cost_base)->toScale(6));
        $this->assertSame($winningVal, (string) BigDecimal::of((string) $costState->inventory_value_base)->toScale(6));

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_actor_role_downgrade_while_company_lock_held_refuses_both_attempts_without_economic_writes(): void
    {
        $key = (string) Str::uuid();
        $intent = [
            'quantity' => '2',
            'unit_cost' => '1.000000',
            'idempotency_key' => $key,
            'actor_id' => $this->owner->id,
        ];

        $viewerRole = Role::where('company_id', $this->company->id)->where('name', 'Viewer')->firstOrFail();
        $beforeRelease = function () use ($viewerRole): void {
            $this->owner->syncRoles($viewerRole);
        };

        $results = $this->race($intent, $intent, $beforeRelease);

        $this->assertSame(['status' => 'denied'], $results[0]);
        $this->assertSame(['status' => 'denied'], $results[1]);

        // Zero economic writes
        $this->assertSame(0, StockMovement::where('company_id', $this->company->id)->count());
        $this->assertSame(0, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame(0, InventoryOperation::where('company_id', $this->company->id)->count());
        $this->assertSame(0, InventoryBalance::where('company_id', $this->company->id)->count());

        $this->assertHealthyAndControlUnchanged();
    }

    public function test_membership_revoked_while_company_lock_held_refuses_both_attempts_without_economic_writes(): void
    {
        $collaborator = User::factory()->create();
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $collaborator->id,
            'status' => 'active',
            'is_owner' => false,
            'joined_at' => now(),
        ]);
        setPermissionsTeamId($this->company->id);
        $collaborator->givePermissionTo('inventory.stock.adjust');
        $collaborator->givePermissionTo('inventory.cost.view');

        $key = (string) Str::uuid();
        $intent = [
            'quantity' => '2',
            'unit_cost' => '1.000000',
            'idempotency_key' => $key,
            'actor_id' => $collaborator->id,
        ];

        $beforeRelease = function () use ($collaborator): void {
            CompanyUser::where('company_id', $this->company->id)
                ->where('user_id', $collaborator->id)
                ->update(['status' => 'inactive']);
        };

        $results = $this->race($intent, $intent, $beforeRelease);

        $this->assertSame(['status' => 'denied'], $results[0]);
        $this->assertSame(['status' => 'denied'], $results[1]);

        // Zero economic writes
        $this->assertSame(0, StockMovement::where('company_id', $this->company->id)->count());
        $this->assertSame(0, PostingBatch::where('company_id', $this->company->id)->count());
        $this->assertSame(0, InventoryOperation::where('company_id', $this->company->id)->count());
        $this->assertSame(0, InventoryBalance::where('company_id', $this->company->id)->count());

        $this->assertHealthyAndControlUnchanged();
    }

    private function activate(Company $company, ?User $actor = null): void
    {
        $user = $actor ?? $this->owner;
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($company, $user);
        setPermissionsTeamId($company->id);
    }

    private function fingerprint(Company $company): string
    {
        $rows = [];
        foreach ([
            'posting_batches', 'posting_lines', 'stock_movements',
            'inventory_operations', 'inventory_lots', 'inventory_balances',
            'inventory_lot_balances', 'inventory_cost_states', 'audit_events',
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
        foreach (['accounting:reconcile', 'inventory:reconcile'] as $command) {
            $code = Artisan::call($command, ['companyPublicId' => $this->company->public_id]);
            $this->assertSame(0, $code, $command.': '.Artisan::output());
        }
        $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accountingReport->isHealthy, implode('; ', $accountingReport->violations));
        $this->assertSame([], $accountingReport->violations);
        $inventoryReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inventoryReport->isHealthy, implode('; ', $inventoryReport->discrepancies));
        $this->assertSame([], $inventoryReport->discrepancies);
        $this->assertSame($this->controlFingerprint, $this->fingerprint($this->controlCompany));
        $this->assertSame(0, DB::transactionLevel());
        app(CompanyContext::class)->clear();
        auth()->forgetUser();
        setPermissionsTeamId(null);
    }

    /**
     * @param  array<string,mixed>  $intentA
     * @param  array<string,mixed>  $intentB
     * @param  (?callable():void)  $beforeRelease
     * @return array{array<string,mixed>,array<string,mixed>}
     */
    private function race(
        array $intentA,
        array $intentB,
        ?callable $beforeRelease = null,
    ): array {
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stock-event-race-'.bin2hex(random_bytes(8));
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
            throw new \RuntimeException('A valid APP_KEY is required for the stock event race harness.');
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
        $environment['PHASE8_TEST_DB_PASSWORD'] = getenv('PHASE8_TEST_DB_PASSWORD');
        $environment = array_replace($environment, $this->fixtureSchema->environment());

        $intents = [$intentA, $intentB];

        $locked = false;
        try {
            DB::beginTransaction();
            $locked = true;
            DB::table('companies')->where('id', $this->company->id)->lockForUpdate()->first();

            foreach ($intents as $index => $intent) {
                $payloadPath = $directory.DIRECTORY_SEPARATOR.'payload-'.$index.'.json';
                $ready = $directory.DIRECTORY_SEPARATOR.'ready-'.$index;
                $attempting = $directory.DIRECTORY_SEPARATOR.'attempting-'.$index;
                $files = array_merge($files, [$payloadPath, $ready, $attempting]);
                $readyFiles[] = $ready;
                $attemptFiles[] = $attempting;
                file_put_contents($payloadPath, json_encode([
                    'operation' => 'opening_stock',
                    'company_id' => $this->company->id,
                    'actor_id' => $intent['actor_id'] ?? $this->owner->id,
                    'product_id' => $this->product->id,
                    'warehouse_id' => $this->warehouse->id,
                    'quantity' => $intent['quantity'],
                    'unit_cost' => $intent['unit_cost'],
                    'idempotency_key' => $intent['idempotency_key'],
                    'ready' => $ready,
                    'attempting' => $attempting,
                    'release' => $release,
                ], JSON_THROW_ON_ERROR));
                chmod($payloadPath, 0600);
                $process = new Process(
                    [PHP_BINARY, base_path('tests/Support/StockAccountingIntegrity/stock-event-race-worker.php'), $payloadPath],
                    base_path(),
                    $environment,
                );
                $process->setTimeout(45)->start();
                $processes[] = $process;
            }

            $this->waitForFiles($readyFiles, $processes);
            file_put_contents($release, 'release');
            $this->waitForFiles($attemptFiles, $processes);
            usleep(200000);
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Both independent stock event attempts must overlap behind the company lock.');
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
            if (is_dir($directory)) {
                rmdir($directory);
            }
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
                    $this->fail('Stock event race child exited before the barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Stock event race barrier timed out.');
    }
}
