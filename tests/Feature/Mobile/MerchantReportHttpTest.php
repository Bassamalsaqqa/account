<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Expenses\PostExpenseAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyMobileSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\LedgerAccount;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileShiftClosure;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class MerchantReportHttpTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $owner;

    private MobileDevice $device;

    private string $token;

    private MoneyAccount $cashAccount;

    private MoneyAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();

        config(['mobile.enabled' => true, 'mobile.merchant_api_enabled' => true]);

        Currency::updateOrCreate(['code' => 'ILS'], ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]);

        $this->owner = User::factory()->create(['name' => 'مالك المتجر']);
        $creator = app(CreateCompanyAction::class);
        $this->company = $creator->execute($this->owner, [
            'name_ar' => 'شركة التقارير المعتمدة',
            'base_currency_code' => 'ILS',
            'timezone' => 'Asia/Hebron',
        ]);

        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->owner->assignRole('Owner');
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        CompanyCurrency::firstOrCreate(
            ['company_id' => $this->company->id, 'currency_code' => 'ILS'],
            ['enabled' => true, 'is_base' => true]
        );

        $moneyAccountAction = app(CreateMoneyAccountAction::class);
        $this->cashAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق المتجر الرئيسي',
            'name_en' => 'Main Cash Box',
        ]);

        $this->bankAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_BANK,
            'currency_code' => 'ILS',
            'name_ar' => 'حساب البنك الرئيسي',
            'name_en' => 'Main Bank Account',
        ]);

        CompanyMobileSetting::updateOrCreate(
            ['company_id' => $this->company->id],
            [
                'approved_cash_account_id' => $this->cashAccount->id,
            ]
        );

        $deviceId = (string) Str::uuid();
        $this->device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Merchant Test POS',
            'user_id' => $this->owner->id,
        ]);

        $rawToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $deviceId,
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $this->owner->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);
        $this->token = $rawToken;
    }

    private function authHeader(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    public function test_shift_summary_reports_exact_canonical_cash_flows_without_double_counting(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        $tz = (string) ($this->company->timezone ?? 'Asia/Hebron');
        $today = now($tz)->toDateString();

        $customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل التقارير',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        // 1. Canonical sales invoice posted today: 150.00 ILS
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => $today,
            'due_date' => $today,
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'بضاعة عامة',
                    'quantity' => '1.000000',
                    'unit_price' => '150.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $invoice = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);

        // 2. Canonical customer cash payment allocated to today's invoice -> cash_sales (150.00)
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'idempotency_key' => 'receipt-cash-sale-1',
            'customer_id' => $customer->id,
            'money_account_id' => $this->cashAccount->id,
            'payment_date' => $today,
            'payment_method' => 'cash',
            'amount' => '150.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '150.000000',
                ],
            ],
        ]);

        // 3. Canonical customer cash payment unallocated (debt collection) -> cash_collections (50.00)
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'idempotency_key' => 'receipt-cash-coll-1',
            'customer_id' => $customer->id,
            'money_account_id' => $this->cashAccount->id,
            'payment_date' => $today,
            'payment_method' => 'cash',
            'amount' => '50.000000',
            'exchange_rate' => '1.0000000000',
        ]);

        // 4. Customer payment by bank transfer (must NOT be counted in cash flows)
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'idempotency_key' => 'receipt-bank-1',
            'customer_id' => $customer->id,
            'money_account_id' => $this->bankAccount->id,
            'payment_date' => $today,
            'payment_method' => 'bank_transfer',
            'amount' => '300.000000',
            'exchange_rate' => '1.0000000000',
        ]);

        // 5. Vendor payment today in cash: 30.00 ILS -> cash_disbursements
        $vendor = Vendor::create([
            'company_id' => $this->company->id,
            'name_ar' => 'مورد التقارير',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'idempotency_key' => 'vpay-test-1',
            'vendor_id' => $vendor->id,
            'money_account_id' => $this->cashAccount->id,
            'payment_date' => $today,
            'payment_method' => VendorPayment::METHOD_CASH,
            'amount' => '30.000000',
            'exchange_rate' => '1.0000000000',
        ]);

        // 6. Cash operating expense today: 20.00 ILS -> operating_expenses
        $parent = LedgerAccount::where('company_id', $this->company->id)
            ->where('system_key', 'operating_expense_parent')
            ->firstOrFail();

        $expenseLedger = LedgerAccount::where('company_id', $this->company->id)
            ->where('parent_id', $parent->id)
            ->where('account_type', 'expense')
            ->where('normal_balance', 'debit')
            ->where('is_control', false)
            ->firstOrFail();

        $category = ExpenseCategory::create([
            'company_id' => $this->company->id,
            'code' => 'EXP-GEN',
            'name_ar' => 'مصروفات عامة',
            'ledger_account_id' => $expenseLedger->id,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        app(PostExpenseAction::class)->execute($this->company, $this->owner, [
            'idempotency_key' => 'exp-key-1',
            'expense_date' => $today,
            'classification' => 'operating',
            'category_id' => $category->id,
            'description' => 'شراء ضيافة',
            'currency_code' => 'ILS',
            'payment_method' => 'cash',
            'money_account_id' => $this->cashAccount->id,
            'amount' => '20.000000',
            'exchange_rate' => '1.0000000000',
        ]);

        $response = $this->withHeaders($this->authHeader())
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-summary");

        $response->assertOk()
            ->assertJsonPath('sales_count', 1)
            ->assertJsonPath('cash_sales', '150.00')
            ->assertJsonPath('cash_collections', '50.00')
            ->assertJsonPath('cash_disbursements', '30.00')
            ->assertJsonPath('operating_expenses', '20.00')
            ->assertJsonPath('currency', 'ILS')
            ->assertJsonPath('currency_minor_units', 2);

        // Expected cash: opening 0.00 + 150.00 + 50.00 - 30.00 - 20.00 = 150.00
        $response->assertJsonPath('expected_cash', '150.00');
    }

    public function test_shift_close_persists_immutable_audited_count_observation(): void
    {
        $idempotencyKey = 'close-key-'.Str::random(12);

        $payload = [
            'counted_cash' => '145.50',
            'notes' => 'إغلاق وردية - نقص طفيف 4.50',
        ];

        $response = $this->withHeaders(array_merge($this->authHeader(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", $payload);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('is_shift_closed', true)
            ->assertJsonPath('counted_cash', '145.50')
            ->assertJsonStructure([
                'success',
                'id',
                'shift_date',
                'currency',
                'currency_minor_units',
                'expected_cash',
                'counted_cash',
                'variance',
                'variance_status',
                'is_shift_closed',
                'closed_at',
            ]);

        $closureId = $response->json('id');
        $this->assertNotNull($closureId);

        // Verify persisted record in database
        $closure = MobileShiftClosure::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('public_id', $closureId)
            ->first();

        $this->assertNotNull($closure);
        $this->assertSame($this->company->id, $closure->company_id);
        $this->assertSame($this->owner->id, $closure->actor_id);
        $this->assertSame($this->device->device_id, $closure->device_id);
        $this->assertSame('145.500000', $closure->counted_cash);
        $this->assertSame($idempotencyKey, $closure->idempotency_key);
        $this->assertSame('إغلاق وردية - نقص طفيف 4.50', $closure->notes);
        $this->assertSame($this->owner->id, $closure->actor_snapshot['id']);
        $this->assertSame('مالك المتجر', $closure->actor_snapshot['name']);
    }

    public function test_shift_close_idempotent_replay_and_conflict_behavior(): void
    {
        $idempotencyKey = 'close-replay-'.Str::random(12);

        $payload = [
            'counted_cash' => '200.00',
            'notes' => 'مطابقة تامة',
        ];

        // 1. Initial successful close
        $firstResponse = $this->withHeaders(array_merge($this->authHeader(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", $payload);

        $firstResponse->assertOk();
        $firstId = $firstResponse->json('id');
        $firstClosedAt = $firstResponse->json('closed_at');

        // 2. Exact same payload with same idempotency key -> 200 Replay
        $replayResponse = $this->withHeaders(array_merge($this->authHeader(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", $payload);

        $replayResponse->assertOk()
            ->assertJsonPath('id', $firstId)
            ->assertJsonPath('closed_at', $firstClosedAt);

        // 3. Different payload with same idempotency key -> 409 Conflict
        $conflictPayload = [
            'counted_cash' => '250.00',
            'notes' => 'تعديل مبلغ غير مسموح',
        ];

        $conflictResponse = $this->withHeaders(array_merge($this->authHeader(), [
            'Idempotency-Key' => $idempotencyKey,
        ]))->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", $conflictPayload);

        $conflictResponse->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_CONFLICT');
    }

    public function test_shift_close_requires_idempotency_key_and_valid_counted_cash(): void
    {
        // 1. Missing idempotency key
        $this->withHeaders($this->authHeader())
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", ['counted_cash' => '100.00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'MISSING_IDEMPOTENCY_KEY');

        // 2. Negative counted cash
        $this->withHeaders(array_merge($this->authHeader(), ['Idempotency-Key' => 'key-neg']))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", ['counted_cash' => '-10.00'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 3. Foreign / invalid cash account public ID
        $this->withHeaders(array_merge($this->authHeader(), ['Idempotency-Key' => 'key-foreign']))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", [
                'counted_cash' => '100.00',
                'cash_account_id' => (string) Str::ulid(),
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_reports_permission_denial_for_unauthorized_actor(): void
    {
        // Create cashier without reports or money permissions
        $cashier = User::factory()->create(['name' => 'كاشير محدود']);
        $this->company->users()->attach($cashier->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);

        $role = Role::findOrCreate('RestrictedCashier', 'web');
        $role->syncPermissions(['sales.invoice.create']);
        $cashier->assignRole($role);

        $deviceId = (string) Str::uuid();
        MobileDevice::create(['device_id' => $deviceId, 'device_name' => 'Cashier Device', 'user_id' => $cashier->id]);
        $cashierToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $cashierToken),
            'device_id' => $deviceId,
            'user_id' => $cashier->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $cashier->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);

        $cashierHeaders = ['Authorization' => 'Bearer '.$cashierToken];

        // Shift summary forbidden
        $this->withHeaders($cashierHeaders)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-summary")
            ->assertStatus(403);

        // Shift close forbidden
        $this->withHeaders(array_merge($cashierHeaders, ['Idempotency-Key' => 'cashier-close-1']))
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/reports/shift-close", ['counted_cash' => '100.00'])
            ->assertStatus(403);
    }

    public function test_home_and_product_redact_monetary_metrics_when_unauthorized(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $cashier = User::factory()->create(['name' => 'كاشير دون أذونات تكلفة']);
        $this->company->users()->attach($cashier->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);

        // Role with only sales.invoice.view, no cost or money view
        $role = Role::findOrCreate('ViewOnlyCashier', 'web');
        $role->syncPermissions(['sales.invoice.view', 'inventory.stock.view']);
        $cashier->assignRole($role);

        $deviceId = (string) Str::uuid();
        MobileDevice::create(['device_id' => $deviceId, 'device_name' => 'POS 2', 'user_id' => $cashier->id]);
        $cashierToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $cashierToken),
            'device_id' => $deviceId,
            'user_id' => $cashier->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $cashier->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);

        // Create test product with secret cost while company context is active
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $unit = Unit::create([
            'company_id' => $this->company->id,
            'code' => 'PCS',
            'name_ar' => 'قطعة',
            'allows_fraction' => false,
            'decimal_places' => 0,
            'active' => true,
        ]);
        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'SECRET-COST',
            'name_ar' => 'صنف سري التكلفة',
            'base_unit_id' => $unit->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'default_sale_price_base' => '10.000000',
            'default_purchase_cost_base' => '5.500000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $product->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'default_sale_price_base' => '10.000000',
            'active' => true,
        ]);

        $headers = ['Authorization' => 'Bearer '.$cashierToken];

        // 1. Home dashboard: cash_in_drawer, total_receivables, total_payables are NULL (not 0.00)
        $homeResp = $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/home");

        $homeResp->assertOk()
            ->assertJsonPath('cash_in_drawer', null)
            ->assertJsonPath('total_receivables', null)
            ->assertJsonPath('total_payables', null);

        // 2. Product detail: purchase_cost is NULL (cost redaction mandatory)
        $prodResp = $this->withHeaders($headers)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products/{$product->public_id}");

        $prodResp->assertOk()
            ->assertJsonPath('sale_price', '10.000000')
            ->assertJsonPath('purchase_cost', null)
            ->assertJsonPath('cost_ils', null);
    }

    public function test_cross_tenant_access_is_strictly_denied(): void
    {
        $company2 = Company::factory()->create(['base_currency_code' => 'ILS', 'status' => 'active']);

        $response = $this->withHeaders($this->authHeader())
            ->getJson("/api/mobile/v1/companies/{$company2->public_id}/reports/shift-summary");

        // Cross-company access is blocked by tenancy boundary
        $this->assertContains($response->status(), [403, 404]);
    }
}
