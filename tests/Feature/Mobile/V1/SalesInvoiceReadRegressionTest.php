<?php

declare(strict_types=1);

namespace Tests\Feature\Mobile\V1;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\InventoryOperation;
use App\Models\MobileAccessToken;
use App\Models\MobileDevice;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;
use App\Support\Tenancy\CompanyScope;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class SalesInvoiceReadRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private Customer $customer;

    private MoneyAccount $cashAccount;

    private MoneyAccount $bankAccount;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mobile.enabled' => true, 'mobile.merchant_api_enabled' => true]);

        Currency::updateOrCreate(['code' => 'ILS'], ['name' => 'Shekel', 'symbol' => 'ILS', 'minor_units' => 2, 'active' => true]);
        Currency::updateOrCreate(['code' => 'JOD'], ['name' => 'Jordanian Dinar', 'symbol' => 'JD', 'minor_units' => 3, 'active' => true]);
        Currency::updateOrCreate(['code' => 'JPY'], ['name' => 'Japanese Yen', 'symbol' => '¥', 'minor_units' => 0, 'active' => true]);

        $this->owner = User::factory()->create(['name' => 'المالك الأصلي']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة الاختبارات الأصيلة',
            'name_en' => 'Authentic Test Co',
            'base_currency_code' => 'ILS',
        ]);

        $this->company->update([
            'phone' => '0599000111',
            'tax_number' => '987654321',
        ]);

        setPermissionsTeamId($this->company->id);
        $this->owner->assignRole('Owner');

        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);

        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'ILS'], ['enabled' => true, 'is_base' => true]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JOD'], ['enabled' => true, 'is_base' => false]);
        CompanyCurrency::firstOrCreate(['company_id' => $this->company->id, 'currency_code' => 'JPY'], ['enabled' => true, 'is_base' => false]);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الاختبار الأصلي',
            'name_en' => 'Original Customer',
            'phone' => '0599123456',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $moneyAccountAction = app(CreateMoneyAccountAction::class);
        $this->cashAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق الكاش الرئيسي',
            'name_en' => 'Main Cash Box',
        ]);

        $this->bankAccount = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_BANK,
            'currency_code' => 'ILS',
            'name_ar' => 'حساب البنك الوطني',
            'name_en' => 'National Bank Account',
        ]);

        $device = (string) Str::uuid();
        MobileDevice::create(['device_id' => $device, 'device_name' => 'Regression Device', 'user_id' => $this->owner->id]);
        $rawToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $rawToken),
            'device_id' => $device,
            'user_id' => $this->owner->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $this->owner->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);
        $this->token = $rawToken;
        $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function createAndPostInvoice(
        string $currency = 'ILS',
        string $exchangeRate = '1.0000000000',
        string $amount = '100.000000',
        string $quantity = '1.000000',
        ?int $productId = null
    ): SalesInvoice {
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        setPermissionsTeamId($this->company->id);

        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'exchange_rate' => $exchangeRate,
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => $productId,
                    'item_description' => 'خدمة توريد',
                    'quantity' => $quantity,
                    'unit_price' => $amount,
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    public function test_canonical_posted_financial_summaries_for_cash_bank_partial_and_unpaid(): void
    {
        $paymentAction = app(PostCustomerPaymentAction::class);

        // 1. Full cash paid invoice
        $invFullCash = $this->createAndPostInvoice('ILS', '1.0000000000', '100.000000');
        $paymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->cashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'reg-cash-'.(string) Str::uuid(),
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                ['sales_invoice_id' => $invFullCash->id, 'allocated_amount' => '100.000000'],
            ],
        ]);

        // 2. Full bank paid invoice
        $invFullBank = $this->createAndPostInvoice('ILS', '1.0000000000', '120.000000');
        $paymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->bankAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'reg-bank-'.(string) Str::uuid(),
            'payment_method' => CustomerPayment::METHOD_BANK,
            'amount' => '120.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                ['sales_invoice_id' => $invFullBank->id, 'allocated_amount' => '120.000000'],
            ],
        ]);

        // 3. Partially paid invoice
        $invPartial = $this->createAndPostInvoice('ILS', '1.0000000000', '200.000000');
        $paymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->cashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'reg-part-'.(string) Str::uuid(),
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '75.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                ['sales_invoice_id' => $invPartial->id, 'allocated_amount' => '75.000000'],
            ],
        ]);

        // 4. Unpaid invoice
        $invUnpaid = $this->createAndPostInvoice('ILS', '1.0000000000', '300.000000');

        // Test show for full cash
        $resCash = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invFullCash->public_id}")
            ->assertOk()
            ->assertJsonPath('payment_status', 'PAID_CASH')
            ->assertJsonPath('paid', '100.00')
            ->assertJsonPath('remaining', '0.00')
            ->assertJsonPath('currency_minor_units', 2);

        // Test show for full bank (MUST NOT be PAID_CASH)
        $resBank = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invFullBank->public_id}")
            ->assertOk()
            ->assertJsonPath('payment_status', 'PAID')
            ->assertJsonPath('paid', '120.00')
            ->assertJsonPath('remaining', '0.00');

        // Test show for partial
        $resPart = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invPartial->public_id}")
            ->assertOk()
            ->assertJsonPath('payment_status', 'PARTIAL')
            ->assertJsonPath('paid', '75.00')
            ->assertJsonPath('remaining', '125.00');

        // Test show for unpaid
        $resUnpaid = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invUnpaid->public_id}")
            ->assertOk()
            ->assertJsonPath('payment_status', 'ON_CREDIT')
            ->assertJsonPath('paid', '0.00')
            ->assertJsonPath('remaining', '300.00');

        // Test index filters
        $indexCash = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?status=PAID_CASH")
            ->assertOk();
        $cashInvoices = is_array($indexCash->json('invoices')) ? $indexCash->json('invoices') : [];
        $cashIds = array_column($cashInvoices, 'id');
        $this->assertContains($invFullCash->public_id, $cashIds);
        $this->assertNotContains($invFullBank->public_id, $cashIds);
        $this->assertNotContains($invPartial->public_id, $cashIds);
        $this->assertNotContains($invUnpaid->public_id, $cashIds);

        $indexPart = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?status=PARTIAL")
            ->assertOk();
        $partInvoices = is_array($indexPart->json('invoices')) ? $indexPart->json('invoices') : [];
        $partIds = array_column($partInvoices, 'id');
        $this->assertContains($invPartial->public_id, $partIds);
        $this->assertNotContains($invFullCash->public_id, $partIds);

        $indexCredit = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?status=ON_CREDIT")
            ->assertOk();
        $creditInvoices = is_array($indexCredit->json('invoices')) ? $indexCredit->json('invoices') : [];
        $creditIds = array_column($creditInvoices, 'id');
        $this->assertContains($invUnpaid->public_id, $creditIds);
        $this->assertNotContains($invFullCash->public_id, $creditIds);
    }

    public function test_mutate_master_customer_product_and_company_after_posting_leaves_history_frozen(): void
    {
        $unit = Unit::where('company_id', $this->company->id)->where('code', 'box')->firstOrFail();

        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'FROZEN-SKU',
            'name_ar' => 'صنف قبل التعديل',
            'name_en' => 'Pre-edit Product',
            'base_unit_id' => $unit->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => false,
            'default_sale_price_base' => '50.000000',
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
            'active' => true,
        ]);

        $invoice = $this->createAndPostInvoice('ILS', '1.0000000000', '50.000000', '1.000000', $product->id);

        // Verify initial posted state
        $resInitial = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoice->public_id}")
            ->assertOk()
            ->assertJsonPath('customer_name', 'عميل الاختبار الأصلي')
            ->assertJsonPath('lines.0.product_name', 'صنف قبل التعديل')
            ->assertJsonPath('lines.0.unit', 'صندوق')
            ->assertJsonPath('thermal_receipt.store_name_ar', 'شركة الاختبارات الأصيلة')
            ->assertJsonPath('thermal_receipt.phone', '0599000111')
            ->assertJsonPath('thermal_receipt.tax_number', '987654321')
            ->assertJsonPath('thermal_receipt.cashier', 'المالك الأصلي');

        // Now mutate master customer, product, and company
        $this->customer->update([
            'name_ar' => 'اسم عميل تم تغييره جذريا',
            'phone' => '0599999999',
        ]);

        $product->update([
            'name_ar' => 'صنف جديد كليا بعد التعديل',
        ]);

        $unit->update([
            'name_ar' => 'وحدة جديدة بعد التعديل',
        ]);

        $this->company->update([
            'name_ar' => 'شركة بعد التعديل والتحوير',
            'phone' => '0500000000',
            'tax_number' => '111111111',
        ]);

        // Create a SECOND user who views the invoice to ensure viewing user is NOT reported as cashier
        $viewer = User::factory()->create(['name' => 'مشاهد آخر جديد']);
        $viewer->companies()->attach($this->company->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);
        $ownerRole = Role::where('company_id', $this->company->id)->where('name', 'Owner')->firstOrFail();
        $viewer->assignRole($ownerRole);
        $viewerDevice = (string) Str::uuid();
        MobileDevice::create(['device_id' => $viewerDevice, 'device_name' => 'Viewer Device', 'user_id' => $viewer->id]);
        $viewerToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $viewerToken),
            'device_id' => $viewerDevice,
            'user_id' => $viewer->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $viewer->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);

        // Re-read invoice as the second user
        $this->withHeader('Authorization', 'Bearer '.$viewerToken);
        $resAfterMutation = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoice->public_id}")
            ->assertOk();

        // Provenance & snapshots must remain FROZEN to the posting time!
        $this->assertSame('عميل الاختبار الأصلي', $resAfterMutation->json('customer_name'));
        $this->assertSame('صنف قبل التعديل', $resAfterMutation->json('lines.0.product_name'));
        $this->assertSame('صندوق', $resAfterMutation->json('lines.0.unit'));
        $this->assertSame('شركة الاختبارات الأصيلة', $resAfterMutation->json('thermal_receipt.store_name_ar'));
        $this->assertSame('0599000111', $resAfterMutation->json('thermal_receipt.phone'));
        $this->assertSame('987654321', $resAfterMutation->json('thermal_receipt.tax_number'));
        $this->assertSame('المالك الأصلي', $resAfterMutation->json('thermal_receipt.cashier'));
        $this->assertNotSame('مشاهد آخر جديد', $resAfterMutation->json('thermal_receipt.cashier'));
    }

    public function test_authoritative_currency_minor_units_and_microquantity_and_unit_price_precision(): void
    {
        // 1. 3 decimal currency (JOD)
        $invJod = $this->createAndPostInvoice('JOD', '5.0000000000', '12.345000', '1.000000');
        $resJod = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invJod->public_id}")
            ->assertOk()
            ->assertJsonPath('currency', 'JOD')
            ->assertJsonPath('currency_minor_units', 3)
            ->assertJsonPath('total', '12.345')
            ->assertJsonPath('subtotal', '12.345');

        // 2. 0 decimal currency (JPY)
        $invJpy = $this->createAndPostInvoice('JPY', '0.0250000000', '500.000000', '1.000000');
        $resJpy = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invJpy->public_id}")
            ->assertOk()
            ->assertJsonPath('currency', 'JPY')
            ->assertJsonPath('currency_minor_units', 0)
            ->assertJsonPath('total', '500')
            ->assertJsonPath('subtotal', '500');

        // 3. Microquantity and arbitrary unit price precision
        $invPrecise = $this->createAndPostInvoice('ILS', '1.0000000000', '123.456789', '1.000125');
        $resPrecise = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invPrecise->public_id}")
            ->assertOk();

        $line = $resPrecise->json('lines.0');
        // Quantity must NOT be truncated to 4 decimals (e.g. '1.0001')
        $this->assertSame('1.000125', $line['quantity']);
        // Unit price must NOT be truncated to 2 decimals (e.g. '123.45')
        $this->assertSame('123.456789', $line['unit_price']);
    }

    public function test_lines_expose_public_id_and_not_integer_id(): void
    {
        $invoice = $this->createAndPostInvoice('ILS', '1.0000000000', '10.000000', '1.000000');
        $firstLine = $invoice->lines()->firstOrFail();

        $res = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoice->public_id}")
            ->assertOk();

        $linePayload = $res->json('lines.0');
        $this->assertSame($firstLine->public_id, $linePayload['id']);
        $this->assertNotSame((string) $firstLine->id, $linePayload['id']);
        $this->assertMatchesRegularExpression('/\A[0-7][0-9A-HJKMNP-TV-Z]{25}\z/', $linePayload['id']);
    }

    public function test_tenant_and_internal_id_rejection_on_movements_and_invoices(): void
    {
        $unit = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $product = Product::create([
            'company_id' => $this->company->id,
            'sku' => 'TEST-MOV-SKU',
            'name_ar' => 'صنف الحركات',
            'base_unit_id' => $unit->id,
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'default_sale_price_base' => '10.000000',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $op = InventoryOperation::create([
            'company_id' => $this->company->id,
            'operation_type' => 'movement',
            'idempotency_key' => (string) Str::ulid(),
            'line_count' => 1,
            'request_hash' => hash('sha256', 'reg-test-movement'),
            'created_by' => $this->owner->id,
        ]);

        // Create a movement
        StockMovement::create([
            'company_id' => $this->company->id,
            'public_id' => (string) Str::ulid(),
            'inventory_operation_id' => $op->id,
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'movement_type' => StockMovement::TYPE_OPENING_BALANCE,
            'movement_date' => Carbon::now()->toDateString(),
            'quantity_delta_base' => '5.000000',
            'unit_cost_base' => '10.000000',
            'value_delta_base' => '50.000000',
            'average_cost_after' => '10.000000',
            'quantity_after_product_company' => '5.000000',
            'source_type' => 'manual',
            'source_id' => 1,
            'idempotency_key' => (string) Str::ulid(),
            'reason' => 'حركة رصيد أولي',
            'created_by' => $this->owner->id,
            'created_at' => now(),
        ]);

        // 1. Valid ULID on movements route succeeds
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products/{$product->public_id}/movements")
            ->assertOk()
            ->assertJsonPath('product_id', $product->public_id);

        // 2. Integer ID fallback on movements route MUST be rejected with 404
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products/{$product->id}/movements")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        // 3. Noncanonical ULID on movements route MUST be rejected with 404
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products/NOT-A-ULID/movements")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        // 4. Create another company and verify cross-company rejection
        $otherOwner = User::factory()->create();
        $otherCompany = Company::factory()->create([
            'name_ar' => 'شركة أخرى معزولة',
            'base_currency_code' => 'ILS',
            'status' => 'active',
        ]);
        $otherProduct = CompanyScope::executeWithoutScope(function () use ($otherCompany, $otherOwner) {
            $otherUnit = Unit::create([
                'company_id' => $otherCompany->id,
                'code' => 'other-piece',
                'name_ar' => 'قطعة أخرى',
                'name_en' => 'Other Piece',
                'symbol_ar' => 'قطعة',
                'symbol_en' => 'pc',
                'allows_fraction' => false,
                'decimal_places' => 0,
                'active' => true,
            ]);

            return Product::create([
                'company_id' => $otherCompany->id,
                'sku' => 'OTHER-SKU',
                'name_ar' => 'صنف شركة أخرى',
                'base_unit_id' => $otherUnit->id,
                'product_type' => Product::TYPE_STOCK,
                'track_stock' => false,
                'active' => true,
                'created_by' => $otherOwner->id,
            ]);
        });

        // Querying other company's product from our company must return 404
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/products/{$otherProduct->public_id}/movements")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        // 5. Invoices route: integer ID rejection
        $invoice = $this->createAndPostInvoice('ILS', '1.0000000000', '10.000000', '1.000000');
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/{$invoice->id}")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        // 6. Invoices route: noncanonical ULID rejection
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices/INVALID-ULID")
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'NOT_FOUND');

        // 7. Invoices index: customer_id filter rejection when invalid or cross-tenant
        $resFilter = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?customer_id=INVALID-ULID")
            ->assertOk()
            ->assertJsonPath('count', 0);
    }

    public function test_invoice_cursor_pagination_first_page_subsequent_pages_and_exhaustion(): void
    {
        // Seed 75 invoices for pagination test (with limit=30, page 1: 30, page 2: 30, page 3: 15)
        $invoiceIds = [];
        for ($i = 1; $i <= 75; $i++) {
            $inv = $this->createAndPostInvoice(
                currency: 'ILS',
                exchangeRate: '1.0000000000',
                amount: (string) ($i * 10).'.000000',
                quantity: '1.000000'
            );
            $invoiceIds[] = $inv->public_id;
        }

        // Invoices are created in ascending order; descending order means newest first
        $expectedOrder = array_reverse($invoiceIds);

        // 1. First page: limit=30
        $res1 = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30")
            ->assertOk()
            ->assertJsonPath('count', 30)
            ->assertJsonPath('has_more', true);

        $data1 = $res1->json();
        $this->assertNotNull($data1['next_cursor']);
        $this->assertLessThanOrEqual(255, strlen((string) $data1['next_cursor']));
        $page1Ids = array_column($data1['invoices'], 'id');
        $this->assertCount(30, $page1Ids);
        $this->assertSame(array_slice($expectedOrder, 0, 30), $page1Ids);

        // 2. Subsequent page (page 2): using next_cursor
        $res2 = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&cursor=".urlencode((string) $data1['next_cursor']))
            ->assertOk()
            ->assertJsonPath('count', 30)
            ->assertJsonPath('has_more', true);

        $data2 = $res2->json();
        $this->assertNotNull($data2['next_cursor']);
        $page2Ids = array_column($data2['invoices'], 'id');
        $this->assertCount(30, $page2Ids);
        $this->assertSame(array_slice($expectedOrder, 30, 30), $page2Ids);

        // 3. Exhaustion (page 3): final 15 items
        $res3 = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&cursor=".urlencode((string) $data2['next_cursor']))
            ->assertOk()
            ->assertJsonPath('count', 15)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);

        $data3 = $res3->json();
        $page3Ids = array_column($data3['invoices'], 'id');
        $this->assertCount(15, $page3Ids);
        $this->assertSame(array_slice($expectedOrder, 60, 15), $page3Ids);

        // Total check: exactly 75 records, zero duplicates, zero skips
        $allRetrieved = array_merge($page1Ids, $page2Ids, $page3Ids);
        $this->assertCount(75, array_unique($allRetrieved));
        $this->assertSame($expectedOrder, $allRetrieved);

        // Missing cursor request returns first page
        $resMissing = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30")
            ->assertOk();
        $this->assertSame($page1Ids, array_column($resMissing->json('invoices'), 'id'));

        // Empty cursor request returns first page
        $resEmpty = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&cursor=")
            ->assertOk();
        $this->assertSame($page1Ids, array_column($resEmpty->json('invoices'), 'id'));
    }

    public function test_cursor_tamper_signature_payload_and_malformed_rejection(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->createAndPostInvoice('ILS', '1.0000000000', '10.000000', '1.000000');
        }

        $res = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30")
            ->assertOk();
        $cursor = (string) $res->json('next_cursor');
        $this->assertNotEmpty($cursor);

        // 1. Tamper payload JSON
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        $this->assertIsString($decoded);
        $lastDot = strrpos($decoded, '.');
        $this->assertIsInt($lastDot);
        $json = substr($decoded, 0, $lastDot);
        $sig = substr($decoded, $lastDot + 1);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($json, true);
        $payload['p'] = '01JCV8X9MDPTRSVWXYZ0000000'; // modified position
        $tamperedJson = json_encode($payload);
        $tamperedCursor = rtrim(strtr(base64_encode($tamperedJson.'.'.$sig), '+/', '-_'), '=');

        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?cursor=".urlencode($tamperedCursor))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Tampered pagination cursor signature.');

        // 2. Tamper signature
        $corruptSig = str_repeat('0', 64);
        $tamperedSigCursor = rtrim(strtr(base64_encode($json.'.'.$corruptSig), '+/', '-_'), '=');
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?cursor=".urlencode($tamperedSigCursor))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Tampered pagination cursor signature.');

        // 3. Malformed base64
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?cursor=not_valid_base64!!!")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 4. Missing dot/signature
        $noDotCursor = rtrim(strtr(base64_encode('{"v":1}'), '+/', '-_'), '=');
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?cursor=".urlencode($noDotCursor))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Missing cursor integrity signature.');
    }

    public function test_cursor_cross_tenant_and_cross_filter_rejection(): void
    {
        for ($i = 1; $i <= 35; $i++) {
            $this->createAndPostInvoice('ILS', '1.0000000000', '10.000000', '1.000000');
        }

        $res = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&status=POSTED")
            ->assertOk();
        $cursorPosted = (string) $res->json('next_cursor');
        $this->assertNotEmpty($cursorPosted);

        // 1. Cross-filter: Change status from POSTED to DRAFT
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&status=DRAFT&cursor=".urlencode($cursorPosted))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Cross-filter pagination cursor reuse is rejected.');

        // 2. Cross-filter: Remove status filter completely
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&cursor=".urlencode($cursorPosted))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Cross-filter pagination cursor reuse is rejected.');

        // 3. Cross-filter: Change search query
        $resSearch = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&search=Original")
            ->assertOk();
        if ($resSearch->json('next_cursor')) {
            $cursorSearch = (string) $resSearch->json('next_cursor');
            $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit=30&search=Changed&cursor=".urlencode($cursorSearch))
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED')
                ->assertJsonPath('error.message', 'Cross-filter pagination cursor reuse is rejected.');
        }

        // 4. Cross-tenant: Reuse Company A's cursor on Company B
        $otherUser = User::factory()->create();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherUser, [
            'name_ar' => 'شركة أخرى لعزل المؤشر',
            'base_currency_code' => 'ILS',
        ]);
        $otherCompany->users()->attach($this->owner->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($otherCompany->id);
        $this->owner->givePermissionTo('sales.invoice.view');

        $otherDevice = (string) Str::uuid();
        MobileDevice::create(['device_id' => $otherDevice, 'device_name' => 'Other Device', 'user_id' => $this->owner->id]);
        $otherToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $otherToken),
            'device_id' => $otherDevice,
            'user_id' => $this->owner->id,
            'company_id' => $otherCompany->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $this->owner->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$otherToken)
            ->getJson("/api/mobile/v1/companies/{$otherCompany->public_id}/sales/invoices?status=POSTED&cursor=".urlencode($cursorPosted))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Cross-tenant pagination cursor reuse is rejected.');

        $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    public function test_cursor_bounded_length_and_format_validation(): void
    {
        // Cursor exceeding 255 characters
        $longCursor = str_repeat('A', 256);
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?cursor=".urlencode($longCursor))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Cursor exceeds maximum length of 255 characters.');

        // Cursor parameter as array
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?cursor[]=test")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath('error.message', 'Cursor must be a valid string.');
    }

    public function test_identical_dates_ordering_and_page_boundaries_and_date_status_input_validation(): void
    {
        $sameDate = '2026-11-19';
        $invoices = [];
        for ($i = 1; $i <= 10; $i++) {
            app(CompanyContext::class)->setCompany($this->company, $this->owner);
            $this->actingAs($this->owner);
            setPermissionsTeamId($this->company->id);

            $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'issue_date' => $sameDate,
                'due_date' => Carbon::now()->addDays(30)->toDateString(),
                'lines' => [
                    [
                        'item_description' => "خدمة في نفس التاريخ {$i}",
                        'quantity' => '1.000000',
                        'unit_price' => '10.000000',
                        'discount_type' => 'none',
                        'discount_value' => '0.000000',
                        'tax_rate_id' => null,
                    ],
                ],
            ]);
            $inv = app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
            $invoices[] = $inv->public_id;
        }

        $expectedOrder = array_reverse($invoices);

        // Fetch page 1 (limit 4)
        $res1 = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?date_from={$sameDate}&date_to={$sameDate}&limit=4")
            ->assertOk()
            ->assertJsonPath('count', 4)
            ->assertJsonPath('has_more', true);
        $data1 = $res1->json();
        $this->assertSame(array_slice($expectedOrder, 0, 4), array_column($data1['invoices'], 'id'));

        // Fetch page 2 (limit 4)
        $res2 = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?date_from={$sameDate}&date_to={$sameDate}&limit=4&cursor=".urlencode((string) $data1['next_cursor']))
            ->assertOk()
            ->assertJsonPath('count', 4)
            ->assertJsonPath('has_more', true);
        $data2 = $res2->json();
        $this->assertSame(array_slice($expectedOrder, 4, 4), array_column($data2['invoices'], 'id'));

        // Fetch page 3 (remaining 2)
        $res3 = $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?date_from={$sameDate}&date_to={$sameDate}&limit=4&cursor=".urlencode((string) $data2['next_cursor']))
            ->assertOk()
            ->assertJsonPath('count', 2)
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);
        $data3 = $res3->json();
        $this->assertSame(array_slice($expectedOrder, 8, 2), array_column($data3['invoices'], 'id'));

        // All 10 items fetched with 0 duplicates and 0 skips
        $combined = array_merge(
            array_column($data1['invoices'], 'id'),
            array_column($data2['invoices'], 'id'),
            array_column($data3['invoices'], 'id')
        );
        $this->assertCount(10, array_unique($combined));
        $this->assertSame($expectedOrder, $combined);

        // Invalid date_from
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?date_from=not-a-date")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Invalid date_to
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?date_to=2026-13-45")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Invalid date array
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?date_from[]=2026-11-19")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Invalid status
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?status=UNKNOWN_STATUS")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Invalid status array
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?status[]=POSTED")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_bounded_query_limits_and_permissions(): void
    {
        // 1. Invalid limits rejected with 422
        foreach (['0', '-1', '101', 'abc', '10 5'] as $badLimit) {
            $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit={$badLimit}")
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'VALIDATION_FAILED');
        }

        // Limit as array
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?limit[]=10")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 2. Search exceeding 100 characters rejected with 422
        $longSearch = str_repeat('s', 101);
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?search={$longSearch}")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // Search as array rejected with 422
        $this->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices?search[]=foo")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');

        // 3. User without sales.invoice.view permission is rejected with 403 FORBIDDEN
        $unauthorizedUser = User::factory()->create();
        $this->company->users()->attach($unauthorizedUser->id, ['status' => 'active', 'is_owner' => false]);
        setPermissionsTeamId($this->company->id);

        $device = (string) Str::uuid();
        MobileDevice::create(['device_id' => $device, 'device_name' => 'Restricted Device', 'user_id' => $unauthorizedUser->id]);
        $unauthRawToken = 'st_m_'.bin2hex(random_bytes(32));
        MobileAccessToken::create([
            'token_hash' => hash('sha256', $unauthRawToken),
            'device_id' => $device,
            'user_id' => $unauthorizedUser->id,
            'company_id' => $this->company->id,
            'password_epoch' => 'epoch-'.substr(hash('sha256', $unauthorizedUser->password), 0, 16),
            'expires_at' => now()->addHour(),
        ]);

        $this->withHeader('Authorization', 'Bearer '.$unauthRawToken)
            ->getJson("/api/mobile/v1/companies/{$this->company->public_id}/sales/invoices")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }
}
