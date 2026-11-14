<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DocumentSequence;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MobileEventReceipt;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\SalesReconciliationService;
use App\Services\Tenancy\CompanyRoleService;
use App\Support\Mobile\Receipt\CompositeFinancialExecutionScope;
use App\Support\Mobile\Receipt\FinancialExecutionAdapterInterface;
use App\Support\Mobile\Receipt\MobileEventReceiptManager;
use App\Support\Mobile\Receipt\NativeFinancialExecutionCapability;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\EnforcesDisposableDatabaseOwnership;
use Tests\TestCase;

final class MobileReceiptRollbackAtomicityTest extends TestCase
{
    use EnforcesDisposableDatabaseOwnership;

    // Non-nested fixture strategy on owned disposable schema: no outer transaction wrapper

    protected function tearDown(): void
    {
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

    private ProductUnit $productUnit;

    private MoneyAccount $cash;

    private MobileDevice $device;

    private MobileAccessToken $token;

    private MobileEventReceiptManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        self::$failAfterReceiptCreation = false;
        $this->assertOwnedDisposableDatabase();

        $this->owner = User::factory()->create([
            'email' => 'atomicity_owner_'.Str::random(8).'@example.com',
            'password' => Hash::make('AtomicitySecured!Pass123'),
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

        $this->productUnit = ProductUnit::create([
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

        $this->manager = new MobileEventReceiptManager;

        MobileEventReceipt::created(function (): void {
            if (self::$failAfterReceiptCreation) {
                throw new RuntimeException('Simulated fault immediately after receipt record creation');
            }
        });
    }

    private function createFaultInjectingExecutor(
        ?string $failAt = null
    ): FinancialExecutionAdapterInterface {
        $customer = $this->customer;
        $warehouse = $this->warehouse;
        $product = $this->product;
        $productUnit = $this->productUnit;
        $cash = $this->cash;

        return new class($customer, $warehouse, $product, $productUnit, $cash, $failAt) implements FinancialExecutionAdapterInterface
        {
            public function __construct(
                private Customer $customer,
                private Warehouse $warehouse,
                private Product $product,
                private ProductUnit $productUnit,
                private MoneyAccount $cash,
                private ?string $failAt
            ) {}

            public function authorize(
                Company $company,
                User $actor,
                MobileDevice $device,
                string $operation,
                ?array $payload = null
            ): void {
                // Verified test actor authorization
            }

            public function execute(
                Company $company,
                User $actor,
                MobileDevice $device,
                array $payload,
                string $idempotencySuffix,
                ?NativeFinancialExecutionCapability $capability = null
            ): array {
                $run = function () use ($company, $actor, $idempotencySuffix) {
                    // 1. Advance numbering
                    $docNumber = app(DocumentSequenceService::class)->generateNextNumber(
                        (int) $company->id,
                        DocumentSequence::TYPE_SALES_INVOICE
                    );

                    if ($this->failAt === 'numbering') {
                        throw new RuntimeException('Simulated fault immediately after numbering');
                    }

                    // 2. Create invoice draft
                    $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($company, $actor, [
                        'customer_id' => $this->customer->id,
                        'warehouse_id' => $this->warehouse->id,
                        'currency_code' => 'ILS',
                        'exchange_rate' => '1.0000000000',
                        'issue_date' => '2026-11-12',
                        'document_locale' => 'en',
                        'lines' => [
                            [
                                'product_id' => $this->product->id,
                                'product_unit_id' => $this->productUnit->id,
                                'item_description' => 'Atomicity verified stock line',
                                'quantity' => '2.000000',
                                'unit_price' => '50.000000',
                                'discount_type' => 'none',
                                'discount_value' => '0',
                                'tax_rate_id' => null,
                            ],
                        ],
                    ]);

                    if ($this->failAt === 'draft') {
                        throw new RuntimeException('Simulated fault after invoice draft creation');
                    }

                    // 3. Post sales invoice (stock decrement + FEFO + accounting batch)
                    $posted = app(PostSalesInvoiceAction::class)->execute($invoice, $actor);

                    if ($this->failAt === 'posting_or_stock') {
                        throw new RuntimeException('Simulated fault after invoice posting and inventory decrement');
                    }

                    // 4. Post customer payment
                    $payment = app(PostCustomerPaymentAction::class)->execute($company, $actor, [
                        'customer_id' => $this->customer->id,
                        'money_account_id' => $this->cash->id,
                        'payment_method' => 'cash',
                        'payment_date' => '2026-11-12',
                        'amount' => '100.000000',
                        'exchange_rate' => '1.0000000000',
                        'idempotency_key' => 'atom-pay-'.$idempotencySuffix,
                        'allocations' => [
                            [
                                'sales_invoice_id' => (int) $posted->id,
                                'allocated_amount' => '100.000000',
                            ],
                        ],
                    ]);

                    if ($this->failAt === 'payment') {
                        throw new RuntimeException('Simulated fault after payment creation');
                    }

                    if ($this->failAt === 'before_receipt_commit') {
                        throw new RuntimeException('Simulated fault immediately before receipt record commit');
                    }

                    return [
                        'sales_invoice_id' => (int) $posted->id,
                        'sales_invoice_public_id' => $posted->public_id,
                        'customer_payment_id' => (int) $payment->id,
                        'customer_payment_public_id' => $payment->public_id,
                        'command_receipt_id' => null,
                        'terminal_canonical_result' => [
                            'status' => 'completed',
                            'invoice_number' => $docNumber,
                            'invoice_public_id' => $posted->public_id,
                            'payment_public_id' => $payment->public_id,
                            'grand_total' => '100.000000',
                            'paid_amount' => '100.000000',
                        ],
                    ];
                };

                if ($capability !== null) {
                    return CompositeFinancialExecutionScope::withPermit(
                        $capability,
                        (int) $company->id,
                        (int) $actor->id,
                        $run
                    );
                }

                return $run();
            }
        };
    }

    public function test_full_successful_canonical_execution_commits_all_legs_and_satisfies_all_six_reconciliations(): void
    {
        $payload = '{"action":"checkout","total":"100.000000"}';

        $this->app->instance(
            FinancialExecutionAdapterInterface::class,
            $this->createFaultInjectingExecutor(null)
        );

        $result = $this->manager->recordOrReplay(
            companyId: (int) $this->company->id,
            clientEventId: (string) Str::uuid(),
            tokenHash: $this->token->token_hash,
            eventVersion: 2,
            representation: 'v2_intent',
            canonicalBytes: $payload
        );

        $this->assertSame('created', $result['outcome']);
        $this->assertSame('completed', $result['status']);
        $this->assertNotNull($result['sales_invoice_id']);
        $this->assertNotNull($result['customer_payment_id']);

        // Assert exact database counts
        $this->assertSame(1, DB::table('sales_invoices')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
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

    public function test_fault_after_numbering_rolls_back_entire_state(): void
    {
        $payload = '{"action":"checkout"}';

        $this->app->instance(
            FinancialExecutionAdapterInterface::class,
            $this->createFaultInjectingExecutor('numbering')
        );

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault immediately after numbering', $e->getMessage());
        }

        $this->assertSame(0, DB::table('sales_invoices')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('mobile_event_receipts')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->count()); // only opening stock
        $this->assertSame(1, DB::table('stock_movements')->where('company_id', $this->company->id)->count()); // only opening stock
    }

    public function test_fault_after_posting_and_stock_movement_rolls_back_inventory_and_invoices(): void
    {
        $payload = '{"action":"checkout"}';

        $this->app->instance(
            FinancialExecutionAdapterInterface::class,
            $this->createFaultInjectingExecutor('posting_or_stock')
        );

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault after invoice posting', $e->getMessage());
        }

        $this->assertSame(0, DB::table('sales_invoices')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('mobile_event_receipts')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->count()); // only opening stock
        $this->assertSame(1, DB::table('stock_movements')->where('company_id', $this->company->id)->count()); // only opening stock
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity_base' => '10.000000', // unmutated!
        ]);
    }

    public function test_fault_after_payment_rolls_back_payment_and_invoice(): void
    {
        $payload = '{"action":"checkout"}';

        $this->app->instance(
            FinancialExecutionAdapterInterface::class,
            $this->createFaultInjectingExecutor('payment')
        );

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault after payment creation', $e->getMessage());
        }

        $this->assertSame(0, DB::table('sales_invoices')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('mobile_event_receipts')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('company_id', $this->company->id)->count());
    }

    public function test_fault_before_receipt_commit_leaves_zero_partial_state(): void
    {
        $payload = '{"action":"checkout"}';

        $this->app->instance(
            FinancialExecutionAdapterInterface::class,
            $this->createFaultInjectingExecutor('before_receipt_commit')
        );

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault immediately before receipt record commit', $e->getMessage());
        }

        // NO invoice without receipt, NO cash or stock partial!
        $this->assertSame(0, DB::table('sales_invoices')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('mobile_event_receipts')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('company_id', $this->company->id)->count());
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity_base' => '10.000000',
        ]);
    }

    public function test_fault_after_receipt_creation_rolls_back_entire_state_and_preserves_reconciliations(): void
    {
        $payload = '{"action":"checkout","total":"100.000000"}';

        $this->app->instance(
            FinancialExecutionAdapterInterface::class,
            $this->createFaultInjectingExecutor(null)
        );

        self::$failAfterReceiptCreation = true;

        try {
            $this->manager->recordOrReplay(
                companyId: (int) $this->company->id,
                clientEventId: (string) Str::uuid(),
                tokenHash: $this->token->token_hash,
                eventVersion: 2,
                representation: 'v2_intent',
                canonicalBytes: $payload
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated fault immediately after receipt record creation', $e->getMessage());
        } finally {
            self::$failAfterReceiptCreation = false;
        }

        // Complete atomicity: everything rolled back, including receipt, invoice, payment, movements
        $this->assertSame(0, DB::table('sales_invoices')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('customer_payments')->where('company_id', $this->company->id)->count());
        $this->assertSame(0, DB::table('mobile_event_receipts')->where('company_id', $this->company->id)->count());
        $this->assertSame(1, DB::table('posting_batches')->where('company_id', $this->company->id)->count()); // only opening stock
        $this->assertSame(1, DB::table('stock_movements')->where('company_id', $this->company->id)->count()); // only opening stock
        $this->assertDatabaseHas('inventory_balances', [
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'quantity_base' => '10.000000',
        ]);

        // Restore test context and verify all 6 reconciliations pass cleanly with 0 violations
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->assertTrue(app(AccountingReconciliationService::class)->reconcile($this->company)->isHealthy, 'Accounting reconciliation must pass');
        $this->assertTrue(app(InventoryReconciliationService::class)->auditCompany($this->company)->isHealthy, 'Inventory reconciliation must pass');
        $this->assertTrue(app(SalesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Sales reconciliation must pass');
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy, 'Payables reconciliation must pass');
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy, 'Money reconciliation must pass');
        $this->assertTrue(app(Phase7ReconciliationService::class)->reconcile($this->company)->isHealthy, 'Phase7 reconciliation must pass');
    }
}
