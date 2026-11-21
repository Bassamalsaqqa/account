<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V2;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Http\Middleware\MobileV2ApiBoundary;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\NativeCommandReceipt;
use App\Models\PostingBatch;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\PurchasePayableAsOf;
use App\Services\Sales\SalesHistoryAudit;
use App\Services\Sales\SalesReconciliationService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
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
            'sales.invoice.change_price',
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
        $this->restoreStaffPermissions();
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

    private function restoreStaffPermissions(): void
    {
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->staff->assignRole('Sales');
        $this->staff->givePermissionTo([
            'sales.invoice.create',
            'sales.invoice.post',
            'sales.invoice.view',
            'sales.invoice.change_price',
            'sales.return.create',
            'sales.return.post',
            'sales.return.view',
            'money.receipt.create',
            'money.receipt.allocate',
            'money.receipt.view',
            'money.vendor_payment.create',
            'purchasing.cost.view',
        ]);
        $this->staff->unsetRelation('roles')->unsetRelation('permissions');
    }

    public function test_three_step_fractional_return_negative_revenue_residual_balances_journal(): void
    {
        $this->restoreStaffPermissions();
        $this->baseUnit->update(['allows_fraction' => true, 'decimal_places' => 6]);

        // 1. Create a sale with 1 unit at 0.07 price + 16% tax (0.01) => 0.08 gross
        $salePayload = $this->validSalePayload(
            eventId: (string) Str::uuid(),
            amount: '0.080000',
            unitPrice: '0.070000',
            settlementMode: 'full'
        );
        $sale = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $salePayload);
        $this->assertSame(201, $sale->status(), $sale->getContent());
        $invoicePublicId = (string) $sale->json('invoice_id');

        $returnPath = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";

        // Step 1: Return 0.49 units
        // Total: 0.04, Tax: 0.00, Subtotal: 0.04
        $payload1 = [
            'sales_invoice_id' => $invoicePublicId,
            'lines' => [['product_id' => $this->product->public_id, 'quantity' => '0.490000']],
        ];
        $preview1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson($returnPath.'/preview', $payload1);
        $preview1->assertStatus(200);
        $this->assertSame('0.04', $preview1->json('total_return_amount'));

        $resp1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'step-return-1')
            ->postJson($returnPath, $payload1);
        $this->assertSame(201, $resp1->status(), 'Resp1 error: '.$resp1->getContent());
        $this->assertSame('0.04', $resp1->json('total_amount'));

        // Step 2: Return 0.02 units
        // Cumulative qty: 0.51 units
        // Cumulative total: 0.51 * 0.08 = 0.0408 => 0.04. Prior total: 0.04 => Step total: 0.00!
        // Cumulative tax: 0.51 * 0.01 = 0.0051 => 0.01. Prior tax: 0.00 => Step tax: 0.01!
        // Subtotal: 0.00 - 0.01 = -0.01 (negative revenue residual!)
        $payload2 = [
            'sales_invoice_id' => $invoicePublicId,
            'lines' => [['product_id' => $this->product->public_id, 'quantity' => '0.020000']],
        ];
        $preview2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson($returnPath.'/preview', $payload2);
        $preview2->assertStatus(200);
        $this->assertSame('0.00', $preview2->json('total_return_amount'));

        $resp2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'step-return-2')
            ->postJson($returnPath, $payload2);
        $this->assertSame(201, $resp2->status(), 'Resp2 error: '.$resp2->getContent());
        $this->assertSame('0.00', $resp2->json('total_amount'));

        // Verify Step 2 posting batch is balanced (Debits = Credits: 0.01 revenue residual + 0.40 inventory restoration)
        $latestBatch = PostingBatch::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('source_type', 'sales_return')
            ->latest('id')
            ->firstOrFail();

        $lines = DB::table('posting_lines')->where('company_id', $this->company->id)->where('posting_batch_id', $latestBatch->id)->get();
        $this->assertNotEmpty($lines);
        $totalDebits = BigDecimal::zero();
        $totalCredits = BigDecimal::zero();
        foreach ($lines as $line) {
            $totalDebits = $totalDebits->plus((string) $line->debit_base);
            $totalCredits = $totalCredits->plus((string) $line->credit_base);
        }
        $this->assertTrue($totalDebits->isEqualTo($totalCredits), "Debits [{$totalDebits}] must equal Credits [{$totalCredits}]");
        $this->assertTrue($totalDebits->isEqualTo('0.410000'), "Expected total debits 0.410000 (0.01 revenue/tax residual + 0.40 inventory restoration), got [{$totalDebits}]");

        // Step 3: Return remaining 0.49 units (completing 1.0 unit total return)
        $payload3 = [
            'sales_invoice_id' => $invoicePublicId,
            'lines' => [['product_id' => $this->product->public_id, 'quantity' => '0.490000']],
        ];
        $preview3 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson($returnPath.'/preview', $payload3);
        $preview3->assertStatus(200);
        $this->assertSame('0.04', $preview3->json('total_return_amount'));

        $resp3 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'step-return-3')
            ->postJson($returnPath, $payload3);
        $this->assertSame(201, $resp3->status(), 'Resp3 error: '.$resp3->getContent());
        $this->assertSame('0.04', $resp3->json('total_amount'));

        // Fourth attempt should fail because 1.000000 is fully returned
        $payload4 = [
            'sales_invoice_id' => $invoicePublicId,
            'lines' => [['product_id' => $this->product->public_id, 'quantity' => '0.010000']],
        ];
        $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson($returnPath.'/preview', $payload4)->assertStatus(422);

        $this->assertAllSixReconciliationsPass();
    }

    public function test_authoritative_jod_three_decimal_precision_in_sales_return(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        Currency::updateOrCreate(['code' => 'JOD'], ['name' => 'Jordanian Dinar', 'symbol' => 'JD', 'minor_units' => 3, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JOD'], ['enabled' => true, 'is_base' => false]);

        $jodCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل أردني',
            'name_en' => 'Jordanian Customer',
            'default_currency_code' => 'JOD',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $jodCustomer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'JOD',
            'exchange_rate' => '5.0000000000',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'JOD Item',
                    'quantity' => '1.000000',
                    'unit_price' => '1.234000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
        $invoicePublicId = $invoice->public_id;

        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();

        $payload = [
            'sales_invoice_id' => $invoicePublicId,
            'lines' => [['product_id' => $this->product->public_id, 'quantity' => '1.000000']],
        ];
        $preview = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns/preview", $payload);
        $preview->assertStatus(200);
        $this->assertSame(3, $preview->json('currency_minor_units'));
        $this->assertSame('1.234', $preview->json('total_return_amount'));
        $this->assertSame('1.234000', $preview->json('total_return_amount_exact'));

        $key = 'jod-return-precision-'.Str::uuid();
        $resp = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->rawToken,
            'Idempotency-Key' => $key,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", $payload);
        $resp->assertStatus(201);
        $this->assertSame(3, $resp->json('currency_minor_units'));
        $this->assertSame('1.234', $resp->json('total_amount'));
        $this->assertSame('1.234000', $resp->json('total_amount_exact'));
        $this->assertSame('JOD', $resp->json('currency'));

        // Frozen replay
        $replay = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->rawToken,
            'Idempotency-Key' => $key,
        ])->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", $payload);
        $replay->assertStatus(200);
        $replay->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($resp->json(), $replay->json());

        // Receipt status query verification
        $receiptCheck = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$key}/status");
        $receiptCheck->assertStatus(200);
        $this->assertSame($resp->json(), $receiptCheck->json('response_snapshot'));

        $this->assertAllSixReconciliationsPass();
    }

    public function test_idempotency_key_with_slashes_and_unicode_routing_and_lookup(): void
    {
        $payload = $this->returnPayload();
        $slashKey = 'pos/shift-1/return-99';

        $resp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $slashKey)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", $payload);
        $resp->assertStatus(201);

        // Path lookup with URL encoding (%2F)
        $encodedPath = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/".urlencode($slashKey).'/status';
        $getResp1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->getJson($encodedPath);
        $getResp1->assertStatus(200);
        $this->assertSame($slashKey, $getResp1->json('idempotency_key'));
        $this->assertSame('sales_return', $getResp1->json('command_type'));

        // Query parameter fallback lookup
        $queryPath = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/status?idempotency_key=".urlencode($slashKey);
        $getResp2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->getJson($queryPath);
        $getResp2->assertStatus(200);
        $this->assertSame($slashKey, $getResp2->json('idempotency_key'));

        // Unicode key lookup
        $unicodeKey = 'مفتاح-١/فاتورة-٢';
        $payload2 = $this->returnPayload();
        $respUnicode = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $unicodeKey)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", $payload2);
        $this->assertSame(201, $respUnicode->status(), $respUnicode->getContent());

        $queryUnicode = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/status?idempotency_key=".urlencode($unicodeKey);
        $getRespUnicode = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->getJson($queryUnicode);
        $getRespUnicode->assertStatus(200);
        $this->assertSame($unicodeKey, $getRespUnicode->json('idempotency_key'));
    }

    public function test_unrecorded_legacy_mobile_return_lost_ack_repost_is_blocked(): void
    {
        $payload = $this->returnPayload();
        $invoicePublicId = $payload['sales_invoice_id'];

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $invoice = SalesInvoice::where('public_id', $invoicePublicId)->firstOrFail();

        // Simulate old 8c1 committed return with no NativeCommandReceipt row
        DB::table('sales_returns')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'return_number' => 'RET-LEGACY-001',
            'status' => SalesReturn::STATUS_POSTED,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'subtotal_base' => '50.000000',
            'discount_total_base' => '0.000000',
            'tax_total_base' => '8.000000',
            'grand_total_base' => '58.000000',
            'cogs_total_base' => '0.000000',
            'subtotal_currency' => '50.000000',
            'discount_total_currency' => '0.000000',
            'tax_total_currency' => '8.000000',
            'grand_total_currency' => '58.000000',
            'posted_at' => now(),
            'posted_by' => $this->staff->id,
            'reason' => 'مرتجع مبيعات من تطبيق الجوال',
            'document_locale' => 'ar',
            'created_by' => $this->staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();

        // Attempting to post return for this invoice should fail closed with 409
        $resp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'retry-lost-ack')
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", $payload);

        $resp->assertStatus(409);
        $this->assertStringContainsString('already has an unrecorded mobile return', (string) ($resp->json('error.message') ?? $resp->json('message')));

        // Ensure no new sales return was added
        $this->assertSame(1, DB::table('sales_returns')->where('sales_invoice_id', $invoice->id)->count());
    }

    public function test_foreign_currency_payment_without_explicit_exchange_rate_fails_422_and_rolls_back(): void
    {
        setPermissionsTeamId($this->company->id);
        $this->staff->givePermissionTo(['money.receipt.create', 'money.vendor_payment.create', 'purchasing.cost.view']);

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        Currency::updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'USD'], ['enabled' => true, 'is_base' => false]);

        // 1. Customer payment without explicit exchange rate
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();
        $custResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'cust-usd-no-rate')
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", [
                'amount' => '100.00',
                'currency' => 'USD',
            ]);
        $custResp->assertStatus(422);
        $this->assertStringContainsString('Explicit exchange_rate is required', (string) ($custResp->json('error.message') ?? $custResp->json('message')));
        $this->assertSame(0, DB::table('customer_payments')->where('company_id', $this->company->id)->where('currency_code', 'USD')->count());

        // 2. Supplier payment without explicit exchange rate
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد أمريكي',
            'name_en' => 'US Vendor',
            'currency_code' => 'USD',
            'created_by' => $this->owner->id,
        ]);
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();
        $suppResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'supp-usd-no-rate')
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$vendor->public_id}/payments", [
                'amount' => '100.00',
                'currency' => 'USD',
            ]);
        $suppResp->assertStatus(422);
        $this->assertStringContainsString('Explicit exchange_rate is required', (string) ($suppResp->json('error.message') ?? $suppResp->json('message')));
        $this->assertSame(0, DB::table('vendor_payments')->where('company_id', $this->company->id)->where('currency_code', 'USD')->count());
    }

    public function test_genuine_canonically_posted_free_purchase_read_provenance_and_tamper_rejection(): void
    {
        $this->restoreStaffPermissions();
        $this->staff->givePermissionTo(['purchasing.purchase.view', 'purchasing.cost.view']);
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد العينات المجانية',
            'name_en' => 'Free Samples Vendor',
            'currency_code' => 'ILS',
            'created_by' => $this->owner->id,
        ]);

        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => now()->toDateString(),
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'Free sample line',
                    'quantity' => '10.000000',
                    'unit_cost' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $purchase = app(PostPurchaseAction::class)->execute($draft, $this->owner);
        $this->assertSame(Purchase::STATUS_POSTED, $purchase->status);
        $this->assertNull($purchase->posting_batch_id);

        // 1. Read provenance verification
        $asOf = app(PurchasePayableAsOf::class);
        $result = $asOf->components([$purchase], Carbon::now());
        $this->assertArrayHasKey((int) $purchase->id, $result);
        $this->assertTrue(BigDecimal::of($result[(int) $purchase->id]['outstanding'])->isZero());

        // 2. HTTP list test
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();
        $listResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases");
        $listResp->assertStatus(200);
        $row = collect((array) $listResp->json('purchases'))->firstWhere('id', $purchase->public_id);
        $this->assertNotNull($row);
        $this->assertSame('0.00', $row['total']);

        // 3. HTTP detail test
        $detailResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchase->public_id}");
        $detailResp->assertStatus(200);
        $this->assertSame('0.00', $detailResp->json('total_ils'));
        $this->assertNotEmpty($detailResp->json('lines'));

        // 4. Tamper test: direct tampering of economic amounts breaks read provenance
        DB::table('purchases')->where('id', $purchase->id)->update(['tax_total_base' => '5.000000']);
        $tampered = Purchase::withoutGlobalScopes()->findOrFail($purchase->id);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->expectException(\InvalidArgumentException::class);
        $asOf->components([$tampered], Carbon::now());
    }

    public function test_foreign_currency_payment_missing_exchange_rate_replay_recovers_receipt(): void
    {
        $this->restoreStaffPermissions();
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        Currency::updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'USD'], ['enabled' => true, 'is_base' => false]);

        $custKey = 'cust-usd-replay-'.Str::random(6);
        $custPayload = [
            'actor_id' => (int) $this->staff->id,
            'device_id' => (string) $this->device->device_id,
            'customer_id' => (string) $this->customer->public_id,
            'cash_account_id' => null,
            'amount' => '100.000000',
            'currency' => 'USD',
            'payment_date' => now()->toDateString(),
            'reference' => null,
            'notes' => null,
            'allocations' => [],
            'exchange_rate' => '1.0000000000',
        ];
        $custHash = hash('sha256', json_encode($custPayload, JSON_THROW_ON_ERROR));
        $custSnapshot = [
            'id' => (string) Str::ulid(),
            'receipt_id' => (string) Str::ulid(),
            'payment_number' => 'RCT-USD-001',
            'amount' => '100.00',
            'currency' => 'USD',
            'success' => true,
        ];
        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $custKey,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => $custHash,
            'payload' => $custPayload,
            'response_snapshot' => $custSnapshot,
            'status' => 'completed',
        ]);

        // Replay of existing receipt without exchange_rate recovers receipt!
        $replayResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $custKey)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", [
                'amount' => '100.00',
                'currency' => 'USD',
            ]);
        $replayResp->assertStatus(200);
        $this->assertSame('true', $replayResp->headers->get('X-Idempotent-Replay'));
        $this->assertSame($custSnapshot, $replayResp->json());

        // Supplier side: replay without exchange_rate also recovers receipt!
        $suppKey = 'supp-usd-replay-'.Str::random(6);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $vendor = Vendor::firstOrCreate(
            ['company_id' => $this->company->id, 'name_ar' => 'مورد دفعات أمريكي'],
            ['currency_code' => 'USD', 'created_by' => $this->owner->id]
        );
        $suppPayload = [
            'actor_id' => (int) $this->staff->id,
            'device_id' => (string) $this->device->device_id,
            'supplier_id' => (string) $vendor->public_id,
            'cash_account_id' => null,
            'amount' => '250.000000',
            'currency' => 'USD',
            'payment_date' => now()->toDateString(),
            'reference' => null,
            'notes' => null,
            'allocations' => [],
            'exchange_rate' => '1.0000000000',
        ];
        $suppHash = hash('sha256', json_encode($suppPayload, JSON_THROW_ON_ERROR));
        $suppSnapshot = [
            'id' => (string) Str::ulid(),
            'payment_number' => 'VPT-USD-001',
            'amount' => '250.00',
            'currency' => 'USD',
            'success' => true,
        ];
        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'supplier_payment',
            'idempotency_key' => $suppKey,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => $suppHash,
            'payload' => $suppPayload,
            'response_snapshot' => $suppSnapshot,
            'status' => 'completed',
        ]);

        $suppReplayResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $suppKey)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$vendor->public_id}/payments", [
                'amount' => '250.00',
                'currency' => 'USD',
            ]);
        $suppReplayResp->assertStatus(200);
        $this->assertSame('true', $suppReplayResp->headers->get('X-Idempotent-Replay'));
        $this->assertSame($suppSnapshot, $suppReplayResp->json());

        // Fresh request with new key without exchange_rate is rejected with 422
        $freshResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'fresh-no-rate-'.Str::random(6))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", [
                'amount' => '100.00',
                'currency' => 'USD',
            ]);
        $freshResp->assertStatus(422);

        // Explicit FX 1 vs 2 must conflict on foreign payment
        $conflictResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $custKey)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", [
                'amount' => '100.00',
                'currency' => 'USD',
                'exchange_rate' => '2.0000000000',
            ]);
        $conflictResp->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');

        // Missing payment_date retry across midnight must recover frozen business date from existing receipt
        $midnightKey = 'midnight-retry-'.Str::random(6);
        $frozenDate = '2026-11-20';
        $midnightPayload = [
            'actor_id' => (int) $this->staff->id,
            'device_id' => (string) $this->device->device_id,
            'customer_id' => (string) $this->customer->public_id,
            'cash_account_id' => null,
            'amount' => '75.000000',
            'currency' => 'ILS',
            'payment_date' => $frozenDate,
            'reference' => null,
            'notes' => null,
            'allocations' => [],
        ];
        $midnightSnapshot = [
            'id' => (string) Str::ulid(),
            'amount' => '75.00',
            'payment_date' => $frozenDate,
            'success' => true,
        ];
        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $midnightKey,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => hash('sha256', json_encode($midnightPayload, JSON_THROW_ON_ERROR)),
            'payload' => $midnightPayload,
            'response_snapshot' => $midnightSnapshot,
            'status' => 'completed',
        ]);

        // Retrying without payment_date on a different day recovers frozen business date
        $midnightRetry = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $midnightKey)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", [
                'amount' => '75.00',
                'currency' => 'ILS',
            ]);
        $midnightRetry->assertStatus(200);
        $this->assertSame('true', $midnightRetry->headers->get('X-Idempotent-Replay'));
        $this->assertSame($midnightSnapshot, $midnightRetry->json());
    }

    public function test_base_currency_payment_replay_compatibility_both_directions(): void
    {
        $this->restoreStaffPermissions();
        $paymentDate = now()->toDateString();

        // Direction 1: stored WITHOUT exchange_rate, replayed WITH exchange_rate: '1'
        $key1 = 'base-dir1-'.Str::random(6);
        $payload1 = [
            'actor_id' => (int) $this->staff->id,
            'device_id' => (string) $this->device->device_id,
            'customer_id' => (string) $this->customer->public_id,
            'cash_account_id' => null,
            'amount' => '50.000000',
            'currency' => 'ILS',
            'payment_date' => $paymentDate,
            'reference' => null,
            'notes' => null,
            'allocations' => [],
        ];
        $snapshot1 = ['id' => (string) Str::ulid(), 'amount' => '50.00', 'currency' => 'ILS', 'success' => true];
        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $key1,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => hash('sha256', json_encode($payload1, JSON_THROW_ON_ERROR)),
            'payload' => $payload1,
            'response_snapshot' => $snapshot1,
            'status' => 'completed',
        ]);

        $r1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $key1)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", [
                'amount' => '50.00',
                'currency' => 'ILS',
                'exchange_rate' => '1',
            ]);
        $r1->assertStatus(200);
        $this->assertSame('true', $r1->headers->get('X-Idempotent-Replay'));
        $this->assertSame($snapshot1, $r1->json());

        // Direction 2: stored WITH exchange_rate: '1.0000000000', replayed WITHOUT exchange_rate
        $key2 = 'base-dir2-'.Str::random(6);
        $payload2 = $payload1;
        $payload2['exchange_rate'] = '1.0000000000';
        $snapshot2 = ['id' => (string) Str::ulid(), 'amount' => '50.00', 'currency' => 'ILS', 'success' => true];
        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $key2,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => hash('sha256', json_encode($payload2, JSON_THROW_ON_ERROR)),
            'payload' => $payload2,
            'response_snapshot' => $snapshot2,
            'status' => 'completed',
        ]);

        $r2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $key2)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", [
                'amount' => '50.00',
                'currency' => 'ILS',
            ]);
        $r2->assertStatus(200);
        $this->assertSame('true', $r2->headers->get('X-Idempotent-Replay'));
        $this->assertSame($snapshot2, $r2->json());
    }

    public function test_sales_invoice_date_cutoff_and_returned_exact_exposure(): void
    {
        $this->restoreStaffPermissions();

        // 1. Create a sale
        $salePayload = $this->validSalePayload(
            eventId: (string) Str::uuid(),
            amount: '174.000000',
            unitPrice: '50.000000'
        );
        $salePayload['lines'][0]['quantity'] = '3.000000';
        $sale = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $salePayload);
        $this->assertSame(201, $sale->status(), $sale->getContent());
        $invoicePublicId = (string) $sale->json('invoice_id');

        // Post a partial return of 1 unit (58.00)
        $returnResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'return-for-detail-test')
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", [
                'sales_invoice_id' => $invoicePublicId,
                'lines' => [['product_id' => $this->product->public_id, 'quantity' => '1.000000']],
            ]);
        $returnResp->assertStatus(201);

        // 2. Check detail view for returned and returned_exact
        $detail = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoicePublicId}");
        $detail->assertStatus(200);
        $this->assertSame('58.00', $detail->json('returned'));
        $this->assertSame('58.000000', $detail->json('returned_exact'));
        $this->assertSame('58.00', $detail->json('thermal_receipt.returned_amount'));
        $this->assertSame('58.000000', $detail->json('thermal_receipt.returned_exact'));

        // 3. Check list view
        $list = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices");
        $list->assertStatus(200);
        $row = collect((array) $list->json('invoices'))->firstWhere('id', $invoicePublicId);
        $this->assertNotNull($row);
        $this->assertSame('58.00', $row['returned']);
        $this->assertSame('58.000000', $row['returned_exact']);
    }

    public function test_negative_tax_residual_canonical_journal_balanced(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->baseUnit->update(['allows_fraction' => true, 'decimal_places' => 6]);

        Currency::updateOrCreate(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JPY'], ['enabled' => true, 'is_base' => false]);

        $tax15 = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT15',
            'name_ar' => 'ضريبة 15%',
            'name_en' => 'VAT 15%',
            'rate' => '15.000000',
            'calculation' => 'exclusive',
            'active' => true,
        ]);

        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        // Create genuine sales invoice in JPY: 1 qty at 4.00 unit price + 15% tax (0.60) = 4.60 gross
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'JPY',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'JPY Item',
                    'quantity' => '1.000000',
                    'unit_price' => '4.000000',
                    'tax_rate_id' => $tax15->id,
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);

        // Step 1: Return 0.99 qty
        // In JPY (minor units 0): subtotal = 4, tax = 1, gross = 5
        $draft1 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => now()->format('Y-m-d'),
            'reason' => 'First return 0.99',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoice->lines->first()->id,
                    'quantity' => '0.990000',
                ],
            ],
        ]);
        $return1 = app(PostSalesReturnAction::class)->execute($draft1, $this->owner);
        $this->assertSame('5.000000', (string) $return1->grand_total_currency);
        $this->assertSame('1.000000', (string) $return1->tax_total_currency);
        $this->assertSame('4.000000', (string) $return1->subtotal_currency);

        // Step 2: Final 0.01 qty has negative tax residual:
        // Invoice had gross 4.60, tax 0.60. Return 1 took 5.00 gross and 1.00 tax.
        // Residual remaining: 4.60 - 5.00 = -0.40 gross, 0.60 - 1.00 = -0.40 tax, subtotal = 0.00!
        $invLine = $invoice->lines->first();

        // Native preview -> commit path for genuine partial JPY fixture
        $previewResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns/preview", [
                'sales_invoice_id' => $invoice->public_id,
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine->public_id,
                        'quantity' => '0.010000',
                    ],
                ],
            ]);
        $previewResp->assertStatus(200);

        $return2Key = 'ret-residual-2-'.(string) Str::uuid();
        $return2Resp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $return2Key)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", [
                'sales_invoice_id' => $invoice->public_id,
                'reason' => 'Final return 0.01 negative tax residual',
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine->public_id,
                        'quantity' => '0.010000',
                    ],
                ],
            ]);
        $return2Resp->assertStatus(201);
        $return2PublicId = $return2Resp->json('id');
        $return2 = SalesReturn::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $return2PublicId)->firstOrFail();

        $batch = PostingBatch::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('source_type', 'sales_return')
            ->where('source_id', $return2->id)
            ->firstOrFail();

        $batchLines = DB::table('posting_lines')->where('posting_batch_id', $batch->id)->get();
        $totalDr = BigDecimal::zero();
        $totalCr = BigDecimal::zero();
        foreach ($batchLines as $bl) {
            $totalDr = $totalDr->plus((string) $bl->debit_base);
            $totalCr = $totalCr->plus((string) $bl->credit_base);
        }
        $this->assertTrue($totalDr->isEqualTo($totalCr), "Debits [{$totalDr}] must equal Credits [{$totalCr}]");
        $this->assertTrue($totalDr->isGreaterThan(0));

        // Verify Dr AR exists with original currency components and Cr Sales Tax Reversal Adjustment exists
        $arLine = $batchLines->firstWhere('description', "Sales Return {$return2->return_number} - {$this->customer->displayName()}");
        $this->assertNotNull($arLine);
        $this->assertSame('0.400000', (string) $arLine->debit_base);
        $this->assertSame('JPY', $arLine->transaction_currency_code);
        $this->assertSame('0.400000', (string) $arLine->transaction_amount);

        $taxLine = $batchLines->firstWhere('description', "Sales Return {$return2->return_number} Tax Reversal Adjustment");
        $this->assertNotNull($taxLine);
        $this->assertSame('0.400000', (string) $taxLine->credit_base);

        // Assert exact preview amounts match posted document totals
        $this->assertSame($previewResp->json('total_return_amount'), $return2Resp->json('total_amount'));
        $this->assertSame($previewResp->json('total_return_amount_exact'), $return2Resp->json('total_amount_exact'));

        $audit = app(SalesHistoryAudit::class);
        $this->assertEmpty($audit->audit((int) $this->company->id));

        $this->assertAllSixReconciliationsPass();
    }

    public function test_genuine_jpy_two_lines_same_tax_account_net_zero_group_success(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->baseUnit->update(['allows_fraction' => true, 'decimal_places' => 6]);

        Currency::updateOrCreate(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JPY'], ['enabled' => true, 'is_base' => false]);

        $tax15 = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT15-NETZERO',
            'name_ar' => 'ضريبة 15%',
            'name_en' => 'VAT 15%',
            'rate' => '15.000000',
            'calculation' => 'exclusive',
            'active' => true,
        ]);

        $tax10 = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT10-NETZERO',
            'name_ar' => 'ضريبة 10%',
            'name_en' => 'VAT 10%',
            'rate' => '10.000000',
            'calculation' => 'exclusive',
            'active' => true,
        ]);

        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        // Create genuine sales invoice in JPY with 2 lines sharing the same default tax output account:
        // Line 1: 1 qty at 4.00 unit price + 15% tax (0.60) = 4.60 gross
        // Line 2: 1 qty at 4.00 unit price + 10% tax (0.40) = 4.40 gross
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'JPY',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'JPY Line 1',
                    'quantity' => '1.000000',
                    'unit_price' => '4.000000',
                    'tax_rate_id' => $tax15->id,
                ],
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'JPY Line 2',
                    'quantity' => '1.000000',
                    'unit_price' => '4.000000',
                    'tax_rate_id' => $tax10->id,
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);

        // First return: 0.99 qty on Line 1 only.
        // In JPY (minor units 0): subtotal = 4, tax = 1, gross = 5
        $invLine1 = $invoice->lines->first();
        $invLine2 = $invoice->lines->last();

        $draft1 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => now()->format('Y-m-d'),
            'reason' => 'First return 0.99 of line 1',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invLine1->id,
                    'quantity' => '0.990000',
                ],
            ],
        ]);
        $return1 = app(PostSalesReturnAction::class)->execute($draft1, $this->owner);
        $this->assertSame('5.000000', (string) $return1->grand_total_currency);
        $this->assertSame('1.000000', (string) $return1->tax_total_currency);
        $this->assertSame('4.000000', (string) $return1->subtotal_currency);

        // Next remainder return: Line 1 remaining 0.01 (tax = -0.40) + Line 2 full 1.00 (tax = +0.40).
        // Both lines share the same tax account, so tax group net in transaction currency is exactly 0!
        // Canonical preview:
        $previewResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns/preview", [
                'sales_invoice_id' => $invoice->public_id,
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine1->public_id,
                        'quantity' => '0.010000',
                    ],
                    [
                        'sales_invoice_line_id' => $invLine2->public_id,
                        'quantity' => '1.000000',
                    ],
                ],
            ]);
        $previewResp->assertStatus(200);

        // Post return via mobile canonical endpoint:
        $return2Key = 'ret-netzero-'.(string) Str::uuid();
        $return2Resp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', $return2Key)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", [
                'sales_invoice_id' => $invoice->public_id,
                'reason' => 'Remainder return tax group net zero',
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine1->public_id,
                        'quantity' => '0.010000',
                    ],
                    [
                        'sales_invoice_line_id' => $invLine2->public_id,
                        'quantity' => '1.000000',
                    ],
                ],
            ]);
        $return2Resp->assertStatus(201);
        $return2PublicId = $return2Resp->json('id');
        $return2 = SalesReturn::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $return2PublicId)->firstOrFail();

        $batch = PostingBatch::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('source_type', 'sales_return')
            ->where('source_id', $return2->id)
            ->firstOrFail();

        $batchLines = DB::table('posting_lines')->where('posting_batch_id', $batch->id)->get();

        // Must not construct zero journal DTO (no line with zero amount/base)
        foreach ($batchLines as $bl) {
            $dr = BigDecimal::of((string) $bl->debit_base);
            $cr = BigDecimal::of((string) $bl->credit_base);
            $this->assertFalse($dr->isZero() && $cr->isZero(), 'Journal line must not have both debit and credit zero');
            if ($bl->transaction_currency_code !== null) {
                $this->assertNotNull($bl->transaction_amount);
                $this->assertTrue(BigDecimal::of((string) $bl->transaction_amount)->isPositive(), 'Transaction amount must be strictly positive');
            }
        }

        $totalDr = BigDecimal::zero();
        $totalCr = BigDecimal::zero();
        foreach ($batchLines as $bl) {
            $totalDr = $totalDr->plus((string) $bl->debit_base);
            $totalCr = $totalCr->plus((string) $bl->credit_base);
        }
        $this->assertTrue($totalDr->isEqualTo($totalCr), "Debits [{$totalDr}] must equal Credits [{$totalCr}]");
        $this->assertTrue($totalDr->isGreaterThan(0));

        // Verify signed tax legs:
        // Debit tax line (+0.40 JPY from line 2)
        $taxDrLine = $batchLines->first(fn ($l) => str_contains((string) $l->description, 'Tax Reversal') && BigDecimal::of((string) $l->debit_base)->isPositive());
        $this->assertNotNull($taxDrLine);
        $this->assertSame('0.400000', (string) $taxDrLine->debit_base);
        $this->assertSame('JPY', $taxDrLine->transaction_currency_code);
        $this->assertSame('0.400000', (string) $taxDrLine->transaction_amount);

        // Credit tax line (-0.40 JPY from line 1 residual adjustment)
        $taxCrLine = $batchLines->first(fn ($l) => str_contains((string) $l->description, 'Tax Reversal Adjustment') && BigDecimal::of((string) $l->credit_base)->isPositive());
        $this->assertNotNull($taxCrLine);
        $this->assertSame('0.400000', (string) $taxCrLine->credit_base);
        $this->assertSame('JPY', $taxCrLine->transaction_currency_code);
        $this->assertSame('0.400000', (string) $taxCrLine->transaction_amount);

        // Assert exact preview amounts match posted document totals
        $this->assertSame($previewResp->json('total_return_amount'), $return2Resp->json('total_amount'));
        $this->assertSame($previewResp->json('total_return_amount_exact'), $return2Resp->json('total_amount_exact'));

        // History audit passes with independent signed currency component verification
        $audit = app(SalesHistoryAudit::class);
        $this->assertEmpty($audit->audit((int) $this->company->id));

        $this->assertAllSixReconciliationsPass();
    }

    public function test_preview_return_history_shared_snapshot_interleaving_actor_denial(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // 1. Create an invoice with 2 units
        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'Test Item',
                    'quantity' => '2.000000',
                    'unit_price' => '50.000000',
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
        $invLine = $invoice->lines->first();

        // 2. Staff user has sales.return.create and sales.invoice.view, but strictly lacks sales.return.view
        $this->staff->removeRole('Sales');
        $this->staff->syncPermissions(['sales.return.create', 'sales.invoice.view']);

        // Fresh preview with NO prior returns: allowed without sales.return.view
        $previewResp1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns/preview", [
                'sales_invoice_id' => $invoice->public_id,
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine->public_id,
                        'quantity' => '1.000000',
                    ],
                ],
            ]);
        $previewResp1->assertStatus(200);

        // 3. Interleaving actor (owner) commits a partial return under company/owner context
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => now()->format('Y-m-d'),
            'reason' => 'Interleaving owner return',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invLine->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        app(PostSalesReturnAction::class)->execute($draft, $this->owner);

        // 4. Staff attempts preview again: prior return history exists!
        // Because staff lacks sales.return.view, preview is denied under the exact same snapshot
        $previewResp2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns/preview", [
                'sales_invoice_id' => $invoice->public_id,
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine->public_id,
                        'quantity' => '1.000000',
                    ],
                ],
            ]);
        $previewResp2->assertStatus(403);
        $this->assertStringContainsString('Missing required sales.return.view permission', (string) $previewResp2->json('error.message'));
    }

    public function test_four_positive_tax_groups_signed_tax_direction_preserved(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->baseUnit->update(['allows_fraction' => true, 'decimal_places' => 6]);

        Currency::updateOrCreate(['code' => 'JOD'], ['name' => 'Jordanian Dinar', 'symbol' => 'JD', 'minor_units' => 3, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JOD'], ['enabled' => true, 'is_base' => false]);

        $t1 = TaxRate::create(['company_id' => $this->company->id, 'code' => 'TX1', 'rate' => '1.000000', 'name_ar' => 'ض1', 'active' => true]);
        $t2 = TaxRate::create(['company_id' => $this->company->id, 'code' => 'TX2', 'rate' => '2.000000', 'name_ar' => 'ض2', 'active' => true]);
        $t3 = TaxRate::create(['company_id' => $this->company->id, 'code' => 'TX3', 'rate' => '3.000000', 'name_ar' => 'ض3', 'active' => true]);
        $t4 = TaxRate::create(['company_id' => $this->company->id, 'code' => 'TX4', 'rate' => '4.000000', 'name_ar' => 'ض4', 'active' => true]);

        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'JOD',
            'exchange_rate' => '0.0000500000',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                ['product_id' => $this->product->id, 'product_unit_id' => $productUnit->id, 'item_description' => 'L1', 'quantity' => '1.000000', 'unit_price' => '1.000000', 'tax_rate_id' => $t1->id],
                ['product_id' => $this->product->id, 'product_unit_id' => $productUnit->id, 'item_description' => 'L2', 'quantity' => '1.000000', 'unit_price' => '0.500000', 'tax_rate_id' => $t2->id],
                ['product_id' => $this->product->id, 'product_unit_id' => $productUnit->id, 'item_description' => 'L3', 'quantity' => '1.000000', 'unit_price' => '0.333333', 'tax_rate_id' => $t3->id],
                ['product_id' => $this->product->id, 'product_unit_id' => $productUnit->id, 'item_description' => 'L4', 'quantity' => '1.000000', 'unit_price' => '0.250000', 'tax_rate_id' => $t4->id],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);

        // Create return with lines where tax in each group is positive
        $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => now()->format('Y-m-d'),
            'reason' => 'Four tax groups reversal',
            'lines' => $invoice->lines->map(fn ($l) => ['sales_invoice_line_id' => $l->id, 'quantity' => '1.000000'])->all(),
        ]);
        $postedReturn = app(PostSalesReturnAction::class)->execute($draft, $this->owner);

        $batch = PostingBatch::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('source_type', 'sales_return')
            ->where('source_id', $postedReturn->id)
            ->firstOrFail();

        $lines = DB::table('posting_lines')->where('posting_batch_id', $batch->id)->get();
        // Invariant: all foreign-currency tax reversal lines must be DEBITS, never credited foreign currency!
        $foreignTaxLines = $lines->filter(fn ($l) => str_contains((string) $l->description, 'Tax Reversal') && ! str_contains((string) $l->description, 'Adjustment'));
        foreach ($foreignTaxLines as $ftl) {
            $this->assertSame('JOD', $ftl->transaction_currency_code);
            $this->assertTrue(BigDecimal::of((string) $ftl->debit_base)->isPositive(), 'Tax reversal must debit tax in base currency');
            $this->assertTrue(BigDecimal::of((string) $ftl->credit_base)->isZero(), 'Foreign tax reversal must NOT credit base');
        }
        $this->assertAllSixReconciliationsPass();
    }

    public function test_sales_return_create_post_without_return_view_permission_succeeds_and_replays(): void
    {
        $this->restoreStaffPermissions();
        $payload = $this->returnPayload();
        $path = "/api/mobile/v1/companies/{$this->company->public_id}/sales/returns";
        $key = 'no-view-perm-return-'.Str::uuid();

        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->staff->roles()->detach();
        $this->staff->syncPermissions([
            'sales.invoice.view',
            'sales.return.create',
            'sales.return.post',
        ]);
        $this->staff->unsetRelation('roles')->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Preview permitted with sales.return.create
        $preview = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson($path.'/preview', $payload);
        $preview->assertStatus(200);

        // Store succeeds
        $resp1 = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->rawToken,
            'Idempotency-Key' => $key,
        ])->postJson($path, $payload);
        $resp1->assertStatus(201);

        // Preview on an invoice with prior posted returns is now denied 403 for an actor without sales.return.view
        $previewDenied = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)->postJson($path.'/preview', $payload);
        $previewDenied->assertStatus(403);
        $this->assertStringContainsString('sales.return.view', (string) ($previewDenied->json('error.message') ?? $previewDenied->json('message')));

        // Retry returns 200 replay (no 403 paradox!)
        $resp2 = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->rawToken,
            'Idempotency-Key' => $key,
        ])->postJson($path, $payload);
        $resp2->assertStatus(200);
        $this->assertSame('true', $resp2->headers->get('X-Idempotent-Replay'));
        $this->assertSame($resp1->json(), $resp2->json());

        // Restore permissions
        $this->restoreStaffPermissions();
    }

    public function test_voided_legacy_unrecorded_return_blocks_retry(): void
    {
        $payload = $this->returnPayload();
        $invoicePublicId = $payload['sales_invoice_id'];

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $invoice = SalesInvoice::where('public_id', $invoicePublicId)->firstOrFail();

        // Unrecorded legacy return with status VOID
        DB::table('sales_returns')->insert([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'sales_invoice_id' => $invoice->id,
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'return_number' => 'RET-LEGACY-VOID',
            'status' => SalesReturn::STATUS_VOID,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'subtotal_base' => '50.000000',
            'discount_total_base' => '0.000000',
            'tax_total_base' => '8.000000',
            'grand_total_base' => '58.000000',
            'cogs_total_base' => '0.000000',
            'subtotal_currency' => '50.000000',
            'discount_total_currency' => '0.000000',
            'tax_total_currency' => '8.000000',
            'grand_total_currency' => '58.000000',
            'reason' => 'مرتجع مبيعات من تطبيق الجوال',
            'document_locale' => 'ar',
            'created_by' => $this->staff->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();

        // Attempting to post return must be blocked with 409
        $resp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'retry-voided-lost-ack')
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", $payload);

        $resp->assertStatus(409);
        $this->assertStringContainsString('already has an unrecorded mobile return', (string) ($resp->json('error.message') ?? $resp->json('message')));
        $this->assertStringNotContainsString('RET-LEGACY-VOID', (string) ($resp->json('error.message') ?? $resp->json('message')), 'Prior return ID must not be disclosed in unrecorded legacy conflict message');
    }

    public function test_migration_down_refuses_non_ascii_unicode_downgrade(): void
    {
        // Seed non-ASCII Unicode receipt
        $key = 'مفتاح-يونيكود-'.Str::random(4);
        $receipt = NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $key,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => hash('sha256', 'unicode-test'),
            'payload' => [],
            'response_snapshot' => [],
            'status' => 'completed',
        ]);

        $migration = require database_path('migrations/2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot downgrade native_command_receipts idempotency_key collation');

        try {
            $migration->down();
        } finally {
            DB::table('native_command_receipts')->where('id', $receipt->id)->delete();
        }
    }

    public function test_migration_guarded_ascii_empty_roundtrip(): void
    {
        $existing = DB::table('native_command_receipts')->get();
        DB::table('native_command_receipts')->delete();

        $migration = require database_path('migrations/2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php');

        try {
            // On empty table, down() then up() must succeed cleanly and alter actual column collation
            $migration->down();
            $downCollation = DB::selectOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'native_command_receipts' AND COLUMN_NAME = 'idempotency_key'")->COLLATION_NAME;
            $this->assertSame('ascii_bin', $downCollation);

            $migration->up();
            $upCollation = DB::selectOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'native_command_receipts' AND COLUMN_NAME = 'idempotency_key'")->COLLATION_NAME;
            $this->assertSame('utf8mb4_bin', $upCollation);
        } finally {
            foreach ($existing as $row) {
                DB::table('native_command_receipts')->insert((array) $row);
            }
        }
    }

    public function test_migration_guarded_ascii_populated_roundtrip(): void
    {
        $existing = DB::table('native_command_receipts')->get();
        DB::table('native_command_receipts')->delete();

        $asciiKey = 'ascii-key-'.Str::random(16);
        $receipt = NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $asciiKey,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => hash('sha256', 'ascii-test'),
            'payload' => ['k' => 'v'],
            'response_snapshot' => ['status' => 'ok'],
            'status' => 'completed',
        ]);

        $migration = require database_path('migrations/2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php');

        try {
            $migration->down();
            $downCollation = DB::selectOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'native_command_receipts' AND COLUMN_NAME = 'idempotency_key'")->COLLATION_NAME;
            $this->assertSame('ascii_bin', $downCollation);
            $this->assertDatabaseHas('native_command_receipts', ['id' => $receipt->id, 'idempotency_key' => $asciiKey]);

            $migration->up();
            $upCollation = DB::selectOne("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'native_command_receipts' AND COLUMN_NAME = 'idempotency_key'")->COLLATION_NAME;
            $this->assertSame('utf8mb4_bin', $upCollation);
            $this->assertDatabaseHas('native_command_receipts', ['id' => $receipt->id, 'idempotency_key' => $asciiKey]);
        } finally {
            DB::table('native_command_receipts')->where('id', $receipt->id)->delete();
            foreach ($existing as $row) {
                DB::table('native_command_receipts')->insert((array) $row);
            }
        }
    }

    public function test_migration_source_rollback_forward_schema_rehearsal(): void
    {
        $unicodeKey = 'مفتاح-يونيكود-'.Str::random(6);
        $receipt = NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $unicodeKey,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => hash('sha256', 'unicode-forward-schema'),
            'payload' => ['arabic' => 'تجربة'],
            'response_snapshot' => ['status' => 'ok'],
            'status' => 'completed',
        ]);

        $migration = require database_path('migrations/2026_11_21_100000_update_native_command_receipts_idempotency_key_collation.php');

        // Attempting to downgrade schema fails
        try {
            $migration->down();
            $this->fail('Collation downgrade must throw RuntimeException when Unicode keys exist');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cannot downgrade native_command_receipts idempotency_key collation', $e->getMessage());
            $this->assertStringContainsString('Under the forward-schema rollback policy', $e->getMessage());
        }

        // Under forward-schema rollback policy, the application source code rolls back
        // while retaining forward schema (utf8mb4_bin). Rehearse that the table remains
        // fully operable for both ASCII and Unicode keys under the retained schema.
        $this->assertDatabaseHas('native_command_receipts', ['id' => $receipt->id, 'idempotency_key' => $unicodeKey]);

        $asciiKey2 = 'ascii-rehearsal-'.Str::random(8);
        $receipt2 = NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $asciiKey2,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'request_hash' => hash('sha256', 'ascii-forward-schema'),
            'payload' => ['ascii' => 'test'],
            'response_snapshot' => ['status' => 'ok'],
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('native_command_receipts', ['id' => $receipt2->id, 'idempotency_key' => $asciiKey2]);

        DB::table('native_command_receipts')->whereIn('id', [$receipt->id, $receipt2->id])->delete();
    }

    public function test_sales_invoice_dated_cash_cutoff_and_future_payment_filtering(): void
    {
        $this->restoreStaffPermissions();

        $tz = $this->company->timezone ?? 'Asia/Hebron';
        $day1 = Carbon::parse('2026-11-20 10:00:00', $tz);
        $day2 = Carbon::parse('2026-11-21 10:00:00', $tz);
        $day3 = Carbon::parse('2026-11-22 10:00:00', $tz);

        try {
            // 1. Set business clock BEFORE canonical invoice creation
            Carbon::setTestNow($day1);

            $salePayload = $this->validSalePayload(
                eventId: (string) Str::uuid(),
                amount: '0.000000',
                unitPrice: '50.000000',
                settlementMode: 'unpaid'
            );
            $sale = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $salePayload);
            $sale->assertStatus(201);
            $invoicePublicId = (string) $sale->json('invoice_id');

            // Invoice initially unpaid
            $detail1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoicePublicId}");
            $detail1->assertStatus(200);
            $this->assertSame('ON_CREDIT', $detail1->json('payment_status'));

            // 2. Post canonical cash payment dated Day 2 while business clock is Day 1
            $this->actingAs($this->owner);
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->owner->givePermissionTo('money.receipt.create');
            $invoice = SalesInvoice::where('public_id', $invoicePublicId)->firstOrFail();

            $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'money_account_id' => $this->cashAccount->id,
                'payment_date' => $day2->toDateString(),
                'payment_method' => CustomerPayment::METHOD_CASH,
                'amount' => '58.000000',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'cp-dated-'.Str::random(8),
                'allocations' => [
                    [
                        'sales_invoice_id' => $invoice->id,
                        'allocated_amount' => '58.000000',
                    ],
                ],
            ]);
            $paymentId = $payment->id;

            auth()->forgetUser();
            app(CompanyContext::class)->clearSessionless();

            // As of Day 1: payment is future-dated -> invoice payment_status is ON_CREDIT
            $detail2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoicePublicId}");
            $detail2->assertStatus(200);
            $this->assertSame('ON_CREDIT', $detail2->json('payment_status'));

            // Filter payment_status=PAID_CASH does NOT list it as of Day 1
            $listResp1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?payment_status=PAID_CASH");
            $listResp1->assertStatus(200);
            $this->assertNull(collect((array) $listResp1->json('invoices'))->firstWhere('id', $invoicePublicId));

            // 3. Advance business clock to Day 2 (payment effective date)
            Carbon::setTestNow($day2);

            $detail3 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoicePublicId}");
            $detail3->assertStatus(200);
            $this->assertSame('PAID_CASH', $detail3->json('payment_status'));

            $listResp2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?payment_status=PAID_CASH");
            $listResp2->assertStatus(200);
            $this->assertNotNull(collect((array) $listResp2->json('invoices'))->firstWhere('id', $invoicePublicId));

            // 4. Advance business clock to Day 3 and call canonical ReverseCustomerPaymentAction on distinct date
            Carbon::setTestNow($day3);
            $this->actingAs($this->owner);
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->owner->givePermissionTo('money.receipt.reverse');
            $paymentModel = CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->where('id', $paymentId)->firstOrFail();
            app(ReverseCustomerPaymentAction::class)->execute($paymentModel, $this->owner, 'Canonical dated payment reversal', $day3->toDateString());

            auth()->forgetUser();
            app(CompanyContext::class)->clearSessionless();

            // 5. Verify list/detail BEFORE effective cutoff (Day 2) still preserves PAID_CASH
            Carbon::setTestNow($day2);
            $detailPreCutoff = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoicePublicId}");
            $detailPreCutoff->assertStatus(200);
            $this->assertSame('PAID_CASH', $detailPreCutoff->json('payment_status'));

            $listPreCutoff = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?payment_status=PAID_CASH");
            $listPreCutoff->assertStatus(200);
            $this->assertNotNull(collect((array) $listPreCutoff->json('invoices'))->firstWhere('id', $invoicePublicId));

            // 6. Verify list/detail AT reversal cutoff (Day 3) returns to ON_CREDIT
            Carbon::setTestNow($day3);
            $detailAtCutoff = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoicePublicId}");
            $detailAtCutoff->assertStatus(200);
            $this->assertSame('ON_CREDIT', $detailAtCutoff->json('payment_status'));

            $listAtCutoff = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?payment_status=PAID_CASH");
            $listAtCutoff->assertStatus(200);
            $this->assertNull(collect((array) $listAtCutoff->json('invoices'))->firstWhere('id', $invoicePublicId));

            // 7. Reconciliations pass cleanly across dated payment and reversal history
            $this->assertAllSixReconciliationsPass();
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_sales_history_audit_accepts_canonical_receivable_and_revenue_rounding_adjustments(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->baseUnit->update(['allows_fraction' => true, 'decimal_places' => 6]);

        Currency::updateOrCreate(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JPY'], ['enabled' => true, 'is_base' => false]);

        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();

        // 2 JPY invoice at FX 0.3333333333 books 0.666667 base
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'JPY',
            'exchange_rate' => '0.3333333333',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => '2 JPY Fractional Line',
                    'quantity' => '2.000000',
                    'unit_price' => '1.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
        $this->assertSame('0.666667', (string) $invoice->grand_total_base);

        // First return: 1 JPY relieves 0.333333
        $invLine = $invoice->lines->first();
        $draft1 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => now()->format('Y-m-d'),
            'reason' => 'First 1 JPY return',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invLine->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        $return1 = app(PostSalesReturnAction::class)->execute($draft1, $this->owner);
        $this->assertSame('0.333333', (string) $return1->grand_total_base);

        // Final return: 1 JPY relieves remaining base 0.333334
        // The helper emits 0.333333 currency line and 0.000001 base-only adjustment on both accounts
        $draft2 = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => now()->format('Y-m-d'),
            'reason' => 'Final 1 JPY return with rounding residual',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invLine->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        $return2 = app(PostSalesReturnAction::class)->execute($draft2, $this->owner);
        $this->assertSame('0.333334', (string) $return2->grand_total_base);

        // Verify batch lines contain the 0.000001 base-only adjustments
        $batch2 = PostingBatch::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('source_type', 'sales_return')
            ->where('source_id', $return2->id)
            ->firstOrFail();
        $lines2 = DB::table('posting_lines')->where('posting_batch_id', $batch2->id)->get();
        $baseOnlyAdjustments = $lines2->filter(fn ($l) => $l->transaction_currency_code === null && (BigDecimal::of((string) $l->debit_base)->isEqualTo('0.000001') || BigDecimal::of((string) $l->credit_base)->isEqualTo('0.000001')));
        $this->assertGreaterThanOrEqual(2, $baseOnlyAdjustments->count(), 'Both accounts must receive exact rounding adjustments');

        // Audit MUST accept both receivables and revenue base-only rounding adjustments
        $audit = app(SalesHistoryAudit::class);
        $this->assertEmpty($audit->audit((int) $this->company->id));

        $this->assertAllSixReconciliationsPass();
    }

    public function test_sales_return_below_precision_tax_component_rejected_consistently(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->baseUnit->update(['allows_fraction' => true, 'decimal_places' => 6]);

        Currency::updateOrCreate(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JPY'], ['enabled' => true, 'is_base' => false]);

        $tax10 = TaxRate::create([
            'company_id' => $this->company->id,
            'code' => 'VAT10-MICRO',
            'name_ar' => 'ضريبة 10%',
            'name_en' => 'VAT 10%',
            'rate' => '10.000000',
            'calculation' => 'exclusive',
            'active' => true,
        ]);

        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        // 4 JPY with 10% tax = 0.40 JPY tax. At FX 0.000001, tax 0.40 * 0.000001 = 0.000000 base
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'JPY',
            'exchange_rate' => '0.0000010000',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'Below precision tax line',
                    'quantity' => '1.000000',
                    'unit_price' => '4.000000',
                    'tax_rate_id' => $tax10->id,
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
        $invLine = $invoice->lines->first();

        // 1. Preview MUST reject unrepresentable tax component with 422 before draft creation or side effects
        $previewResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns/preview", [
                'sales_invoice_id' => $invoice->public_id,
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine->public_id,
                        'quantity' => '1.000000',
                    ],
                ],
            ]);
        $previewResp->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('cannot be represented at the current exchange rate', (string) $previewResp->json('error.message'));

        // 2. Post endpoint MUST reject unrepresentable tax component before sequence numbering or stock movements
        $postResp = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Idempotency-Key', 'RET-MICRO-'.Str::uuid())
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/returns", [
                'sales_invoice_id' => $invoice->public_id,
                'reason' => 'Below precision tax return',
                'lines' => [
                    [
                        'sales_invoice_line_id' => $invLine->public_id,
                        'quantity' => '1.000000',
                    ],
                ],
            ]);
        $postResp->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('cannot be represented at the current exchange rate', (string) $postResp->json('error.message'));

        // 3. Domain action directly throws InvalidArgumentException before side effects
        $draft = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => now()->format('Y-m-d'),
            'reason' => 'Action test',
            'lines' => [
                [
                    'sales_invoice_line_id' => $invLine->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sales return tax component cannot be represented at the current exchange rate.');
        app(PostSalesReturnAction::class)->execute($draft, $this->owner);
    }

    public function test_sales_history_audit_validates_all_or_none_currency_metadata(): void
    {
        $this->restoreStaffPermissions();
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        // Create standard invoice
        $productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => $this->company->base_currency_code,
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->format('Y-m-d'),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $productUnit->id,
                    'item_description' => 'Metadata validation line',
                    'quantity' => '1.000000',
                    'unit_price' => '10.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);

        $batch = PostingBatch::where('id', $invoice->posting_batch_id)->firstOrFail();
        $firstLine = DB::table('posting_lines')->where('posting_batch_id', $batch->id)->first();
        $this->assertNotNull($firstLine);

        // Deliberate corruption: null currency code but non-null transaction amount
        DB::table('posting_lines')->where('id', $firstLine->id)->update([
            'transaction_currency_code' => null,
            'transaction_amount' => '10.000000',
            'exchange_rate' => '1.0000000000',
        ]);

        $audit = app(SalesHistoryAudit::class);
        $errors = $audit->audit((int) $this->company->id);
        $this->assertNotEmpty($errors);
        $hasMetadataError = collect($errors)->contains(fn ($e) => str_contains((string) $e, 'posting currency metadata mismatch.'));
        $this->assertTrue($hasMetadataError, 'Audit must fail when transaction_currency_code is null but amount/rate are present');

        // Restore clean line
        DB::table('posting_lines')->where('id', $firstLine->id)->update([
            'transaction_currency_code' => $firstLine->transaction_currency_code,
            'transaction_amount' => $firstLine->transaction_amount,
            'exchange_rate' => $firstLine->exchange_rate,
        ]);
    }
}
