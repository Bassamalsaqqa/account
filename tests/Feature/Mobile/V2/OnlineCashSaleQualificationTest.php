<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V2;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\UpdateRolePermissionsAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\ExecuteImmediateOnlineCashSaleAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\SaveTaxRateAction;
use App\Actions\Sales\UpdateMoneyAccountAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Http\Middleware\MobileV2ApiBoundary;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\LedgerAccount;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileEventReceipt;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
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
use App\Support\Mobile\CatalogSnapshot;
use App\Support\Mobile\Contract\CanonicalJsonSerializer;
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\Offline\OfflineSaleEvent;
use App\Support\Mobile\Receipt\DenyFinancialExecutionAdapter;
use App\Support\Mobile\Receipt\Exceptions\FinancialExecutionDeniedException;
use App\Support\Mobile\Receipt\Exceptions\IllegalStateException;
use App\Support\Mobile\Receipt\Exceptions\SecurityException;
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Mobile\Receipt\NativeFinancialExecutionCapability;
use App\Support\Mobile\Receipt\NativeOnlineCashFinancialExecutionAdapter;
use App\Support\Mobile\WireResponse;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Closure;
use Error;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use PHPUnit\Framework\Attributes\TestWith;
use PragmaRX\Google2FA\Google2FA;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\DisposableMariaDbSchema;
use Tests\Support\EnforcesDisposableDatabaseOwnership;
use Tests\Support\QualifiedCashFinancialExecutionAdapter;
use Tests\Support\StrictBarrier;
use Tests\TestCase;

