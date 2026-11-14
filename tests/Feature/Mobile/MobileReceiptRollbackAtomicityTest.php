<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\ExecuteImmediateOnlineCashSaleAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileEventReceipt;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
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
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\EnforcesDisposableDatabaseOwnership;
use Tests\Support\QualifiedCashFinancialExecutionAdapter;
use Tests\TestCase;

final class MobileReceiptRollbackAtomicityTest extends TestCase
{
    use EnforcesDisposableDatabaseOwnership;

    // Non-nested fixture strategy on owned disposable schema: no outer transaction wrapper

    protected function tearDown(): void
    {
        ExecuteImmediateOnlineCashSaleAction::$stageFaultHook = null;
        self::$failAfterReceiptCreation = false;

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
            parent::tearDown();
        }
    }

    private static bool $failAfterReceiptCreation = false;

    private User $owner;

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private Product $product;

    private MoneyAccount $cash;

    private Unit $unit;

    private MobileDevice $device;

    private MobileAccessToken $token;

    private MobileEventReceiptManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertOwnedDisposableDatabase();

        ExecuteImmediateOnlineCashSaleAction::$stageFaultHook = null;
        self::$failAfterReceiptCreation = false;

        $this->owner = User::create([
            'name' => 'Atomicity Auditor',
            'email' => 'auditor_'.bin2hex(random_bytes(6)).'@example.com',
            'password' => Hash::make('password123'),
        ]);

        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة الذرية التامة',
            'name_en' => 'Complete Atomicity Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->actingAs($this->owner);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);

        app(CompanyRoleService::class)->ensurePermissionsExist();
        app(CompanyRoleService::class)->seedCompanyRoles($this->company);
        setPermissionsTeamId($this->company->id);
        $this->owner->assignRole('Owner');

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الذرية',
            'name_en' => 'Atomicity Customer',
            'currency_code' => 'ILS',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $this->cash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'cash',
            'name_ar' => 'صندوق المتجر',
            'name_en' => 'Store Cash ILS',
            'currency_code' => 'ILS',
        ]);

        $unit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();
        $this->unit = $unit;
        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'سلعة الاختبار الذري',
            'name_en' => 'Atomicity Test Item',
            'base_unit_id' => $unit->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $unit->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        // Post opening stock: 10 units @ 15 ILS
        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('10'),
            unitCostBase: '15.000000',
            user: $this->owner,
            idempotencyKey: 'atomicity-opening-stock',
            unitId: $unit->id,
            movementDate: '2026-11-12'
        );

        $deviceId = (string) Str::uuid();
        $this->device = MobileDevice::create([
            'device_id' => $deviceId,
            'device_name' => 'Atomicity Register',
            'user_id' => $this->owner->id,
        ]);

        $rawToken = 'st_m_atom_'.bin2hex(random_bytes(32));
        $this->token = MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $this->device->device_id,
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', (string) $this->owner->password), 0, 16),
            'expires_at' => now()->addDays(7),
        ]);

        $this->app->bind(FinancialExecutionAdapterInterface::class, QualifiedCashFinancialExecutionAdapter::class);
        $this->manager = new MobileEventReceiptManager;

        MobileEventReceipt::created(function (): void {
            if (self::$failAfterReceiptCreation) {
                throw new RuntimeException('Simulated fault immediately after receipt record creation');
            }
        });
    }

    private function buildNormativePayload(string $clientEventId): string
    {
        $serverContext = [
            'actor_id' => (string) $this->owner->public_id,
            'company_id' => (string) $this->company->public_id,
            'currency_minor_units' => 2,
        ];

        $raw = [
            'client_event_id' => $clientEventId,
            'currency' => 'ILS',
            'customer_id' => (string) $this->customer->public_id,
            'device_id' => (string) $this->device->device_id,
            'lines' => [
                [
                    'conversion_to_base' => '1.000000',
                    'product_id' => (string) $this->product->public_id,
                    'product_unit_id' => (string) $this->unit->public_id,
                    'quantity' => '2.000000',
                    'tax_inclusive' => false,
                    'tax_rate' => '0.0000',
                    'tax_rate_id' => null,
                    'unit_price' => '50.000000',
                ],
            ],
            'payment' => [
                'amount' => '100.000000',
                'cash_account_id' => (string) $this->cash->public_id,
                'payment_method' => 'cash',
                'settlement_mode' => 'full',
            ],
            'warehouse_id' => (string) $this->warehouse->public_id,
        ];

        $rawJson = (string) json_encode($raw, JSON_THROW_ON_ERROR);
        $profile = OnlineSaleIntentProfile::construct($serverContext, $rawJson);

        return OnlineSaleIntentProfile::serialize($profile);
    }

    private function assertFullFingerprintAndReconciliationRollback(): void
    {
        $this->assertSame(0, SalesInvoice::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, CustomerPayment::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('customer_payment_allocations')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, MobileEventReceipt::withoutGlobalScopes()->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->count()); // only opening stock
        $this->assertSame(1, DB::table('stock_movements')->where('company_id', $this->company->id)->count()); // only opening stock

        // Inventory balance unmutated at opening stock (10.000000)
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity_base' => '10.000000',
        ]);

        // Zero sequence leakage
        $seq = DocumentSequence::where('company_id', $this->company->id)
            ->where('document_type', DocumentSequence::TYPE_SALES_INVOICE)
            ->first();
        $this->assertTrue($seq === null || (int) $seq->next_number === 1);

        // Restore context and verify all 6 domain reconciliations
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase7 reconciliation must pass');
    }

    public function test_full_successful_canonical_execution_commits_all_legs_and_satisfies_all_six_reconciliations(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->buildNormativePayload($eventId);

        $result = $this->manager->recordOrReplay(
            companyId: (int) $this->company->id,
            clientEventId: $eventId,
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_online_sale_intent',
            canonicalBytes: $payload
        );

        $this->assertSame('created', $result['outcome']);
        $this->assertSame('completed', $result['status']);
        $this->assertNotNull($result['sales_invoice_id']);
        $this->assertNotNull($result['customer_payment_id']);

        // Assert exact database counts
        $this->assertSame(1, DB::table('sales_invoices')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('customer_payment_allocations')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('mobile_event_receipts')->where('company_id', $this->company->id)->count());

        // Opening stock batch + invoice batch + payment batch = 3 batches
        $this->assertSame(3, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
        // Opening stock movement (10) + sale movement (-2) = 2 movements
        $this->assertSame(2, DB::table('stock_movements')->where('company_id', $this->company->id)->count());

        // Balance updated from 10 to 8
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity_base' => '8.000000',
        ]);

        // Restore test context and verify all 6 reconciliations pass cleanly
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase7 reconciliation must pass');
    }

    public function test_fault_after_invoice_draft_rolls_back_entire_state_and_preserves_reconciliations(): void
    {
        ExecuteImmediateOnlineCashSaleAction::$stageFaultHook = function (string $stage): void {
            if ($stage === 'after_draft') {
                throw new RuntimeException('Simulated fault after invoice draft creation');
            }
        };

        $eventId = (string) Str::uuid();
        $payload = $this->buildNormativePayload($eventId);

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: $eventId,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_online_sale_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault after invoice draft creation', $e->getMessage());
        }

        $this->assertFullFingerprintAndReconciliationRollback();
    }

    public function test_fault_after_posting_and_stock_rolls_back_inventory_and_preserves_reconciliations(): void
    {
        ExecuteImmediateOnlineCashSaleAction::$stageFaultHook = function (string $stage): void {
            if ($stage === 'after_posting') {
                throw new RuntimeException('Simulated fault after invoice posting and inventory decrement');
            }
        };

        $eventId = (string) Str::uuid();
        $payload = $this->buildNormativePayload($eventId);

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: $eventId,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_online_sale_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault after invoice posting and inventory decrement', $e->getMessage());
        }

        $this->assertFullFingerprintAndReconciliationRollback();
    }

    public function test_fault_after_customer_payment_rolls_back_entire_state_and_preserves_reconciliations(): void
    {
        ExecuteImmediateOnlineCashSaleAction::$stageFaultHook = function (string $stage): void {
            if ($stage === 'after_payment') {
                throw new RuntimeException('Simulated fault after customer payment creation');
            }
        };

        $eventId = (string) Str::uuid();
        $payload = $this->buildNormativePayload($eventId);

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: $eventId,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_online_sale_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault after customer payment creation', $e->getMessage());
        }

        $this->assertFullFingerprintAndReconciliationRollback();
    }

    public function test_fault_after_composite_completion_rolls_back_entire_state_and_preserves_reconciliations(): void
    {
        ExecuteImmediateOnlineCashSaleAction::$stageFaultHook = function (string $stage): void {
            if ($stage === 'after_composite_completion') {
                throw new RuntimeException('Simulated fault after composite completion registration');
            }
        };

        $eventId = (string) Str::uuid();
        $payload = $this->buildNormativePayload($eventId);

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: $eventId,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_online_sale_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault after composite completion registration', $e->getMessage());
        }

        $this->assertFullFingerprintAndReconciliationRollback();
    }

    public function test_fault_after_receipt_creation_rolls_back_entire_state_and_preserves_reconciliations(): void
    {
        $eventId = (string) Str::uuid();
        $payload = $this->buildNormativePayload($eventId);

        self::$failAfterReceiptCreation = true;

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: $eventId,
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_online_sale_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault immediately after receipt record creation', $e->getMessage());
        } finally {
            self::$failAfterReceiptCreation = false;
        }

        $this->assertFullFingerprintAndReconciliationRollback();
    }
}
