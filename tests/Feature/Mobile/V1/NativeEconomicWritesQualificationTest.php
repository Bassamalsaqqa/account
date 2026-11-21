<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\RecordStagedPurchaseBillAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyMobileSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\ExpenseCategory;
use App\Models\GoodsReceiptNote;
use App\Models\LedgerAccount;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\NativeCommandReceipt;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\StagedPurchaseFreezeCapability;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\CustomerCatalogService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Mobile\Receipt\NativeCommandReceiptManager;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class NativeEconomicWritesQualificationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $user;

    private MobileDevice $device;

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
        app(CompanyContext::class)->clear();

        config([
            'mobile.enabled' => true,
            'mobile.merchant_api_enabled' => true,
        ]);

        Currency::updateOrCreate(
            ['code' => 'ILS'],
            ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]
        );

        $this->user = User::factory()->create([
            'password' => Hash::make('merchant-secret-pass-2026'),
            'locale' => 'ar',
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->user, [
            'name_ar' => 'شركة التاجر المحلية للتجزئة',
            'name_en' => 'Local Trader Retail Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        setPermissionsTeamId($this->company->id);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('active', true)->firstOrFail();

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
            'name_ar' => 'سلعة تجريبية قابلة للجرد',
            'name_en' => 'Inventory Tracked Item',
            'sku' => 'SKU-QUAL-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_sale_price_base' => '50.000000',
            'default_purchase_cost_base' => '30.000000',
            'base_unit_id' => $pieceUnit->id,
        ], $this->user->id);

        $this->customer = app(CustomerCatalogService::class)->save($this->company, $this->user, [
            'name_ar' => 'عميل معتمد محلي',
            'name_en' => 'Approved Local Customer',
            'phone' => '+970599112233',
        ]);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->user, [
            'name_ar' => 'مورد جملة معتمد',
            'name_en' => 'Approved Wholesale Supplier',
            'default_currency_code' => 'ILS',
        ]);

        $this->cashAccount = MoneyAccount::where('company_id', $this->company->id)
            ->where('account_type', MoneyAccount::TYPE_CASH)
            ->where('currency_code', 'ILS')
            ->where('is_active', true)
            ->firstOrFail();

        CompanyMobileSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'approved_cash_account_id' => $this->cashAccount->id,
                'default_warehouse_id' => $this->warehouse->id,
            ]
        );

        $parentAccount = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'operating_expense_parent')
            ->firstOrFail();

        $expenseLedgerAccount = LedgerAccount::where('company_id', $this->company->id)
            ->where('parent_id', $parentAccount->id)
            ->where('account_type', LedgerAccount::TYPE_EXPENSE)
            ->where('is_control', false)
            ->first();

        if ($expenseLedgerAccount === null) {
            $expenseLedgerAccount = LedgerAccount::create([
                'company_id' => $this->company->id,
                'parent_id' => $parentAccount->id,
                'code' => '6010',
                'name_ar' => 'مصاريف تشغيلية',
                'name_en' => 'Operating Expenses',
                'account_type' => LedgerAccount::TYPE_EXPENSE,
                'normal_balance' => LedgerAccount::BALANCE_DEBIT,
                'is_control' => false,
                'is_system' => false,
                'active' => true,
            ]);
        }

        $this->expenseCategory = ExpenseCategory::create([
            'company_id' => $this->company->id,
            'code' => 'CAT-UTIL-01',
            'name_ar' => 'مصاريف كهرباء ومياه',
            'name_en' => 'Utilities & Water',
            'ledger_account_id' => $expenseLedgerAccount->id,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->user->givePermissionTo([
            'money.receipt.create',
            'money.vendor_payment.create',
            'purchasing.cost.view',
            'money.expense.manage',
            'inventory.stock.adjust',
            'inventory.cost.view',
            'customers.view',
            'vendors.view',
            'inventory.stock.view',
        ]);

        $deviceId = (string) Str::uuid();
        $this->device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Merchant Test Phone',
            'user_id' => $this->user->id,
        ]);

        $this->rawToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $this->rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        auth()->forgetGuards();
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $idempotencyKey): array
    {
        return [
            'Authorization' => 'Bearer '.$this->rawToken,
            'Idempotency-Key' => $idempotencyKey,
            'Accept' => 'application/json',
        ];
    }

    private function rebindContext(): void
    {
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        setPermissionsTeamId($this->company->id);
    }

    public function test_customer_payment_lifecycle_replay_and_status(): void
    {
        $key = 'cust-pay-test-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
        $payload = [
            'amount' => '120.000000',
            'payment_date' => now()->toDateString(),
            'reference' => 'PAY-REC-001',
            'notes' => 'دفعة نقدية من الحساب',
        ];

        // 1. Fresh Execution: HTTP 201 Created
        $response1 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $response1->assertStatus(201);
        $response1->assertHeader('Idempotency-Key', $key);
        $response1->assertHeaderMissing('X-Idempotent-Replay');
        $json1 = $response1->json();
        $this->assertTrue($json1['success']);
        $this->assertNotEmpty($json1['id']);
        $this->assertNotEmpty($json1['receipt_id']);
        $this->assertSame('120.00', $json1['amount']);
        $this->assertSame('ILS', $json1['currency']);
        $this->assertSame($this->customer->public_id, $json1['customer_id']);

        // Verify receipt table record
        $receipt = NativeCommandReceipt::where('company_id', $this->company->id)
            ->where('idempotency_key', $key)
            ->first();
        $this->assertNotNull($receipt);
        $this->assertSame('customer_payment', $receipt->command_type);
        $this->assertSame($this->user->id, $receipt->actor_id);
        $this->assertSame($this->device->device_id, $receipt->device_id);

        // 2. Exact Replay: HTTP 200 OK with X-Idempotent-Replay: true
        $response2 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $response2->assertStatus(200);
        $response2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($json1, $response2->json());

        // 3. Status GET route: HTTP 200 OK
        $statusUrl = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$key}/status";
        $statusRes = $this->withHeaders($this->authHeaders('status-key'))->getJson($statusUrl);
        $statusRes->assertStatus(200);
        $statusJson = $statusRes->json();
        $this->assertSame('completed', $statusJson['status']);
        $this->assertSame('replay', $statusJson['outcome']);
        $this->assertSame($key, $statusJson['idempotency_key']);
        $this->assertSame($json1, $statusJson['response_snapshot']);

        // 4. Payload Conflict: HTTP 409 Conflict
        $conflictingPayload = array_merge($payload, ['amount' => '130.000000']);
        $responseConflict = $this->withHeaders($this->authHeaders($key))->postJson($url, $conflictingPayload);
        $responseConflict->assertStatus(409);
        $responseConflict->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    public function test_receipt_status_denies_original_actor_after_read_permission_revocation(): void
    {
        $key = 'status-revoked-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
        $this->withHeaders($this->authHeaders($key))->postJson($url, ['amount' => '10.000000'])->assertStatus(201);
        $this->rebindContext();
        $this->user->syncRoles([]);
        $this->user->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withHeaders($this->authHeaders('lookup'))
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$key}/status")
            ->assertForbidden();
    }

    public function test_completed_native_receipt_cannot_be_rewritten_or_deleted(): void
    {
        $key = 'immutable-receipt-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
        $this->withHeaders($this->authHeaders($key))->postJson($url, ['amount' => '10.000000'])->assertStatus(201);
        $this->rebindContext();
        $receipt = NativeCommandReceipt::where('company_id', $this->company->id)->where('idempotency_key', $key)->firstOrFail();
        foreach (['update', 'delete'] as $operation) {
            try {
                if ($operation === 'update') {
                    $receipt->response_snapshot = ['id' => 'forged'];
                    $receipt->save();
                } else {
                    $receipt->delete();
                }
                $this->fail('A completed receipt must remain immutable.');
            } catch (ImmutableRecordException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_customer_payment_with_invoice_allocation_and_bounds(): void
    {
        $this->rebindContext();

        // Create a posted sales invoice of 200.00 ILS
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'استشارة تجارية',
                    'quantity' => '1',
                    'unit_price' => '200.00',
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->user);

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        // 1. Over-allocation fails closed: 422
        $overKey = 'alloc-over-'.(string) Str::uuid();
        $resOver = $this->withHeaders($this->authHeaders($overKey))->postJson($url, [
            'amount' => '250.000000',
            'allocations' => [
                [
                    'invoice_id' => $invoice->public_id,
                    'amount' => '250.000000',
                ],
            ],
        ]);
        $resOver->assertStatus(422);

        // 2. Valid allocation: 201 Created
        $validKey = 'alloc-valid-'.(string) Str::uuid();
        $resValid = $this->withHeaders($this->authHeaders($validKey))->postJson($url, [
            'amount' => '150.000000',
            'allocations' => [
                [
                    'invoice_id' => $invoice->public_id,
                    'amount' => '150.000000',
                ],
            ],
        ]);
        $resValid->assertStatus(201);
        $this->assertTrue($resValid->json('success'));

        $readUrl = "/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoice->public_id}";
        $read = $this->withHeaders($this->authHeaders('read'))->getJson($readUrl)->assertOk();
        $this->assertTrue(BigDecimal::of($read->json('remaining_exact'))->isEqualTo('50'));
        $this->assertTrue(BigDecimal::of($read->json('paid_exact'))->isEqualTo('150'));

        $explicitKey = 'alloc-explicit-'.(string) Str::uuid();
        $explicit = ['amount' => '25.000000', 'allocations' => [[
            'invoice_id' => $invoice->public_id,
            'document_currency_amount' => '25.000000',
            'payment_currency_amount' => '25.000000',
        ]]];
        $this->withHeaders($this->authHeaders($explicitKey))->postJson($url, $explicit)->assertCreated();
        $this->withHeaders($this->authHeaders($explicitKey))->postJson($url, $explicit)->assertOk();
        $explicit['allocations'][0]['payment_currency_amount'] = '24.999999';
        $this->withHeaders($this->authHeaders($explicitKey))->postJson($url, $explicit)->assertStatus(409);

        // Verify invoice allocation recorded
        $this->assertDatabaseHas('customer_payment_allocations', [
            'sales_invoice_id' => $invoice->id,
            'allocated_amount' => '150.000000',
        ]);
    }

    public function test_cross_currency_http_allocation_requires_both_amounts_and_uses_canonical_fx(): void
    {
        $this->rebindContext();
        Currency::updateOrCreate(['code' => 'USD'], ['name' => 'Dollar', 'symbol' => '$', 'minor_units' => 2, 'active' => true]);
        CompanyCurrency::updateOrCreate(['company_id' => $this->company->id, 'currency_code' => 'USD'], ['enabled' => true]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.5', 'issue_date' => now()->toDateString(),
            'lines' => [['product_id' => null, 'item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->user);
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
        $key = 'cross-http-'.Str::uuid();
        $payload = ['amount' => '330', 'allocations' => [['invoice_id' => $invoice->public_id, 'amount' => '100']]];
        $this->withHeaders($this->authHeaders($key))->postJson($url, $payload)->assertStatus(422);
        $payload['allocations'][0] = ['invoice_id' => $invoice->public_id, 'document_currency_amount' => '100', 'payment_currency_amount' => '330'];
        $this->withHeaders($this->authHeaders($key))->postJson($url, $payload)->assertCreated();
        $this->rebindContext();
        $allocation = CustomerPaymentAllocation::where('sales_invoice_id', $invoice->id)->sole();
        $this->assertSame('-20.000000', $allocation->realized_fx_gain_loss_base);
        $this->assertSame('330.000000', $allocation->payment_currency_amount);
        $this->assertTrue($invoice->fresh()->calculateOutstanding()->isZero());
    }

    public function test_purchase_http_reads_actual_partial_payment_and_exact_currency(): void
    {
        $this->rebindContext();
        $result = app(RecordStagedPurchaseBillAction::class)->execute(
            company: $this->company, actor: $this->user, supplierPublicId: $this->vendor->public_id,
            billNumber: 'EXACT-100', linesInput: [['product_id' => $this->product->public_id, 'quantity' => '1', 'unit_cost_ils' => '100']],
            idempotencyKey: 'exact-bill', mobileToken: MobileAccessToken::where('token_hash', hash('sha256', $this->rawToken))->first(), mobileDevice: $this->device,
        );
        $id = $result['snapshot']['id'];
        $url = "/api/mobile/v1/companies/{$this->company->public_id}";
        $this->withHeaders($this->authHeaders('exact-pay'))->postJson($url."/suppliers/{$this->vendor->public_id}/payments", [
            'amount' => '25', 'allocations' => [['bill_id' => $id, 'document_currency_amount' => '25', 'payment_currency_amount' => '25']],
        ])->assertCreated();
        $read = $this->withHeaders($this->authHeaders('read'))->getJson($url."/purchases/{$id}")->assertOk()->assertJsonPath('currency', 'ILS');
        $this->assertTrue(BigDecimal::of($read->json('paid_exact'))->isEqualTo('25'));
        $this->assertTrue(BigDecimal::of($read->json('remaining_exact'))->isEqualTo('75'));
        $list = $this->withHeaders($this->authHeaders('read'))->getJson($url."/purchases?allocatable=1&supplier_id={$this->vendor->public_id}")->assertOk();
        $this->assertTrue(BigDecimal::of($list->json('purchases.0.remaining_exact'))->isEqualTo('75'));
    }

    public function test_supplier_payment_lifecycle_replay_and_conflict(): void
    {
        $key = 'supp-pay-test-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/payments";
        $payload = [
            'amount' => '250.000000',
            'payment_date' => now()->toDateString(),
            'reference' => 'VOUCH-SUPP-01',
            'notes' => 'سداد دفعة تحت الحساب للمورد',
        ];

        // 1. Fresh Execution: HTTP 201 Created
        $res1 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res1->assertStatus(201);
        $res1->assertHeader('Idempotency-Key', $key);
        $res1->assertHeaderMissing('X-Idempotent-Replay');
        $json1 = $res1->json();
        $this->assertTrue($json1['success']);
        $this->assertNotEmpty($json1['voucher_id']);
        $this->assertSame('250.00', $json1['amount']);
        $this->assertSame('ILS', $json1['currency']);
        $this->assertSame($this->vendor->public_id, $json1['supplier_id']);

        // 2. Exact Replay: HTTP 200 OK
        $res2 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res2->assertStatus(200);
        $res2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($json1, $res2->json());

        // 3. Status GET
        $statusUrl = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$key}/status";
        $statusRes = $this->withHeaders($this->authHeaders('status-key'))->getJson($statusUrl);
        $statusRes->assertStatus(200);
        $this->assertSame('completed', $statusRes->json('status'));
        $this->assertSame('supplier_payment', $statusRes->json('command_type'));

        // 4. Conflict: HTTP 409
        $conflictPayload = array_merge($payload, ['amount' => '300.000000']);
        $conflictRes = $this->withHeaders($this->authHeaders($key))->postJson($url, $conflictPayload);
        $conflictRes->assertStatus(409);
        $conflictRes->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    public function test_expense_lifecycle_replay_and_validations(): void
    {
        $key = 'exp-test-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/expenses";
        $payload = [
            'expense_category_id' => $this->expenseCategory->public_id,
            'amount' => '45.500000',
            'description' => 'شراء مستلزمات نظافة للمحل',
            'expense_date' => now()->toDateString(),
            'reference' => 'EXP-INV-99',
        ];

        // 1. Fresh Execution: HTTP 201 Created
        $res1 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res1->assertStatus(201);
        $res1->assertHeader('Idempotency-Key', $key);
        $json1 = $res1->json();
        $this->assertTrue($json1['success']);
        $this->assertNotEmpty($json1['id']);
        $this->assertNotEmpty($json1['expense_number']);
        $this->assertSame('45.50', $json1['amount']);
        $this->assertSame('ILS', $json1['currency']);
        $this->assertSame($this->expenseCategory->public_id, $json1['expense_category_id']);

        // 2. Exact Replay: HTTP 200 OK
        $res2 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res2->assertStatus(200);
        $res2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($json1, $res2->json());

        // 3. Status GET
        $statusUrl = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$key}/status";
        $statusRes = $this->withHeaders($this->authHeaders('status-key'))->getJson($statusUrl);
        $statusRes->assertStatus(200);
        $this->assertSame('expense', $statusRes->json('command_type'));

        // 4. Conflict: HTTP 409
        $conflictPayload = array_merge($payload, ['description' => 'وصف مختلف لنفس المفتاح']);
        $resConflict = $this->withHeaders($this->authHeaders($key))->postJson($url, $conflictPayload);
        $resConflict->assertStatus(409);

        // 5. Missing / invalid category: 422
        $badCatRes = $this->withHeaders($this->authHeaders('bad-cat-key'))->postJson($url, [
            'expense_category_id' => (string) Str::ulid(),
            'amount' => '10.00',
            'description' => 'Test invalid category',
        ]);
        $this->assertContains($badCatRes->status(), [404, 422]);

        // Missing description: 422
        $noDescRes = $this->withHeaders($this->authHeaders('no-desc-key'))->postJson($url, [
            'expense_category_id' => $this->expenseCategory->public_id,
            'amount' => '10.00',
            'description' => '   ',
        ]);
        $noDescRes->assertStatus(422);
    }

    public function test_inventory_adjust_lifecycle_replay_and_bounds(): void
    {
        $keyInc = 'inv-inc-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/inventory/adjustments";

        // 1. Positive adjustment (increase) with explicit cost: HTTP 201 Created
        $payloadInc = [
            'product_id' => $this->product->public_id,
            'delta' => '10.000000',
            'reason' => 'جرد مخزني أولي بزيادة الكمية',
            'unit_cost' => '30.000000',
        ];
        $resInc = $this->withHeaders($this->authHeaders($keyInc))->postJson($url, $payloadInc);
        $resInc->assertStatus(201);
        $resInc->assertHeader('Idempotency-Key', $keyInc);
        $jsonInc = $resInc->json();
        $this->assertNotEmpty($jsonInc['id']);
        $this->assertSame('adjustment_increase', $jsonInc['movement_type']);
        $this->assertSame('10.000000', $jsonInc['delta']);
        $this->assertSame('10.000000', $jsonInc['resulting_stock']);

        // 2. Replay of increase: HTTP 200 OK
        $resIncReplay = $this->withHeaders($this->authHeaders($keyInc))->postJson($url, $payloadInc);
        $resIncReplay->assertStatus(200);
        $resIncReplay->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($jsonInc, $resIncReplay->json());

        // 3. Status GET
        $statusUrl = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$keyInc}/status";
        $statusRes = $this->withHeaders($this->authHeaders('status-key'))->getJson($statusUrl);
        $statusRes->assertStatus(200);
        $this->assertSame('inventory.adjustment', $statusRes->json('command_type'));

        // 4. Negative adjustment (decrease): HTTP 201 Created
        $keyDec = 'inv-dec-'.(string) Str::uuid();
        $payloadDec = [
            'product_id' => $this->product->public_id,
            'delta' => '-3.000000',
            'reason' => 'تلف 3 حبات أثناء النقل الداخلي',
        ];
        $resDec = $this->withHeaders($this->authHeaders($keyDec))->postJson($url, $payloadDec);
        $resDec->assertStatus(201);
        $jsonDec = $resDec->json();
        $this->assertSame('adjustment_decrease', $jsonDec['movement_type']);
        $this->assertSame('-3.000000', $jsonDec['delta']);
        $this->assertSame('7.000000', $jsonDec['resulting_stock']);

        // 4b. Fractional adjustment exact 6 decimals (0.000001) without truncation
        $this->rebindContext();
        $microUnit = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'MICRO',
            'name_ar' => 'وحدة ميكرو',
            'name_en' => 'Micro Unit',
            'allows_fraction' => true,
            'decimal_places' => 6,
            'active' => true,
        ]);
        $fracProduct = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'سلعة قابلة للتجزئة الكسرية',
            'name_en' => 'Fractional Stock Item',
            'sku' => 'SKU-FRAC-001',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'default_sale_price_base' => '50.000000',
            'default_purchase_cost_base' => '30.000000',
            'base_unit_id' => $microUnit->id,
        ], $this->user->id);

        $keyFrac = 'inv-frac-'.(string) Str::uuid();
        $payloadFrac = [
            'product_id' => $fracProduct->public_id,
            'delta' => '0.000001',
            'reason' => 'تعديل كسري دقيق بمقدار ميكرو واحد',
            'unit_cost' => '10.000000',
        ];
        $resFrac = $this->withHeaders($this->authHeaders($keyFrac))->postJson($url, $payloadFrac);
        $resFrac->assertStatus(201);
        $jsonFrac = $resFrac->json();
        $this->assertSame('0.000001', $jsonFrac['delta']);
        $this->assertSame('0.000001', $jsonFrac['resulting_stock']);

        // 5. Zero delta rejected: 422
        $keyZero = 'inv-zero-'.(string) Str::uuid();
        $resZero = $this->withHeaders($this->authHeaders($keyZero))->postJson($url, [
            'product_id' => $this->product->public_id,
            'delta' => '0.000000',
            'reason' => 'تعديل بصفر',
        ]);
        $resZero->assertStatus(422);

        // 6. Explicit cost on decrease rejected: 422
        $keyBadCost = 'inv-bad-cost-'.(string) Str::uuid();
        $resBadCost = $this->withHeaders($this->authHeaders($keyBadCost))->postJson($url, [
            'product_id' => $this->product->public_id,
            'delta' => '-1.000000',
            'reason' => 'تعديل بنقص وتكلفة صريحة',
            'unit_cost' => '15.000000',
        ]);
        $resBadCost->assertStatus(422);
    }

    public function test_security_gates_cross_actor_cross_device_and_epoch(): void
    {
        $this->rebindContext();

        // Establish an initial committed customer payment
        $origKey = 'sec-key-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
        $payload = [
            'amount' => '50.000000',
            'notes' => 'دفعة أصلية للاختبار الأمني',
        ];
        $resOrig = $this->withHeaders($this->authHeaders($origKey))->postJson($url, $payload);
        $resOrig->assertStatus(201);

        // 1. Cross-Actor Attack: User B attempts replay of User A's key -> HTTP 403 Forbidden
        $userB = User::factory()->create(['password' => Hash::make('userb-secret-pass-2026')]);
        $this->company->users()->attach($userB->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $userB->givePermissionTo(['money.receipt.create']);

        $deviceBId = (string) Str::uuid();
        $deviceB = MobileDevice::create([
            'device_id' => $deviceBId,
            'device_name' => 'Attacker Phone',
            'user_id' => $userB->id,
        ]);
        $rawTokenB = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawTokenB),
            'device_id' => $deviceBId,
            'user_id' => $userB->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $userB->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $resCrossActor = $this->withHeaders([
            'Authorization' => 'Bearer '.$rawTokenB,
            'Idempotency-Key' => $origKey,
            'Accept' => 'application/json',
        ])->postJson($url, $payload);
        $resCrossActor->assertStatus(403);

        // 2. Cross-Device Attack: Same User A, but from Device C -> HTTP 403 Forbidden
        $deviceCId = (string) Str::uuid();
        $deviceC = MobileDevice::create([
            'device_id' => $deviceCId,
            'device_name' => 'User A Second Device',
            'user_id' => $this->user->id,
        ]);
        $rawTokenC = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawTokenC),
            'device_id' => $deviceCId,
            'user_id' => $this->user->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->user->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $resCrossDevice = $this->withHeaders([
            'Authorization' => 'Bearer '.$rawTokenC,
            'Idempotency-Key' => $origKey,
            'Accept' => 'application/json',
        ])->postJson($url, $payload);
        $resCrossDevice->assertStatus(403);

        // 3. Password Epoch Change -> HTTP 401 Unauthenticated
        $this->user->update(['password' => Hash::make('new-changed-password-2026')]);
        $newKey = 'epoch-fail-key-'.(string) Str::uuid();
        $resEpoch = $this->withHeaders($this->authHeaders($newKey))->postJson($url, $payload);
        $resEpoch->assertStatus(401);
    }

    public function test_existing_frozen_receipt_replay_does_not_execute_again(): void
    {
        $this->rebindContext();

        $key = 'race-key-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
        $payload = [
            'amount' => '80.000000',
            'payment_date' => now()->toDateString(),
            'notes' => 'اختبار سباق التزامن',
        ];

        // Seed a pre-existing receipt to test replay only; this is not a concurrent race.
        $canonicalPayload = [
            'actor_id' => (int) $this->user->id,
            'device_id' => (string) $this->device->device_id,
            'customer_id' => $this->customer->public_id,
            'cash_account_id' => null,
            'amount' => '80.000000',
            'currency' => 'ILS',
            'payment_date' => now()->toDateString(),
            'reference' => null,
            'notes' => 'اختبار سباق التزامن',
            'allocations' => [],
        ];
        $requestHash = hash('sha256', json_encode($canonicalPayload, JSON_THROW_ON_ERROR));

        $snapshot = [
            'id' => (string) Str::ulid(),
            'receipt_id' => (string) Str::ulid(),
            'payment_number' => 'RCT-CONCURRENT-001',
            'receipt_number' => 'RCT-CONCURRENT-001',
            'amount' => '80.00',
            'currency' => 'ILS',
            'posted_at' => now()->format('Y-m-d\TH:i:s\Z'),
            'customer_id' => $this->customer->public_id,
            'success' => true,
        ];

        NativeCommandReceipt::create([
            'company_id' => $this->company->id,
            'command_type' => 'customer_payment',
            'idempotency_key' => $key,
            'actor_id' => $this->user->id,
            'device_id' => $this->device->device_id,
            'request_hash' => $requestHash,
            'payload' => $canonicalPayload,
            'response_snapshot' => $snapshot,
            'status' => 'completed',
        ]);

        // When the runner submits with identical payload, it gracefully recovers and returns 200 replay
        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res->assertStatus(200);
        $res->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($snapshot, $res->json());
    }

    public function test_all_six_reconciliation_services_pass_after_operations(): void
    {
        $this->rebindContext();

        // 1. Customer Payment
        $custKey = 'recon-cust-'.(string) Str::uuid();
        $resCust = $this->withHeaders($this->authHeaders($custKey))->postJson(
            "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments",
            [
                'amount' => '100.000000',
                'notes' => 'دفعة عميل لفحص المطابقة',
            ]
        );
        $resCust->assertStatus(201);

        // 2. Supplier Payment
        $suppKey = 'recon-supp-'.(string) Str::uuid();
        $resSupp = $this->withHeaders($this->authHeaders($suppKey))->postJson(
            "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/payments",
            [
                'amount' => '50.000000',
                'notes' => 'دفعة مورد لفحص المطابقة',
            ]
        );
        $resSupp->assertStatus(201);

        // 3. Expense
        $expKey = 'recon-exp-'.(string) Str::uuid();
        $resExp = $this->withHeaders($this->authHeaders($expKey))->postJson(
            "/api/mobile/v1/companies/{$this->company->public_id}/expenses",
            [
                'expense_category_id' => $this->expenseCategory->public_id,
                'amount' => '25.000000',
                'description' => 'مصروف تشغيلي لفحص المطابقة',
            ]
        );
        $resExp->assertStatus(201);

        // 4. Inventory Adjustments (Increase + Decrease)
        $invIncKey = 'recon-inv-inc-'.(string) Str::uuid();
        $resInvInc = $this->withHeaders($this->authHeaders($invIncKey))->postJson(
            "/api/mobile/v1/companies/{$this->company->public_id}/inventory/adjustments",
            [
                'product_id' => $this->product->public_id,
                'delta' => '20.000000',
                'reason' => 'زيادة رصيد مخزني للمطابقة',
                'unit_cost' => '30.000000',
            ]
        );
        $resInvInc->assertStatus(201);

        $invDecKey = 'recon-inv-dec-'.(string) Str::uuid();
        $resInvDec = $this->withHeaders($this->authHeaders($invInvKey = $invDecKey))->postJson(
            "/api/mobile/v1/companies/{$this->company->public_id}/inventory/adjustments",
            [
                'product_id' => $this->product->public_id,
                'delta' => '-5.000000',
                'reason' => 'نقص رصيد مخزني للمطابقة',
            ]
        );
        $resInvDec->assertStatus(201);

        // 5. Rebind context and run all 6 Reconciliations
        $this->rebindContext();

        // 1. Accounting Reconciliation
        $accountingReport = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($accountingReport->isHealthy, 'Accounting reconciliation reported unhealthy: '.json_encode($accountingReport->violations));
        $this->assertEmpty($accountingReport->violations);

        // 2. Inventory Reconciliation
        $inventoryReport = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inventoryReport->isHealthy, 'Inventory reconciliation reported unhealthy: '.json_encode($inventoryReport->discrepancies));
        $this->assertEmpty($inventoryReport->discrepancies);

        // 3. Sales Reconciliation
        $salesReport = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($salesReport->isHealthy, 'Sales reconciliation reported unhealthy: '.json_encode($salesReport->violations));
        $this->assertEmpty($salesReport->violations);

        // 4. Payables Reconciliation
        $payablesReport = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesReport->isHealthy, 'Payables reconciliation reported unhealthy: '.json_encode($payablesReport->violations));
        $this->assertEmpty($payablesReport->violations);

        // 5. Money Reconciliation
        $moneyReport = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($moneyReport->isHealthy, 'Money reconciliation reported unhealthy: '.json_encode($moneyReport->violations));
        $this->assertEmpty($moneyReport->violations);

        // 6. Phase 7 Comprehensive Integrity
        $phase7Report = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($phase7Report->isHealthy, 'Phase 7 reconciliation reported unhealthy: '.json_encode($phase7Report->violations));
        $this->assertEmpty($phase7Report->violations);
    }

    public function test_purchase_freeze_snapshot_rejects_direct_outside_call_or_fake_capability(): void
    {
        $this->rebindContext();

        // 1. Create a staged purchase bill canonically
        $key = 'freeze-bill-'.(string) Str::uuid();
        $action = app(RecordStagedPurchaseBillAction::class);
        $res = $action->execute(
            company: $this->company,
            actor: $this->user,
            supplierPublicId: $this->vendor->public_id,
            billNumber: 'BILL-FREEZE-1',
            linesInput: [
                ['product_id' => $this->product->public_id, 'quantity' => '10', 'unit_cost_ils' => '15.00'],
            ],
            idempotencyKey: $key,
            mobileToken: MobileAccessToken::where('token_hash', hash('sha256', $this->rawToken))->first(),
            mobileDevice: $this->device
        );

        $purchase = Purchase::where('company_id', $this->company->id)->where('public_id', $res['snapshot']['id'])->firstOrFail();
        $this->assertNotNull($purchase->response_snapshot);

        // 2. Direct outside call with no capability must throw ImmutableRecordException
        try {
            $purchase->freezeResponseSnapshot(['hacked' => true]);
            $this->fail('Expected ImmutableRecordException when calling freezeResponseSnapshot directly from outside.');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Response snapshot can only be frozen by an active canonical staged purchase bill action', $e->getMessage());
        }

        // 3. Fake capability or fake owner must throw ImmutableRecordException
        $caught = false;
        try {
            $fakeAction = new RecordStagedPurchaseBillAction;
            // Fake capability created outside active action invocation
            $fakeCapability = new StagedPurchaseFreezeCapability($fakeAction, (int) $this->company->id, (int) $this->user->id, (int) $purchase->id);
            $purchase->freezeResponseSnapshot(['hacked' => true], $fakeCapability);
        } catch (ImmutableRecordException $e) {
            $caught = true;
            $this->assertTrue(
                str_contains($e->getMessage(), 'active transaction') ||
                str_contains($e->getMessage(), 'Only the executing canonical staged purchase bill action')
            );
        }
        $this->assertTrue($caught, 'Expected ImmutableRecordException when freezing with fake capability');
    }

    public function test_purchase_freeze_snapshot_rejects_repeated_freeze_on_already_frozen_record(): void
    {
        $this->rebindContext();

        $key = 'freeze-bill-rep-'.(string) Str::uuid();
        $action = app(RecordStagedPurchaseBillAction::class);
        $res = $action->execute(
            company: $this->company,
            actor: $this->user,
            supplierPublicId: $this->vendor->public_id,
            billNumber: 'BILL-FREEZE-REP',
            linesInput: [
                ['product_id' => $this->product->public_id, 'quantity' => '5', 'unit_cost_ils' => '20.00'],
            ],
            idempotencyKey: $key,
            mobileToken: MobileAccessToken::where('token_hash', hash('sha256', $this->rawToken))->first(),
            mobileDevice: $this->device
        );

        $purchase = Purchase::where('company_id', $this->company->id)->where('public_id', $res['snapshot']['id'])->firstOrFail();
        $this->assertNotNull($purchase->response_snapshot);

        // Direct call or repeated call must throw
        $this->expectException(ImmutableRecordException::class);
        $purchase->freezeResponseSnapshot(['tampered' => true]);
    }

    public function test_purchase_rejects_arbitrary_dirty_response_snapshot_mutation(): void
    {
        $this->rebindContext();

        $key = 'freeze-bill-mut-'.(string) Str::uuid();
        $action = app(RecordStagedPurchaseBillAction::class);
        $res = $action->execute(
            company: $this->company,
            actor: $this->user,
            supplierPublicId: $this->vendor->public_id,
            billNumber: 'BILL-FREEZE-MUT',
            linesInput: [
                ['product_id' => $this->product->public_id, 'quantity' => '2', 'unit_cost_ils' => '50.00'],
            ],
            idempotencyKey: $key,
            mobileToken: MobileAccessToken::where('token_hash', hash('sha256', $this->rawToken))->first(),
            mobileDevice: $this->device
        );

        $purchase = Purchase::where('company_id', $this->company->id)->where('public_id', $res['snapshot']['id'])->firstOrFail();

        // Direct attribute modification followed by save must throw
        $purchase->response_snapshot = ['overwritten' => true];
        $this->expectException(ImmutableRecordException::class);
        $purchase->save();
    }

    public function test_goods_receipt_note_posted_immutability_and_draft_snapshot_rejection(): void
    {
        $this->rebindContext();

        $purchase = Purchase::where('company_id', $this->company->id)->first();
        if ($purchase === null) {
            $action = app(RecordStagedPurchaseBillAction::class);
            $res = $action->execute(
                company: $this->company,
                actor: $this->user,
                supplierPublicId: $this->vendor->public_id,
                billNumber: 'BILL-GRN-IMM',
                linesInput: [
                    ['product_id' => $this->product->public_id, 'quantity' => '10', 'unit_cost_ils' => '15.00'],
                ],
                idempotencyKey: 'grn-imm-bill-'.(string) Str::uuid(),
                mobileToken: MobileAccessToken::where('token_hash', hash('sha256', $this->rawToken))->first(),
                mobileDevice: $this->device
            );
            $purchase = Purchase::where('company_id', $this->company->id)->where('public_id', $res['snapshot']['id'])->firstOrFail();
        }

        // 1. Creating a draft GRN with non-null response_snapshot must throw
        try {
            GoodsReceiptNote::create([
                'public_id' => (string) Str::ulid(),
                'company_id' => $this->company->id,
                'purchase_id' => $purchase->id,
                'grn_number' => 'GRN-TEST-DRAFT',
                'warehouse_id' => $this->warehouse->id,
                'receipt_date' => now()->format('Y-m-d'),
                'status' => GoodsReceiptNote::STATUS_DRAFT,
                'inventory_operation_id' => null,
                'idempotency_key' => 'grn-draft-key',
                'request_hash' => 'hash123',
                'response_snapshot' => ['invalid_on_draft' => true],
                'created_by' => $this->user->id,
            ]);
            $this->fail('Expected draft GRN with response_snapshot to throw ImmutableRecordException');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Draft Goods Receipt Notes cannot carry a response snapshot', $e->getMessage());
        }

        // 2. A posted GRN cannot be modified
        $line = $purchase->lines()->firstOrFail();
        $grnRes = $this->withHeaders($this->authHeaders('grn-test-key-imm'))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/purchases/{$purchase->public_id}/grn", [
                'destination_warehouse_id' => $this->warehouse->public_id,
                'receipt_date' => now()->format('Y-m-d'),
                'lines' => [
                    [
                        'purchase_line_id' => $line->public_id,
                        'quantity' => '1',
                    ],
                ],
            ]);
        $grnRes->assertStatus(201);
        $grn = GoodsReceiptNote::withoutGlobalScopes()->where('company_id', $this->company->id)->firstOrFail();
        $this->assertNotNull($grn->response_snapshot);

        // Attempt to update response_snapshot on posted GRN must throw
        try {
            $grn->response_snapshot = ['tampered' => true];
            $grn->save();
            $this->fail('Expected modifying posted GRN to throw ImmutableRecordException');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Goods Receipt Notes are strictly immutable once posted', $e->getMessage());
        }

        // Attempt to delete posted GRN must throw
        try {
            $grn->delete();
            $this->fail('Expected deleting posted GRN to throw ImmutableRecordException');
        } catch (ImmutableRecordException $e) {
            $this->assertStringContainsString('Goods Receipt Notes cannot be deleted', $e->getMessage());
        }
    }

    public function test_fault_rollback_before_receipt_insert_rolls_back_all_domain_effects_with_zero_orphans(): void
    {
        $this->rebindContext();

        $key = 'fault-cust-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";
        $payload = [
            'amount' => '150.000000',
            'notes' => 'دفعة سيتم محاكاة عطل بعدها قبل حفظ الإيصال',
        ];

        // 1. Inject a fail-only stage after posting, without executing a caller callback.
        NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = true;

        try {
            $res = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
            $this->assertContains($res->status(), [422, 500]);
        } finally {
            NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = false;
        }

        // 2. Verify complete transaction rollback: no receipt, no customer payment, no posting batches
        $this->assertSame(0, NativeCommandReceipt::withoutGlobalScopes()->where('idempotency_key', $key)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        // 3. Retry same request cleanly: succeeds 201 Created without stale receipt conflict
        $resClean = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $resClean->assertStatus(201);
        $this->assertSame(1, NativeCommandReceipt::withoutGlobalScopes()->where('idempotency_key', $key)->count());
        $this->assertSame(1, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_fail_only_selector_is_inert_outside_testing_environment(): void
    {
        $priorEnvironment = app()->environment();
        NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = true;
        app()->instance('env', 'production');
        try {
            $response = $this->withServerVariables(['HTTPS' => 'on'])->withHeaders($this->authHeaders('production-fault-selector'))
                ->postJson("https://localhost/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments", ['amount' => '10.000000']);
            $this->assertSame(201, $response->status(), $response->getContent());
        } finally {
            app()->instance('env', $priorEnvironment);
            NativeCommandReceiptManager::$failBeforeReceiptInsertForTesting = false;
        }
    }
}
