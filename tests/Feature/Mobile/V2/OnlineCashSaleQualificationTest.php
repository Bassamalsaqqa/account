<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V2;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Company\UpdateRolePermissionsAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\ExecuteImmediateOnlineCashSaleAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Customer;
use App\Models\CustomerPayment;
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
use App\Support\Mobile\Contract\Revision3\OnlineSaleIntentProfile;
use App\Support\Mobile\Receipt\Exceptions\IllegalStateException;
use App\Support\Mobile\Receipt\Exceptions\SecurityException;
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Mobile\Receipt\NativeFinancialExecutionCapability;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Closure;
use Error;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
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
        $this->assertOwnedDisposableDatabase();

        config([
            'mobile.enabled' => true,
            'mobile.v2_enabled' => true,
            'mobile.v2_cash_slice_enabled' => true,
        ]);

        $this->app->bind(FinancialExecutionAdapterInterface::class, QualifiedCashFinancialExecutionAdapter::class);

        $this->setupQualificationFixture();
    }

    protected function tearDown(): void
    {
        if (! $this->isDisposableDatabaseOwnershipAdmitted()) {
            parent::tearDown();

            return;
        }

        try {
            $this->revalidateOwnedDisposableDatabase();

            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
            }

            CompanyScope::executeWithoutScope(function () {
                if (isset($this->company)) {
                    $companyId = $this->company->id;
                    DB::table('mobile_event_receipts')->where('company_id', $companyId)->delete();
                    DB::table('customer_payment_allocations')->where('company_id', $companyId)->delete();
                    DB::table('customer_payments')->where('company_id', $companyId)->delete();
                    DB::table('sales_invoice_lot_allocations')->where('company_id', $companyId)->delete();
                    DB::table('sales_invoice_lines')->where('company_id', $companyId)->delete();
                    DB::table('sales_invoices')->where('company_id', $companyId)->delete();
                    DB::table('inventory_lot_balances')->where('company_id', $companyId)->delete();
                    DB::table('inventory_balances')->where('company_id', $companyId)->delete();
                    DB::table('inventory_lots')->where('company_id', $companyId)->delete();
                    DB::table('stock_movements')->where('company_id', $companyId)->delete();
                    DB::table('posting_lines')->where('company_id', $companyId)->delete();
                    DB::table('posting_batches')->where('company_id', $companyId)->delete();
                }
            });

            app(CompanyContext::class)->clear();
        } finally {
            $this->disposableDatabaseOwnershipAdmitted = false;
            $this->admittedDatabaseName = null;
            auth()->forgetUser();
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
    private function validSalePayload(string $eventId, string $amount = '58.000000', string $unitPrice = '50.000000'): array
    {
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
            'payment' => [
                'settlement_mode' => 'full',
                'payment_method' => 'cash',
                'cash_account_id' => $this->cashAccount->public_id,
                'amount' => $amount,
            ],
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

        // Mint a test-registered capability
        $eventId = (string) Str::uuid();
        $payload = $this->validSalePayload($eventId);
        $digest = hash('sha256', 'canonical-bytes');

        // Attempting to consume outside transaction throws IllegalStateException
        $manager = app(MobileEventReceiptManager::class);

        $this->expectException(IllegalStateException::class);
        $this->expectExceptionMessage('depth 1');

        $companyId = $this->company->id;
        $companyPublicId = (string) $this->company->public_id;
        $staffId = $this->staff->id;
        $staffPublicId = (string) $this->staff->public_id;
        $deviceId = $this->device->device_id;
        $passwordEpoch = $this->token->password_epoch;
        $connName = DB::connection()->getName();

        // Create capability through private admission simulation
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

        // Register in manager
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
            ];
        }, null, MobileEventReceiptManager::class))();

        MobileEventReceiptManager::verifyAndConsumeCapability(
            $capability, $this->company->id, $this->staff->id, $this->device->device_id, $eventId, $digest
        );
    }

    /**
     * T-05: Replay or second consumption of same capability throws SecurityException.
     */
    public function test_reused_capability_throws_security_exception(): void
    {
        $eventId = (string) Str::uuid();
        $digest = hash('sha256', 'test-payload');

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
                'active' => true,
                'consumed' => false,
            ];
        }, null, MobileEventReceiptManager::class))();

        DB::transaction(function () use ($capability, $eventId, $digest) {
            // First consumption succeeds
            MobileEventReceiptManager::verifyAndConsumeCapability(
                $capability, $this->company->id, $this->staff->id, $this->device->device_id, $eventId, $digest
            );

            // Second consumption on same capability must throw SecurityException
            $this->expectException(SecurityException::class);
            $this->expectExceptionMessage('already been consumed');

            MobileEventReceiptManager::verifyAndConsumeCapability(
                $capability, $this->company->id, $this->staff->id, $this->device->device_id, $eventId, $digest
            );
        });
    }

    /**
     * T-06: Stale or mismatched event hash throws SecurityException.
     */
    public function test_stale_or_mismatched_event_hash_throws_security_exception(): void
    {
        $eventId = (string) Str::uuid();
        $digest = hash('sha256', 'digest-A');

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
            ];
        }, null, MobileEventReceiptManager::class))();

        DB::transaction(function () use ($capability, $eventId) {
            $this->expectException(SecurityException::class);
            $this->expectExceptionMessage('event hash mismatch');

            MobileEventReceiptManager::verifyAndConsumeCapability(
                $capability, $this->company->id, $this->staff->id, $this->device->device_id, $eventId, 'tampered-hash-B'
            );
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
            'outcome' => 'created',
            'status' => 'completed',
            'currency' => 'ILS',
            'grand_total' => '58.000000',
            'paid_amount' => '58.000000',
            'lines_count' => 1,
        ]);

        $data = $response->json();
        $invoiceId = $data['sales_invoice_id'];
        $paymentId = $data['customer_payment_id'];

        $this->assertNotNull($invoiceId);
        $this->assertNotNull($paymentId);

        // Verify database state: invoice is posted
        $invoice = SalesInvoice::withoutGlobalScopes()->find($invoiceId);
        $this->assertNotNull($invoice);
        $this->assertSame('posted', $invoice->status);
        $this->assertSame('58.000000', (string) $invoice->grand_total_currency);

        // Verify payment is posted and allocated
        $payment = CustomerPayment::withoutGlobalScopes()->find($paymentId);
        $this->assertNotNull($payment);
        $this->assertFalse($payment->is_reversed);
        $this->assertNotNull($payment->posting_batch_id);
        $this->assertSame('58.000000', (string) $payment->amount);

        $allocation = DB::table('customer_payment_allocations')
            ->where('customer_payment_id', $paymentId)
            ->where('sales_invoice_id', $invoiceId)
            ->first();
        $this->assertNotNull($allocation);
        $this->assertSame('58.000000', (string) $allocation->allocated_amount);

        // Verify stock movements were created
        $stockMovement = StockMovement::withoutGlobalScopes()->where('company_id', $this->company->id)
            ->where('source_type', 'sales_invoice')
            ->where('source_id', $invoiceId)
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

        $this->assertSame('replayed', $data2['outcome']);
        $this->assertSame($data1['sales_invoice_id'], $data2['sales_invoice_id']);
        $this->assertSame($data1['customer_payment_id'], $data2['customer_payment_id']);
        $this->assertSame($data1['invoice_number'], $data2['invoice_number']);

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

    /**
     * T-12: Real two-process MariaDB contention test qualifying that concurrent workers with identical
     * (company_id, client_event_id) race under row lock: winner gets created (201), contender gets replayed (200),
     * proven via StrictBarrier + exact CID processlist pending query holder release ACK with all 6 reconciliations.
     */
    public function test_two_process_concurrent_online_cash_sale_converges_to_single_sale(): void
    {
        $schema = DisposableMariaDbSchema::create();
        try {
            $schema->migrate();
            $schema->switchLaravelConnection();
            $this->setupQualificationFixture();

            $directory = sys_get_temp_dir().'/mobile_receipt_race_'.bin2hex(random_bytes(8));
            mkdir($directory, 0700);

            $eventId = (string) Str::uuid();
            $payload = $this->validSalePayload($eventId);
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
                'executor_mode' => 'qualified_cash',
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
                'executor_mode' => 'qualified_cash',
            ];

            $workerA = $this->startWorkerProcess($schema, $directory, 'worker-a', $inputHolder);
            $workerB = $this->startWorkerProcess($schema, $directory, 'worker-b', $inputContender);

            // Signal go to start both workers
            touch($directory.'/go');

            // Wait until worker A acquires the lock
            StrictBarrier::await(
                fn (): bool => is_file($directory.'/worker-a.locked'),
                fn (): bool => $workerA->isRunning() && $workerB->isRunning()
            );

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
            $this->assertSame(['created', 'replay'], $outcomes, 'Concurrent workers must converge to one created and one replay. OutA: '.json_encode($outA).' OutB: '.json_encode($outB));

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
            if (isset($directory) && is_dir($directory)) {
                @array_map('unlink', glob($directory.'/*') ?: []);
                @rmdir($directory);
            }
            $schema->drop();
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
        $this->assertStringContainsString('conversion_to_base', (string) $res1->getContent());

        // 2. Tax rate mismatch
        $eventId2 = (string) Str::uuid();
        $payload2 = $this->validSalePayload($eventId2);
        $payload2['lines'][0]['tax_rate'] = '0.999999';

        $res2 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload2);
        $this->assertSame(422, $res2->status());
        $this->assertStringContainsString('tax_rate', (string) $res2->getContent());

        // 3. Tax inclusive mismatch
        $eventId3 = (string) Str::uuid();
        $payload3 = $this->validSalePayload($eventId3);
        $payload3['lines'][0]['tax_inclusive'] = true; // tax rate in fixture is exclusive

        $res3 = $this->withHeader('Authorization', 'Bearer '.$this->rawToken)
            ->postJson("/api/mobile/v2/companies/{$this->company->public_id}/sales/online-cash", $payload3);
        $this->assertSame(422, $res3->status());
        $this->assertStringContainsString('tax_inclusive', (string) $res3->getContent());

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
        $this->staff->revokePermissionTo('sales.invoice.view');
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
        $this->assertSame('VALIDATION_FAILED', $response->json('code'));
        $this->assertStringContainsString('Duplicate key', (string) $response->json('message'));

        // Zero effects
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
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
}
