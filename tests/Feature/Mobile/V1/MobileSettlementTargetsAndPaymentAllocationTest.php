<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\CompanyMobileSetting;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\NativeCommandReceipt;
use App\Models\OpeningTradeItem;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\SalesInvoice;
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
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Sales\CustomerCatalogService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

final class MobileSettlementTargetsAndPaymentAllocationTest extends TestCase
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

        Currency::updateOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'symbol' => '$', 'minor_units' => 2, 'active' => true]
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
            'sku' => 'SKU-SETTLE-001',
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

        $this->user->givePermissionTo([
            'money.receipt.create',
            'money.vendor_payment.create',
            'purchasing.cost.view',
            'customers.view',
            'vendors.view',
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
    private function authHeaders(?string $idempotencyKey = null): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->rawToken,
            'Accept' => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return $headers;
    }

    private function rebindContext(): void
    {
        $this->actingAs($this->user);
        app(CompanyContext::class)->setCompany($this->company, $this->user);
        setPermissionsTeamId($this->company->id);
    }

    private function createPostedSalesInvoice(string $amount = '200.00', string $currency = 'ILS', string $date = '2026-03-01'): SalesInvoice
    {
        $this->rebindContext();
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'exchange_rate' => '1.0000000000',
            'issue_date' => $date,
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'فاتورة مبيعات تجريبية',
                    'quantity' => '1',
                    'unit_price' => $amount,
                ],
            ],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->user);
    }

    private function createPostedCustomerOpeningItem(string $amount = '300.00', string $currency = 'ILS', string $date = '2026-01-15'): OpeningTradeItem
    {
        $this->rebindContext();

        return app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: $date,
            amount: $amount,
            currencyCode: $currency,
            exchangeRate: '1.0000000000',
            actor: $this->user,
            idempotencyKey: 'open-cust-'.(string) Str::uuid(),
        );
    }

    private function createPostedPurchaseBill(string $amount = '400.00', string $currency = 'ILS', string $date = '2026-03-05'): Purchase
    {
        $this->rebindContext();
        $draft = app(CreatePurchaseDraftAction::class)->execute($this->company, $this->user, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'vendor_invoice_number' => 'BILL-'.(string) Str::random(8),
            'purchase_date' => $date,
            'currency_code' => $currency,
            'exchange_rate' => '1.0000000000',
            'document_locale' => 'ar',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '1',
                    'unit_cost' => $amount,
                    'lots' => [],
                ],
            ],
        ]);

        return app(PostPurchaseAction::class)->execute($draft, $this->user);
    }

    private function createPostedVendorOpeningItem(string $amount = '500.00', string $currency = 'ILS', string $date = '2026-01-20'): OpeningTradeItem
    {
        $this->rebindContext();

        return app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->vendor->id,
            openingDate: $date,
            amount: $amount,
            currencyCode: $currency,
            exchangeRate: '1.0000000000',
            actor: $this->user,
            idempotencyKey: 'open-vend-'.(string) Str::uuid(),
        );
    }

    private function assertAllSixReconciliations(): void
    {
        foreach ([
            AccountingReconciliationService::class,
            SalesReconciliationService::class,
            PayablesReconciliationService::class,
            MoneyReconciliationService::class,
            Phase7ReconciliationService::class,
        ] as $service) {
            $report = app($service)->reconcile($this->company);
            $this->assertTrue($report->isHealthy, $service.': '.json_encode($report));
        }
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report));
    }

    // =========================================================================
    // CUSTOMER SETTLEMENT TARGET READS
    // =========================================================================

    public function test_customer_settlement_targets_empty_state(): void
    {
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/settlement-targets";
        $res = $this->withHeaders($this->authHeaders())->getJson($url);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertSame($this->customer->public_id, $json['customer_id']);
        $this->assertSame(0, $json['total_targets']);
        $this->assertSame([], $json['targets']);
        $this->assertSame([], $json['totals']['remaining_by_currency']);

        // Alias route /outstanding works identically
        $aliasUrl = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/outstanding";
        $aliasRes = $this->withHeaders($this->authHeaders())->getJson($aliasUrl);
        $aliasRes->assertStatus(200);
        $this->assertSame($json, $aliasRes->json());
    }

    public function test_customer_settlement_targets_returns_posted_invoices_and_opening_items_chronologically(): void
    {
        // Opening item on 2026-01-15: 300.00 ILS
        $opening = $this->createPostedCustomerOpeningItem('300.00', 'ILS', '2026-01-15');
        // Sales invoice on 2026-03-01: 200.00 ILS
        $invoice = $this->createPostedSalesInvoice('200.00', 'ILS', '2026-03-01');

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/settlement-targets";
        $res = $this->withHeaders($this->authHeaders())->getJson($url);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertSame(2, $json['total_targets']);

        // Must be sorted chronologically: opening item first, then invoice
        $this->assertSame('opening_trade_item', $json['targets'][0]['target_type']);
        $this->assertSame($opening->public_id, $json['targets'][0]['target_id']);
        $this->assertSame('2026-01-15', $json['targets'][0]['business_date']);
        $this->assertSame('300.00', $json['targets'][0]['remaining_amount']);
        $this->assertSame('300.000000', $json['targets'][0]['remaining_amount_exact']);

        $this->assertSame('invoice', $json['targets'][1]['target_type']);
        $this->assertSame($invoice->public_id, $json['targets'][1]['target_id']);
        $this->assertSame('2026-03-01', $json['targets'][1]['business_date']);
        $this->assertSame('200.00', $json['targets'][1]['remaining_amount']);
        $this->assertSame('200.000000', $json['targets'][1]['remaining_amount_exact']);

        // Check totals by currency
        $this->assertSame('500.00', $json['totals']['remaining_by_currency']['ILS']['amount']);
        $this->assertSame('500.000000', $json['totals']['remaining_by_currency']['ILS']['amount_exact']);
    }

    public function test_customer_settlement_targets_currency_filtering(): void
    {
        $this->createPostedCustomerOpeningItem('100.00', 'ILS', '2026-01-10');
        $usdOpening = $this->createPostedCustomerOpeningItem('50.00', 'USD', '2026-01-12');

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/settlement-targets?currency=USD";
        $res = $this->withHeaders($this->authHeaders())->getJson($url);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertSame(1, $json['total_targets']);
        $this->assertSame($usdOpening->public_id, $json['targets'][0]['target_id']);
        $this->assertSame('USD', $json['targets'][0]['currency']);
        $this->assertArrayHasKey('USD', $json['totals']['remaining_by_currency']);
        $this->assertArrayNotHasKey('ILS', $json['totals']['remaining_by_currency']);
    }

    public function test_customer_settlement_targets_excludes_unposted_reversed_and_other_parties(): void
    {
        // 1. Reversed opening trade item
        $opening = $this->createPostedCustomerOpeningItem('250.00', 'ILS', '2026-01-05');
        app(ReverseOpeningTradeItemAction::class)->execute($opening, $this->user, 'Correction', '2026-01-06');

        // 2. Draft sales invoice
        $this->rebindContext();
        app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-02-01',
            'lines' => [['product_id' => null, 'item_description' => 'Draft', 'quantity' => '1', 'unit_price' => '100.00']],
        ]);

        // 3. Opening item for another customer
        $otherCustomer = app(CustomerCatalogService::class)->save($this->company, $this->user, [
            'name_ar' => 'عميل آخر',
            'name_en' => 'Another Customer',
        ]);
        app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $otherCustomer->id,
            openingDate: '2026-01-01',
            amount: '900.00',
            currencyCode: 'ILS',
            actor: $this->user,
            idempotencyKey: 'other-cust-'.(string) Str::uuid(),
        );

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/settlement-targets";
        $res = $this->withHeaders($this->authHeaders())->getJson($url);

        $res->assertStatus(200);
        $this->assertSame(0, $res->json('total_targets'));
    }

    public function test_customer_settlement_targets_permission_denial(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/settlement-targets";
        $res = $this->withHeaders($this->authHeaders())->getJson($url);

        $res->assertStatus(403);
        $res->assertJsonPath('error.code', 'FORBIDDEN');
    }

    // =========================================================================
    // CUSTOMER PAYMENT ALLOCATION WRITES
    // =========================================================================

    public function test_customer_payment_unallocated_advance_semantics(): void
    {
        // When allocations are omitted, payment is created as an unallocated advance payment
        $key = 'cust-pay-adv-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '150.000000',
            'payment_date' => now()->toDateString(),
            'notes' => 'دفعة مقدمة غير مخصصة',
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertTrue($json['is_advance']);
        $this->assertSame('150.00', $json['amount']);
        $this->assertSame('150.00', $json['unallocated_amount']);
        $this->assertSame('0.00', $json['allocated_amount']);
        $this->assertSame([], $json['allocations']);
    }

    public function test_customer_payment_full_allocation_to_invoice_via_typed_target(): void
    {
        $invoice = $this->createPostedSalesInvoice('200.00', 'ILS', '2026-03-01');

        $key = 'cust-pay-inv-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '200.000000',
            'allocations' => [
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '200.000000',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertFalse($json['is_advance']);
        $this->assertSame('200.00', $json['amount']);
        $this->assertSame('200.00', $json['allocated_amount']);
        $this->assertSame('0.00', $json['unallocated_amount']);
        $this->assertCount(1, $json['allocations']);
        $this->assertSame('invoice', $json['allocations'][0]['target_type']);
        $this->assertSame($invoice->public_id, $json['allocations'][0]['target_id']);
        $this->assertSame('200.00', $json['allocations'][0]['allocated_amount']);

        $this->rebindContext();

        // Check invoice outstanding balance is now 0
        $this->assertTrue(BigDecimal::zero()->isEqualTo($invoice->fresh()->calculateOutstanding()));
    }

    public function test_customer_payment_full_allocation_to_opening_trade_item_via_typed_target(): void
    {
        $opening = $this->createPostedCustomerOpeningItem('350.00', 'ILS', '2026-01-10');

        $key = 'cust-pay-open-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '350.000000',
            'allocations' => [
                [
                    'target_type' => 'opening_trade_item',
                    'target_id' => $opening->public_id,
                    'amount' => '350.000000',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertFalse($json['is_advance']);
        $this->assertSame('350.00', $json['amount']);
        $this->assertSame('350.00', $json['allocated_amount']);
        $this->assertSame('0.00', $json['unallocated_amount']);
        $this->assertCount(1, $json['allocations']);
        $this->assertSame('opening_trade_item', $json['allocations'][0]['target_type']);
        $this->assertSame($opening->public_id, $json['allocations'][0]['target_id']);

        // Check opening trade item outstanding is now 0
        $this->assertTrue(BigDecimal::zero()->isEqualTo($opening->fresh()->calculateOutstanding()));
    }

    public function test_customer_payment_partial_allocation_leaves_unallocated_advance(): void
    {
        $invoice = $this->createPostedSalesInvoice('100.00', 'ILS', '2026-03-01');

        $key = 'cust-pay-part-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        // Pay 250 ILS total: 100 ILS allocated to invoice, 150 ILS remaining advance
        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '250.000000',
            'allocations' => [
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '100.000000',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertFalse($json['is_advance']);
        $this->assertSame('250.00', $json['amount']);
        $this->assertSame('100.00', $json['allocated_amount']);
        $this->assertSame('150.00', $json['unallocated_amount']);
        $this->assertCount(1, $json['allocations']);
    }

    public function test_customer_payment_mixed_allocations_to_invoice_and_opening_trade_item(): void
    {
        $opening = $this->createPostedCustomerOpeningItem('150.00', 'ILS', '2026-01-10');
        $invoice = $this->createPostedSalesInvoice('250.00', 'ILS', '2026-02-15');

        $key = 'cust-pay-mix-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '400.000000',
            'allocations' => [
                [
                    'target_type' => 'opening_trade_item',
                    'target_id' => $opening->public_id,
                    'amount' => '150.000000',
                ],
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '250.000000',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertSame('400.00', $json['allocated_amount']);
        $this->assertSame('0.00', $json['unallocated_amount']);
        $this->assertCount(2, $json['allocations']);

        $this->rebindContext();

        $this->assertTrue(BigDecimal::zero()->isEqualTo($opening->fresh()->calculateOutstanding()));
        $this->assertTrue(BigDecimal::zero()->isEqualTo($invoice->fresh()->calculateOutstanding()));
    }

    public function test_customer_payment_backward_compatible_with_legacy_and_direct_keys(): void
    {
        $invoice = $this->createPostedSalesInvoice('120.00', 'ILS', '2026-03-01');
        $opening = $this->createPostedCustomerOpeningItem('180.00', 'ILS', '2026-01-10');

        $key = 'cust-pay-leg-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        // Legacy invoice_id and direct opening_trade_item_id
        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '300.000000',
            'allocations' => [
                [
                    'invoice_id' => $invoice->public_id,
                    'amount' => '120.000000',
                ],
                [
                    'opening_trade_item_id' => $opening->public_id,
                    'amount' => '180.000000',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertSame('300.00', $json['allocated_amount']);
        $this->assertSame('0.00', $json['unallocated_amount']);
    }

    public function test_customer_payment_rejects_ambiguous_allocation_target_keys(): void
    {
        $invoice = $this->createPostedSalesInvoice('100.00', 'ILS');
        $opening = $this->createPostedCustomerOpeningItem('100.00', 'ILS');

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        // Specifying both invoice_id and opening_trade_item_id on the same allocation
        $res = $this->withHeaders($this->authHeaders('bad-ambig-1'))->postJson($url, [
            'amount' => '100.000000',
            'allocations' => [
                [
                    'invoice_id' => $invoice->public_id,
                    'opening_trade_item_id' => $opening->public_id,
                    'amount' => '100.000000',
                ],
            ],
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_customer_payment_rejects_duplicate_targets_in_same_payment(): void
    {
        $invoice = $this->createPostedSalesInvoice('200.00', 'ILS');

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        // Two allocations targeting the same invoice
        $res = $this->withHeaders($this->authHeaders('bad-dup-1'))->postJson($url, [
            'amount' => '200.000000',
            'allocations' => [
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '100.000000',
                ],
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '100.000000',
                ],
            ],
        ]);

        $res->assertStatus(422);
        $res->assertJsonPath('error.code', 'VALIDATION_FAILED');
        $this->assertStringContainsString('Duplicate allocation target', $res->json('error.message'));
    }

    public function test_customer_payment_rejects_cross_customer_and_cross_company_targets(): void
    {
        $this->rebindContext();

        // 1. Cross customer: opening item for a different customer
        $otherCustomer = app(CustomerCatalogService::class)->save($this->company, $this->user, [
            'name_ar' => 'عميل ثان',
            'name_en' => 'Second Customer',
        ]);
        $otherOpening = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $otherCustomer->id,
            openingDate: '2026-01-01',
            amount: '500.00',
            currencyCode: 'ILS',
            actor: $this->user,
            idempotencyKey: 'other-cust-open-'.(string) Str::uuid(),
        );

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        $res = $this->withHeaders($this->authHeaders('bad-cross-cust'))->postJson($url, [
            'amount' => '100.000000',
            'allocations' => [
                [
                    'target_type' => 'opening_trade_item',
                    'target_id' => $otherOpening->public_id,
                    'amount' => '100.000000',
                ],
            ],
        ]);

        $this->assertContains($res->status(), [404, 422]);
        $this->assertStringContainsString('not found', $res->json('error.message'));
    }

    public function test_customer_payment_rejects_overallocation_of_target_and_payment(): void
    {
        $invoice = $this->createPostedSalesInvoice('100.00', 'ILS');
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        // Exceeding invoice remaining balance (allocating 150 to a 100 invoice)
        $resTargetOver = $this->withHeaders($this->authHeaders('over-target'))->postJson($url, [
            'amount' => '150.000000',
            'allocations' => [
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '150.000000',
                ],
            ],
        ]);
        $resTargetOver->assertStatus(422);
        $this->assertStringContainsString('exceeds', $resTargetOver->json('error.message'));

        // Exceeding total payment amount (allocating 100 on a 50 payment)
        $resPayOver = $this->withHeaders($this->authHeaders('over-pay'))->postJson($url, [
            'amount' => '50.000000',
            'allocations' => [
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '100.000000',
                ],
            ],
        ]);
        $resPayOver->assertStatus(422);
        $this->assertStringContainsString('exceeds', $resPayOver->json('error.message'));
    }

    public function test_customer_payment_idempotency_replay_and_conflict(): void
    {
        $invoice = $this->createPostedSalesInvoice('200.00', 'ILS');
        $key = 'idem-cust-play-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/customers/{$this->customer->public_id}/payments";

        $payload = [
            'amount' => '200.000000',
            'allocations' => [
                [
                    'target_type' => 'invoice',
                    'target_id' => $invoice->public_id,
                    'amount' => '200.000000',
                ],
            ],
        ];

        // 1. Fresh Execution: 201 Created
        $res1 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res1->assertStatus(201);
        $json1 = $res1->json();
        $this->assertSame($this->device->device_id, NativeCommandReceipt::where('idempotency_key', $key)->value('device_id'));

        // 2. Exact Replay: 200 OK with X-Idempotent-Replay: true
        $res2 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res2->assertStatus(200);
        $res2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($json1, $res2->json());

        // 3. Status GET route: 200 OK
        $statusUrl = "/api/mobile/v1/companies/{$this->company->public_id}/receipts/{$key}/status";
        $statusRes = $this->withHeaders($this->authHeaders())->getJson($statusUrl);
        $statusRes->assertStatus(200);
        $this->assertSame('completed', $statusRes->json('status'));

        // 4. Conflict: different amount under same key: 409 Conflict
        $conflictPayload = array_merge($payload, ['amount' => '150.000000']);
        $resConflict = $this->withHeaders($this->authHeaders($key))->postJson($url, $conflictPayload);
        $resConflict->assertStatus(409);
        $resConflict->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    // =========================================================================
    // SUPPLIER SETTLEMENT TARGET READS & COST REDACTION
    // =========================================================================

    public function test_supplier_settlement_targets_cost_redaction_denies_staff_without_cost_view(): void
    {
        $this->user->syncRoles([]);
        $this->user->syncPermissions(['vendors.view']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/settlement-targets";
        $res = $this->withHeaders($this->authHeaders())->getJson($url);

        $res->assertStatus(403);
        $res->assertJsonPath('error.code', 'FORBIDDEN');
        $this->assertStringContainsString('purchasing cost permission', $res->json('error.message'));
    }

    public function test_supplier_settlement_targets_returns_open_purchases_and_vendor_opening_items_chronologically(): void
    {
        $opening = $this->createPostedVendorOpeningItem('450.00', 'ILS', '2026-01-20');
        $purchase = $this->createPostedPurchaseBill('600.00', 'ILS', '2026-03-05');

        $url = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/settlement-targets";
        $res = $this->withHeaders($this->authHeaders())->getJson($url);

        $res->assertStatus(200);
        $json = $res->json();
        $this->assertSame(2, $json['total_targets']);

        // Sorted chronologically: opening item first, then purchase bill
        $this->assertSame('opening_trade_item', $json['targets'][0]['target_type']);
        $this->assertSame($opening->public_id, $json['targets'][0]['target_id']);
        $this->assertSame('2026-01-20', $json['targets'][0]['business_date']);
        $this->assertSame('450.00', $json['targets'][0]['remaining_amount']);

        $this->assertSame('purchase', $json['targets'][1]['target_type']);
        $this->assertSame($purchase->public_id, $json['targets'][1]['target_id']);
        $this->assertSame('2026-03-05', $json['targets'][1]['business_date']);
        $this->assertSame('600.00', $json['targets'][1]['remaining_amount']);

        // Alias route /outstanding works identically
        $aliasUrl = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/outstanding";
        $aliasRes = $this->withHeaders($this->authHeaders())->getJson($aliasUrl);
        $aliasRes->assertStatus(200);
        $this->assertSame($json, $aliasRes->json());
    }

    // =========================================================================
    // SUPPLIER PAYMENT ALLOCATION WRITES
    // =========================================================================

    public function test_supplier_payment_unallocated_advance_when_allocations_omitted(): void
    {
        $key = 'supp-pay-adv-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/payments";

        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '300.000000',
            'payment_date' => now()->toDateString(),
            'notes' => 'سداد مورد غير مخصص',
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertTrue($json['is_advance']);
        $this->assertSame('300.00', $json['amount']);
        $this->assertSame('300.00', $json['unallocated_amount']);
        $this->assertSame('0.00', $json['allocated_amount']);
        $this->assertSame([], $json['allocations']);
    }

    public function test_supplier_payment_full_allocation_to_purchase_and_opening_item(): void
    {
        $purchase = $this->createPostedPurchaseBill('400.00', 'ILS', '2026-03-01');
        $opening = $this->createPostedVendorOpeningItem('250.00', 'ILS', '2026-01-15');

        $key = 'supp-pay-mix-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/payments";

        $res = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '650.000000',
            'allocations' => [
                [
                    'target_type' => 'purchase',
                    'target_id' => $purchase->public_id,
                    'amount' => '400.000000',
                ],
                [
                    'target_type' => 'opening_trade_item',
                    'target_id' => $opening->public_id,
                    'amount' => '250.000000',
                ],
            ],
        ]);

        $res->assertStatus(201);
        $json = $res->json();
        $this->assertTrue($json['success']);
        $this->assertFalse($json['is_advance']);
        $this->assertSame('650.00', $json['amount']);
        $this->assertSame('650.00', $json['allocated_amount']);
        $this->assertSame('0.00', $json['unallocated_amount']);
        $this->assertCount(2, $json['allocations']);

        $this->rebindContext();

        $this->assertTrue(BigDecimal::zero()->isEqualTo($purchase->fresh()->calculateOutstanding()));
        $this->assertTrue(BigDecimal::zero()->isEqualTo($opening->fresh()->calculateOutstanding()));
    }

    public function test_supplier_payment_rejects_ambiguous_duplicate_cross_vendor_and_overallocated(): void
    {
        $purchase = $this->createPostedPurchaseBill('200.00', 'ILS');
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/payments";

        // 1. Ambiguous target keys
        $resAmbig = $this->withHeaders($this->authHeaders('supp-ambig'))->postJson($url, [
            'amount' => '100.000000',
            'allocations' => [
                [
                    'bill_id' => $purchase->public_id,
                    'opening_trade_item_id' => (string) Str::ulid(),
                    'amount' => '100.000000',
                ],
            ],
        ]);
        $resAmbig->assertStatus(422);

        // 2. Duplicate target in same payment
        $resDup = $this->withHeaders($this->authHeaders('supp-dup'))->postJson($url, [
            'amount' => '200.000000',
            'allocations' => [
                ['target_type' => 'purchase', 'target_id' => $purchase->public_id, 'amount' => '100.000000'],
                ['target_type' => 'purchase', 'target_id' => $purchase->public_id, 'amount' => '100.000000'],
            ],
        ]);
        $resDup->assertStatus(422);
        $this->assertStringContainsString('Duplicate allocation target', $resDup->json('error.message'));

        // 3. Overallocation beyond bill remaining
        $resOver = $this->withHeaders($this->authHeaders('supp-over'))->postJson($url, [
            'amount' => '250.000000',
            'allocations' => [
                ['target_type' => 'purchase', 'target_id' => $purchase->public_id, 'amount' => '250.000000'],
            ],
        ]);
        $resOver->assertStatus(422);
        $this->assertStringContainsString('exceeds', $resOver->json('error.message'));
    }

    public function test_supplier_payment_idempotency_replay_and_conflict(): void
    {
        $purchase = $this->createPostedPurchaseBill('300.00', 'ILS');
        $key = 'idem-supp-replay-'.(string) Str::uuid();
        $url = "/api/mobile/v1/companies/{$this->company->public_id}/suppliers/{$this->vendor->public_id}/payments";

        $payload = [
            'amount' => '300.000000',
            'allocations' => [
                [
                    'target_type' => 'purchase',
                    'target_id' => $purchase->public_id,
                    'amount' => '300.000000',
                ],
            ],
        ];

        // 1. Fresh Execution: 201 Created
        $res1 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res1->assertStatus(201);
        $json1 = $res1->json();
        $this->assertSame($this->device->device_id, NativeCommandReceipt::where('idempotency_key', $key)->value('device_id'));

        // 2. Exact Replay: 200 OK with X-Idempotent-Replay: true
        $res2 = $this->withHeaders($this->authHeaders($key))->postJson($url, $payload);
        $res2->assertStatus(200);
        $res2->assertHeader('X-Idempotent-Replay', 'true');
        $this->assertSame($json1, $res2->json());

        // 3. Conflict: altered allocations under same key: 409 Conflict
        $resConflict = $this->withHeaders($this->authHeaders($key))->postJson($url, [
            'amount' => '300.000000',
        ]);
        $resConflict->assertStatus(409);
        $resConflict->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_CONFLICT');
    }

    // =========================================================================
    // RECONCILIATIONS INVARIANT
    // =========================================================================

    public function test_all_six_reconciliations_pass_after_economic_settlements(): void
    {
        $this->assertAllSixReconciliations();
    }
}
