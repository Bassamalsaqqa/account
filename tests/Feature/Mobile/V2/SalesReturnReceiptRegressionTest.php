<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V2;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Http\Middleware\MobileV2ApiBoundary;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\SalesReconciliationService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\EnforcesDisposableDatabaseOwnership;
use Tests\Support\StrictBarrier;
use Tests\TestCase;

final class SalesReturnReceiptRegressionTest extends TestCase
{
    use EnforcesDisposableDatabaseOwnership;

    private User $owner;

    private User $staff;

    private Company $company;

    private MobileDevice $device;

    private MobileAccessToken $token;

    private string $rawToken;

    private Customer $customer;

    private Warehouse $warehouse;

    private Unit $baseUnit;

    private Product $product;

    private TaxRate $taxRate;

    private MoneyAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertOwnedDisposableDatabase();

        config([
            'mobile.enabled' => true,
            'mobile.merchant_api_enabled' => true,
            'mobile.v2_enabled' => true,
            'mobile.v2_cash_slice_enabled' => true,
        ]);

        if (! Route::has('mobile.v2.sales.online-cash')) {
            Route::prefix('api/mobile/v2')
                ->middleware(MobileV2ApiBoundary::class)
                ->group(base_path('routes/mobile-v2.php'));
        }

        $this->setupQualificationFixture();
    }

    protected function tearDown(): void
    {
        app(CompanyContext::class)->clear();
        auth()->forgetUser();
        parent::tearDown();
    }

    private function setupQualificationFixture(): void
    {
        app(CompanyContext::class)->clear();

        $this->owner = User::factory()->create([
            'email' => 'owner_'.Str::random(8).'@example.com',
            'password' => Hash::make('SecretPass123!'),
        ]);

        $this->staff = User::factory()->create([
            'email' => 'staff_'.Str::random(8).'@example.com',
            'password' => Hash::make('StaffPass123!'),
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة مؤهلة للنقد',
            'name_en' => 'Qualified Cash Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Seed roles & permissions
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
        // Grant staff required permissions for cash sale and read replays
        $this->staff->givePermissionTo([
            'sales.invoice.create',
            'sales.invoice.post',
            'sales.invoice.view',
            'money.receipt.create',
            'money.receipt.allocate',
            'money.receipt.view',
        ]);

        $deviceId = (string) Str::uuid();
        $this->device = MobileDevice::create([
            'device_id' => $deviceId,
            'user_id' => $this->staff->id,
            'device_name' => 'Cash Register Pixel',
            'platform' => 'android',
            'app_version' => '2.0.0',
            'os_version' => '14.0',
            'status' => 'active',
        ]);

        $this->rawToken = 'st_m_test_'.bin2hex(random_bytes(32));
        $this->token = MobileAccessToken::create([
            'token_hash' => hash('sha256', $this->rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->staff->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->staff->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل نقدي مؤهل',
            'name_en' => 'Qualified Cash Customer',
            'default_currency_code' => 'ILS',
            'preferred_locale' => 'ar',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        $this->baseUnit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'base_unit_id' => $this->baseUnit->id,
            'name_ar' => 'منتج نقدي تجريبي',
            'name_en' => 'Online Cash Test Product',
            'sku' => 'CASH-PROD-'.Str::random(4),
            'product_type' => 'stock',
            'track_stock' => true,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $this->baseUnit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
            'default_sale_price_base' => '50.000000',
        ]);

        $this->taxRate = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT16',
            'name_ar' => 'ضريبة القيمة المضافة 16%',
            'name_en' => 'VAT 16%',
            'rate' => '16.000000',
            'calculation' => 'exclusive',
            'active' => true,
        ]);

        // Post opening stock so product has lots available
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('100.000000'),
            unitCostBase: '20.000000',
            user: $this->owner,
            idempotencyKey: 'opening-'.Str::ulid(),
        );

        $this->cashAccount = MoneyAccount::where('company_id', $this->company->id)
            ->where('account_type', MoneyAccount::TYPE_CASH)
            ->where('currency_code', $this->company->base_currency_code)
            ->firstOrFail();

        // Forget ambient owner from guard so mobile requests rely solely on token
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();
    }

    /**
     * @return array<string, mixed>
     */
    private function validSalePayload(
        string $eventId,
        string $amount = '58.000000',
        string $unitPrice = '50.000000',
        string $settlementMode = 'full',
        ?string $cashAccountId = null
    ): array {
        $payment = [
            'settlement_mode' => $settlementMode,
            'amount' => $amount,
        ];
        if ($settlementMode === 'unpaid') {
            $payment['payment_method'] = null;
            $payment['cash_account_id'] = null;
        } else {
            $payment['payment_method'] = 'cash';
            $payment['cash_account_id'] = $cashAccountId ?? $this->cashAccount->public_id;
        }

        return [
            'client_event_id' => $eventId,
            'device_id' => $this->device->device_id,
            'customer_id' => $this->customer->public_id,
            'warehouse_id' => $this->warehouse->public_id,
            'currency' => 'ILS',
            'lines' => [
                [
                    'product_id' => $this->product->public_id,
                    'product_unit_id' => $this->baseUnit->public_id,
                    'conversion_to_base' => '1.000000',
                    'quantity' => '1.000000',
                    'unit_price' => $unitPrice,
                    'tax_rate_id' => $this->taxRate->public_id,
                    'tax_rate' => '16.000000',
                    'tax_inclusive' => false,
                ],
            ],
            'payment' => $payment,
        ];
    }

    public function test_http_return_preview_and_receipt_replay(): void
    {
        $payload = $this->returnPayload();
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";
        $preview = $this->postJson($path.'/preview', $payload);
        $preview->assertStatus(200);
        $this->assertSame('58.00', $preview->json('total_return_amount'));
        $first = $this->withHeader('Idempotency-Key', 'review-same-return')->postJson($path, $payload);
        $this->assertSame(201, $first->status(), $first->getContent());
        $this->assertSame('58.00', $first->json('total_amount'));
        $beforeReplay = $this->fingerprint();
        $second = $this->withHeader('Idempotency-Key', 'review-same-return')->postJson($path, $payload);
        $second->assertStatus(200);
        $this->assertSame($first->json(), $second->json());
        $this->assertSame($beforeReplay, $this->fingerprint());
        $payload['lines'][0]['quantity'] = '2.000000';
        $this->postJson($path, $payload)->assertStatus(409);

    }

    /** @return array<string, mixed> */
    private function returnPayload(): array
    {
        $salePayload = $this->validSalePayload((string) Str::uuid(), amount: '174.000000');
        $salePayload['lines'][0]['quantity'] = '3.000000';
        $sale = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $salePayload);
        $sale->assertStatus(201);

        return ['sales_invoice_id' => $sale->json('invoice_id'), 'lines' => [['product_id' => $this->product->public_id, 'quantity' => '1.000000']]];
    }

    public function test_invalid_and_duplicate_lines_fail_before_effects(): void
    {
        $payload = $this->returnPayload();
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";
        $invalid = [null, ['bad'], [['product_id' => (string) Str::ulid(), 'quantity' => '1']],
            [$payload['lines'][0], $payload['lines'][0]],
            [['product_id' => $this->product->public_id, 'sales_invoice_line_id' => (string) Str::ulid(), 'quantity' => '1']],
            [['product_id' => $this->product->public_id, 'quantity' => '0']],
        ];
        foreach ($invalid as $lines) {
            $this->postJson($path.'/preview', array_replace($payload, ['lines' => $lines]))->assertStatus(422);
            $this->withHeader('Idempotency-Key', 'invalid')->postJson($path, array_replace($payload, ['lines' => $lines]))->assertStatus(422);
        }
        $this->assertSame(0, DB::table('sales_returns')->where('company_id', $this->company->id)->count());
    }

    public function test_receipt_failure_rolls_back_all_economic_effects(): void
    {
        $payload = $this->returnPayload();
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";
        $before = $this->fingerprint();
        NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = true;
        try {
            $this->withHeader('Idempotency-Key', 'failure')->postJson($path, $payload)->assertStatus(422);
        } finally {
            NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = false;
        }
        $this->assertSame($before, $this->fingerprint());
        $this->assertAllSixReconciliationsPass();
    }

    /** @return array<string, string> */
    private function fingerprint(): array
    {
        $result = [];
        foreach (['sales_returns', 'sales_return_lines', 'stock_movements', 'posting_batches', 'posting_lines', 'native_command_receipts', 'document_sequences', 'audit_events'] as $table) {
            if (Schema::hasTable($table)) {
                $result[$table] = hash('sha256', json_encode(DB::table($table)->orderBy('id')->get()->all(), JSON_THROW_ON_ERROR));
            }
        }

        return $result;
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

    public function test_concurrent_sales_return_replays_original_receipt_and_commits_once(): void
    {
        $schema = DisposableMariaDbSchema::create();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->setupQualificationFixture();
            $payload = $this->returnPayload();
            $input = [
                'mode' => 'http',
                'http' => [
                    'uri' => "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns",
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->rawToken,
                        'Idempotency-Key' => 'concurrent-return',
                        'Content-Type' => 'application/json',
                        'Accept' => 'application/json',
                    ],
                    'body' => $payload,
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
            $this->assertSame(1, DB::table('sales_returns')->where('company_id', $this->company->id)->count());
            $this->assertSame(1, DB::table('native_command_receipts')->where('command_type', 'sales_return')->count());
            $this->assertSame(1, DB::table('stock_movements')->where('company_id', $this->company->id)->where('source_type', 'sales_return')->count());
            $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->where('source_type', 'sales_return')->count());
            $this->assertAllSixReconciliationsPass();
        } finally {
            if (isset($holder)) {
                $holder->stop();
            }
            if (isset($contender)) {
                $contender->stop();
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            $schema->drop();
        }
    }

    public function test_duplicate_product_requires_original_line_and_historical_discount_preview_matches_post(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $line = ['item_description' => 'Historical item', 'product_id' => (int) $this->product->id, 'product_unit_id' => (int) ProductUnit::where('product_id', $this->product->id)->firstOrFail()->id,
            'quantity' => '3', 'unit_price' => '50', 'tax_rate_id' => $this->taxRate->id];
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => now()->format('Y-m-d'),
            'lines' => [$line + ['discount_type' => 'percent', 'discount_value' => '10'], $line + ['discount_type' => 'fixed', 'discount_value' => '15']],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";
        $payload = ['sales_invoice_id' => $invoice->public_id, 'lines' => [['product_id' => $this->product->public_id, 'quantity' => '1']]];
        $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson($path.'/preview', $payload)->assertStatus(422);
        $this->withHeader('Idempotency-Key', 'ambiguous')->postJson($path, $payload)->assertStatus(422);
        $payload['lines'][0]['sales_invoice_line_id'] = $invoice->lines->first()->public_id;
        $payload['lines'][] = ['sales_invoice_line_id' => $invoice->lines->last()->public_id, 'product_id' => $this->product->public_id, 'quantity' => '1'];
        $preview = $this->postJson($path.'/preview', $payload)->assertStatus(200);
        $this->assertSame((string) $invoice->lines->first()->public_id, (string) $preview->json('lines.0.sales_invoice_line_id'));
        $this->assertSame((string) $invoice->lines->last()->public_id, (string) $preview->json('lines.1.sales_invoice_line_id'));
        $posted = $this->withHeader('Idempotency-Key', 'specific')->postJson($path, $payload)->assertStatus(201);
        $this->assertSame('104.40', $preview->json('total_return_amount'));
        $this->assertSame($preview->json('total_return_amount'), $posted->json('total_amount'));
        $this->assertAllSixReconciliationsPass();
    }

    public function test_movement_wire_preserves_six_decimal_quantity(): void
    {
        $this->returnPayload();
        app(CompanyContext::class)->setCompany($this->company, $this->staff);
        $movement = StockMovement::where('company_id', $this->company->id)->firstOrFail();
        // Projection-only fixture: do not alter economic history through a public runtime path.
        DB::table('stock_movements')->where('id', $movement->id)->update(['quantity_delta_base' => '0.000001', 'quantity_after_product_company' => '0.000001']);
        $response = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products/{$this->product->public_id}/movements")->assertStatus(200);
        $movements = $response->json('movements');
        $this->assertIsArray($movements);
        /** @var array<int, array<string, mixed>> $movements */
        $entry = collect($movements)->firstWhere('id', $movement->public_id);
        $this->assertIsArray($entry);
        $this->assertSame('0.000001', $entry['delta']);
        $this->assertSame('0.000001', $entry['resulting_stock']);
    }

    public function test_replay_rejects_other_device_and_actor(): void
    {
        $payload = $this->returnPayload();
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";
        $this->withHeader('Idempotency-Key', 'scoped')->postJson($path, $payload)->assertStatus(201);
        foreach ([$this->staff, $this->owner] as $actor) {
            $device = $this->device->replicate();
            $device->device_id = (string) Str::uuid();
            $device->user_id = $actor->id;
            $device->save();
            $raw = 'st_m_test_'.bin2hex(random_bytes(32));
            $token = $this->token->replicate();
            $token->token_hash = hash('sha256', $raw);
            $token->device_id = $device->device_id;
            $token->user_id = $actor->id;
            $token->password_epoch = 'epoch-'.substr(hash('sha256', (string) $actor->password), 0, 16);
            $token->save();
            $this->withHeader('Authorization', 'Bearer '.$raw)->postJson($path, $payload)->assertStatus(403);
        }
    }

    public function test_sales_return_receipt_status_lookup_lost_ack_and_security_boundaries(): void
    {
        $payload = $this->returnPayload();
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";
        $key = 'lost-ack-sales-return-'.(string) Str::uuid();

        // 1. Submit sales return and successfully post
        $createResp = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->rawToken,
            'Idempotency-Key' => $key,
        ])->postJson($path, $payload);
        $createResp->assertStatus(201);
        $returnId = (string) $createResp->json('id');
        $fingerprintAfterPost = $this->fingerprint();

        // 2. Lost ACK recovery: GET /receipts/{idempotencyKey}/status returns 200 with frozen response
        $statusUrl = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$key}/status";
        $statusResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->getJson($statusUrl);
        $statusResp->assertStatus(200);
        $statusResp->assertJsonPath('command_type', 'sales_return');
        $statusResp->assertJsonPath('status', 'completed');
        $statusResp->assertJsonPath('entity_public_id', $returnId);
        $statusResp->assertJsonPath('result.id', $returnId);
        $this->assertSame($createResp->json(), $statusResp->json('result'));

        // No economic repeat
        $this->assertSame($fingerprintAfterPost, $this->fingerprint());
        $this->assertSame(1, DB::table('sales_returns')->where('company_id', $this->company->id)->count());

        // 3. Foreign company context => 404 NOT_FOUND
        $foreignCompany = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة أخرى أجنبية',
            'name_en' => 'Foreign Company',
            'base_currency_code' => 'ILS',
        ]);
        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($foreignCompany);
        CompanyUser::create([
            'company_id' => $foreignCompany->id,
            'user_id' => $this->staff->id,
            'status' => 'active',
            'is_owner' => false,
        ]);
        setPermissionsTeamId($foreignCompany->id);
        $this->staff->assignRole('Sales');

        $foreignRawToken = 'st_m_foreign_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $foreignRawToken),
            'device_id' => $this->device->device_id,
            'user_id' => $this->staff->id,
            'company_id' => $foreignCompany->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->staff->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$foreignRawToken)
            ->getJson("/api/mobile/v1/companies/{$foreignCompany->public_id}/receipts/{$key}/status")
            ->assertStatus(404);

        // 4. Other actor or device context => 403 Forbidden
        $altDevice = $this->device->replicate();
        $altDevice->device_id = (string) Str::uuid();
        $altDevice->user_id = $this->staff->id;
        $altDevice->save();
        $altRawToken = 'st_m_test_'.bin2hex(random_bytes(32));
        $altToken = $this->token->replicate();
        $altToken->token_hash = hash('sha256', $altRawToken);
        $altToken->device_id = $altDevice->device_id;
        $altToken->user_id = $this->staff->id;
        $altToken->password_epoch = 'epoch-'.substr(hash('sha256', (string) $this->staff->password), 0, 16);
        $altToken->save();

        $this->withHeader('Authorization', 'Bearer '.$altRawToken)
            ->getJson($statusUrl)
            ->assertStatus(403);

        $ownerDevice = MobileDevice::create([
            'device_id' => (string) Str::uuid(),
            'user_id' => $this->owner->id,
            'device_name' => 'Owner Pixel',
            'platform' => 'android',
            'app_version' => '2.0.0',
            'os_version' => '14.0',
            'status' => 'active',
        ]);
        $ownerRawToken = 'st_m_test_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $ownerRawToken),
            'device_id' => $ownerDevice->device_id,
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->owner->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);
        $this->withHeader('Authorization', 'Bearer '.$ownerRawToken)
            ->getJson($statusUrl)
            ->assertStatus(403);

        // 5. Revoked permission => 403 Forbidden
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->staff->removeRole('Sales');
        $this->staff->revokePermissionTo('sales.return.view');
        $this->staff->unsetRelation('roles')->unsetRelation('permissions');

        $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->getJson($statusUrl)
            ->assertStatus(403);

        // Restore role for subsequent assertions and recon
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->staff->assignRole('Sales');
        $this->assertAllSixReconciliationsPass();
    }

    public function test_audit_failure_rolls_back_return(): void
    {
        $payload = $this->returnPayload();
        $before = $this->fingerprint();
        $dispatcher = AuditEvent::getEventDispatcher();
        $isolated = clone $dispatcher;
        AuditEvent::setEventDispatcher($isolated);
        AuditEvent::created(function (): void {
            throw new \RuntimeException('Injected audit persistence failure');
        });
        try {
            $resp = $this->withHeader('Idempotency-Key', 'audit-failure')->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", $payload);
            $resp->assertStatus(422);
            $this->assertStringContainsString('Injected audit persistence failure', (string) ($resp->json('error.message') ?? $resp->json('message')));
        } finally {
            AuditEvent::setEventDispatcher($dispatcher);
        }
        $this->assertSame(0, DB::table('sales_returns')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('sales_return_lines')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('stock_movements')->where('company_id', $this->company->id)->where('source_type', 'sales_return')->count());
        $this->assertSame(0, DB::table('posting_batches')->where('company_id', $this->company->id)->where('source_type', 'sales_return')->count());
        $this->assertSame($before, $this->fingerprint());
        $this->assertAllSixReconciliationsPass();
    }
}
