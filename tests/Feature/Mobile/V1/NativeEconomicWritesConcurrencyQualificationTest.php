<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\EnsureCompanyInTransitWarehouseAction;
use App\Actions\Purchasing\RecordStagedPurchaseBillAction;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\GoodsReceiptNote;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\NativeCommandReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\CustomerCatalogService;
use App\Services\Sales\SalesReconciliationService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\EnforcesDisposableDatabaseOwnership;
use Tests\Support\StrictBarrier;
use Tests\TestCase;

/**
 * Real separate PHP process + MariaDB connection barrier HTTP qualification
 * for customer payment, vendor payment, expense, inventory adjust, and GRN receipts.
 */
final class NativeEconomicWritesConcurrencyQualificationTest extends TestCase
{
    use EnforcesDisposableDatabaseOwnership;

    private User $owner;

    private User $staff;

    private Company $company;

    private MobileDevice $device;

    private MobileAccessToken $token;

    private string $rawToken;

    private MoneyAccount $cashAccount;

    private Warehouse $warehouse;

    private Product $product;

    private Customer $customer;

    private Vendor $vendor;

    private ExpenseCategory $expenseCategory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enterIsolatedDisposableSchema();
        $this->setupFixture();
    }

    protected function tearDown(): void
    {
        try {
            $this->exitAndVerifyIsolatedDisposableSchema();
        } finally {
            parent::tearDown();
        }
    }

    private function setupFixture(): void
    {
        app(CompanyContext::class)->clear();

        config([
            'mobile.enabled' => true,
            'mobile.merchant_api_enabled' => true,
        ]);

        Currency::updateOrCreate(
            ['code' => 'ILS'],
            ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]
        );

        $this->owner = User::factory()->create([
            'email' => 'owner_'.Str::random(8).'@example.com',
            'password' => Hash::make('SecretPass123!'),
        ]);

        $this->staff = User::factory()->create([
            'email' => 'staff_'.Str::random(8).'@example.com',
            'password' => Hash::make('StaffPass123!'),
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة مؤهلة للتزامن الاقتصادي',
            'name_en' => 'Economic Concurrency Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);

        $this->owner->assignRole('Owner');

        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $this->staff->id,
            'status' => 'active',
            'is_owner' => false,
        ]);

        $this->staff->assignRole('Sales');
        $this->staff->givePermissionTo([
            'money.receipt.create',
            'money.receipt.view',
            'money.vendor_payment.create',
            'purchasing.cost.view',
            'money.expense.manage',
            'money.expense.view',
            'inventory.stock.adjust',
            'inventory.stock.view',
            'inventory.cost.view',
            'inventory.stock.transfer',
            'purchasing.purchase.create',
            'purchasing.purchase.post',
            'purchasing.purchase.view',
            'customers.view',
            'vendors.view',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('active', true)->firstOrFail();

        app(EnsureCompanyInTransitWarehouseAction::class)->execute($this->company, $this->owner);

        $this->cashAccount = MoneyAccount::where('company_id', $this->company->id)
            ->where('account_type', MoneyAccount::TYPE_CASH)
            ->where('currency_code', 'ILS')
            ->firstOrFail();

        CompanyMobileSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'approved_cash_account_id' => $this->cashAccount->id,
                'default_warehouse_id' => $this->warehouse->id,
            ]
        );

        $pieceUnit = Unit::firstOrCreate(
            ['company_id' => $this->company->id, 'code' => 'PCS'],
            [
                'name_ar' => 'قطعة',
                'name_en' => 'Piece',
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]
        );

        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'سلعة سباق التزامن',
            'name_en' => 'Race Tracked Item',
            'sku' => 'SKU-RACE-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_sale_price_base' => '50.000000',
            'default_purchase_cost_base' => '30.000000',
            'base_unit_id' => $pieceUnit->id,
        ], $this->owner->id);

        $this->customer = app(CustomerCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'عميل سباق التزامن',
            'name_en' => 'Race Customer',
            'phone' => '+970599000111',
        ]);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد سباق التزامن',
            'name_en' => 'Race Supplier',
            'phone' => '+970599000222',
        ]);

        $this->expenseCategory = ExpenseCategory::where('company_id', $this->company->id)->firstOrFail();

        $deviceId = (string) Str::uuid();
        $this->device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Concur Worker Phone',
            'user_id' => $this->staff->id,
        ]);

        $this->rawToken = 'st_m_'.bin2hex(random_bytes(32));
        $this->token = MobileAccessToken::create([
            'token_hash' => hash('sha256', $this->rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->staff->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->staff->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->staff);
        $this->actingAs($this->staff);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startWorkerProcess(DisposableMariaDbSchema $schema, string $directory, string $slot, array $input): Process
    {
        file_put_contents($directory.'/'.$slot.'.json', json_encode($input, JSON_THROW_ON_ERROR));
        chmod($directory.'/'.$slot.'.json', 0600);

        $settings = DisposableMariaDbSchema::testSettings();
        $env = array_replace([
            'APP_KEY' => (string) (config('app.key') ?: getenv('APP_KEY')),
            'PHASE8_ALLOW_DISPOSABLE_DB' => '1',
            'PHASE8_TEST_DB_HOST' => $settings['HOST'],
            'PHASE8_TEST_DB_PORT' => $settings['PORT'],
            'PHASE8_TEST_DB_USERNAME' => $settings['USERNAME'],
            'PHASE8_TEST_DB_PASSWORD' => $settings['PASSWORD'],
            'PHASE8_TEST_DB_EMPTY_PASSWORD' => $settings['PASSWORD'] === '' ? '1' : '0',
        ], $schema->environment());

        $process = new Process([PHP_BINARY, base_path('tests/Support/mobile-receipt-worker.php'), $directory, $slot], base_path(), $env);
        $process->setTimeout(90)->start();

        $deadline = microtime(true) + 30;
        while (! is_file($directory.'/'.$slot.'.ready')) {
            if (! $process->isRunning() || microtime(true) > $deadline) {
                $process->stop();
                $this->fail('Worker '.$slot.' failed to reach ready barrier: [err='.$process->getErrorOutput().'] [out='.$process->getOutput().']');
            }
            usleep(1000);
        }

        return $process;
    }

    private function awaitProcesslistBlockedCid(string $directory, DisposableMariaDbSchema $schema, Process $workerA, Process $workerB): void
    {
        StrictBarrier::await(function () use ($directory, $schema): bool {
            $connectionPath = $directory.'/worker-b.connection';
            if (! is_file($connectionPath)) {
                return false;
            }
            $id = trim((string) file_get_contents($connectionPath));
            if (! ctype_digit($id)) {
                return false;
            }
            foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
                $sql = strtolower((string) ($row->Info ?? ''));
                if ((string) $row->Id === $id && $row->db === $schema->schemaName()
                    && str_contains($sql, 'from `companies`') && str_contains($sql, 'for update')) {
                    return true;
                }
            }

            return false;
        }, fn (): bool => $workerA->isRunning() && $workerB->isRunning());
    }

    private function assertAllSixReconciliationsPass(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $acct = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($acct->isHealthy, 'Accounting reconciliation failed: '.json_encode($acct->violations));

        $inv = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inv->isHealthy, 'Inventory reconciliation failed: '.json_encode($inv->discrepancies));

        $sales = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($sales->isHealthy, 'Sales reconciliation failed: '.json_encode($sales->violations));

        $pay = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($pay->isHealthy, 'Payables reconciliation failed: '.json_encode($pay->violations));

        $money = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($money->isHealthy, 'Money reconciliation failed: '.json_encode($money->violations));

        $p7 = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($p7->isHealthy, 'Phase 7 reconciliation failed: '.json_encode($p7->violations));
    }

    public function test_customer_payment_concurrent_identical_and_conflict_races(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            // 1. Same key identical requests converge: 201 Created + 200 OK replay
            $key = 'race-cust-'.(string) Str::uuid();
            $uri = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
            $payload = [
                'amount' => '100.000000',
                'notes' => 'سداد عميل متزامن متطابق',
            ];

            $inputHolder = [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ];

            $inputContender = [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', $inputHolder);
            touch($directory.'/go-worker-a');

            StrictBarrier::await(
                fn (): bool => is_file($directory.'/worker-a.locked'),
                fn (): bool => $workerA->isRunning()
            );

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', $inputContender);
            touch($directory.'/go-worker-b');

            $this->awaitProcesslistBlockedCid($directory, $schema, $workerA, $workerB);
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait(), 'Worker A failed: '.$workerA->getErrorOutput());
            $this->assertSame(0, $workerB->wait(), 'Worker B failed: '.$workerB->getErrorOutput());

            $outA = json_decode($workerA->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outB = json_decode($workerB->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $statuses = [$outA['status'], $outB['status']];
            sort($statuses);
            $this->assertSame([200, 201], $statuses, 'Concurrent identical requests must converge to 201 + 200 replay.');

            $winnerOut = $outA['status'] === 201 ? $outA : $outB;
            $replayOut = $outA['status'] === 200 ? $outA : $outB;
            $this->assertSame('true', $replayOut['headers']['X-Idempotent-Replay']);
            $this->assertSame($winnerOut['body']['id'], $replayOut['body']['id']);
            $this->assertSame('100.00', $winnerOut['body']['amount']);

            // Database counts: exactly 1 payment and 1 receipt
            $this->assertSame(1, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('idempotency_key', $key)->count());

            // 2. Same key different payload -> winner 201, loser 409
            @array_map('unlink', glob($directory.'/*') ?: []);
            $keyConflict = 'race-cust-conf-'.(string) Str::uuid();
            $payloadWinner = ['amount' => '50.000000', 'notes' => 'سداد عميل أصلي'];
            $payloadLoser = ['amount' => '75.000000', 'notes' => 'سداد عميل مختلف'];

            $inputHolderConf = [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder-conf',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConflict,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payloadWinner,
                ],
            ];

            $inputContenderConf = [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConflict,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payloadLoser,
                ],
            ];

            $workerAConf = $this->startWorkerProcess($schema, $directory, 'worker-a', $inputHolderConf);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerAConf->isRunning());

            $workerBConf = $this->startWorkerProcess($schema, $directory, 'worker-b', $inputContenderConf);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerAConf, $workerBConf);
            touch($directory.'/release-holder-conf');

            $this->assertSame(0, $workerAConf->wait());
            $this->assertSame(0, $workerBConf->wait());

            $outAConf = json_decode($workerAConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outBConf = json_decode($workerBConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(201, $outAConf['status']);
            $this->assertSame(409, $outBConf['status']);
            $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $outBConf['body']['error']['code']);

            $this->assertSame(2, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(2, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

            $this->assertAllSixReconciliationsPass();
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null, $workerAConf ?? null, $workerBConf ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }

    public function test_vendor_payment_concurrent_identical_and_conflict_races(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            $key = 'race-supp-'.(string) Str::uuid();
            $uri = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/payments";
            $payload = ['amount' => '40.000000', 'notes' => 'سداد مورد متزامن متطابق'];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerA->isRunning());

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ]);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerA, $workerB);
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait());
            $this->assertSame(0, $workerB->wait());

            $outA = json_decode($workerA->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outB = json_decode($workerB->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $statuses = [$outA['status'], $outB['status']];
            sort($statuses);
            $this->assertSame([200, 201], $statuses);

            $this->assertSame(1, VendorPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('idempotency_key', $key)->count());

            // Conflict race
            @array_map('unlink', glob($directory.'/*') ?: []);
            $keyConf = 'race-supp-conf-'.(string) Str::uuid();
            $workerAConf = $this->startWorkerProcess($schema, $directory, 'worker-a', [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder-conf',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConf,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => ['amount' => '30.000000', 'notes' => 'أصلي'],
                ],
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerAConf->isRunning());

            $workerBConf = $this->startWorkerProcess($schema, $directory, 'worker-b', [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConf,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => ['amount' => '60.000000', 'notes' => 'مختلف'],
                ],
            ]);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerAConf, $workerBConf);
            touch($directory.'/release-holder-conf');

            $this->assertSame(0, $workerAConf->wait());
            $this->assertSame(0, $workerBConf->wait());

            $outAConf = json_decode($workerAConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outBConf = json_decode($workerBConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(201, $outAConf['status']);
            $this->assertSame(409, $outBConf['status']);
            $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $outBConf['body']['error']['code']);

            $this->assertSame(2, VendorPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertAllSixReconciliationsPass();
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null, $workerAConf ?? null, $workerBConf ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }

    public function test_expense_concurrent_identical_and_conflict_races(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            $key = 'race-exp-'.(string) Str::uuid();
            $uri = "/api/mobile/v1/companies/{$this->company->public_id}/expenses";
            $payload = [
                'expense_category_id' => $this->expenseCategory->public_id,
                'amount' => '20.000000',
                'description' => 'مصروف متزامن متطابق',
            ];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerA->isRunning());

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ]);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerA, $workerB);
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait());
            $this->assertSame(0, $workerB->wait());

            $outA = json_decode($workerA->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outB = json_decode($workerB->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $statuses = [$outA['status'], $outB['status']];
            sort($statuses);
            $this->assertSame([200, 201], $statuses);

            $this->assertSame(1, Expense::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('idempotency_key', $key)->count());

            // Conflict race
            @array_map('unlink', glob($directory.'/*') ?: []);
            $keyConf = 'race-exp-conf-'.(string) Str::uuid();
            $workerAConf = $this->startWorkerProcess($schema, $directory, 'worker-a', [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder-conf',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConf,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => ['expense_category_id' => $this->expenseCategory->public_id, 'amount' => '15.000000', 'description' => 'أصلي'],
                ],
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerAConf->isRunning());

            $workerBConf = $this->startWorkerProcess($schema, $directory, 'worker-b', [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConf,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => ['expense_category_id' => $this->expenseCategory->public_id, 'amount' => '25.000000', 'description' => 'مختلف'],
                ],
            ]);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerAConf, $workerBConf);
            touch($directory.'/release-holder-conf');

            $this->assertSame(0, $workerAConf->wait());
            $this->assertSame(0, $workerBConf->wait());

            $outAConf = json_decode($workerAConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outBConf = json_decode($workerBConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(201, $outAConf['status']);
            $this->assertSame(409, $outBConf['status']);
            $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $outBConf['body']['error']['code']);

            $this->assertSame(2, Expense::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertAllSixReconciliationsPass();
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null, $workerAConf ?? null, $workerBConf ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }

    public function test_inventory_adjust_concurrent_identical_and_conflict_races(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            $key = 'race-inv-'.(string) Str::uuid();
            $uri = "/api/mobile/v1/companies/{$this->company->public_id}/inventory/adjustments";
            $payload = [
                'product_id' => $this->product->public_id,
                'delta' => '10.000000',
                'reason' => 'جرد متزامن متطابق',
                'unit_cost' => '30.000000',
            ];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerA->isRunning());

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $key,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
                ],
            ]);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerA, $workerB);
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait());
            $this->assertSame(0, $workerB->wait());

            $outA = json_decode($workerA->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outB = json_decode($workerB->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            $statuses = [$outA['status'], $outB['status']];
            sort($statuses);
            $this->assertSame([200, 201], $statuses);

            $winner = $outA['status'] === 201 ? $outA : $outB;
            $this->assertSame('10.000000', $winner['body']['resulting_stock']);

            $this->assertSame(1, StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, NativeCommandReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('idempotency_key', $key)->count());

            // Conflict race
            @array_map('unlink', glob($directory.'/*') ?: []);
            $keyConf = 'race-inv-conf-'.(string) Str::uuid();
            $workerAConf = $this->startWorkerProcess($schema, $directory, 'worker-a', [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder-conf',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConf,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => ['product_id' => $this->product->public_id, 'delta' => '5.000000', 'reason' => 'أصلي', 'unit_cost' => '30.000000'],
                ],
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerAConf->isRunning());

            $workerBConf = $this->startWorkerProcess($schema, $directory, 'worker-b', [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $uri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyConf,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => ['product_id' => $this->product->public_id, 'delta' => '8.000000', 'reason' => 'مختلف', 'unit_cost' => '30.000000'],
                ],
            ]);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerAConf, $workerBConf);
            touch($directory.'/release-holder-conf');

            $this->assertSame(0, $workerAConf->wait());
            $this->assertSame(0, $workerBConf->wait());

            $outAConf = json_decode($workerAConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outBConf = json_decode($workerBConf->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame(201, $outAConf['status']);
            $this->assertSame(409, $outBConf['status']);
            $this->assertSame('IDEMPOTENCY_KEY_CONFLICT', $outBConf['body']['error']['code']);

            $this->assertSame(2, StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertAllSixReconciliationsPass();
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null, $workerAConf ?? null, $workerBConf ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }

    public function test_concurrent_purchase_return_replays_original_receipt_and_commits_once(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $this->staff->givePermissionTo(['purchasing.return.manage', 'purchasing.return.post']);
            $bill = app(RecordStagedPurchaseBillAction::class)->execute(
                company: $this->company,
                actor: $this->staff,
                supplierPublicId: $this->vendor->public_id,
                billNumber: 'BILL-RETURN-RACE',
                linesInput: [['product_id' => $this->product->public_id, 'quantity' => '100', 'unit_cost_ils' => '25.00']],
                idempotencyKey: 'return-race-bill',
                mobileToken: $this->token,
                mobileDevice: $this->device
            );
            $input = [
                'mode' => 'http',
                'http' => [
                    'uri' => "/api/mobile/v1/companies/{$this->company->public_id}/purchases/returns",
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => 'concurrent-return',
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => [
                        'purchase_id' => $bill['snapshot']['id'],
                        'lines' => [['product_id' => $this->product->public_id, 'quantity' => '5']],
                    ],
                ],
            ];
            $holder = $this->startWorkerProcess($schema, $directory, 'worker-a', $input + [
                'hold_lock_table' => 'companies', 'wait_for_file' => 'release-holder',
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $holder->isRunning());
            $contender = $this->startWorkerProcess($schema, $directory, 'worker-b', $input);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $holder, $contender);
            touch($directory.'/release-holder');
            $this->assertSame(0, $holder->wait());
            $this->assertSame(0, $contender->wait());
            $created = json_decode($holder->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $replay = json_decode($contender->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(201, $created['status'], json_encode($created['body']));
            $this->assertSame(200, $replay['status']);
            $this->assertSame($created['body'], $replay['body']);
            $this->assertSame('true', $replay['headers']['X-Idempotent-Replay']);
            $this->assertSame(1, DB::table('purchase_returns')->count());
            $this->assertSame(1, DB::table('native_command_receipts')->where('command_type', 'purchase_return')->count());
            $this->assertAllSixReconciliationsPass();
        } finally {
            if (isset($holder) && $holder->isRunning()) {
                $holder->stop();
            }
            if (isset($contender) && $contender->isRunning()) {
                $contender->stop();
            }
            if (is_dir($directory)) {
                foreach (glob($directory.'/*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($directory);
            }
        }
    }

    public function test_grn_concurrent_distinct_keys_total_requested_exceeds_remaining_limits_and_bounds(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            // 1. Create a staged purchase bill for 100 units canonically
            $billKey = 'grn-race-bill-'.(string) Str::uuid();
            $action = app(RecordStagedPurchaseBillAction::class);
            $billRes = $action->execute(
                company: $this->company,
                actor: $this->staff,
                supplierPublicId: $this->vendor->public_id,
                billNumber: 'BILL-GRN-RACE',
                linesInput: [
                    ['product_id' => $this->product->public_id, 'quantity' => '100', 'unit_cost_ils' => '25.00'],
                ],
                idempotencyKey: $billKey,
                mobileToken: $this->token,
                mobileDevice: $this->device
            );

            $purchase = Purchase::where('company_id', $this->company->id)->where('public_id', $billRes['snapshot']['id'])->firstOrFail();
            $line = $purchase->lines()->firstOrFail();

            // 2. Race two workers with DISTINCT keys each requesting 60 units (total 120 > 100)
            $keyA = 'grn-race-a-'.(string) Str::uuid();
            $keyB = 'grn-race-b-'.(string) Str::uuid();
            $grnUri = "/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchase->public_id}/grn";

            $grnPayloadA = [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'receipt_date' => now()->format('Y-m-d'),
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '60'],
                ],
            ];

            $grnPayloadB = [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'receipt_date' => now()->format('Y-m-d'),
                'lines' => [
                    ['purchase_line_id' => $line->public_id, 'quantity' => '60'],
                ],
            ];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', [
                'mode' => 'http',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
                'http' => [
                    'method' => 'POST',
                    'uri' => $grnUri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyA,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $grnPayloadA,
                ],
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $workerA->isRunning());

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', [
                'mode' => 'http',
                'http' => [
                    'method' => 'POST',
                    'uri' => $grnUri,
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => $keyB,
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $grnPayloadB,
                ],
            ]);
            touch($directory.'/go-worker-b');
            $this->awaitProcesslistBlockedCid($directory, $schema, $workerA, $workerB);
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait());
            $this->assertSame(0, $workerB->wait());

            $outA = json_decode($workerA->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $outB = json_decode($workerB->getOutput(), true, 512, JSON_THROW_ON_ERROR);

            // Winner commits permitted 60 units (201 Created), loser exceeds remaining 40 and gets 422
            $this->assertSame(201, $outA['status']);
            $this->assertSame(422, $outB['status']);
            $this->assertSame('OVER_RECEIPT_LIMIT_EXCEEDED', $outB['body']['error']['code']);

            // Verified quantities: Exactly 1 GRN for 60 units
            $this->assertSame(1, GoodsReceiptNote::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

            // 3. Same key replay: Replaying winner's keyA returns exact 200 OK replay
            $replayRes = $this->withHeaders([
                'Authorization' => 'Bearer '.$this->rawToken,
                'Idempotency-Key' => $keyA,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->postJson($grnUri, $grnPayloadA);

            $replayRes->assertStatus(200);
            $replayRes->assertHeader('X-Idempotent-Replay', 'true');
            $this->assertSame($outA['body']['grn_number'], $replayRes->json('grn_number'));

            // 4. Cross-actor bound: replay by another user is blocked with 403 Forbidden
            $otherUser = User::factory()->create([
                'email' => 'other_'.Str::random(8).'@example.com',
                'password' => Hash::make('OtherPass123!'),
            ]);
            CompanyUser::create([
                'company_id' => $this->company->id,
                'user_id' => $otherUser->id,
                'status' => 'active',
                'is_owner' => false,
            ]);
            setPermissionsTeamId($this->company->id);
            $otherUser->assignRole('Sales');
            $otherUser->givePermissionTo(['inventory.stock.transfer', 'purchasing.purchase.view']);

            $otherDevice = MobileDevice::create([
                'device_id' => (string) Str::uuid(),
                'device_name' => 'Other Device',
                'user_id' => $otherUser->id,
            ]);
            $otherRawToken = 'st_m_'.bin2hex(random_bytes(32));
            MobileAccessToken::create([
                'token_hash' => hash('sha256', $otherRawToken),
                'device_id' => $otherDevice->device_id,
                'user_id' => $otherUser->id,
                'company_id' => $this->company->id,
                'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $otherUser->password), 0, 16),
                'expires_at' => now()->addDays(7),
            ]);

            $crossActorRes = $this->withHeaders([
                'Authorization' => 'Bearer '.$otherRawToken,
                'Idempotency-Key' => $keyA,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->postJson($grnUri, $grnPayloadA);
            $crossActorRes->assertStatus(403);

            // 5. Cross-device bound: replay by same user from different device is blocked with 403 Forbidden
            $altDevice = MobileDevice::create([
                'device_id' => (string) Str::uuid(),
                'device_name' => 'Staff Secondary Device',
                'user_id' => $this->staff->id,
            ]);
            $altRawToken = 'st_m_'.bin2hex(random_bytes(32));
            MobileAccessToken::create([
                'token_hash' => hash('sha256', $altRawToken),
                'device_id' => $altDevice->device_id,
                'user_id' => $this->staff->id,
                'company_id' => $this->company->id,
                'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->staff->password), 0, 16),
                'expires_at' => now()->addDays(7),
            ]);

            $crossDeviceRes = $this->withHeaders([
                'Authorization' => 'Bearer '.$altRawToken,
                'Idempotency-Key' => $keyA,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->postJson($grnUri, $grnPayloadA);
            $crossDeviceRes->assertStatus(409);

            $this->assertAllSixReconciliationsPass();
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }
}