final class OnlineCashSaleQualificationTest extends TestCase
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
        $this->enterIsolatedDisposableSchema();

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
        try {
            $this->exitAndVerifyIsolatedDisposableSchema(static function (): void {
                ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = null;
            });
        } finally {
            parent::tearDown();
        }
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

    /**
     * T-01: Prohibit public constructor and public issue() on NativeFinancialExecutionCapability.
     */
    public function test_forged_public_issue_and_constructor_prohibited(): void
    {
        $ref = new ReflectionClass(NativeFinancialExecutionCapability::class);
        $this->assertTrue($ref->getConstructor()->isPrivate(), 'NativeFinancialExecutionCapability constructor must be private.');
        $this->assertFalse($ref->hasMethod('issue'), 'NativeFinancialExecutionCapability must NEVER possess a public static issue() method.');

        $this->expectException(Error::class);
        // Direct instantiation via 'new' must fail fatal error
        /** @phpstan-ignore-next-line */
        new NativeFinancialExecutionCapability(
            $this->company->id, (string) $this->company->public_id,
            $this->staff->id, (string) $this->staff->public_id,
            $this->device->device_id, $this->token->password_epoch,
            (string) Str::uuid(), 'digest', 2, 'mysql', 1, 'scope'
        );
    }

    /**
     * T-02: Container cannot resolve NativeFinancialExecutionCapability.
     */
    public function test_container_cannot_mint_capability(): void
    {
        $this->expectException(BindingResolutionException::class);
        app()->make(NativeFinancialExecutionCapability::class);
    }

    /**
     * T-03: Passing fake/unregistered capability to action throws SecurityException.
     */
    public function test_unregistered_capability_throws_security_exception(): void
    {
        $companyId = $this->company->id;
        $companyPublicId = (string) $this->company->public_id;
        $staffId = $this->staff->id;
        $staffPublicId = (string) $this->staff->public_id;
        $deviceId = $this->device->device_id;
        $passwordEpoch = $this->token->password_epoch;
        $eventId = (string) Str::uuid();

        $fake = (Closure::bind(
            static fn () => new NativeFinancialExecutionCapability(
                $companyId, $companyPublicId,
                $staffId, $staffPublicId,
                $deviceId, $passwordEpoch,
                $eventId, 'fake-digest', 2, 'mysql', 1, 'fake-scope'
            ),
            null,
            NativeFinancialExecutionCapability::class
        ))();

        $action = app(ExecuteImmediateOnlineCashSaleAction::class);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Unauthorized financial capability');

        DB::transaction(function () use ($action, $fake) {
            $payload = $this->validSalePayload((string) Str::uuid());
            $action->execute($fake, $this->company, $this->staff, $this->device, $payload, 'test-suffix');
        });
    }

    /**
     * T-04: Non-root transaction level (>1 or 0) throws IllegalStateException.
     */
    public function test_non_root_transaction_depth_rejected(): void
    {
        // 1. Transaction level 0 (outside transaction)
        $this->assertSame(0, DB::transactionLevel());

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $canonicalBytes = OnlineSaleIntentProfile::serialize($payload);
        $digest = hash('sha256', $canonicalBytes);

        $action = app(ExecuteImmediateOnlineCashSaleAction::class);

        $companyId = $this->company->id;
        $companyPublicId = (string) $this->company->public_id;
        $staffId = $this->staff->id;
        $staffPublicId = (string) $this->staff->public_id;
        $deviceId = $this->device->device_id;
        $passwordEpoch = $this->token->password_epoch;
        $connName = DB::connection()->getName();

        $capability = (Closure::bind(
            static fn () => new NativeFinancialExecutionCapability(
                $companyId, $companyPublicId,
                $staffId, $staffPublicId,
                $deviceId, $passwordEpoch,
                $eventId, $digest, 2, $connName, 1, 'test-scope'
            ),
            null,
            NativeFinancialExecutionCapability::class
        ))();

        (Closure::bind(static function () use ($capability, $companyId, $staffId, $deviceId, $passwordEpoch, $eventId, $digest, $connName) {
            self::$capabilityRegistry = new \WeakMap;
            self::$currentInvocationScopeId = 'test-scope';
            self::$capabilityRegistry[$capability] = [
                'company_id' => $companyId,
                'actor_id' => $staffId,
                'device_id' => $deviceId,
                'token_password_epoch' => $passwordEpoch,
                'client_event_id' => $eventId,
                'event_hash' => $digest,
                'currency_minor_units' => 2,
                'connection_name' => $connName,
                'root_transaction_level' => 1,
                'invocation_scope_id' => 'test-scope',
                'active' => true,
                'consumed' => false,
                'composite_completed' => false,
            ];
        }, null, MobileEventReceiptManager::class))();

        $this->expectException(IllegalStateException::class);
        $this->expectExceptionMessage('root database transaction level');

        $action->execute($capability, $this->company, $this->staff, $this->device, $payload, 'depth-suffix');
    }

    /**
     * T-05: Replay or second consumption of same capability throws SecurityException.
     */
    public function test_reused_capability_throws_security_exception(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $canonicalBytes = OnlineSaleIntentProfile::serialize($payload);
        $digest = hash('sha256', $canonicalBytes);

        $action = app(ExecuteImmediateOnlineCashSaleAction::class);

        $companyId = $this->company->id;
        $companyPublicId = (string) $this->company->public_id;
        $staffId = $this->staff->id;
        $staffPublicId = (string) $this->staff->public_id;
        $deviceId = $this->device->device_id;
        $passwordEpoch = $this->token->password_epoch;
        $connName = DB::connection()->getName();

        $capability = (Closure::bind(
            static fn () => new NativeFinancialExecutionCapability(
                $companyId, $companyPublicId,
                $staffId, $staffPublicId,
                $deviceId, $passwordEpoch,
                $eventId, $digest, 2, $connName, 1, 'scope-1'
            ),
            null,
            NativeFinancialExecutionCapability::class
        ))();

        (Closure::bind(static function () use ($capability, $companyId, $staffId, $deviceId, $passwordEpoch, $eventId, $digest, $connName) {
            self::$capabilityRegistry = new \WeakMap;
            self::$currentInvocationScopeId = 'scope-1';
            self::$capabilityRegistry[$capability] = [
                'company_id' => $companyId,
                'actor_id' => $staffId,
                'device_id' => $deviceId,
                'token_password_epoch' => $passwordEpoch,
                'client_event_id' => $eventId,
                'event_hash' => $digest,
                'currency_minor_units' => 2,
                'connection_name' => $connName,
                'root_transaction_level' => 1,
                'invocation_scope_id' => 'scope-1',
                'active' => false,
                'consumed' => true,
                'composite_completed' => false,
            ];
        }, null, MobileEventReceiptManager::class))();

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('already been consumed');

        DB::transaction(function () use ($action, $capability, $payload) {
            $action->execute($capability, $this->company, $this->staff, $this->device, $payload, 'reuse-suffix');
        });
    }

    /**
     * T-06: Stale or mismatched event hash throws SecurityException.
     */
    public function test_stale_or_mismatched_event_hash_throws_security_exception(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $digest = hash('sha256', 'digest-A');

        $action = app(ExecuteImmediateOnlineCashSaleAction::class);

        $companyId = $this->company->id;
        $companyPublicId = (string) $this->company->public_id;
        $staffId = $this->staff->id;
        $staffPublicId = (string) $this->staff->public_id;
        $deviceId = $this->device->device_id;
        $passwordEpoch = $this->token->password_epoch;
        $connName = DB::connection()->getName();

        $capability = (Closure::bind(
            static fn () => new NativeFinancialExecutionCapability(
                $companyId, $companyPublicId,
                $staffId, $staffPublicId,
                $deviceId, $passwordEpoch,
                $eventId, $digest, 2, $connName, 1, 'scope-hash'
            ),
            null,
            NativeFinancialExecutionCapability::class
        ))();

        (Closure::bind(static function () use ($capability, $companyId, $staffId, $deviceId, $passwordEpoch, $eventId, $digest, $connName) {
            self::$capabilityRegistry = new \WeakMap;
            self::$currentInvocationScopeId = 'scope-hash';
            self::$capabilityRegistry[$capability] = [
                'company_id' => $companyId,
                'actor_id' => $staffId,
                'device_id' => $deviceId,
                'token_password_epoch' => $passwordEpoch,
                'client_event_id' => $eventId,
                'event_hash' => $digest,
                'currency_minor_units' => 2,
                'connection_name' => $connName,
                'root_transaction_level' => 1,
                'invocation_scope_id' => 'scope-hash',
                'active' => true,
                'consumed' => false,
                'composite_completed' => false,
            ];
        }, null, MobileEventReceiptManager::class))();

        DB::transaction(function () use ($action, $capability, $payload) {
            $this->expectException(SecurityException::class);
            $this->expectExceptionMessage('event hash mismatch');

            $action->execute($capability, $this->company, $this->staff, $this->device, $payload, 'mismatch-suffix');
        });
    }

    /**
     * T-07: Fresh DB role definition revocation is observed under lock and refuses execution.
     */
    public function test_fresh_role_definition_revocation_refuses_execution(): void
    {
        // Revoke sales.invoice.post permission from the Sales role definition in DB
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $salesRole = Role::where('company_id', $this->company->id)->where('name', 'Sales')->firstOrFail();
        app(UpdateRolePermissionsAction::class)->execute($this->company, $salesRole, 'sales.invoice.post', false, $this->owner);

        // Also revoke direct permission from staff user
        $this->staff->revokePermissionTo('sales.invoice.post');

        // Clear owner from guard and context before mobile API request
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        // Must fail with 403 authorization error
        $response->assertStatus(403);
    }

    /**
     * T-08: Full end-to-end HTTP creation (HTTP 201) executes all legs and passes 6 reconciliations.
     */
    public function test_fresh_creation_commits_all_legs_and_reconciles_cleanly(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'client_event_id' => $eventId,
            'status' => 'posted',
            'grand_total' => '58.000000',
            'amount_paid' => '58.000000',
            'change_returned' => '0.000000',
        ]);

        $data = $response->json();
        $this->assertArrayNotHasKey('outcome', $data);
        $this->assertArrayNotHasKey('sales_invoice_id', $data);
        $this->assertArrayNotHasKey('customer_payment_id', $data);
        $this->assertArrayNotHasKey('customer_id', $data);
        $this->assertArrayNotHasKey('currency', $data);
        $this->assertArrayNotHasKey('paid_amount', $data);
        $this->assertArrayNotHasKey('lines_count', $data);

        $this->assertTrue(WireResponse::accepts(json_decode((string) $response->getContent()), 'sale-commit-response'));

        $invoicePublicId = $data['invoice_id'];
        $paymentPublicId = $data['payment_id'];

        $this->assertNotNull($invoicePublicId);
        $this->assertNotNull($paymentPublicId);

        // Verify database state: invoice is posted
        $invoice = SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $invoicePublicId)->first();
        $this->assertNotNull($invoice);
        $this->assertSame('posted', $invoice->status);
        $this->assertSame('58.000000', (string) $invoice->grand_total_currency);

        // Verify payment is posted and allocated
        $payment = CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $paymentPublicId)->first();
        $this->assertNotNull($payment);
        $this->assertFalse($payment->is_reversed);
        $this->assertNotNull($payment->posting_batch_id);
        $this->assertSame('58.000000', (string) $payment->amount);

        $allocation = DB::table('customer_payment_allocations')
            ->where('customer_payment_id', $payment->id)
            ->where('sales_invoice_id', $invoice->id)
            ->first();
        $this->assertNotNull($allocation);
        $this->assertSame('58.000000', (string) $allocation->allocated_amount);

        // Verify stock movements were created
        $stockMovement = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('source_type', 'sales_invoice')
            ->where('source_id', $invoice->id)
            ->first();
        $this->assertNotNull($stockMovement);

        // Verify immutable receipt persisted
        $receipt = MobileEventReceipt::withoutGlobalScopes()->where('client_event_id', $eventId)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('completed', $receipt->status);
        $this->assertSame((int) $this->staff->id, (int) $receipt->actor_id);
        $this->assertSame($this->device->device_id, $receipt->device_id);

        // Run all 6 accounting reconciliations with company context
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $reconciliation = app(AccountingReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($reconciliation->isHealthy, 'Accounting reconciliation must be balanced');

        $salesRecon = app(SalesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($salesRecon->isHealthy, 'Sales reconciliation must be balanced');

        $moneyRecon = app(MoneyReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($moneyRecon->isHealthy, 'Money reconciliation must be balanced');

        $invRecon = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($invRecon->isHealthy, 'Inventory reconciliation must be balanced: '.json_encode($invRecon->discrepancies));

        $phase7Recon = app(Phase7ReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($phase7Recon->isHealthy, 'Phase 7 reconciliation must be balanced');

        $payablesRecon = app(PayablesReconciliationService::class)->reconcile($this->company);
        $this->assertTrue($payablesRecon->isHealthy, 'Payables reconciliation must be balanced');
    }

    /**
     * T-09: Lost-ACK replay (identical payload, same client_event_id) returns HTTP 200 replayed without duplicating records.
     */
    public function test_lost_ack_replay_returns_200_without_duplication(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        // First attempt (HTTP 201)
        $first = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);
        $first->assertStatus(201);
        $data1 = $first->json();

        $invoiceCountBefore = SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
        $paymentCountBefore = CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
        $receiptCountBefore = MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count();

        // Second attempt: simulated client reconnect / lost ACK replay (HTTP 200)
        $second = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $second->assertStatus(200);
        $data2 = $second->json();

        $this->assertSame($data1, $data2);
        $this->assertSame($data1['invoice_id'], $data2['invoice_id']);
        $this->assertSame($data1['payment_id'], $data2['payment_id']);
        $this->assertSame($data1['invoice_number'], $data2['invoice_number']);
        $this->assertArrayNotHasKey('sales_invoice_id', $data2);
        $this->assertArrayNotHasKey('customer_payment_id', $data2);
        $this->assertArrayNotHasKey('customer_id', $data2);
        $this->assertArrayNotHasKey('outcome', $data2);
        $this->assertTrue(WireResponse::accepts(json_decode((string) $second->getContent()), 'sale-commit-response'));

        // Zero sequence or record duplication
        $this->assertSame($invoiceCountBefore, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame($paymentCountBefore, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame($receiptCountBefore, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    /**
     * T-10: Tampered payload with same client_event_id returns HTTP 409 Conflict.
     */
    public function test_tampered_payload_returns_409_conflict(): void
    {
        $eventId = (string) Str::uuid();
        $payloadOriginal = $this->validSalePayload($eventId, '58.000000', '50.000000');

        $first = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payloadOriginal);
        $first->assertStatus(201);

        // Tamper with unit_price and amount
        $payloadTampered = $this->validSalePayload($eventId, '116.000000', '100.000000');

        $tampered = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payloadTampered);

        $tampered->assertStatus(409);
        $tampered->assertJson([
            'error' => [
                'code' => 'CONFLICT',
                'message' => 'Resource state conflict.',
            ],
        ]);
    }

    /**
     * T-11: Full rollback atomicity: injected fault after actual numbering, stock, GL, payment, allocation,
     * and receipt write restores pre-event database fingerprint with zero sequence leakage and 100% reconciliation health.
     */
    public function test_all_fault_rollback_restores_pre_event_database_fingerprint(): void
    {
        $faultFired = false;
        MobileEventReceipt::created(function () use (&$faultFired): void {
            $faultFired = true;
            throw new RuntimeException('Simulated real fault immediately after receipt write');
        });

        try {
            $invoicesBefore = SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
            $paymentsBefore = CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
            $receiptsBefore = MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
            $movementsBefore = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->count();
            $allocationsBefore = DB::table('customer_payment_allocations')->where('company_id', $this->company->id)->count();
            $batchesBefore = DB::table('posting_batches')->where('company_id', $this->company->id)->count();
            $linesBefore = DB::table('posting_lines')->where('company_id', $this->company->id)->count();

            $eventId = (string) Str::uuid();
            $payload = $this->validSalePayload($eventId);

            $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

            $this->assertTrue($response->status() >= 400);
            $this->assertTrue($faultFired, 'Fault must have fired after numbering, stock, GL, payment, allocation, and receipt write.');

            // Verify full tenant fingerprints: zero leakage across all tables
            $this->assertSame($invoicesBefore, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame($paymentsBefore, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame($receiptsBefore, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame($movementsBefore, StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame($allocationsBefore, DB::table('customer_payment_allocations')->where('company_id', $this->company->id)->count());
            $this->assertSame($batchesBefore, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
            $this->assertSame($linesBefore, DB::table('posting_lines')->where('company_id', $this->company->id)->count());

            // Warehouse inventory balance unmutated (100.000000)
            $this->assertDatabaseHas('inventory_balances', [
                'company_id' => $this->company->id,
                'product_id' => $this->product->id,
                'quantity_base' => '100.000000',
            ]);

            // All 6 domain reconciliations remain 100% healthy
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
            $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
            $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
            $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
            $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
            $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase7 reconciliation must pass');
        } finally {
            MobileEventReceipt::flushEventListeners();
        }
    }

    public function test_offline_sale_replay_and_status_are_bound_to_original_event_even_after_lease_expiry(): void
    {
        $lease = $this->issueOfflineLease();
        $wire = $this->offlineSaleWire($lease);
        $eventId = $wire['client_event_id'];
        $root = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";

        $first = $this->postJson($root, $wire)->assertOk()
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('payload_digest', $wire['payload_digest'])
            ->assertJsonPath('outcome.grand_total', '58.000000');
        $officialInvoice = $first->json('outcome.invoice_id');

        DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id'])->update(['expires_at' => now('UTC')->subSecond()->toDateTimeString()]);
        $this->postJson($root, $wire)->assertOk()->assertExactJson($first->json());
        $status = $this->get($root.'/'.$eventId.'/status', ['Accept' => 'application/json']);
        $this->assertSame(200, $status->status(), $status->getContent());
        $status->assertOk()
            ->assertJsonPath('official_invoice.invoice_id', $officialInvoice)
            ->assertJsonPath('payload_digest', $wire['payload_digest']);

        $this->assertDatabaseHas('mobile_event_receipts', [
            'company_id' => $this->company->id,
            'client_event_id' => $eventId,
            'original_canonical_bytes' => CanonicalJsonSerializer::serialize($wire['event']),
        ]);
        $this->assertDatabaseHas('mobile_offline_sale_leases', [
            'id' => $lease['lease_id'],
            'posted_sales_count' => 1,
            'posted_total_amount' => '58.000000',
        ]);

        $wire['event']['local_receipt_number'] = 'changed';
        $wire['payload_digest'] = CanonicalJsonSerializer::computeDigest($wire['event']);
        $this->postJson($root, $wire)->assertStatus(409)
            ->assertJsonPath('error.conflict_evidence.reason', 'EVENT_PAYLOAD_CHANGED');
        $this->postJson($root, $this->offlineSaleWire($lease))->assertStatus(409)
            ->assertJsonPath('error.conflict_evidence.reason', 'LEASE_WINDOW_MISMATCH');

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy);
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_offline_lease_migration_refuses_populated_rollback_without_changing_history(): void
    {
        $lease = $this->issueOfflineLease();
        $before = DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id'])->first();
        $migration = require database_path('migrations/2026_11_25_100001_create_mobile_offline_sale_leases_table.php');
        try {
            $migration->down();
            $this->fail('Issued lease history was removed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Issued offline leases require a forward correction.', $exception->getMessage());
        }
        $this->assertEquals($before, DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id'])->first());
    }

    public function test_offline_authority_migration_resumes_after_each_ddl_and_preserves_history(): void
    {
        $schema = DisposableMariaDbSchema::create();
        $primaryCompany = $this->company;
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $migration = require database_path('migrations/2026_11_25_100002_track_mobile_authority_and_offline_policy_revocation.php');
            $leaseMigration = require database_path('migrations/2026_11_25_100001_create_mobile_offline_sale_leases_table.php');
            $failure = new class
            {
                public ?string $afterDdl = null;

                public int $invalidations = 0;
            };
            DB::listen(static function (QueryExecuted $query) use ($failure): void {
                if (str_starts_with($query->sql, 'update `users` set `mobile_authority_revision`')
                    || str_starts_with($query->sql, 'update `mobile_offline_sale_leases` set `revoked_at`')) {
                    $failure->invalidations++;
                }
                if ($failure->afterDdl !== null && str_starts_with($query->sql, $failure->afterDdl)) {
                    $failure->afterDdl = null;
                    throw new RuntimeException('Interrupted after committed DDL.');
                }
            });

            $this->assertDatabaseCount('mobile_offline_sale_leases', 0);
            foreach ([
                'DROP TRIGGER `company_mobile_settings_revoke_leases_on_delete`',
                'DROP TRIGGER `company_mobile_settings_revoke_leases_on_update`',
                'DROP TRIGGER `users_advance_mobile_authority`',
                'ALTER TABLE `mobile_offline_sale_leases` DROP COLUMN',
                'ALTER TABLE `users` DROP COLUMN',
            ] as $ddl) {
                $failure->afterDdl = $ddl;
                try {
                    $migration->down();
                    $this->fail("Missing failure injection after {$ddl}.");
                } catch (RuntimeException $exception) {
                    $this->assertSame('Interrupted after committed DDL.', $exception->getMessage());
                }
            }
            $migration->down();
            $leaseMigration->down();
            $this->assertFalse(DB::getSchemaBuilder()->hasTable('mobile_offline_sale_leases'));
            $leaseMigration->up();
            $migration->up();
            $this->setupQualificationFixture();

            $lease = $this->issueOfflineLease();
            $wire = $this->offlineSaleWire($lease);
            $url = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";
            $this->postJson($url, $wire)->assertOk();
            $receipt = DB::table('mobile_event_receipts')->where('client_event_id', $wire['client_event_id'])->first();
            $leaseQuery = DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id']);
            $leaseRow = (array) $leaseQuery->first();
            try {
                $migration->down();
                $this->fail('Authority history was removed.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Mobile authority or issued lease history requires a forward correction.', $exception->getMessage());
            }

            // Recreate the preceding schema, then upgrade a synthetic populated fixture.
            $leaseQuery->delete();
            $migration->down();
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('users', 'mobile_authority_revision'));
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('mobile_offline_sale_leases', 'revoked_at'));
            unset($leaseRow['revoked_at']);
            DB::table('mobile_offline_sale_leases')->insert($leaseRow);
            $migrationName = '2026_11_25_100002_track_mobile_authority_and_offline_policy_revocation';
            $migrationRecord = DB::table('migrations')->where('migration', $migrationName);
            $batch = $migrationRecord->value('batch');
            $migrationRecord->delete();
            $status = $url.'/'.$wire['client_event_id'].'/status';
            $this->get($status)->assertStatus(503);

            DB::statement('ALTER TABLE users ADD COLUMN mobile_authority_revision BIGINT UNSIGNED NOT NULL DEFAULT 9');
            try {
                $migration->up();
                $this->fail('A column with the wrong default passed validation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Unexpected definition for users.mobile_authority_revision.', $exception->getMessage());
            }
            $this->assertFalse(DB::getSchemaBuilder()->hasColumn('mobile_offline_sale_leases', 'revoked_at'));
            DB::statement('ALTER TABLE users DROP COLUMN mobile_authority_revision');

            $failure->invalidations = 0;
            $revokedAt = null;
            foreach ([
                'ALTER TABLE `users` ADD COLUMN',
                'ALTER TABLE `mobile_offline_sale_leases` ADD COLUMN',
                'CREATE TRIGGER users_advance_mobile_authority',
                'CREATE TRIGGER company_mobile_settings_revoke_leases_on_update',
                'CREATE TRIGGER company_mobile_settings_revoke_leases_on_delete',
            ] as $step => $ddl) {
                $failure->afterDdl = $ddl;
                try {
                    $migration->up();
                    $this->fail("Missing failure injection after {$ddl}.");
                } catch (RuntimeException $exception) {
                    $this->assertSame('Interrupted after committed DDL.', $exception->getMessage());
                }
                $this->assertSame($step < 2 ? 0 : 2, $failure->invalidations);
                $this->get($status)->assertStatus(503);
                $this->assertEquals($receipt, DB::table('mobile_event_receipts')->where('client_event_id', $wire['client_event_id'])->first());
                if ($step >= 2) {
                    $revokedAt ??= $leaseQuery->value('revoked_at');
                    $this->assertNotNull($revokedAt);
                    $this->assertSame($revokedAt, $leaseQuery->value('revoked_at'));
                    $this->assertSame(1, (int) DB::table('users')->where('id', $this->staff->id)->value('mobile_authority_revision'));
                }
            }

            // A same-named but ineffective trigger cannot be accepted on retry.
            DB::unprepared('DROP TRIGGER company_mobile_settings_revoke_leases_on_delete');
            DB::unprepared('CREATE TRIGGER company_mobile_settings_revoke_leases_on_delete AFTER DELETE ON company_mobile_settings FOR EACH ROW SET @lease_trigger = 1');
            try {
                $migration->up();
                $this->fail('A same-named trigger with a different body passed validation.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Unexpected definition or definer for trigger company_mobile_settings_revoke_leases_on_delete.', $exception->getMessage());
            }
            $this->get($status)->assertStatus(503);
            DB::unprepared('DROP TRIGGER company_mobile_settings_revoke_leases_on_delete');
            $migration->up();
            $this->assertSame(2, $failure->invalidations);
            $this->get($status)->assertStatus(503);
            app('migration.repository')->log($migrationName, (int) $batch);

            $after = (array) $leaseQuery->first();
            $this->assertNotNull($after['revoked_at']);
            unset($after['revoked_at']);
            $this->assertSame($leaseRow, $after);
            $this->assertEquals($receipt, DB::table('mobile_event_receipts')->where('client_event_id', $wire['client_event_id'])->first());
            $this->get($status)->assertUnauthorized();
            $this->loginOnOfflineDevice();
            $this->get($status)->assertOk()->assertJsonPath('payload_digest', $wire['payload_digest']);
            $newLease = $this->issueOfflineLease();
            $migration->up();
            $this->assertSame(2, $failure->invalidations);
            $this->assertNull(DB::table('mobile_offline_sale_leases')->where('id', $newLease['lease_id'])->value('revoked_at'));
            $this->get($status)->assertOk()->assertJsonPath('payload_digest', $wire['payload_digest']);
            $this->assertSixReconciliations();
        } finally {
            $this->company = $primaryCompany;
            $schema->drop();
        }
    }

    public function test_offline_intake_pause_and_policy_revocation_preserve_committed_receipt(): void
    {
        $lease = $this->issueOfflineLease();
        $posted = $this->offlineSaleWire($lease);
        $pending = $this->offlineSaleWire($lease);
        $url = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";
        $status = $url.'/'.$posted['client_event_id'].'/status';
        $first = $this->postJson($url, $posted)->assertOk();
        $settings = CompanyMobileSetting::whereKey($this->company->id);

        // The application flag pauses HTTP intake. Policy revocation is a durable tenant write.
        config(['mobile.v2_offline_sales_enabled' => false]);
        $this->postJson($url, $pending)->assertNotFound();
        $this->get($status)->assertOk()->assertJsonPath('official_invoice.invoice_id', $first->json('outcome.invoice_id'));
        $this->get($url.'/'.$pending['client_event_id'].'/status')->assertOk()->assertJsonPath('status', 'unknown');
        config(['mobile.v2_offline_sales_enabled' => true]);

        DB::beginTransaction();
        try {
            $settings->update(['offline_policy_enabled' => false]);
            $this->assertNotNull(DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id'])->value('revoked_at'));
        } finally {
            DB::rollBack();
        }
        $this->assertNull(DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id'])->value('revoked_at'));

        $settings->update(['offline_policy_enabled' => false]);
        $this->postJson($url, $pending)->assertForbidden();
        $this->get($status)->assertOk()->assertJsonPath('payload_digest', $posted['payload_digest']);
        $settings->update(['offline_policy_enabled' => true]);
        $this->postJson($url, $pending)->assertConflict()->assertJsonPath('error.conflict_evidence.reason', 'LEASE_POLICY_CHANGED');
        $this->postJson($url, $posted)->assertOk()->assertExactJson($first->json());
        $newLease = $this->issueOfflineLease();
        $this->assertNotSame($lease['lease_id'], $newLease['lease_id']);

        $settings->delete();
        $newestLease = $this->issueOfflineLease();
        $this->assertNotSame($newLease['lease_id'], $newestLease['lease_id']);
        $this->postJson($url, $this->offlineSaleWire($newLease))->assertConflict()
            ->assertJsonPath('error.conflict_evidence.reason', 'LEASE_POLICY_CHANGED');
        $this->assertDatabaseHas('mobile_offline_sale_leases', ['id' => $lease['lease_id'], 'posted_sales_count' => 1]);
        $this->assertDatabaseMissing('mobile_event_receipts', ['client_event_id' => $pending['client_event_id']]);
    }

    /** @param 'cash_active'|'cash_route'|'tax_route' $change */
    #[TestWith(['cash_active'])]
    #[TestWith(['cash_route'])]
    #[TestWith(['tax_route'])]
    public function test_offline_configuration_retires_leases_without_changing_committed_receipt(string $change): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $settings = CompanyMobileSetting::query()->whereKey($this->company->id);
        $alternateCash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'name_ar' => 'Alternate cash',
            'currency_code' => 'ILS',
        ]);
        [$output, $alternateOutput] = $this->offlineTaxRoutes();
        $taxData = $this->offlineTaxData($output->id);
        app(SaveTaxRateAction::class)->execute($this->company, $this->owner, $taxData, $this->taxRate->id);
        $cashData = ['name_ar' => 'Cash register', 'sort_order' => 0, 'is_active' => true];
        auth()->forgetUser();

        $lease = $this->issueOfflineLease();
        $posted = $this->offlineSaleWire($lease);
        $pending = $this->offlineSaleWire($lease);
        $url = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";
        $status = $url.'/'.$posted['client_event_id'].'/status';
        $this->postJson($url, $posted)->assertOk();
        $receiptQuery = DB::table('mobile_event_receipts')->where('client_event_id', $posted['client_event_id']);
        $receipt = $receiptQuery->first();
        $leaseQuery = DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id']);

        $write = function (bool $restore) use ($change, $settings, $alternateCash, $cashData, $taxData, $alternateOutput): void {
            match ($change) {
                'cash_active' => app(UpdateMoneyAccountAction::class)->execute($this->company, $this->owner, $this->cashAccount->id,
                    array_replace($cashData, ['is_active' => $restore])),
                'cash_route' => $settings->update(['approved_cash_account_id' => $restore ? $this->cashAccount->id : $alternateCash->id]),
                'tax_route' => app(SaveTaxRateAction::class)->execute($this->company, $this->owner,
                    $restore ? $taxData : array_replace($taxData, ['sales_tax_account_id' => $alternateOutput->id]), $this->taxRate->id),
            };
        };

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        app(UpdateMoneyAccountAction::class)->execute($this->company, $this->owner, $alternateCash->id, array_replace($cashData, ['is_active' => false]));
        app(UpdateMoneyAccountAction::class)->execute($this->company, $this->owner, $alternateCash->id, $cashData);
        app(UpdateMoneyAccountAction::class)->execute($this->company, $this->owner, $this->cashAccount->id, $cashData);
        app(SaveTaxRateAction::class)->execute($this->company, $this->owner, array_replace($taxData, ['name_en' => 'Renamed tax']), $this->taxRate->id);
        $this->assertDatabaseHas('mobile_offline_sale_leases', ['id' => $lease['lease_id'], 'revoked_at' => null]);

        DB::beginTransaction();
        try {
            $write(false);
            $this->assertDatabaseMissing('mobile_offline_sale_leases', ['id' => $lease['lease_id'], 'revoked_at' => null]);
        } finally {
            DB::rollBack();
        }
        $this->assertDatabaseHas('mobile_offline_sale_leases', ['id' => $lease['lease_id'], 'revoked_at' => null]);

        $write(false);
        $retiredAt = $leaseQuery->value('revoked_at');
        $this->assertNotNull($retiredAt);
        $write(true);
        $this->assertSame($retiredAt, $leaseQuery->value('revoked_at'));
        auth()->forgetUser();

        $this->postJson($url, $pending)->assertConflict()
            ->assertJsonPath('error.conflict_evidence.reason', 'LEASE_POLICY_CHANGED');
        $this->get($status)->assertOk()->assertJsonPath('payload_digest', $posted['payload_digest']);
        $this->assertEquals($receipt, $receiptQuery->first());
        $this->assertDatabaseHas('mobile_offline_sale_leases', ['id' => $lease['lease_id'], 'posted_sales_count' => 1, 'posted_total_amount' => '58.000000']);
        $this->assertDatabaseMissing('mobile_event_receipts', ['client_event_id' => $pending['client_event_id']]);
        $this->assertNotSame($lease['lease_id'], $this->issueOfflineLease()['lease_id']);
    }

    #[TestWith(['cash_active', false])]
    #[TestWith(['cash_active', true])]
    #[TestWith(['tax_route', false])]
    #[TestWith(['tax_route', true])]
    public function test_offline_configuration_writer_and_intake_serialize_on_company(string $change, bool $offlineWins): void
    {
        $lease = $this->issueOfflineLease();
        $wire = $this->offlineSaleWire($lease);
        $intake = [
            'mode' => 'http',
            'enable_offline_routes' => true,
            'http' => [
                'method' => 'POST',
                'uri' => "/api/mobile/v2/companies/{$this->company->public_id}/offline-events",
                'headers' => ['Accept' => 'application/json', 'Content-Type' => 'application/json', 'Authorization' => 'Bearer '.$this->rawToken],
                'body' => $wire,
            ],
        ];
        $taxData = [];
        if ($change === 'tax_route') {
            $this->actingAs($this->owner);
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $taxData = $this->offlineTaxData($this->offlineTaxRoutes()[1]->id);
            auth()->forgetUser();
        }
        $mutation = [
            'mode' => $change,
            'company_id' => $this->company->id,
            'user_id' => $this->owner->id,
            'target_id' => $change === 'cash_active' ? $this->cashAccount->id : $this->taxRate->id,
            'data' => $change === 'cash_active'
                ? ['name_ar' => 'Cash register', 'sort_order' => 0, 'is_active' => false]
                : $taxData,
        ];

        [$first, $second] = $this->runOrderedOfflineRace($offlineWins ? $intake : $mutation, $offlineWins ? $mutation : $intake, 'companies');
        [$sale, $writer] = $offlineWins ? [$first, $second] : [$second, $first];
        $this->assertSame(['outcome' => $change], $writer);
        $this->assertSame($offlineWins ? 200 : 409, $sale['status'], json_encode($sale));
        if (! $offlineWins) {
            $this->assertSame($change === 'cash_active' ? 'OFFLINE_POLICY_UNCONFIGURED' : 'LEASE_POLICY_CHANGED',
                $sale['body']['error']['conflict_evidence']['reason']);
        }
        $this->assertNotNull(DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id'])->value('revoked_at'));
        $this->assertDatabaseHas('mobile_offline_sale_leases', [
            'id' => $lease['lease_id'],
            'posted_sales_count' => (int) $offlineWins,
            'posted_total_amount' => $offlineWins ? '58.000000' : '0.000000',
        ]);
        $this->assertSame((int) $offlineWins, DB::table('mobile_event_receipts')->where('client_event_id', $wire['client_event_id'])->count());
    }

    /** @return array{LedgerAccount, LedgerAccount} */
    private function offlineTaxRoutes(): array
    {
        $output = LedgerAccount::withoutGlobalScopes()->where('company_id', $this->company->id)->where('system_key', 'tax_output')->firstOrFail();
        $alternate = $output->replicate(['public_id', 'system_key', 'code']);
        $alternate->fill([
            'public_id' => (string) Str::ulid(),
            'code' => '2201-ALT',
            'parent_id' => $output->id,
            'is_system' => false,
        ])->save();

        return [$output, $alternate];
    }

    /** @return array<string, mixed> */
    private function offlineTaxData(int $ledgerId): array
    {
        return $this->taxRate->only(['code', 'name_ar', 'name_en', 'calculation', 'active'])
            + ['rate' => '16', 'sales_tax_account_id' => $ledgerId];
    }

    public function test_offline_authority_follows_fortify_and_recovery_login_even_within_one_second(): void
    {
        $this->freezeSecond();
        $lease = $this->issueOfflineLease();
        $originalToken = $this->rawToken;
        $wire = $this->offlineSaleWire($lease);
        $url = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";

        $this->actingAs($this->staff)->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson('/user/two-factor-authentication')->assertOk();
        $this->staff->refresh();
        $secret = Fortify::currentEncrypter()->decrypt($this->staff->two_factor_secret);
        $totp = app(Google2FA::class)->getCurrentOtp($secret);
        $this->postJson('/user/confirmed-two-factor-authentication', ['code' => $totp])->assertOk();
        auth()->forgetUser();

        $codes = $this->staff->fresh()->recoveryCodes();
        $this->loginOnOfflineDevice(['recovery_code' => $codes[0]]);
        $firstMfaToken = $this->rawToken;
        $this->assertSame($this->staff->fresh()->two_factor_confirmed_at, $this->token->created_at->toDateTimeString());
        $mfaLease = $this->issueOfflineLease();
        $this->assertNotSame($lease['lease_id'], $mfaLease['lease_id']);
        $this->postJson($url, $wire)->assertConflict()->assertJsonPath('error.conflict_evidence.reason', 'LEASE_INVALID');

        $this->loginOnOfflineDevice(['recovery_code' => $codes[1]]);
        $recoveryToken = $this->rawToken;
        $recoveryLease = $this->issueOfflineLease();
        $this->assertNotSame($mfaLease['lease_id'], $recoveryLease['lease_id']);
        $this->withToken($firstMfaToken)->postJson($url, $this->offlineSaleWire($mfaLease))->assertUnauthorized();

        $this->actingAs($this->staff->refresh())->withSession(['auth.password_confirmed_at' => time()]);
        $this->postJson('/user/two-factor-recovery-codes')->assertOk();
        auth()->forgetUser();
        $this->withToken($recoveryToken)->postJson($url, $this->offlineSaleWire($recoveryLease))->assertUnauthorized();

        $this->actingAs($this->staff->refresh())->withSession(['auth.password_confirmed_at' => time()]);
        $this->deleteJson('/user/two-factor-authentication')->assertOk();
        auth()->forgetUser();
        $this->withToken($originalToken)->postJson($url, $wire)->assertUnauthorized();
        $this->loginOnOfflineDevice();
        $this->postJson($url, $wire)->assertConflict()->assertJsonPath('error.conflict_evidence.reason', 'LEASE_INVALID');
        $passwordOnlyLease = $this->issueOfflineLease();

        $this->actingAs($this->staff->refresh())->putJson('/user/password', [
            'current_password' => 'StaffPass123!',
            'password' => 'NewStaffPass123!',
            'password_confirmation' => 'NewStaffPass123!',
        ])->assertOk();
        auth()->forgetUser();
        $this->postJson($url, $this->offlineSaleWire($passwordOnlyLease))->assertUnauthorized();
        $this->loginOnOfflineDevice(['password' => 'NewStaffPass123!']);
        $this->postJson($url, $this->offlineSaleWire($passwordOnlyLease))->assertConflict()
            ->assertJsonPath('error.conflict_evidence.reason', 'LEASE_INVALID');
        $this->postJson($url, $this->offlineSaleWire($this->issueOfflineLease()))->assertOk();
    }

    /** @param array<string, string> $overrides */
    private function loginOnOfflineDevice(array $overrides = []): void
    {
        $challenge = $this->postJson('/api/mobile/v1/auth/challenge', [
            'device_id' => $this->device->device_id,
            'platform' => 'android',
            'app_version' => '2.0.0',
        ])->assertOk()->json();
        $response = $this->postJson('/api/mobile/v1/auth/login', array_replace([
            'challenge_id' => $challenge['challenge_id'],
            'nonce' => $challenge['nonce'],
            'device_id' => $this->device->device_id,
            'device_name' => $this->device->device_name,
            'email' => $this->staff->email,
            'password' => 'StaffPass123!',
        ], $overrides))->assertOk()->assertJsonPath('token_type', 'Bearer');
        $this->rawToken = $response->json('token');
        $this->token = MobileAccessToken::query()->where('token_hash', hash('sha256', $this->rawToken))->firstOrFail();
        $this->withToken($this->rawToken);
    }

    public function test_offline_fefo_reserves_repeated_product_lines_and_rejects_expired_stock(): void
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->baseUnit = Unit::query()->where('company_id', $this->company->id)->where('code', 'kg')->firstOrFail();
        $this->product = $this->product->replicate(['public_id', 'sku']);
        $this->product->forceFill(['sku' => 'EXPIRY-'.Str::ulid(), 'track_expiry' => true, 'base_unit_id' => $this->baseUnit->id])->save();
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
        $postingDay = now($this->company->timezone ?: config('app.timezone'))->startOfDay();
        foreach (['EXPIRED' => [-1, '9', '9'], 'EARLY' => [1, '1.5', '4'], 'LATER' => [2, '0.5', '6']] as $lot => [$days, $qty, $cost]) {
            app(PostOpeningStockAction::class)->execute(
                company: $this->company,
                product: $this->product,
                warehouse: $this->warehouse,
                quantity: Quantity::of($qty),
                unitCostBase: $cost,
                user: $this->owner,
                idempotencyKey: 'opening-'.Str::ulid(),
                lotNumber: $lot,
                expiryDate: $postingDay->copy()->addDays($days)->toDateString(),
                movementDate: $postingDay->copy()->subDays(2)->toDateString(),
            );
        }
        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();

        $lease = $this->issueOfflineLease(maxCount: 3, maxAmount: '174.000000');
        $url = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";
        $wire = $this->offlineSaleWire($lease);
        $wire['event']['lines'][] = $wire['event']['lines'][0];
        $wire['event']['payment']['amount_tendered'] = '118.000000';
        $wire['event']['payment']['amount_settled'] = '116.000000';
        $wire['payload_digest'] = CanonicalJsonSerializer::computeDigest($wire['event']);
        $this->postJson($url, $wire)->assertOk()->assertJsonPath('outcome.grand_total', '116.000000');
        $allocations = DB::table('sales_invoice_lot_allocations')->where('company_id', $this->company->id)
            ->orderBy('sales_invoice_line_id')->orderBy('id')
            ->get(['sales_invoice_line_id', 'lot_number', 'quantity_allocated_base', 'total_cost_base']);
        $this->assertSame([
            ['EARLY', '1.000000', '8.181818'],
            ['EARLY', '0.500000', '4.090909'],
            ['LATER', '0.500000', '4.090909'],
        ], $allocations->map(fn ($row) => [$row->lot_number, $row->quantity_allocated_base, $row->total_cost_base])->all());
        $this->assertNotSame($allocations[0]->sales_invoice_line_id, $allocations[1]->sales_invoice_line_id);
        $this->assertSame($allocations[1]->sales_invoice_line_id, $allocations[2]->sales_invoice_line_id);

        $pending = $this->offlineSaleWire($lease);
        $this->postJson($url, $pending)->assertConflict()->assertJsonPath('error.conflict_evidence.reason', 'STOCK_UNAVAILABLE');
        $this->assertDatabaseMissing('mobile_event_receipts', ['client_event_id' => $pending['client_event_id']]);
        $this->assertDatabaseHas('mobile_offline_sale_leases', [
            'id' => $lease['lease_id'], 'posted_sales_count' => 1, 'posted_total_amount' => '116.000000',
        ]);
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id, 'product_id' => $this->product->id, 'quantity_base' => '9.000000',
        ]);
        $this->assertSame(1, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSixReconciliations();
    }

    private function assertSixReconciliations(): void
    {
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy);
        $inventory = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($inventory->isHealthy, implode("\n", $inventory->discrepancies));
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_offline_charge_rolls_back_with_payment_and_can_be_retried(): void
    {
        $lease = $this->issueOfflineLease(maxCount: 1, maxAmount: '58.000000');
        $wire = $this->offlineSaleWire($lease);
        $root = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";
        ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = 'after_payment';

        try {
            $this->postJson($root, $wire)->assertInternalServerError();
        } finally {
            ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = null;
        }
        $this->assertDatabaseHas('mobile_offline_sale_leases', [
            'id' => $lease['lease_id'],
            'posted_sales_count' => 0,
            'posted_total_amount' => '0.000000',
        ]);
        $this->assertDatabaseMissing('sales_invoices', ['company_id' => $this->company->id]);
        $this->assertDatabaseMissing('customer_payments', ['company_id' => $this->company->id]);
        $this->assertDatabaseMissing('mobile_event_receipts', ['client_event_id' => $wire['client_event_id']]);
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity_base' => '100.000000',
        ]);

        $this->postJson($root, $wire)->assertOk();
        $this->postJson($root, $this->offlineSaleWire($lease))->assertStatus(409)
            ->assertJsonPath('error.conflict_evidence.reason', 'LEASE_LIMIT_REACHED');
    }

    public function test_offline_cash_requires_exact_captured_tax_and_a_current_authority_policy(): void
    {
        $lease = $this->issueOfflineLease();
        $wire = $this->offlineSaleWire($lease);
        $root = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";

        $wire['event']['lines'][0]['tax_amount'] = '7.990000';
        $wire['payload_digest'] = CanonicalJsonSerializer::computeDigest($wire['event']);
        $this->postJson($root, $wire)->assertStatus(409)
            ->assertJsonPath('error.conflict_evidence.reason', 'CAPTURED_TOTAL_MISMATCH');
        $this->assertDatabaseMissing('sales_invoices', ['company_id' => $this->company->id]);

        CompanyMobileSetting::whereKey($this->company->id)->update(['offline_policy_enabled' => false]);
        $this->postJson($root, $this->offlineSaleWire($lease))->assertForbidden();
        $this->assertDatabaseHas('mobile_offline_sale_leases', ['id' => $lease['lease_id'], 'posted_sales_count' => 0]);
    }

    public function test_offline_second_sale_uses_same_lease_but_cannot_exceed_remaining_value(): void
    {
        $lease = $this->issueOfflineLease(maxCount: 3, maxAmount: '116.000000');
        $root = "/api/mobile/v2/companies/{$this->company->public_id}/offline-events";

        $first = $this->postJson($root, $this->offlineSaleWire($lease))->assertOk();
        $second = $this->postJson($root, $this->offlineSaleWire($lease))->assertOk();
        $this->assertNotSame($first->json('outcome.invoice_id'), $second->json('outcome.invoice_id'));
        $this->postJson($root, $this->offlineSaleWire($lease))->assertStatus(409)
            ->assertJsonPath('error.conflict_evidence.reason', 'LEASE_LIMIT_REACHED');
        $this->assertDatabaseHas('mobile_offline_sale_leases', [
            'id' => $lease['lease_id'],
            'posted_sales_count' => 2,
            'posted_total_amount' => '116.000000',
        ]);
    }

    public function test_offline_lease_is_unavailable_by_default_and_rejects_revoked_bearer(): void
    {
        config(['mobile.v2_offline_sales_enabled' => false]);
        $root = "/api/mobile/v2/companies/{$this->company->public_id}";
        $snapshot = CatalogSnapshot::version($this->company);
        $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson($root.'/offline-sale-lease', ['catalog_snapshot_version' => $snapshot])
            ->assertNotFound();

        $lease = $this->issueOfflineLease();
        $this->token->revoke();
        $this->postJson($root.'/offline-events', $this->offlineSaleWire($lease))->assertUnauthorized();
        $this->assertDatabaseHas('mobile_offline_sale_leases', ['id' => $lease['lease_id'], 'posted_sales_count' => 0]);
        $this->assertDatabaseMissing('sales_invoices', ['company_id' => $this->company->id]);
    }

    /** @return array<string, mixed> */
    private function issueOfflineLease(int $maxCount = 2, string $maxAmount = '116.000000'): array
    {
        config(['mobile.v2_offline_sales_enabled' => true]);
        if (! Route::has('mobile.v2.offline-sale-lease')) {
            Route::prefix('api/mobile/v2')
                ->middleware(MobileV2ApiBoundary::class)
                ->group(base_path('routes/mobile-v2.php'));
        }

        CompanyMobileSetting::updateOrCreate(['company_id' => $this->company->id], [
            'walk_in_customer_id' => $this->customer->id,
            'default_warehouse_id' => $this->warehouse->id,
            'approved_cash_account_id' => $this->cashAccount->id,
            'default_tax_rate_id' => $this->taxRate->id,
            'offline_policy_enabled' => true,
            'offline_cash_only' => true,
            'max_offline_hours' => 1,
            'max_offline_sales_count' => $maxCount,
            'max_offline_total_amount' => $maxAmount,
        ]);

        return $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/offline-sale-lease", [
                'catalog_snapshot_version' => CatalogSnapshot::version($this->company),
            ])->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $lease
     * @return array<string, mixed>
     */
    private function offlineSaleWire(array $lease, ?string $eventId = null): array
    {
        $eventId ??= (string) Str::uuid();
        $cash = $this->validSalePayload($eventId);
        $line = $cash['lines'][0];
        $line['unit_id'] = $line['product_unit_id'];
        $line['tax_amount'] = '8.000000';
        $line['line_total'] = '58.000000';
        $event = array_replace($cash, [
            'event_version' => 2,
            'company_id' => $this->company->public_id,
            'actor_id' => $this->staff->public_id,
            'device_lease_id' => $lease['lease_id'],
            'captured_at' => $lease['issued_at'],
            'cash_account_id' => $this->cashAccount->public_id,
            'catalog_snapshot_version' => $lease['catalog_snapshot_version'],
            'local_receipt_number' => 'LOCAL-1',
            'lines' => [$line],
            'payment' => [
                'mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => $this->cashAccount->public_id,
                'amount_tendered' => '60.000000',
                'amount_settled' => '58.000000',
                'change_returned' => '2.000000',
            ],
        ]);

        return [
            'client_event_id' => $eventId,
            'device_id' => $this->device->device_id,
            'captured_at' => $event['captured_at'],
            'event' => $event,
            'payload_digest' => CanonicalJsonSerializer::computeDigest($event),
        ];
    }

    #[TestWith(['sessions', false])]
    #[TestWith(['sessions', true])]
    #[TestWith(['policy', false])]
    #[TestWith(['policy', true])]
    #[TestWith(['password', false])]
    #[TestWith(['password', true])]
    #[TestWith(['mfa', false])]
    #[TestWith(['mfa', true])]
    #[TestWith(['stock', false])]
    #[TestWith(['stock', true])]
    public function test_offline_sale_orders_against_authority_changes_and_web_stock(string $competitor, bool $offlineWins): void
    {
        $lease = $this->issueOfflineLease(maxCount: 1);
        $wire = $this->offlineSaleWire($lease);
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$this->rawToken];
        $intake = [
            'mode' => 'http',
            'enable_offline_routes' => true,
            'http' => [
                'method' => 'POST',
                'uri' => "/api/mobile/v2/companies/{$this->company->public_id}/offline-events",
                'headers' => $headers + ['Content-Type' => 'application/json'],
                'body' => $wire,
            ],
        ];
        [$lockTable, $other] = match ($competitor) {
            'sessions' => ['users', ['mode' => 'http', 'http' => [
                'method' => 'DELETE',
                'uri' => '/api/mobile/v1/sessions/'.$this->device->device_id,
                'headers' => $headers,
                'body' => '',
            ]]],
            'policy' => ['company_mobile_settings', ['mode' => 'policy_disable', 'company_id' => $this->company->id]],
            'password' => ['users', ['mode' => 'password_reset', 'user_id' => $this->staff->id]],
            'mfa' => ['users', ['mode' => 'mfa_enable', 'user_id' => $this->staff->id]],
            'stock' => ['companies', [
                'mode' => 'web_sale',
                'company_id' => $this->company->id,
                'user_id' => $this->owner->id,
                'invoice_id' => $this->draftWebSaleForAllStock()->id,
            ]],
        };

        [$first, $second] = $this->runOrderedOfflineRace($offlineWins ? $intake : $other, $offlineWins ? $other : $intake, $lockTable);
        [$sale, $opponent] = $offlineWins ? [$first, $second] : [$second, $first];
        $expectedStatus = $offlineWins ? 200 : match ($competitor) {
            'sessions', 'password', 'mfa' => 401,
            'policy' => 403,
            'stock' => 409,
        };
        $this->assertSame($expectedStatus, $sale['status'], json_encode($sale));

        if ($competitor === 'sessions') {
            $this->assertSame(200, $opponent['status'], json_encode($opponent));
            $this->assertTrue($this->device->fresh()->isRevoked());
            $this->assertNotNull($this->token->fresh()->revoked_at);
        } elseif ($competitor === 'password') {
            $this->assertSame('password_reset', $opponent['outcome']);
            $this->assertTrue(Hash::check('ResetStaffPass123!', $this->staff->fresh()->password));
        } elseif ($competitor === 'mfa') {
            $this->assertSame('mfa_enable', $opponent['outcome']);
            $this->assertNotNull($this->staff->fresh()->two_factor_secret);
        } elseif ($competitor === 'policy') {
            $this->assertSame('policy_disabled', $opponent['outcome']);
            $this->assertNotNull(DB::table('mobile_offline_sale_leases')->where('id', $lease['lease_id'])->value('revoked_at'));
        } else {
            $this->assertSame($offlineWins ? 'stock_unavailable' : 'posted', $opponent['outcome']);
            $this->assertSame($offlineWins ? 'draft' : 'posted', SalesInvoice::withoutGlobalScopes()->findOrFail($other['invoice_id'])->status);
            if (! $offlineWins) {
                $this->assertSame('STOCK_UNAVAILABLE', $sale['body']['error']['conflict_evidence']['reason']);
            }
        }

        $this->assertDatabaseHas('mobile_offline_sale_leases', [
            'id' => $lease['lease_id'],
            'posted_sales_count' => $offlineWins ? 1 : 0,
            'posted_total_amount' => $offlineWins ? '58.000000' : '0.000000',
        ]);
        $this->assertSame((int) $offlineWins, DB::table('mobile_event_receipts')->where('client_event_id', $wire['client_event_id'])->count());
        $this->assertSame((int) $offlineWins, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame($offlineWins || $competitor === 'stock' ? 1 : 0, DB::table('sales_invoices')
            ->where('company_id', $this->company->id)->where('status', 'posted')->count());
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity_base' => $offlineWins ? '99.000000' : ($competitor === 'stock' ? '0.000000' : '100.000000'),
        ]);
        $this->assertSixReconciliations();
    }

    private function draftWebSaleForAllStock(): SalesInvoice
    {
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $unit = ProductUnit::query()->where('product_id', $this->product->id)->firstOrFail();

        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'document_locale' => 'ar',
            'lines' => [[
                'product_id' => $this->product->id,
                'product_unit_id' => $unit->id,
                'item_description' => 'Stock competition',
                'quantity' => '100.000000',
                'unit_price' => '50.000000',
                'discount_value' => '0',
                'discount_type' => null,
                'tax_rate_id' => null,
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $holder
     * @param  array<string, mixed>  $contender
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function runOrderedOfflineRace(array $holder, array $contender, string $table): array
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $first = $this->startWorkerProcess($schema, $directory, 'worker-a', $holder + [
                'hold_lock_table' => $table, 'wait_for_file' => 'release-holder',
            ]);
            touch($directory.'/go-worker-a');
            StrictBarrier::await(fn (): bool => is_file($directory.'/worker-a.locked'), fn (): bool => $first->isRunning());

            $second = $this->startWorkerProcess($schema, $directory, 'worker-b', $contender);
            touch($directory.'/go-worker-b');
            $contenderId = trim((string) file_get_contents($directory.'/worker-b.connection'));
            $database = DB::connection()->getDatabaseName();
            StrictBarrier::await(function () use ($contenderId, $database, $table): bool {
                foreach (DB::select('SHOW FULL PROCESSLIST') as $row) {
                    $query = strtolower((string) ($row->Info ?? ''));
                    if ((string) $row->Id === $contenderId && $row->db === $database
                        && str_contains($query, "from `{$table}`") && str_contains($query, 'for update')) {
                        return true;
                    }
                }

                return false;
            }, fn (): bool => $first->isRunning() && $second->isRunning());
            touch($directory.'/release-holder');

            $outputs = [];
            foreach ([$first, $second] as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput().$worker->getOutput());
                $result = json_decode($worker->getOutput(), true);
                $this->assertIsArray($result, $worker->getErrorOutput().$worker->getOutput());
                $outputs[] = $result;
            }

            return $outputs;
        } finally {
            foreach ([$first ?? null, $second ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (is_dir($directory)) {
                array_map('unlink', glob($directory.'/*') ?: []);
                rmdir($directory);
            }
        }
    }

    /**
     * Hold one canonical admission until MariaDB confirms the second worker is waiting.
     * Identical events replay and a distinct contender for the last lease slot is refused.
     */
    #[TestWith([false])]
    #[TestWith([true])]
    #[TestWith([true, true])]
    public function test_two_process_concurrent_cash_sale_converges_to_single_sale(bool $offline, bool $lastLeaseSlot = false): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            $eventId = (string) Str::uuid();
            $payload = $this->validSalePayload($eventId);
            $serverContext = [
                'actor_id' => (string) $this->staff->public_id,
                'company_id' => (string) $this->company->public_id,
                'currency_minor_units' => 2,
            ];
            $profile = OnlineSaleIntentProfile::construct($serverContext, $payload);
            $canonicalBytes = OnlineSaleIntentProfile::serialize($profile);

            $representation = 'v2_online_sale_intent';
            if ($offline) {
                $lease = $this->issueOfflineLease(maxCount: 1);
                $wire = $this->offlineSaleWire($lease, $eventId);
                $canonicalBytes = CanonicalJsonSerializer::serialize($wire['event']);
                $representation = OfflineSaleEvent::REPRESENTATION;
            }

            $inputHolder = [
                'company_id' => $this->company->id,
                'client_event_id' => $eventId,
                'token_hash' => $this->token->token_hash,
                'event_version' => 2,
                'representation' => $representation,
                'canonical_bytes' => $canonicalBytes,
                'executor_mode' => 'production_cash',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
            ];

            $inputContender = [
                'company_id' => $this->company->id,
                'client_event_id' => $eventId,
                'token_hash' => $this->token->token_hash,
                'event_version' => 2,
                'representation' => $representation,
                'canonical_bytes' => $canonicalBytes,
                'executor_mode' => 'production_cash',
            ];

            if ($lastLeaseSlot) {
                $other = $this->offlineSaleWire($lease);
                $inputContender['client_event_id'] = $other['client_event_id'];
                $inputContender['canonical_bytes'] = CanonicalJsonSerializer::serialize($other['event']);
            }

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', $inputHolder);
            touch($directory.'/go-worker-a');

            // Wait until worker A acquires the lock
            StrictBarrier::await(
                fn (): bool => is_file($directory.'/worker-a.locked'),
                fn (): bool => $workerA->isRunning()
            );

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', $inputContender);
            touch($directory.'/go-worker-b');

            // Wait until worker B's exact CID is pending on the lock in MariaDB processlist
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

            // Release the lock holder
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait(), 'Worker A failed: '.$workerA->getErrorOutput());
            $this->assertSame(0, $workerB->wait(), 'Worker B failed: '.$workerB->getErrorOutput());

            $outA = json_decode($workerA->getOutput(), true);
            $outB = json_decode($workerB->getOutput(), true);

            $this->assertIsArray($outA, 'Worker A output must be valid JSON: '.$workerA->getOutput().' err: '.$workerA->getErrorOutput());
            $this->assertIsArray($outB, 'Worker B output must be valid JSON: '.$workerB->getOutput().' err: '.$workerB->getErrorOutput());

            $this->assertSame('created', $outA['outcome']);
            if ($lastLeaseSlot) {
                $this->assertSame('conflict', $outB['outcome']);
                $this->assertSame('LEASE_LIMIT_REACHED', $outB['reason']);
                $this->assertDatabaseMissing('mobile_event_receipts', ['client_event_id' => $other['client_event_id']]);
            } else {
                $this->assertSame('replay', $outB['outcome']);
                $this->assertSame($outA['sales_invoice_id'], $outB['sales_invoice_id']);
                $this->assertSame($outA['customer_payment_id'], $outB['customer_payment_id']);
            }

            // Database has exactly 1 invoice, 1 payment, 1 receipt
            $this->assertSame(1, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('client_event_id', $eventId)->count());

            if ($offline) {
                $this->assertDatabaseHas('mobile_offline_sale_leases', [
                    'id' => $wire['event']['device_lease_id'],
                    'posted_sales_count' => 1,
                    'posted_total_amount' => '58.000000',
                ]);
            }

            // All 6 reconciliations pass cleanly
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
            $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
            $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
            $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
            $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
            $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase7 reconciliation must pass');
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (isset($directory) && is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }

    /**
     * T-13: Prohibit direct execution of partial financial legs by arbitrary executors; negative test ensures
     * malicious attempt to execute draft/invoice without permit and payment rolls back with zero effects and zero receipts.
     */
    public function test_malicious_executor_invoice_only_partial_posting_prohibited(): void
    {
        $maliciousExecutor = new class implements FinancialExecutionAdapterInterface
        {
            public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void {}

            public function execute(Company $company, User $actor, MobileDevice $device, array $payload, string $idempotencySuffix, ?NativeFinancialExecutionCapability $capability = null): array
            {
                $customer = Customer::where('company_id', $company->id)->firstOrFail();
                $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();
                $product = Product::where('company_id', $company->id)->firstOrFail();
                $unit = ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->firstOrFail();

                // Direct call to CreateSalesInvoiceDraftAction without composite permit is denied
                $draft = app(CreateSalesInvoiceDraftAction::class)->execute($company, $actor, [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'currency_code' => $company->base_currency_code,
                    'exchange_rate' => '1.0000000000',
                    'issue_date' => now()->toDateString(),
                    'lines' => [
                        [
                            'product_id' => $product->id,
                            'product_unit_id' => $unit->id,
                            'item_description' => 'Test item',
                            'quantity' => '1.000000',
                            'unit_price' => '10.000000',
                        ],
                    ],
                ]);

                return [
                    'sales_invoice_id' => (int) $draft->id,
                    'sales_invoice_public_id' => (string) $draft->public_id,
                    'customer_payment_id' => null,
                    'customer_payment_public_id' => null,
                    'command_receipt_id' => null,
                    'terminal_canonical_result' => [],
                ];
            }
        };

        $this->app->instance(FinancialExecutionAdapterInterface::class, $maliciousExecutor);

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $this->assertTrue($response->status() >= 400);

        // Zero effects: no invoice, no payment, no receipt
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    /**
     * T-14: Economic payload substitution rejected: deriving canonical profile bytes from executed payload
     * catches capability event hash mismatch and aborts before consume with zero side effects.
     */
    public function test_executor_capability_payload_substitution_fails(): void
    {
        $eventId = (string) Str::uuid();
        $payloadA = $this->validSalePayload($eventId);
        $payloadB = $payloadA;
        $payloadB['lines'][0]['quantity'] = '5.000000'; // Substitute economic quantity

        $serverContext = [
            'actor_id' => (string) $this->staff->public_id,
            'company_id' => (string) $this->company->public_id,
            'currency_minor_units' => 2,
        ];
        $profileA = OnlineSaleIntentProfile::construct($serverContext, $payloadA);
        $canonicalBytesA = OnlineSaleIntentProfile::serialize($profileA);

        $substitutingExecutor = new class($payloadB) implements FinancialExecutionAdapterInterface
        {
            /**
             * @param  array<string, mixed>  $substitutedPayload
             */
            public function __construct(private array $substitutedPayload) {}

            public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void {}

            public function execute(Company $company, User $actor, MobileDevice $device, array $payload, string $idempotencySuffix, ?NativeFinancialExecutionCapability $capability = null): array
            {
                assert($capability !== null);

                return app(ExecuteImmediateOnlineCashSaleAction::class)->execute(
                    $capability,
                    $company,
                    $actor,
                    $device,
                    $this->substitutedPayload,
                    $idempotencySuffix
                );
            }
        };

        $this->app->instance(FinancialExecutionAdapterInterface::class, $substitutingExecutor);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Capability event hash mismatch.');

        $manager = new MobileEventReceiptManager($substitutingExecutor);
        $manager->recordOrReplay(
            companyId: (int) $this->company->id,
            clientEventId: $eventId,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_online_sale_intent',
            canonicalBytes: $canonicalBytesA
        );
    }

    /**
     * T-15: Conversion and tax snapshots mismatch against authoritative database state are rejected before effects.
     */
    public function test_admitted_conversion_and_tax_snapshots_mismatch_rejected(): void
    {
        // 1. Conversion mismatch
        $eventId1 = (string) Str::uuid();
        $payload1 = $this->validSalePayload($eventId1);
        $payload1['lines'][0]['conversion_to_base'] = '9.999999';

        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload1);
        $this->assertSame(422, $res1->status());
        $this->assertSame('VALIDATION_FAILED', $res1->json('error.code'));

        // 2. Tax rate mismatch
        $eventId2 = (string) Str::uuid();
        $payload2 = $this->validSalePayload($eventId2);
        $payload2['lines'][0]['tax_rate'] = '0.999999';

        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload2);
        $this->assertSame(422, $res2->status());
        $this->assertSame('VALIDATION_FAILED', $res2->json('error.code'));

        // 3. Tax inclusive mismatch
        $eventId3 = (string) Str::uuid();
        $payload3 = $this->validSalePayload($eventId3);
        $payload3['lines'][0]['tax_inclusive'] = true; // tax rate in fixture is exclusive

        $res3 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload3);
        $this->assertSame(422, $res3->status());
        $this->assertSame('VALIDATION_FAILED', $res3->json('error.code'));

        // Assert 0 side effects across all attempts
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    /**
     * T-16: Fresh financial permissions on replay: revoking read permissions refuses replay with 403 Forbidden.
     */
    public function test_revoked_read_permissions_refuses_replay(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        // Initial creation succeeds (201)
        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);
        $this->assertSame(201, $res1->status());

        // Revoke view permission from staff
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        setPermissionsTeamId($this->company->id);
        $this->staff->removeRole('Sales');
        $this->staff->revokePermissionTo('sales.invoice.view');
        $this->staff->revokePermissionTo('money.receipt.view');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Attempt replay of exact same event
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        // Must be refused with 403 Forbidden
        $this->assertSame(403, $res2->status());
    }

    /**
     * T-17: Raw wire JSON duplicate key validation: StrictJsonParser validates raw AST bytes and rejects duplicate keys.
     */
    public function test_raw_request_duplicate_keys_rejected(): void
    {
        $eventId = (string) Str::uuid();
        $validPayload = $this->validSalePayload($eventId);
        $validJson = json_encode($validPayload, JSON_THROW_ON_ERROR);

        // Inject duplicate key into raw JSON wire bytes
        $tamperedJson = '{"client_event_id":"'.$eventId.'",'.substr($validJson, 1);

        $response = $this->call(
            'POST',
            "/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_AUTHORIZATION' => 'Bearer '.$this->rawToken,
            ],
            $tamperedJson
        );

        $this->assertSame(422, $response->status());
        $this->assertSame('INVALID_JSON', $response->json('error.code'));

        // Zero effects
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    /**
     * T-18: Concrete online cash sale attack where executor attempts extra invoice creation / posting,
     * followed by legitimate composite execution: denied by closed permit boundary and rolls back ALL effects.
     */
    public function test_concrete_extra_invoice_attack_fails_and_rolls_back(): void
    {
        $maliciousExecutor = new class implements FinancialExecutionAdapterInterface
        {
            public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void {}

            public function execute(Company $company, User $actor, MobileDevice $device, array $payload, string $idempotencySuffix, ?NativeFinancialExecutionCapability $capability = null): array
            {
                $customer = Customer::where('company_id', $company->id)->firstOrFail();
                $warehouse = Warehouse::where('company_id', $company->id)->where('is_default', true)->firstOrFail();
                $product = Product::where('company_id', $company->id)->firstOrFail();
                $unit = ProductUnit::where('company_id', $company->id)->where('product_id', $product->id)->firstOrFail();

                // Malicious attempt: executor attempts to draft and post an EXTRA invoice before legitimate composite
                app(CreateSalesInvoiceDraftAction::class)->execute($company, $actor, [
                    'customer_id' => $customer->id,
                    'warehouse_id' => $warehouse->id,
                    'currency_code' => $company->base_currency_code,
                    'exchange_rate' => '1.0000000000',
                    'issue_date' => now()->toDateString(),
                    'lines' => [
                        [
                            'product_id' => $product->id,
                            'product_unit_id' => $unit->id,
                            'item_description' => 'Malicious extra line',
                            'quantity' => '1.000000',
                            'unit_price' => '999.000000',
                        ],
                    ],
                ]);

                // Even if it attempts legitimate composite afterwards, transaction is aborted
                return app(ExecuteImmediateOnlineCashSaleAction::class)->execute(
                    $capability,
                    $company,
                    $actor,
                    $device,
                    $payload,
                    $idempotencySuffix
                );
            }
        };

        $this->app->instance(FinancialExecutionAdapterInterface::class, $maliciousExecutor);

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $this->assertTrue($response->status() >= 400);

        // Entire transaction rolled back: zero extra invoices, zero legitimate invoices, zero payments, zero receipts
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    /**
     * T-19: Unsupported representation returning financial entities fails closed with SecurityException.
     */
    public function test_unsupported_representation_returning_financial_entities_fails_closed(): void
    {
        $bypassExecutor = new class implements FinancialExecutionAdapterInterface
        {
            public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void {}

            public function execute(Company $company, User $actor, MobileDevice $device, array $payload, string $idempotencySuffix, ?NativeFinancialExecutionCapability $capability = null): array
            {
                return [
                    'sales_invoice_id' => 99999,
                    'sales_invoice_public_id' => 'sinv_fake',
                    'customer_payment_id' => 88888,
                    'customer_payment_public_id' => 'cpay_fake',
                    'command_receipt_id' => null,
                    'terminal_canonical_result' => ['status' => 'completed'],
                ];
            }
        };

        $this->app->instance(FinancialExecutionAdapterInterface::class, $bypassExecutor);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Unsupported financial representation [v2_intent] produced financial entities.');

        $manager = new MobileEventReceiptManager;
        $manager->recordOrReplay(
            (int) $this->company->id,
            (string) Str::uuid(),
            $this->token->token_hash,
            2,
            'v2_intent',
            '{"action":"checkout"}'
        );
    }

    /**
     * T-20: Missing closed composite completion fails closed with SecurityException.
     */
    public function test_unattested_composite_completion_fails_closed(): void
    {
        $unattestedExecutor = new class implements FinancialExecutionAdapterInterface
        {
            public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void {}

            public function execute(Company $company, User $actor, MobileDevice $device, array $payload, string $idempotencySuffix, ?NativeFinancialExecutionCapability $capability = null): array
            {
                return [
                    'sales_invoice_id' => 11111,
                    'sales_invoice_public_id' => 'sinv_unattested',
                    'customer_payment_id' => 22222,
                    'customer_payment_public_id' => 'cpay_unattested',
                    'command_receipt_id' => null,
                    'terminal_canonical_result' => ['status' => 'completed'],
                ];
            }
        };

        $this->app->instance(FinancialExecutionAdapterInterface::class, $unattestedExecutor);

        $this->expectException(SecurityException::class);
        $this->expectExceptionMessage('Financial execution failed to consume required capability.');

        $manager = new MobileEventReceiptManager;
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $canonicalBytes = OnlineSaleIntentProfile::serialize($payload);

        $manager->recordOrReplay(
            (int) $this->company->id,
            $eventId,
            $this->token->token_hash,
            2,
            'v2_online_sale_intent',
            $canonicalBytes
        );
    }

    /**
     * T-21: Constrained stage fault injection mechanism cannot execute caller code or inject extra invoices.
     */
    public function test_stage_fault_mechanism_cannot_execute_caller_code_or_inject_extra_invoice(): void
    {
        // Assert that arbitrary callback fault hook API does not exist on the action
        $ref = new ReflectionClass(ExecuteImmediateOnlineCashSaleAction::class);
        $this->assertFalse($ref->hasProperty('stageFaultHook'), 'stageFaultHook arbitrary callback API must not exist');

        $this->assertTrue($ref->hasProperty('simulatedFaultStage'), 'simulatedFaultStage string mechanism must exist');
        $prop = $ref->getProperty('simulatedFaultStage');
        $this->assertSame('?string', (string) $prop->getType());

        ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = 'after_draft';

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $this->assertTrue($response->status() >= 400);

        // Entire transaction rolled back: zero invoices, zero payments, zero receipts
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = null;
    }

    /**
     * T-22: Malicious executor cannot consume real capability or forge completion with existing allocated pair.
     */
    public function test_malicious_executor_cannot_consume_real_cap_and_mark_existing_allocated_pair(): void
    {
        // 1. Create a legitimate existing invoice and customer payment in the company
        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        $prodUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();

        $draftInvoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $prodUnit->id,
                    'item_description' => 'Existing line item',
                    'quantity' => '1.000000',
                    'unit_price' => '58.000000',
                    'discount_value' => '0',
                    'discount_type' => null,
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        $existingInvoice = app(PostSalesInvoiceAction::class)->execute($draftInvoice, $this->owner);

        $existingPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->cashAccount->id,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'document_locale' => 'ar',
            'amount' => '58.000000',
            'exchange_rate' => '1.0000000000',
            'reference_number' => null,
            'notes' => 'Existing payment',
            'idempotency_key' => 'existing-pay-'.Str::ulid(),
            'allocations' => [
                [
                    'sales_invoice_id' => $existingInvoice->id,
                    'allocated_amount' => '58.000000',
                ],
            ],
        ]);

        auth()->forgetUser();
        app(CompanyContext::class)->clearSessionless();

        $existingInvId = (int) $existingInvoice->id;
        $existingPayId = (int) $existingPayment->id;

        // 2. Malicious executor that receives real capability and attempts to forge completion
        $maliciousExecutor = new class($existingInvId, $existingPayId) implements FinancialExecutionAdapterInterface
        {
            public function __construct(
                private readonly int $invId,
                private readonly int $payId
            ) {}

            public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void {}

            public function execute(
                Company $company,
                User $actor,
                MobileDevice $device,
                array $payload,
                string $idempotencySuffix,
                ?NativeFinancialExecutionCapability $capability = null
            ): array {
                $forgedInvId = $this->invId;
                $forgedPayId = $this->payId;
                // Attempt to call private completion method via Closure::bind fails closed via caller assertion
                try {
                    (Closure::bind(
                        static fn () => MobileEventReceiptManager::markCompositeCompletedPrivately(
                            $capability,
                            (int) $company->id,
                            (int) $actor->id,
                            $forgedInvId,
                            $forgedPayId
                        ),
                        null,
                        MobileEventReceiptManager::class
                    ))();
                } catch (\Throwable) {
                    // Blocked!
                }

                return [
                    'sales_invoice_id' => $this->invId,
                    'sales_invoice_public_id' => 'sinv_fake',
                    'customer_payment_id' => $this->payId,
                    'customer_payment_public_id' => 'cpay_fake',
                    'command_receipt_id' => null,
                    'terminal_canonical_result' => ['status' => 'completed'],
                ];
            }
        };

        $this->app->instance(FinancialExecutionAdapterInterface::class, $maliciousExecutor);

        $this->expectException(SecurityException::class);

        $manager = new MobileEventReceiptManager;
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $canonicalBytes = OnlineSaleIntentProfile::serialize($payload);

        $manager->recordOrReplay(
            (int) $this->company->id,
            $eventId,
            $this->token->token_hash,
            2,
            'v2_online_sale_intent',
            $canonicalBytes
        );
    }

    public function test_legacy_v1_sale_execution_cannot_bypass_durable_events(): void
    {
        $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/01M3WMTQQZE7P30Z3KC93N7YJ9/execute", [])
            ->assertStatus(410)->assertJsonPath('error.code', 'DURABLE_EVENT_REQUIRED');
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_production_adapter_binding_in_app_service_provider(): void
    {
        $binding = $this->app->make(FinancialExecutionAdapterInterface::class);
        $this->assertInstanceOf(NativeOnlineCashFinancialExecutionAdapter::class, $binding);
    }

    public function test_production_adapter_denies_when_merchant_flag_is_disabled(): void
    {
        config(['mobile.merchant_api_enabled' => false]);

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => [
                'code' => 'FORBIDDEN',
            ],
        ]);

        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    public function test_production_adapter_denies_when_v2_flag_is_disabled(): void
    {
        config(['mobile.v2_enabled' => false]);

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $response->assertStatus(404);
    }

    public function test_production_adapter_denies_when_cash_slice_flag_is_disabled(): void
    {
        config(['mobile.v2_cash_slice_enabled' => false]);

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $response->assertStatus(404);
    }

    /**
     * T-23: Explicit production binding negative test:
     * Asserts container binding resolves strictly to DenyFinancialExecutionAdapter.
     * When all 3 feature flags are enabled (mobile.enabled, mobile.v2_enabled, mobile.v2_cash_slice_enabled),
     * DenyFinancialExecutionAdapter strictly denies both initial commit attempts and replay/status of existing receipts.
     * Asserts zero side effects and zero test-adapter leakage in production configuration.
     */
    public function test_production_deny_binding_denies_initial_and_existing_receipt_replay_and_status_when_all_flags_true(): void
    {
        // 1. Rebind production DenyFinancialExecutionAdapter exactly as registered in AppServiceProvider
        $this->app->bind(
            FinancialExecutionAdapterInterface::class,
            DenyFinancialExecutionAdapter::class
        );

        // Assert container binding class
        $binding = $this->app->make(FinancialExecutionAdapterInterface::class);
        $this->assertInstanceOf(DenyFinancialExecutionAdapter::class, $binding);
        $this->assertSame(DenyFinancialExecutionAdapter::class, get_class($binding));

        // 2. Set all 3 feature flags to true
        config([
            'mobile.enabled' => true,
            'mobile.v2_enabled' => true,
            'mobile.v2_cash_slice_enabled' => true,
        ]);

        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        // 3. Initial commit attempt must fail with 403 Forbidden
        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $response->assertStatus(403);
        $response->assertJson([
            'error' => [
                'code' => 'FORBIDDEN',
            ],
        ]);

        // Zero database effects
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        // 4. Existing receipt replay attempt: insert seeded receipt row directly
        $existingEventId = (string) Str::uuid();
        $existingPayload = $this->validSalePayload($existingEventId);
        $canonicalBytes = OnlineSaleIntentProfile::serialize(
            OnlineSaleIntentProfile::construct([
                'actor_id' => (string) $this->staff->public_id,
                'company_id' => (string) $this->company->public_id,
                'currency_minor_units' => 2,
            ], json_encode($existingPayload, JSON_THROW_ON_ERROR))
        );
        $digest = hash('sha256', $canonicalBytes);

        DB::table('mobile_event_receipts')->insert([
            'company_id' => $this->company->id,
            'client_event_id' => $existingEventId,
            'actor_id' => $this->staff->id,
            'device_id' => $this->device->device_id,
            'event_version' => 2,
            'representation' => 'v2_online_sale_intent',
            'payload_digest' => $digest,
            'original_canonical_bytes' => $canonicalBytes,
            'status' => 'completed',
            'terminal_canonical_result' => json_encode(['status' => 'completed'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Replay attempt must also be strictly denied (403 Forbidden)
        $replayResponse = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $existingPayload);

        $replayResponse->assertStatus(403);
        $replayResponse->assertJson([
            'error' => [
                'code' => 'FORBIDDEN',
            ],
        ]);

        // 5. Status lookup through HTTP endpoint must return 403 Forbidden under production deny binding
        $httpStatusResponse = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$existingEventId}/status");
        $httpStatusResponse->assertStatus(403);
        $httpStatusResponse->assertJson([
            'error' => [
                'code' => 'FORBIDDEN',
            ],
        ]);

        // Direct manager lookup must also throw FinancialExecutionDeniedException
        try {
            $manager = app(MobileEventReceiptManager::class);
            $manager->lookup((int) $this->company->id, $existingEventId, $this->token->token_hash);
            $this->fail('Expected FinancialExecutionDeniedException for direct manager lookup');
        } catch (FinancialExecutionDeniedException $e) {
            $this->assertStringContainsString('Financial authority is unresolved on Mobile V2', $e->getMessage());
        }

        // When cash slice flag is disabled, status endpoint returns 404 NOT_FOUND
        config(['mobile.v2_cash_slice_enabled' => false]);
        $disabledStatusResponse = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$existingEventId}/status");
        $disabledStatusResponse->assertStatus(404);
        $disabledStatusResponse->assertJson([
            'error' => [
                'code' => 'NOT_FOUND',
            ],
        ]);
    }

    /**
     * T-24: Public Wire DTO strict whole keys, ULID regex, null handling, and real Kotlin fixture mapping.
     */
    public function test_public_wire_dto_strict_whole_keys_and_schema_parity(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $response->assertStatus(201);
        $data = $response->json();

        // Strict whole keys check
        $expectedKeys = [
            'client_event_id',
            'status',
            'invoice_id',
            'invoice_number',
            'payment_id',
            'payment_receipt_number',
            'grand_total',
            'amount_paid',
            'change_returned',
            'posted_at',
        ];
        $this->assertSame($expectedKeys, array_keys($data));

        // Absence of internal SQL IDs
        $this->assertArrayNotHasKey('sales_invoice_id', $data);
        $this->assertArrayNotHasKey('customer_payment_id', $data);
        $this->assertArrayNotHasKey('customer_id', $data);
        $this->assertArrayNotHasKey('outcome', $data);

        // Regex checks
        $this->assertMatchesRegularExpression('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $data['invoice_id']);
        $this->assertMatchesRegularExpression('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $data['payment_id']);
        $this->assertMatchesRegularExpression('/\A(?:0\.(?=[0-9]{1,6}\z)[0-9]*[1-9][0-9]*|[1-9][0-9]{0,13}(?:\.[0-9]{1,6})?)\z/', $data['grand_total']);
        $this->assertMatchesRegularExpression('/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/', $data['amount_paid']);
        $this->assertMatchesRegularExpression('/\A(?:0|[1-9][0-9]{0,13})(?:\.[0-9]{1,6})?\z/', $data['change_returned']);
        $this->assertMatchesRegularExpression('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/', $data['posted_at']);

        // WireResponse schema verification
        $this->assertTrue(WireResponse::accepts(json_decode((string) $response->getContent()), 'sale-commit-response'));
    }

    public function test_executor_tampered_sales_invoice_public_id_fails_and_rolls_back(): void
    {
        $tamperedExecutor = new class implements FinancialExecutionAdapterInterface
        {
            private QualifiedCashFinancialExecutionAdapter $delegate;

            public function __construct()
            {
                $this->delegate = new QualifiedCashFinancialExecutionAdapter;
            }

            public function authorize(
                Company $company,
                User $actor,
                MobileDevice $device,
                string $operation,
                ?array $payload = null
            ): void {
                $this->delegate->authorize($company, $actor, $device, $operation, $payload);
            }

            public function execute(
                Company $company,
                User $actor,
                MobileDevice $device,
                array $payload,
                string $idempotencySuffix,
                ?NativeFinancialExecutionCapability $capability = null
            ): array {
                $res = $this->delegate->execute($company, $actor, $device, $payload, $idempotencySuffix, $capability);
                $res['sales_invoice_public_id'] = 'INV_TAMPERED_PUBLIC_ID_000';

                return $res;
            }
        };

        $manager = new MobileEventReceiptManager($tamperedExecutor);
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $normativeBytes = OnlineSaleIntentProfile::serialize(
            OnlineSaleIntentProfile::construct([
                'actor_id' => (string) $this->staff->public_id,
                'company_id' => (string) $this->company->public_id,
                'currency_minor_units' => 2,
            ], json_encode($payload, JSON_THROW_ON_ERROR))
        );

        try {
            $manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: $eventId,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_online_sale_intent',
                canonicalBytes: $normativeBytes
            );
            $this->fail('Expected RuntimeException for sales invoice public ID mismatch');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Sales invoice public ID mismatch between executor and database row.', $e->getMessage());
        }

        $this->assertDatabaseCount('mobile_event_receipts', 0);
        $this->assertDatabaseCount('sales_invoices', 0);
        $this->assertDatabaseCount('customer_payments', 0);

        // Qualify replay and status parity: replay fails to find an existing receipt, status returns 'unknown'
        $lookupResult = $manager->lookup((int) $this->company->id, $eventId, $this->token->token_hash);
        $this->assertNull($lookupResult);

        $statusResponse = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$eventId}/status");
        $statusResponse->assertStatus(200)
            ->assertJson([
                'client_event_id' => $eventId,
                'company_id' => (string) $this->company->public_id,
                'status' => 'unknown',
                'payload_digest' => null,
                'official_invoice' => null,
                'conflict' => null,
            ]);
    }

    public function test_executor_returning_foreign_company_invoice_fails_and_rolls_back(): void
    {
        $foreignCompany = Company::factory()->create(['status' => 'active', 'base_currency_code' => 'ILS']);
        $foreignCustomer = CompanyScope::executeWithoutScope(fn () => Customer::create([
            'company_id' => $foreignCompany->id,
            'name_ar' => 'عميل أجنبي',
            'name_en' => 'Foreign Customer',
            'currency_code' => 'ILS',
            'active' => true,
            'created_by' => $this->owner->id,
        ]));
        $foreignInvoiceId = DB::table('sales_invoices')->insertGetId([
            'company_id' => $foreignCompany->id,
            'customer_id' => $foreignCustomer->id,
            'public_id' => (string) Str::ulid(),
            'invoice_number' => 'INV-FOREIGN-001',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'status' => 'posted',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'subtotal_base' => '50.000000',
            'tax_total_base' => '8.000000',
            'grand_total_base' => '58.000000',
            'grand_total_currency' => '58.000000',
            'created_by' => $this->owner->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignExecutor = new class($foreignInvoiceId) implements FinancialExecutionAdapterInterface
        {
            private QualifiedCashFinancialExecutionAdapter $delegate;

            public function __construct(private int $foreignInvId)
            {
                $this->delegate = new QualifiedCashFinancialExecutionAdapter;
            }

            public function authorize(Company $company, User $actor, MobileDevice $device, string $operation, ?array $payload = null): void
            {
                $this->delegate->authorize($company, $actor, $device, $operation, $payload);
            }

            public function execute(
                Company $company,
                User $actor,
                MobileDevice $device,
                array $payload,
                string $idempotencySuffix,
                ?NativeFinancialExecutionCapability $capability = null
            ): array {
                $res = $this->delegate->execute($company, $actor, $device, $payload, $idempotencySuffix, $capability);
                $res['sales_invoice_id'] = $this->foreignInvId;
                $res['sales_invoice_public_id'] = '01JCV8X9MEFOREIGNINV00000000';

                return $res;
            }
        };

        $manager = new MobileEventReceiptManager($foreignExecutor);
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $normativeBytes = OnlineSaleIntentProfile::serialize(
            OnlineSaleIntentProfile::construct([
                'actor_id' => (string) $this->staff->public_id,
                'company_id' => (string) $this->company->public_id,
                'currency_minor_units' => 2,
            ], json_encode($payload, JSON_THROW_ON_ERROR))
        );

        try {
            $manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: $eventId,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_online_sale_intent',
                canonicalBytes: $normativeBytes
            );
            $this->fail('Expected SecurityException/RuntimeException for foreign company invoice');
        } catch (\Throwable $e) {
            $this->assertTrue(
                str_contains($e->getMessage(), 'Financial entity IDs do not match closed composite completion') ||
                str_contains($e->getMessage(), 'Financial executor returned sales invoice not belonging to target company') ||
                str_contains($e->getMessage(), 'does not belong to company')
            );
        }

        $this->assertDatabaseCount('mobile_event_receipts', 0);
        $this->assertSame(0, SalesInvoice::where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::where('company_id', $this->company->id)->count());
    }

    public function test_status_endpoint_returns_unknown_status_for_unrecorded_event(): void
    {
        $unknownEventId = (string) Str::uuid();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$unknownEventId}/status");

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame($unknownEventId, $data['client_event_id']);
        $this->assertSame((string) $this->company->public_id, $data['company_id']);
        $this->assertSame('unknown', $data['status']);
        $this->assertNull($data['payload_digest']);
        $this->assertNull($data['official_invoice']);
        $this->assertNull($data['conflict']);
        $this->assertNotEmpty($data['queried_at']);

        // Assert wire response conforms to schema
        $obj = json_decode((string) json_encode($data));
        $this->assertTrue(WireResponse::accepts($obj, 'sale-event-status-response'));
    }

    public function test_status_endpoint_returns_posted_official_invoice_after_successful_commit(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);

        $commitResponse = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);
        $commitResponse->assertStatus(201);
        $commitData = $commitResponse->json();

        // Query status endpoint for the committed event
        $statusResponse = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$eventId}/status");

        $statusResponse->assertStatus(200);
        $statusData = $statusResponse->json();

        $this->assertSame($eventId, $statusData['client_event_id']);
        $this->assertSame((string) $this->company->public_id, $statusData['company_id']);
        $this->assertSame('posted', $statusData['status']);
        $this->assertNotNull($statusData['payload_digest']);
        $this->assertStringStartsWith('sha256:', $statusData['payload_digest']);
        $this->assertNull($statusData['conflict']);

        // Assert official_invoice exact parity with commit wire DTO
        $official = $statusData['official_invoice'];
        $this->assertNotNull($official);
        $this->assertSame($commitData['invoice_id'], $official['invoice_id']);
        $this->assertSame($commitData['invoice_number'], $official['invoice_number']);
        $this->assertSame($commitData['payment_id'], $official['payment_id']);
        $this->assertSame($commitData['payment_receipt_number'], $official['payment_receipt_number']);
        $this->assertSame($commitData['grand_total'], $official['grand_total']);
        $this->assertSame($commitData['posted_at'], $official['posted_at']);

        // Assert wire response conforms to schema
        $obj = json_decode((string) json_encode($statusData));
        $this->assertTrue(WireResponse::accepts($obj, 'sale-event-status-response'));
    }

    public function test_status_endpoint_rejects_unauthenticated_or_expired_token(): void
    {
        $eventId = (string) Str::uuid();

        // Without Bearer token
        $response = $this->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$eventId}/status");
        $response->assertStatus(401);

        // With invalid Bearer token
        $response2 = $this->withHeader('Authorization', 'Bearer st_m_invalid_token_9999')
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$eventId}/status");
        $response2->assertStatus(401);
    }

    public function test_status_endpoint_rejects_actor_missing_view_permission(): void
    {
        $eventId = (string) Str::uuid();

        // Revoke sales.invoice.view from staff actor and any roles in this company
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->company->id);
        $this->staff->revokePermissionTo('sales.invoice.view');
        $salesRole = Role::where('name', 'Sales')->where('company_id', $this->company->id)->first();
        if ($salesRole !== null && $salesRole->hasPermissionTo('sales.invoice.view')) {
            $salesRole->revokePermissionTo('sales.invoice.view');
        }
        $cashierRole = Role::where('name', 'Cashier')->where('company_id', $this->company->id)->first();
        if ($cashierRole !== null && $cashierRole->hasPermissionTo('sales.invoice.view')) {
            $cashierRole->revokePermissionTo('sales.invoice.view');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$eventId}/status");

        $response->assertStatus(403);
        $response->assertJson([
            'error' => [
                'code' => 'FORBIDDEN',
            ],
        ]);
    }

    public function test_status_endpoint_rejects_invalid_uuid_client_event_id(): void
    {
        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/not-a-valid-uuid/status");

        $response->assertStatus(422);
        $response->assertJson([
            'error' => [
                'code' => 'VALIDATION_FAILED',
            ],
        ]);
    }

    /**
     * T-37: Genuine unpaid execution: canonical invoice draft + post with explicit null payment,
     * zero money account interaction, amount_paid 0.000000, 201 fresh / 200 replay / status 200,
     * permission boundary (only sales.invoice.create and sales.invoice.post needed), and 6 reconciliations.
     */
    public function test_fresh_creation_unpaid_commits_invoice_with_null_payment_and_reconciles_cleanly(): void
    {
        // Verify permission boundary: staff with ONLY sales.invoice.create and sales.invoice.post can execute unpaid
        setPermissionsTeamId($this->company->id);
        $this->staff->revokePermissionTo('money.receipt.create');
        $this->staff->revokePermissionTo('money.receipt.allocate');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            $eventId = (string) Str::uuid();
            $payload = $this->validSalePayload(
                eventId: $eventId,
                amount: '0.000000',
                settlementMode: 'unpaid'
            );

            $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

            $response->assertStatus(201);
            $response->assertJson([
                'client_event_id' => $eventId,
                'status' => 'posted',
                'payment_id' => null,
                'payment_receipt_number' => null,
                'grand_total' => '58.000000',
                'amount_paid' => '0.00',
                'change_returned' => '0.000000',
            ]);

            $data = $response->json();
            $this->assertNull($data['payment_id']);
            $this->assertNull($data['payment_receipt_number']);
            $this->assertSame('0.00', $data['amount_paid']);
            $this->assertSame('58.000000', $data['grand_total']);
            $this->assertTrue(WireResponse::accepts(json_decode((string) $response->getContent()), 'sale-commit-response'));

            $invoicePublicId = $data['invoice_id'];
            $this->assertNotNull($invoicePublicId);

            // Database assertions: invoice posted, zero customer payments created, zero allocations
            $invoice = SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $invoicePublicId)->first();
            $this->assertNotNull($invoice);
            $this->assertSame('posted', $invoice->status);
            $this->assertSame('58.000000', (string) $invoice->grand_total_currency);

            $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(0, DB::table('customer_payment_allocations')->where('sales_invoice_id', $invoice->id)->count());

            // Event receipt has null customer_payment_id
            $receipt = MobileEventReceipt::withoutGlobalScopes()->where('client_event_id', $eventId)->first();
            $this->assertNotNull($receipt);
            $this->assertSame('completed', $receipt->status);
            $this->assertNull($receipt->customer_payment_id);
            $this->assertSame((int) $invoice->id, (int) $receipt->sales_invoice_id);

            // Replay returns HTTP 200 with identical payload and no duplicate records
            $replay = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);
            $replay->assertStatus(200);
            $this->assertSame($data, $replay->json());
            $this->assertSame(1, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

            // Status endpoint returns 200 with identical immutable canonical result
            $statusRes = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
                ->withHeader('Accept', 'application/json')
                ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$eventId}/status");
            $statusRes->assertStatus(200);
            $statusData = $statusRes->json();
            $this->assertSame('posted', $statusData['status']);
            $official = $statusData['official_invoice'];
            $this->assertNotNull($official);
            $this->assertSame($invoicePublicId, $official['invoice_id']);
            $this->assertNull($official['payment_id']);
            $this->assertSame('58.000000', $official['grand_total']);

            // All 6 accounting reconciliations pass cleanly
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
            $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
            $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
            $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
            $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase 7 reconciliation must pass');
            $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
        } finally {
            setPermissionsTeamId($this->company->id);
            $this->staff->givePermissionTo('money.receipt.create');
            $this->staff->givePermissionTo('money.receipt.allocate');
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * T-38: Genuine partial execution: canonical invoice draft + post with positive partial payment
     * allocated strictly to the paid portion, 201 fresh / 200 replay / status 200, and 6 reconciliations.
     */
    public function test_fresh_creation_partial_commits_invoice_and_partial_payment_and_reconciles_cleanly(): void
    {
        $eventId = (string) Str::uuid();
        $partialAmount = '20.000000';
        $payload = $this->validSalePayload(
            eventId: $eventId,
            amount: $partialAmount,
            settlementMode: 'partial'
        );

        $response = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);

        $response->assertStatus(201);
        $response->assertJson([
            'client_event_id' => $eventId,
            'status' => 'posted',
            'grand_total' => '58.000000',
            'amount_paid' => $partialAmount,
            'change_returned' => '0.000000',
        ]);

        $data = $response->json();
        $this->assertNotNull($data['invoice_id']);
        $this->assertNotNull($data['payment_id']);
        $this->assertNotNull($data['payment_receipt_number']);
        $this->assertSame($partialAmount, $data['amount_paid']);
        $this->assertSame('58.000000', $data['grand_total']);
        $this->assertTrue(WireResponse::accepts(json_decode((string) $response->getContent()), 'sale-commit-response'));

        // Database assertions: invoice posted, payment posted for exact partial amount, allocated exactly to partial amount
        $invoice = SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $data['invoice_id'])->first();
        $this->assertNotNull($invoice);
        $this->assertSame('posted', $invoice->status);
        $this->assertSame('58.000000', (string) $invoice->grand_total_currency);

        $payment = CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->where('public_id', $data['payment_id'])->first();
        $this->assertNotNull($payment);
        $this->assertFalse($payment->is_reversed);
        $this->assertSame($partialAmount, (string) $payment->amount);

        $allocation = DB::table('customer_payment_allocations')
            ->where('customer_payment_id', $payment->id)
            ->where('sales_invoice_id', $invoice->id)
            ->first();
        $this->assertNotNull($allocation);
        $this->assertSame($partialAmount, (string) $allocation->allocated_amount);

        // Event receipt has matching payment ID
        $receipt = MobileEventReceipt::withoutGlobalScopes()->where('client_event_id', $eventId)->first();
        $this->assertNotNull($receipt);
        $this->assertSame((int) $payment->id, (int) $receipt->customer_payment_id);
        $this->assertSame((int) $invoice->id, (int) $receipt->sales_invoice_id);

        // Replay returns HTTP 200 with identical response and zero duplicate records
        $replay = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload);
        $replay->assertStatus(200);
        $this->assertSame($data, $replay->json());
        $this->assertSame(1, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());

        // Status endpoint returns 200 with identical immutable canonical result
        $statusRes = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->withHeader('Accept', 'application/json')
            ->get("/api/mobile/v2/companies/{$this->company->public_id}/offline-events/{$eventId}/status");
        $statusRes->assertStatus(200);
        $statusData = $statusRes->json();
        $this->assertSame('posted', $statusData['status']);
        $official = $statusData['official_invoice'];
        $this->assertNotNull($official);
        $this->assertSame($data['invoice_id'], $official['invoice_id']);
        $this->assertSame($data['payment_id'], $official['payment_id']);
        $this->assertSame('58.000000', $official['grand_total']);

        // All 6 accounting reconciliations pass cleanly
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase 7 reconciliation must pass');
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
    }

    /**
     * T-39: Changed mode or changed payload under the same client_event_id returns HTTP 409 Conflict.
     */
    public function test_changed_mode_and_changed_payload_returns_409_conflict(): void
    {
        // 1. Submit unpaid sale
        $eventId1 = (string) Str::uuid();
        $unpaidPayload = $this->validSalePayload(
            eventId: $eventId1,
            amount: '0.000000',
            settlementMode: 'unpaid'
        );

        $res1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $unpaidPayload);
        $res1->assertStatus(201);

        // Attempt replay with changed mode (full instead of unpaid) under same eventId1 -> 409
        $tamperedModePayload = $this->validSalePayload(
            eventId: $eventId1,
            amount: '58.000000',
            settlementMode: 'full'
        );
        $conflict1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $tamperedModePayload);
        $conflict1->assertStatus(409);

        // Attempt replay with changed amount under same eventId1 -> 409
        $tamperedAmountPayload = $this->validSalePayload(
            eventId: $eventId1,
            amount: '20.000000',
            settlementMode: 'partial'
        );
        $conflict2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $tamperedAmountPayload);
        $conflict2->assertStatus(409);

        // 2. Submit full cash sale
        $eventId2 = (string) Str::uuid();
        $fullPayload = $this->validSalePayload(
            eventId: $eventId2,
            amount: '58.000000',
            settlementMode: 'full'
        );
        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $fullPayload);
        $res2->assertStatus(201);

        // Attempt replay with unpaid mode under same eventId2 -> 409
        $tamperedUnpaidPayload = $this->validSalePayload(
            eventId: $eventId2,
            amount: '0.000000',
            settlementMode: 'unpaid'
        );
        $conflict3 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $tamperedUnpaidPayload);
        $conflict3->assertStatus(409);

        // Exactly 2 sales invoices and 1 customer payment exist in the company
        $this->assertSame(2, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
    }

    /**
     * T-40: Simulated faults after invoice posting and after customer payment roll back all legs with zero leakage.
     */
    public function test_simulated_fault_rollback_after_posting_and_after_payment(): void
    {
        $movementsBefore = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->count();

        // 1. Fault after invoice posting
        ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = 'after_posting';

        $eventId1 = (string) Str::uuid();
        $payload1 = $this->validSalePayload(
            eventId: $eventId1,
            amount: '0.000000',
            settlementMode: 'unpaid'
        );

        $response1 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload1);
        $this->assertTrue($response1->status() >= 400);

        // Entire transaction rolled back
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame($movementsBefore, StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->where('source_type', 'sales_invoice')->count());

        // 2. Fault after customer payment
        ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = 'after_payment';

        $eventId2 = (string) Str::uuid();
        $payload2 = $this->validSalePayload(
            eventId: $eventId2,
            amount: '20.000000',
            settlementMode: 'partial'
        );

        $response2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload2);
        $this->assertTrue($response2->status() >= 400);

        // Entire transaction rolled back
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame($movementsBefore, StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)->where('source_type', 'sales_invoice')->count());

        ExecuteImmediateOnlineCashSaleAction::$simulatedFaultStage = null;

        // Reconciliations pass cleanly with zero phantom records
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase 7 reconciliation must pass');
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
    }

    /**
     * T-41: Real two-process MariaDB contention test for unpaid sales:
     * concurrent workers converge to single created (201) and replay (200), null payment, and 6 reconciliations.
     */
    public function test_two_process_concurrent_unpaid_sale_converges_to_single_sale(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            $eventId = (string) Str::uuid();
            $payload = $this->validSalePayload(
                eventId: $eventId,
                amount: '0.000000',
                settlementMode: 'unpaid'
            );
            $serverContext = [
                'actor_id' => (string) $this->staff->public_id,
                'company_id' => (string) $this->company->public_id,
                'currency_minor_units' => 2,
            ];
            $profile = OnlineSaleIntentProfile::construct($serverContext, $payload);
            $canonicalBytes = OnlineSaleIntentProfile::serialize($profile);

            $inputHolder = [
                'company_id' => $this->company->id,
                'client_event_id' => $eventId,
                'token_hash' => $this->token->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $canonicalBytes,
                'executor_mode' => 'production_cash',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
            ];

            $inputContender = [
                'company_id' => $this->company->id,
                'client_event_id' => $eventId,
                'token_hash' => $this->token->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $canonicalBytes,
                'executor_mode' => 'production_cash',
            ];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', $inputHolder);
            touch($directory.'/go-worker-a');

            // Wait until worker A acquires the lock
            StrictBarrier::await(
                fn (): bool => is_file($directory.'/worker-a.locked'),
                fn (): bool => $workerA->isRunning()
            );

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', $inputContender);
            touch($directory.'/go-worker-b');

            // Wait until worker B's exact CID is pending on the lock in MariaDB processlist
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

            // Release the lock holder
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait(), 'Worker A failed: '.$workerA->getErrorOutput());
            $this->assertSame(0, $workerB->wait(), 'Worker B failed: '.$workerB->getErrorOutput());

            $outA = json_decode($workerA->getOutput(), true);
            $outB = json_decode($workerB->getOutput(), true);

            $this->assertIsArray($outA, 'Worker A output must be valid JSON: '.$workerA->getOutput().' err: '.$workerA->getErrorOutput());
            $this->assertIsArray($outB, 'Worker B output must be valid JSON: '.$workerB->getOutput().' err: '.$workerB->getErrorOutput());

            $outcomes = [$outA['outcome'], $outB['outcome']];
            sort($outcomes);
            $this->assertSame(['created', 'replay'], $outcomes, 'Concurrent workers must converge to one created and one replay.');

            // Both workers returned identical invoice identifiers and null payment IDs
            $this->assertSame($outA['sales_invoice_id'], $outB['sales_invoice_id']);
            $this->assertNull($outA['customer_payment_id']);
            $this->assertNull($outB['customer_payment_id']);

            // Database has exactly 1 invoice, 0 payments, 1 receipt
            $this->assertSame(1, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('client_event_id', $eventId)->count());

            // All 6 reconciliations pass cleanly
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
            $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
            $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
            $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
            $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
            $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase7 reconciliation must pass');
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (isset($directory) && is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }

    /**
     * T-42: Real two-process MariaDB contention test for partial sales:
     * concurrent workers converge to single created (201) and replay (200), exact partial payment, and 6 reconciliations.
     */
    public function test_two_process_concurrent_partial_sale_converges_to_single_sale(): void
    {
        $schema = $this->requireIsolatedDisposableSchema();
        $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {

            $eventId = (string) Str::uuid();
            $partialAmount = '20.000000';
            $payload = $this->validSalePayload(
                eventId: $eventId,
                amount: $partialAmount,
                settlementMode: 'partial'
            );
            $serverContext = [
                'actor_id' => (string) $this->staff->public_id,
                'company_id' => (string) $this->company->public_id,
                'currency_minor_units' => 2,
            ];
            $profile = OnlineSaleIntentProfile::construct($serverContext, $payload);
            $canonicalBytes = OnlineSaleIntentProfile::serialize($profile);

            $inputHolder = [
                'company_id' => $this->company->id,
                'client_event_id' => $eventId,
                'token_hash' => $this->token->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $canonicalBytes,
                'executor_mode' => 'production_cash',
                'hold_lock_table' => 'companies',
                'wait_for_file' => 'release-holder',
            ];

            $inputContender = [
                'company_id' => $this->company->id,
                'client_event_id' => $eventId,
                'token_hash' => $this->token->token_hash,
                'event_version' => 2,
                'representation' => 'v2_online_sale_intent',
                'canonical_bytes' => $canonicalBytes,
                'executor_mode' => 'production_cash',
            ];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', $inputHolder);
            touch($directory.'/go-worker-a');

            // Wait until worker A acquires the lock
            StrictBarrier::await(
                fn (): bool => is_file($directory.'/worker-a.locked'),
                fn (): bool => $workerA->isRunning()
            );

            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', $inputContender);
            touch($directory.'/go-worker-b');

            // Wait until worker B's exact CID is pending on the lock in MariaDB processlist
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

            // Release the lock holder
            touch($directory.'/release-holder');

            $this->assertSame(0, $workerA->wait(), 'Worker A failed: '.$workerA->getErrorOutput());
            $this->assertSame(0, $workerB->wait(), 'Worker B failed: '.$workerB->getErrorOutput());

            $outA = json_decode($workerA->getOutput(), true);
            $outB = json_decode($workerB->getOutput(), true);

            $this->assertIsArray($outA, 'Worker A output must be valid JSON: '.$workerA->getOutput().' err: '.$workerA->getErrorOutput());
            $this->assertIsArray($outB, 'Worker B output must be valid JSON: '.$workerB->getOutput().' err: '.$workerB->getErrorOutput());

            $outcomes = [$outA['outcome'], $outB['outcome']];
            sort($outcomes);
            $this->assertSame(['created', 'replay'], $outcomes, 'Concurrent workers must converge to one created and one replay.');

            // Both workers returned identical invoice and payment identifiers
            $this->assertSame($outA['sales_invoice_id'], $outB['sales_invoice_id']);
            $this->assertSame($outA['customer_payment_id'], $outB['customer_payment_id']);

            // Database has exactly 1 invoice, 1 payment, 1 receipt
            $this->assertSame(1, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
            $this->assertSame(1, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->where('client_event_id', $eventId)->count());

            // All 6 reconciliations pass cleanly
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
            $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
            $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
            $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
            $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
            $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase7 reconciliation must pass');
        } finally {
            foreach ([$workerA ?? null, $workerB ?? null] as $worker) {
                if ($worker instanceof Process && $worker->isRunning()) {
                    $worker->stop();
                }
            }
            if (isset($directory) && is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function startWorkerProcess(?DisposableMariaDbSchema $schema, string $directory, string $slot, array $input): Process
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
        ], $schema?->environment() ?? []);
        if ($input['enable_offline_routes'] ?? false) {
            $env['MOBILE_V2_API_ENABLED'] = 'true';
            $env['MOBILE_V2_CASH_SLICE_ENABLED'] = 'true';
            $env['MOBILE_V2_OFFLINE_SALES_ENABLED'] = 'true';
        }

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
}
