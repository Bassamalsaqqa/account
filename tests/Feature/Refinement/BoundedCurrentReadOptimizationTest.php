<?php

declare(strict_types=1);

namespace Tests\Feature\Refinement;

use App\Actions\Accounting\EnsurePhase7FoundationAction;
use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Company\CreateCompanyAction;
use App\Actions\Inventory\PostOpeningStockAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Payroll\PostEmployeeAdvanceAction;
use App\Actions\Payroll\PostSalaryEntryAction;
use App\Actions\Payroll\PostSalaryPaymentAction;
use App\Actions\Payroll\ReverseEmployeeAdvanceAction;
use App\Actions\Payroll\ReverseSalaryEntryAction;
use App\Actions\Payroll\ReverseSalaryPaymentAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\CreatePurchaseDraftAction;
use App\Actions\Purchasing\PostPurchaseAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Actions\Sales\VoidSalesReturnAction;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Exceptions\NoActiveCompanyException;
use App\Livewire\Pages\Customers\CustomerDetail;
use App\Livewire\Pages\Payroll\EmployeeDetail;
use App\Livewire\Pages\Purchasing\PaymentIndex as PurchasingPaymentIndex;
use App\Livewire\Pages\Sales\PaymentForm as SalesPaymentForm;
use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\Customer;
use App\Models\CustomerPaymentApplicationEvent;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\MoneyAccount;
use App\Models\OpeningTradeItem;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalaryEntry;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\Warehouse;
use App\Services\Inventory\ProductCatalogService;
use App\Services\Payroll\PayrollReadService;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Purchasing\VendorPaymentAllocationSummary;
use App\Services\Sales\SalesInvoiceFinancialSummaryBatch;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BoundedCurrentReadOptimizationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Company $company;

    private MoneyAccount $ilsCash;

    private MoneyAccount $usdCash;

    private MoneyAccount $jodCash;

    private MoneyAccount $ilsBank;

    private MoneyAccount $usdBank;

    private Warehouse $warehouse;

    private Product $product;

    private ProductUnit $productUnit;

    private Customer $customer;

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['locale' => 'en']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, [
            'name_ar' => 'شركة اختبار التحسين',
            'name_en' => 'Bounded Read Co',
            'base_currency_code' => 'ILS',
        ]);
        app(EnsurePhase7FoundationAction::class)->execute($this->company);
        $this->activate($this->owner);

        foreach (['USD', 'JOD'] as $curr) {
            CompanyCurrency::updateOrCreate(
                ['company_id' => $this->company->id, 'currency_code' => $curr],
                ['enabled' => true, 'is_base' => false, 'display_order' => $curr === 'USD' ? 2 : 3]
            );
        }

        $moneyAccountAction = app(CreateMoneyAccountAction::class);
        $this->ilsCash = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق الشيكل',
            'name_en' => 'ILS Cash',
        ]);
        $this->usdCash = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'USD',
            'name_ar' => 'صندوق الدولار',
            'name_en' => 'USD Cash',
        ]);
        $this->jodCash = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'JOD',
            'name_ar' => 'صندوق الدينار',
            'name_en' => 'JOD Cash',
        ]);
        $this->ilsBank = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_BANK,
            'currency_code' => 'ILS',
            'name_ar' => 'بنك الشيكل',
            'name_en' => 'ILS Bank',
        ]);
        $this->usdBank = $moneyAccountAction->execute($this->company, $this->owner, [
            'account_type' => MoneyAccount::TYPE_BANK,
            'currency_code' => 'USD',
            'name_ar' => 'بنك الدولار',
            'name_en' => 'USD Bank',
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->firstOrFail();
        $pieceUnit = Unit::where('code', 'piece')->firstOrFail();
        $this->product = app(ProductCatalogService::class)->createProduct($this->company, [
            'name_ar' => 'منتج اختبار',
            'name_en' => 'Bounded Item',
            'sku' => 'BND-01',
            'product_type' => Product::TYPE_STOCK,
            'track_stock' => true,
            'track_expiry' => false,
            'base_unit_id' => $pieceUnit->id,
            'default_purchase_cost_base' => '10.000000',
        ], $this->owner->id);
        $this->productUnit = ProductUnit::where('product_id', $this->product->id)->firstOrFail();

        app(PostOpeningStockAction::class)->execute(
            company: $this->company,
            product: $this->product,
            warehouse: $this->warehouse,
            quantity: Quantity::of('5000'),
            unitCostBase: '10.000000',
            user: $this->owner,
            idempotencyKey: 'bnd-open-stock',
            movementDate: '2026-01-01',
        );

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل التحسين',
            'name_en' => 'Bounded Customer',
            'default_currency_code' => 'ILS',
            'preferred_locale' => 'en',
            'created_by' => $this->owner->id,
            'status' => 'active',
        ]);

        $this->vendor = app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'name_ar' => 'مورد التحسين',
            'name_en' => 'Bounded Vendor',
            'default_currency_code' => 'ILS',
        ]);
    }

    private function activate(User $user, ?Company $company = null): void
    {
        $target = $company ?? $this->company;
        $this->actingAs($user);
        app(CompanyContext::class)->setCompany($target, $user);
        setPermissionsTeamId($target->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function createSalesInvoice(
        string $currency = 'ILS',
        string $rate = '1.0000000000',
        string $unitPrice = '100.00',
        string $qty = '2',
        bool $post = true,
        bool $void = false,
        ?Company $company = null,
        ?Customer $customer = null,
        ?Warehouse $warehouse = null,
        ?Product $product = null,
        ?ProductUnit $productUnit = null,
        string $issueDate = '2026-10-01',
    ): SalesInvoice {
        $targetCompany = $company ?? $this->company;
        $inv = app(CreateSalesInvoiceDraftAction::class)->execute($targetCompany, $this->owner, [
            'customer_id' => ($customer ?? $this->customer)->id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'issue_date' => $issueDate,
            'due_date' => '2026-11-30',
            'lines' => [[
                'product_id' => ($product ?? $this->product)->id,
                'product_unit_id' => ($productUnit ?? $this->productUnit)->id,
                'item_description' => 'Test invoice line',
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'discount_type' => 'none',
                'discount_value' => '0.000000',
                'tax_rate_id' => null,
            ]],
        ]);

        if ($post) {
            $inv = app(PostSalesInvoiceAction::class)->execute($inv, $this->owner);
            if ($void) {
                app(VoidSalesInvoiceAction::class)->execute($inv, $this->owner, 'Test void');
                $inv->refresh();
            }
        }

        return $inv;
    }

    /**
     * @return array<int, array{query: string, time: float}>
     */
    private function captureQueries(callable $fn): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $fn();

            return DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    public function test_sales_invoice_batch_empty_nonposted_and_tenant_fail_closed_guards(): void
    {
        $batch = app(SalesInvoiceFinancialSummaryBatch::class);

        $emptyQueries = $this->captureQueries(function () use ($batch): void {
            $this->assertSame([], $batch->summarize([], $this->company->id));
        });
        $this->assertCount(0, $emptyQueries);

        $draftInv = $this->createSalesInvoice(post: false);
        $voidInv = $this->createSalesInvoice(post: true, void: true);

        $nonPostedQueries = $this->captureQueries(function () use ($batch, $draftInv, $voidInv): void {
            $result = $batch->summarize([$draftInv, $voidInv], $this->company->id);
            $this->assertSame('0', (string) $result[$draftInv->id]['outstanding']);
            $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $result[$draftInv->id]['payment_status']);
            $this->assertSame('0', (string) $result[$voidInv->id]['outstanding']);
            $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $result[$voidInv->id]['payment_status']);
        });
        $this->assertCount(0, $nonPostedQueries);

        $otherOwner = User::factory()->create(['locale' => 'en']);
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other Co',
            'base_currency_code' => 'ILS',
        ]);
        app(EnsurePhase7FoundationAction::class)->execute($otherCompany);
        $this->activate($otherOwner, $otherCompany);
        $otherWh = Warehouse::where('company_id', $otherCompany->id)->firstOrFail();
        $otherCust = Customer::create([
            'company_id' => $otherCompany->id,
            'name_ar' => 'عميل آخر',
            'name_en' => 'Other Customer',
            'default_currency_code' => 'ILS',
            'preferred_locale' => 'en',
            'created_by' => $otherOwner->id,
            'status' => 'active',
        ]);
        $otherInv = SalesInvoice::create([
            'company_id' => $otherCompany->id,
            'customer_id' => $otherCust->id,
            'warehouse_id' => $otherWh->id,
            'invoice_number' => null,
            'issue_date' => '2026-10-01',
            'due_date' => '2026-11-30',
            'status' => SalesInvoice::STATUS_DRAFT,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'subtotal_currency' => '100.00',
            'discount_currency' => '0.00',
            'tax_currency' => '0.00',
            'grand_total_currency' => '100.00',
            'grand_total_base' => '100.00',
            'created_by' => $otherOwner->id,
        ]);

        $this->activate($this->owner, $this->company);

        try {
            $batch->summarize([$draftInv, $otherInv], $this->company->id);
            $this->fail('Expected InvalidArgumentException for mixed-company input.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $batch->summarize([$otherInv]);
            $this->fail('Expected InvalidArgumentException when invoice company mismatches active company context.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $batch->summarize([$draftInv], $otherCompany->id);
            $this->fail('Expected InvalidArgumentException for expectedCompanyId mismatch.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        try {
            $batch->summarize([$this->customer]);
            $this->fail('Expected InvalidArgumentException for non-SalesInvoice item.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }

        app(CompanyContext::class)->clear();
        try {
            $batch->summarize([$draftInv]);
            $this->fail('Expected NoActiveCompanyException when no active company context is set.');
        } catch (NoActiveCompanyException) {
            $this->addToAssertionCount(1);
        }

        $this->activate($this->owner, $this->company);
    }

    public function test_sales_invoice_batch_exact_parity_across_all_ar_lifecycle_and_fx_cases(): void
    {
        $postPay = app(PostCustomerPaymentAction::class);
        $applyPay = app(ApplyCustomerPaymentCreditAction::class);
        $draftRet = app(CreateSalesReturnDraftAction::class);
        $postRet = app(PostSalesReturnAction::class);

        $invUnpaid = $this->createSalesInvoice();
        $invPartial = $this->createSalesInvoice();
        $invFull = $this->createSalesInvoice();
        $invOverpaid = $this->createSalesInvoice();
        $invReturned = $this->createSalesInvoice();
        $invVoidRet = $this->createSalesInvoice();
        $invDraftRet = $this->createSalesInvoice();
        $invRevParent = $this->createSalesInvoice();
        $invUnapplied = $this->createSalesInvoice();
        $invApplied = $this->createSalesInvoice();
        $invRevApp = $this->createSalesInvoice();
        $invMarkerOnly = $this->createSalesInvoice();
        $invPendingChk = $this->createSalesInvoice();
        $invBouncedChk = $this->createSalesInvoice();
        $invJodFractional = $this->createSalesInvoice(currency: 'JOD', rate: '5.0000000000', unitPrice: '12.345', qty: '2');
        $invZero = $this->createSalesInvoice(unitPrice: '0.00', qty: '1');
        $invDraft = $this->createSalesInvoice(post: false);
        $invVoid = $this->createSalesInvoice(post: true, void: true);

        // Partial & Full payments
        $postPay->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '75.25',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ar-partial',
            'allocations' => [['sales_invoice_id' => $invPartial->id, 'allocated_amount' => '75.25']],
        ]);
        $postPay->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '200.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ar-full',
            'allocations' => [['sales_invoice_id' => $invFull->id, 'allocated_amount' => '200.00']],
        ]);

        // Overpaid (credit negative residual: paid 150 + posted return 100 = 250 on 200 invoice -> -50.00)
        $postPay->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '150.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ar-overpay-cash',
            'allocations' => [['sales_invoice_id' => $invOverpaid->id, 'allocated_amount' => '150.00']],
        ]);
        $overRet = $draftRet->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invOverpaid->id,
            'issue_date' => '2026-10-03',
            'lines' => [['sales_invoice_line_id' => $invOverpaid->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);
        $postRet->execute($overRet, $this->owner);

        // Posted, voided, and draft returns
        $postedRet = $draftRet->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invReturned->id,
            'issue_date' => '2026-10-03',
            'lines' => [['sales_invoice_line_id' => $invReturned->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);
        $postRet->execute($postedRet, $this->owner);

        $voidedRet = $draftRet->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invVoidRet->id,
            'issue_date' => '2026-10-03',
            'lines' => [['sales_invoice_line_id' => $invVoidRet->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);
        $voidedRet = $postRet->execute($voidedRet, $this->owner);
        app(VoidSalesReturnAction::class)->execute($voidedRet, $this->owner, 'Void return');

        $draftRet->execute($this->company, $this->owner, [
            'sales_invoice_id' => $invDraftRet->id,
            'issue_date' => '2026-10-03',
            'lines' => [['sales_invoice_line_id' => $invDraftRet->lines()->firstOrFail()->id, 'quantity' => '1']],
        ]);

        // Reversed parent payment
        $revParentPay = $postPay->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '120.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ar-rev-parent',
            'allocations' => [['sales_invoice_id' => $invRevParent->id, 'allocated_amount' => '120.00']],
        ]);
        app(ReverseCustomerPaymentAction::class)->execute($revParentPay, $this->owner, 'Parent reversal');

        // Credit application events: applied, unapplied, reversed_at != null, reversal_posting_batch_id != null WITHOUT reversed_at
        $advPay = $postPay->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '400.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'ar-adv-credit',
            'allocations' => [],
        ]);

        $appApplied = $applyPay->execute($advPay, $this->owner, [
            'application_date' => '2026-10-03',
            'idempotency_key' => 'ar-app-applied',
            'allocations' => [['sales_invoice_id' => $invApplied->id, 'allocated_amount' => '40.00']],
        ]);

        $appUnapplied = $applyPay->execute($advPay, $this->owner, [
            'application_date' => '2026-10-03',
            'idempotency_key' => 'ar-app-unapplied',
            'allocations' => [['sales_invoice_id' => $invUnapplied->id, 'allocated_amount' => '45.00']],
        ]);
        DB::table('customer_payment_application_events')
            ->where('id', $appUnapplied->id)
            ->update(['applied_at' => null]);

        $appReversed = $applyPay->execute($advPay, $this->owner, [
            'application_date' => '2026-10-03',
            'idempotency_key' => 'ar-app-rev',
            'allocations' => [['sales_invoice_id' => $invRevApp->id, 'allocated_amount' => '55.00']],
        ]);
        $this->assertNotNull($advPay->posting_batch_id);
        DB::table('customer_payment_application_events')
            ->where('id', $appReversed->id)
            ->update([
                'reversed_at' => now(),
                'reversal_posting_batch_id' => $advPay->posting_batch_id,
            ]);

        $appMarkerOnly = $applyPay->execute($advPay, $this->owner, [
            'application_date' => '2026-10-03',
            'idempotency_key' => 'ar-app-marker-only',
            'allocations' => [['sales_invoice_id' => $invMarkerOnly->id, 'allocated_amount' => '65.00']],
        ]);
        // Intentional AR semantics: reversal_posting_batch_id present without reversed_at remains active in CustomerPaymentAllocation::active()
        DB::table('customer_payment_application_events')
            ->where('id', $appMarkerOnly->id)
            ->update([
                'reversed_at' => null,
                'reversal_posting_batch_id' => $advPay->posting_batch_id,
            ]);

        // Pending check vs bounced check
        app(ReceiveCheckAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'date' => '2026-10-04',
            'due_date' => '2026-11-20',
            'currency_code' => 'ILS',
            'amount' => '90.00',
            'exchange_rate' => '1.0000000000',
            'check_number' => 'CHK-AR-PENDING',
            'bank_name' => 'Bank A',
            'allocations' => [['sales_invoice_id' => $invPendingChk->id, 'allocated_amount' => '90.00']],
            'idempotency_key' => 'ar-chk-pending',
        ]);
        $bouncedCheck = app(ReceiveCheckAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'date' => '2026-10-04',
            'due_date' => '2026-11-20',
            'currency_code' => 'ILS',
            'amount' => '90.00',
            'exchange_rate' => '1.0000000000',
            'check_number' => 'CHK-AR-BOUNCED',
            'bank_name' => 'Bank A',
            'allocations' => [['sales_invoice_id' => $invBouncedChk->id, 'allocated_amount' => '90.00']],
            'idempotency_key' => 'ar-chk-bounced',
        ]);
        app(TransitionCheckAction::class)->execute($bouncedCheck, $this->owner, [
            'event_type' => 'return',
            'event_date' => '2026-10-05',
            'notes' => 'Bounced',
            'idempotency_key' => 'ar-chk-bounced-ret',
        ]);

        // 3dp JOD fractional partial payment (2 * 12.345 = 24.690; paid 10.125 -> 14.565 unrounded)
        $postPay->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->jodCash->id,
            'payment_date' => '2026-10-04',
            'payment_method' => 'cash',
            'amount' => '10.125',
            'exchange_rate' => '5.0000000000',
            'idempotency_key' => 'ar-jod-frac',
            'allocations' => [['sales_invoice_id' => $invJodFractional->id, 'allocated_amount' => '10.125']],
        ]);

        $allInvoices = [
            $invUnpaid, $invPartial, $invFull, $invOverpaid,
            $invReturned, $invVoidRet, $invDraftRet, $invRevParent,
            $invUnapplied, $invApplied, $invRevApp, $invMarkerOnly,
            $invPendingChk, $invBouncedChk, $invJodFractional, $invZero,
            $invDraft, $invVoid,
        ];

        $batch = app(SalesInvoiceFinancialSummaryBatch::class);
        $batchQueries = $this->captureQueries(function () use ($batch, $allInvoices, &$summaries): void {
            $summaries = $batch->summarize($allInvoices, $this->company->id);
        });

        // Bounded to exactly 2 grouped queries (allocations + returns)
        $this->assertCount(2, $batchQueries);

        foreach ($allInvoices as $invoice) {
            $fresh = $invoice->fresh();
            $oracle = $fresh->calculateFinancialSummary();
            $got = $summaries[$fresh->id];

            $this->assertTrue(
                $oracle['paid']->isEqualTo($got['paid']),
                "Invoice {$fresh->invoice_number} paid mismatch: oracle={$oracle['paid']} got={$got['paid']}"
            );
            $this->assertTrue(
                $oracle['returned']->isEqualTo($got['returned']),
                "Invoice {$fresh->invoice_number} returned mismatch: oracle={$oracle['returned']} got={$got['returned']}"
            );
            $this->assertTrue(
                $oracle['outstanding']->isEqualTo($got['outstanding']),
                "Invoice {$fresh->invoice_number} outstanding mismatch: oracle={$oracle['outstanding']} got={$got['outstanding']}"
            );
            $this->assertSame((string) $oracle['outstanding'], (string) $got['outstanding']);
            $this->assertSame($oracle['payment_status'], $got['payment_status']);
        }

        // Verify key domain expectations explicitly
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_CREDIT, $summaries[$invOverpaid->id]['payment_status']);
        $this->assertTrue($summaries[$invOverpaid->id]['outstanding']->isNegative());
        $this->assertSame('65.000000', (string) $summaries[$invMarkerOnly->id]['paid']);
        $this->assertSame('0', (string) $summaries[$invUnapplied->id]['paid']);
        $this->assertSame('0', (string) $summaries[$invRevApp->id]['paid']);
        $this->assertSame('90.000000', (string) $summaries[$invPendingChk->id]['paid']);
        $this->assertSame('0', (string) $summaries[$invBouncedChk->id]['paid']);
        $this->assertTrue($summaries[$invJodFractional->id]['outstanding']->isEqualTo(BigDecimal::of('14.565')));
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_PAID, $summaries[$invZero->id]['payment_status']);
    }

    public function test_customer_detail_and_sales_payment_form_use_bounded_queries_and_keep_opening_items_unchanged(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $inv = $this->createSalesInvoice();
            app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
                'customer_id' => $this->customer->id,
                'money_account_id' => $this->ilsCash->id,
                'payment_date' => '2026-10-03',
                'payment_method' => 'cash',
                'amount' => '30.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => "cd-pay-{$i}",
                'allocations' => [['sales_invoice_id' => $inv->id, 'allocated_amount' => '30.00']],
            ]);
            if ($i < 3) {
                $ret = app(CreateSalesReturnDraftAction::class)->execute($this->company, $this->owner, [
                    'sales_invoice_id' => $inv->id,
                    'issue_date' => '2026-10-04',
                    'lines' => [['sales_invoice_line_id' => $inv->lines()->firstOrFail()->id, 'quantity' => '1']],
                ]);
                app(PostSalesReturnAction::class)->execute($ret, $this->owner);
            }
        }

        // 1 USD open invoice to test currency/account switching + positive filter
        $usdInv = $this->createSalesInvoice(currency: 'USD', rate: '3.5000000000', unitPrice: '80.00', qty: '1');
        // Paid and draft invoices should be filtered out of open invoices
        $paidInv = $this->createSalesInvoice();
        app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '200.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'cd-pay-full',
            'allocations' => [['sales_invoice_id' => $paidInv->id, 'allocated_amount' => '200.00']],
        ]);
        $draftInv = $this->createSalesInvoice(post: false);

        // CustomerDetail invoices tab
        $overviewBaseQueries = $this->captureQueries(function (): void {
            Livewire::actingAs($this->owner)
                ->test(CustomerDetail::class, ['publicId' => $this->customer->public_id])
                ->assertStatus(200);
        });
        $overviewAllocCount = count(array_filter(
            $overviewBaseQueries,
            fn (array $q) => str_contains($q['query'], 'from `customer_payment_allocations`')
        ));
        $overviewRetCount = count(array_filter(
            $overviewBaseQueries,
            fn (array $q) => str_contains($q['query'], 'from `sales_returns`')
        ));

        $invComponent = null;
        $invTabQueries = $this->captureQueries(function () use (&$invComponent): void {
            $invComponent = Livewire::actingAs($this->owner)
                ->withQueryParams(['tab' => 'invoices'])
                ->test(CustomerDetail::class, ['publicId' => $this->customer->public_id])
                ->assertStatus(200);
        });

        $allocQueries = array_filter($invTabQueries, fn (array $q) => str_contains($q['query'], 'from `customer_payment_allocations`'));
        $retQueries = array_filter($invTabQueries, fn (array $q) => str_contains($q['query'], 'from `sales_returns`'));
        // Only +1 grouped allocation query and +1 grouped return query compared to overview (never N per invoice)
        $this->assertCount($overviewAllocCount + 1, $allocQueries);
        $this->assertCount($overviewRetCount + 1, $retQueries);

        $invComponent->assertViewHas('tabInvoiceSummaries', function (array $summaries) {
            foreach ($summaries as $id => $summary) {
                $oracle = SalesInvoice::findOrFail($id)->calculateFinancialSummary();
                if ((string) $oracle['outstanding'] !== (string) $summary['outstanding']) {
                    return false;
                }
                if ($oracle['payment_status'] !== $summary['payment_status']) {
                    return false;
                }
            }

            return count($summaries) > 0;
        });

        // CustomerDetail returns tab: eager-loads same-company salesInvoice without N+1
        $retTabQueries = $this->captureQueries(function (): void {
            $component = Livewire::actingAs($this->owner)
                ->withQueryParams(['tab' => 'returns'])
                ->test(CustomerDetail::class, ['publicId' => $this->customer->public_id]);

            $component->assertStatus(200)
                ->assertViewHas('tabReturns', function ($returns) {
                    return $returns->count() === 3 && $returns->every(fn ($r) => $r->relationLoaded('salesInvoice'));
                });
        });

        $retInvQueries = array_filter($retTabQueries, fn (array $q) => str_contains($q['query'], 'from `sales_invoices`'));
        // Only eager-load query for returns' sales invoices (no per-return loop queries)
        $this->assertLessThanOrEqual(2, count($retInvQueries));

        // SalesPaymentForm mount with source invoice preference + switch account + useFullBalance
        $mountQueries = $this->captureQueries(function () use ($usdInv, $paidInv, $draftInv, &$lwForm): void {
            $lwForm = Livewire::actingAs($this->owner)
                ->withQueryParams(['customer_id' => $this->customer->id, 'invoice_id' => $usdInv->id])
                ->test(SalesPaymentForm::class);

            $lwForm->assertSet('currency_code', 'USD')
                ->assertSet('money_account_id', (string) $this->usdCash->id)
                ->assertSet('sourceInvoiceId', $usdInv->id);

            $allocRows = collect($lwForm->get('allocations'));
            $openIds = $allocRows->pluck('sales_invoice_id')->filter()->values()->all();
            $this->assertCount(7, $openIds);
            $this->assertContains($usdInv->id, $openIds);
            $this->assertNotContains($paidInv->id, $openIds);
            $this->assertNotContains($draftInv->id, $openIds);

            $usdRow = $allocRows->firstWhere('sales_invoice_id', $usdInv->id);
            $this->assertSame('80.00', $usdRow['allocated_amount']);
        });

        $this->assertCount(
            2,
            array_filter($mountQueries, fn (array $q) => str_contains($q['query'], 'from `customer_payment_allocations`'))
        );

        // Switch to ILS account -> loads all 7 open invoices in 1 allocation + 1 return grouped query
        $switchQueries = $this->captureQueries(function () use ($lwForm): void {
            $lwForm->set('money_account_id', (string) $this->ilsCash->id)
                ->assertSet('currency_code', 'ILS');
        });
        $this->assertCount(
            1,
            array_filter($switchQueries, fn (array $q) => str_contains($q['query'], 'from `customer_payment_allocations`'))
        );
        $this->assertCount(
            1,
            array_filter($switchQueries, fn (array $q) => str_contains($q['query'], 'from `sales_returns`'))
        );
        $this->assertCount(7, $lwForm->get('allocations'));

        // Without sourceInvoiceId pinned, useFullBalance sums open ILS invoices (6 * 170 - 3 * 100 = 720.00) in bounded queries
        $lwFull = Livewire::actingAs($this->owner)
            ->withQueryParams(['customer_id' => $this->customer->id])
            ->test(SalesPaymentForm::class);
        $lwFull->set('money_account_id', (string) $this->ilsCash->id);

        $balanceQueries = $this->captureQueries(function () use ($lwFull): void {
            $lwFull->call('useFullBalance');
        });
        $this->assertSame('720.00', $lwFull->get('amount'));
        $this->assertCount(
            1,
            array_filter($balanceQueries, fn (array $q) => str_contains($q['query'], 'from `customer_payment_allocations`'))
        );

        // OpeningTradeItem retains its unchanged distinct reversal_posting_batch_id & clamp behavior
        $openingItem = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '100.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'open-trade-unchanged',
        );
        $openPay = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-04',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'open-trade-overpay',
            'allocations' => [],
        ]);
        app(ApplyCustomerPaymentCreditAction::class)->execute($openPay, $this->owner, [
            'application_date' => '2026-10-05',
            'idempotency_key' => 'open-trade-overpay-app',
            'allocations' => [['opening_trade_item_id' => $openingItem->id, 'allocated_amount' => '100.00']],
        ]);
        DB::table('customer_payment_allocations')
            ->where('opening_trade_item_id', $openingItem->id)
            ->update(['allocated_amount' => '130.000000']);
        // OpeningTradeItem::calculateOutstanding clamps to 0 on overpay (distinct from AR SalesInvoice)
        $this->assertTrue($openingItem->fresh()->calculateOutstanding()->isZero());

        $this->assertNotNull($openPay->posting_batch_id);
        $openEvent = CustomerPaymentApplicationEvent::where('customer_payment_id', $openPay->id)->firstOrFail();
        DB::table('customer_payment_application_events')
            ->where('id', $openEvent->id)
            ->update(['reversal_posting_batch_id' => $openPay->posting_batch_id, 'reversed_at' => null]);
        // Distinct from AR SalesInvoice: OpeningTradeItem excludes reversal_posting_batch_id != null even if reversed_at is null
        $this->assertTrue($openingItem->fresh()->calculateOutstanding()->isEqualTo(BigDecimal::of('100.00')));
    }

    public function test_vendor_payment_allocation_summary_and_index_parity_and_guards(): void
    {
        $summaryService = app(VendorPaymentAllocationSummary::class);

        $emptyQueries = $this->captureQueries(function () use ($summaryService): void {
            $this->assertSame([], $summaryService->summarize([], $this->company->id));
        });
        $this->assertCount(0, $emptyQueries);

        $postPurch = app(PostPurchaseAction::class);
        $draftPurch = app(CreatePurchaseDraftAction::class);
        $postVp = app(PostVendorPaymentAction::class);

        $pIls = $postPurch->execute($draftPurch->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'warehouse_id' => $this->warehouse->id,
            'purchase_date' => '2026-09-01',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'lines' => [['product_id' => $this->product->id, 'product_unit_id' => $this->productUnit->id, 'quantity' => '100', 'unit_cost' => '30.00']],
        ]), $this->owner);

        $vpUnalloc = $postVp->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'vp-unalloc',
            'allocations' => [],
        ]);
        $vpPartial = $postVp->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'vp-partial',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '35.50']],
        ]);
        $vpFull = $postVp->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'amount' => '80.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'vp-full',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '80.00']],
        ]);
        // Cross-currency USD payment allocating 70 ILS (= 20 USD payment_currency_amount)
        $vpFx = $postVp->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->usdCash->id,
            'payment_date' => '2026-10-04',
            'payment_method' => 'cash',
            'currency_code' => 'USD',
            'amount' => '50.00',
            'exchange_rate' => '3.5000000000',
            'idempotency_key' => 'vp-fx',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '70.00', 'payment_currency_amount' => '20.00']],
        ]);
        $vpRev = $postVp->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-05',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'amount' => '90.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'vp-rev',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '90.00']],
        ]);
        app(ReverseVendorPaymentAction::class)->execute($vpRev, $this->owner, 'VP reversal');

        // Unapplied & reversed application events + over-allocated signed negative check
        $vpAppCases = $postVp->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-06',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'vp-app-cases',
            'allocations' => [],
        ]);
        $applyVp = app(ApplyVendorPaymentCreditAction::class);
        $evApplied = $applyVp->execute($vpAppCases, $this->owner, [
            'application_date' => '2026-10-07',
            'idempotency_key' => 'vp-ev-applied',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '25.00']],
        ]);
        $evUnapplied = $applyVp->execute($vpAppCases, $this->owner, [
            'application_date' => '2026-10-07',
            'idempotency_key' => 'vp-ev-unapplied',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '15.00']],
        ]);
        $evReversed = $applyVp->execute($vpAppCases, $this->owner, [
            'application_date' => '2026-10-07',
            'idempotency_key' => 'vp-ev-reversed',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '10.00']],
        ]);
        $this->assertNotNull($vpAppCases->posting_batch_id);
        DB::table('vendor_payment_application_events')->where('id', $evUnapplied->id)->update(['applied_at' => null]);
        DB::table('vendor_payment_application_events')->where('id', $evReversed->id)->update([
            'reversed_at' => now(),
            'reversal_posting_batch_id' => $vpAppCases->posting_batch_id,
        ]);

        // Over-allocated payment to verify signed subtraction without clamping
        $vpOverAlloc = $postVp->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCash->id,
            'payment_date' => '2026-10-08',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'vp-overalloc',
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '100.00']],
        ]);
        DB::table('vendor_payment_allocations')
            ->where('vendor_payment_id', $vpOverAlloc->id)
            ->update(['payment_currency_amount' => '125.000000']);

        $chk = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'vendor_payment',
            'vendor_id' => $this->vendor->id,
            'date' => '2026-10-09',
            'due_date' => '2026-11-15',
            'currency_code' => 'ILS',
            'amount' => '120.00',
            'exchange_rate' => '1.0000000000',
            'check_number' => 'VCHK-BND-1',
            'bank_name' => 'Bank B',
            'money_account_id' => $this->ilsBank->id,
            'allocations' => [['purchase_id' => $pIls->id, 'allocated_amount' => '45.00']],
            'idempotency_key' => 'vp-chk-1',
        ]);
        $vpCheck = $chk->vendorPayment->fresh();

        $allVps = [
            $vpUnalloc->fresh(),
            $vpPartial->fresh(),
            $vpFull->fresh(),
            $vpFx->fresh(),
            $vpRev->fresh(),
            $vpAppCases->fresh(),
            $vpOverAlloc->fresh(),
            $vpCheck,
        ];

        $queries = $this->captureQueries(function () use ($summaryService, $allVps, &$vpSummaries): void {
            $vpSummaries = $summaryService->summarize($allVps, $this->company->id);
        });
        $this->assertCount(1, $queries);

        foreach ($allVps as $vp) {
            $oracleAlloc = $vp->allocated_amount;
            $oracleUnalloc = $vp->unallocated_amount;
            $this->assertSame($oracleAlloc, $vpSummaries[$vp->id]['allocated'], "VP {$vp->payment_number} allocated mismatch");
            $this->assertSame($oracleUnalloc, $vpSummaries[$vp->id]['unallocated'], "VP {$vp->payment_number} unallocated mismatch");
        }
        $this->assertSame('20.000000', $vpSummaries[$vpFx->id]['allocated']);
        $this->assertSame('30.000000', $vpSummaries[$vpFx->id]['unallocated']);
        $this->assertSame('0.000000', $vpSummaries[$vpRev->id]['allocated']);
        $this->assertSame('0.000000', $vpSummaries[$vpRev->id]['unallocated']);
        $this->assertSame('-25.000000', $vpSummaries[$vpOverAlloc->id]['unallocated']);

        // All reversed -> 0 allocation queries
        $freshRev = $vpRev->fresh();
        $revOnlyQueries = $this->captureQueries(function () use ($summaryService, $freshRev): void {
            $res = $summaryService->summarize([$freshRev], $this->company->id);
            $this->assertSame('0.000000', $res[$freshRev->id]['unallocated']);
        });
        $this->assertCount(0, $revOnlyQueries);

        // PaymentIndex renders with 1 grouped allocation query and no 'allocations' relation eager-loaded
        $idxQueries = $this->captureQueries(function (): void {
            $lw = Livewire::actingAs($this->owner)->test(PurchasingPaymentIndex::class);
            $lw->assertStatus(200)
                ->assertViewHas('payments', fn ($paginator) => $paginator->count() === 8
                    && $paginator->getCollection()->every(fn (VendorPayment $p) => ! $p->relationLoaded('allocations')))
                ->assertViewHas('allocationSummaries', fn (array $s) => count($s) === 8);
        });
        $this->assertCount(
            1,
            array_filter($idxQueries, fn (array $q) => str_contains($q['query'], 'from `vendor_payment_allocations`'))
        );

        // Mixed-tenant guard on VendorPaymentAllocationSummary
        $otherOwner = User::factory()->create(['locale' => 'en']);
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة أخرى',
            'name_en' => 'Other VP Co',
            'base_currency_code' => 'ILS',
        ]);
        $this->activate($this->owner, $this->company);
        $otherVp = new VendorPayment([
            'company_id' => $otherCompany->id,
            'payment_number' => 'VP-OTHER-1',
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'currency_code' => 'ILS',
            'amount' => '10.000000',
            'exchange_rate' => '1.0000000000',
            'base_amount' => '10.000000',
            'is_reversed' => false,
        ]);
        $otherVp->id = 999999;

        try {
            $summaryService->summarize([$vpUnalloc->fresh(), $otherVp], $this->company->id);
            $this->fail('Expected InvalidArgumentException on mixed companies.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_employee_detail_and_payroll_read_service_lazy_tabs_parity_and_identity_isolation(): void
    {
        $employee = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'EMP-BND-01',
            'name' => 'Bounded Payroll Employee',
            'job_title' => 'Analyst',
            'hire_date' => '2025-01-01',
            'default_salary' => '1500.000000',
            'salary_currency_code' => 'USD',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);

        $postAdv = app(PostEmployeeAdvanceAction::class);
        $postEntry = app(PostSalaryEntryAction::class);
        $postSalPay = app(PostSalaryPaymentAction::class);

        $advUsd = $postAdv->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'advance_date' => '2026-01-05',
            'currency_code' => 'USD',
            'amount' => '200.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCash->id,
            'idempotency_key' => 'pay-adv-usd',
        ]);
        $advCheck = app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'source_type' => 'employee_advance',
            'employee_id' => $employee->id,
            'date' => '2026-01-10',
            'due_date' => '2026-02-10',
            'currency_code' => 'USD',
            'amount' => '100.00',
            'exchange_rate' => '3.5000000000',
            'check_number' => 'ADVCHK-BND',
            'bank_name' => 'Bank B',
            'money_account_id' => $this->usdBank->id,
            'idempotency_key' => 'pay-adv-chk',
        ]);
        $advRev = $postAdv->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'advance_date' => '2026-01-12',
            'currency_code' => 'ILS',
            'amount' => '300.000000',
            'exchange_rate' => '1.0000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->ilsCash->id,
            'idempotency_key' => 'pay-adv-rev',
        ]);
        app(ReverseEmployeeAdvanceAction::class)->execute($advRev, $this->owner, 'Adv reversal', '2026-01-15');

        // Salary entry with different advance FX rate (advance consumed vs entry book rate) & partial + reversed payments
        $salUsd = $postEntry->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'recognition_date' => '2026-01-31',
            'period_start' => '2026-01-01',
            'period_end' => '2026-01-31',
            'currency_code' => 'USD',
            'exchange_rate' => '3.7000000000',
            'base_salary' => '1000.000000',
            'bonus' => '100.000000',
            'deduction' => '50.000000',
            'advances' => [['advance_id' => $advUsd->id, 'allocated_amount' => '120.000000']],
            'idempotency_key' => 'pay-sal-usd',
        ]);
        $salRev = $postEntry->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'recognition_date' => '2026-02-28',
            'period_start' => '2026-02-01',
            'period_end' => '2026-02-28',
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'base_salary' => '900.000000',
            'bonus' => '0.000000',
            'deduction' => '0.000000',
            'advances' => [],
            'idempotency_key' => 'pay-sal-rev',
        ]);
        app(ReverseSalaryEntryAction::class)->execute($salRev, $this->owner, 'Sal reversal', '2026-02-28');

        $spActive = $postSalPay->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'payment_date' => '2026-02-05',
            'currency_code' => 'USD',
            'exchange_rate' => '3.8000000000',
            'amount' => '400.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCash->id,
            'allocations' => [['salary_entry_id' => $salUsd->id, 'allocated_amount' => '400.000000']],
            'idempotency_key' => 'pay-sp-active',
        ]);
        $spRev = $postSalPay->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'payment_date' => '2026-02-06',
            'currency_code' => 'USD',
            'exchange_rate' => '3.8000000000',
            'amount' => '150.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCash->id,
            'allocations' => [['salary_entry_id' => $salUsd->id, 'allocated_amount' => '150.000000']],
            'idempotency_key' => 'pay-sp-rev',
        ]);
        app(ReverseSalaryPaymentAction::class)->execute($spRev, $this->owner, 'SP reversal', '2026-02-07');

        // Over-consumed signed negative test on an extra advance & entry
        $advOver = $postAdv->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'advance_date' => '2026-03-01',
            'currency_code' => 'USD',
            'amount' => '50.000000',
            'exchange_rate' => '3.5000000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCash->id,
            'idempotency_key' => 'pay-adv-over',
        ]);
        $salOver = $postEntry->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'recognition_date' => '2026-03-31',
            'period_start' => '2026-03-01',
            'period_end' => '2026-03-31',
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'base_salary' => '600.000000',
            'bonus' => '0.000000',
            'deduction' => '0.000000',
            'advances' => [['advance_id' => $advOver->id, 'allocated_amount' => '50.000000']],
            'idempotency_key' => 'pay-sal-over',
        ]);
        $payOver = $postSalPay->execute($this->company, $this->owner, [
            'employee_id' => $employee->id,
            'payment_date' => '2026-04-02',
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'amount' => '100.000000',
            'payment_method' => 'cash',
            'money_account_id' => $this->usdCash->id,
            'allocations' => [['salary_entry_id' => $salOver->id, 'allocated_amount' => '100.000000']],
            'idempotency_key' => 'pay-sp-over',
        ]);
        // Inject synthetic over-allocation after all canonical Phase7 postings complete
        DB::table('salary_advance_allocations')
            ->where('employee_advance_id', $advOver->id)
            ->update([
                'allocated_amount' => '65.000000',
                'advance_base_consumed' => '227.500000',
            ]);
        DB::table('salary_payment_allocations')
            ->where('salary_payment_id', $payOver->id)
            ->update([
                'allocated_amount' => '580.000000',
                'salary_book_relief_base' => '2088.000000',
            ]);

        $payrollRead = app(PayrollReadService::class);

        $advPositions = $payrollRead->advancePositions($employee, $this->owner);
        $this->assertCount(3, $advPositions);
        foreach ($advPositions as $row) {
            /** @var EmployeeAdvance $advModel */
            $advModel = $row['advance'];
            $this->assertFalse($advModel->relationLoaded('allocations'));
            if ($advModel->check_id !== null) {
                $this->assertTrue($advModel->relationLoaded('check'));
            }
            $this->assertSame((string) $advModel->getRemainingAmount(), $row['remaining_amount']);
            $this->assertSame((string) $advModel->getRemainingBaseAmount(), $row['remaining_base_amount']);
        }
        $advOverRow = collect($advPositions)->firstWhere('advance.id', $advOver->id);
        $this->assertTrue(BigDecimal::of($advOverRow['remaining_amount'])->isNegative());

        $salPositions = $payrollRead->salaryPositions($employee, $this->owner);
        $this->assertCount(2, $salPositions);
        foreach ($salPositions as $row) {
            /** @var SalaryEntry $entryModel */
            $entryModel = $row['entry'];
            $this->assertFalse($entryModel->relationLoaded('paymentAllocations'));
            $this->assertSame((string) $entryModel->getRemainingPayableAmount(), $row['remaining_payable']);
            $this->assertSame((string) $entryModel->getRemainingPayableBaseAmount(), $row['remaining_payable_base']);
        }
        $salOverRow = collect($salPositions)->firstWhere('entry.id', $salOver->id);
        $this->assertTrue(BigDecimal::of($salOverRow['remaining_payable'])->isNegative());

        // Statement retains all reversed records & matches exact oracle balances_by_currency
        $stmt = $payrollRead->employeeStatement($employee, $this->owner);
        $this->assertCount(10, $stmt['events']);
        $reversedEventCount = collect($stmt['events'])->filter(fn (array $ev) => ($ev['status'] ?? null) === 'reversed')->count();
        $this->assertSame(3, $reversedEventCount);

        $oracleAdvUsd = BigDecimal::zero();
        foreach (EmployeeAdvance::where('employee_id', $employee->id)->where('currency_code', 'USD')->get() as $a) {
            $oracleAdvUsd = $oracleAdvUsd->plus($a->getRemainingAmount());
        }
        $oracleSalUsd = BigDecimal::zero();
        foreach (SalaryEntry::where('employee_id', $employee->id)->where('currency_code', 'USD')->get() as $e) {
            $oracleSalUsd = $oracleSalUsd->plus($e->getRemainingPayableAmount());
        }
        $this->assertSame((string) $oracleAdvUsd, $stmt['balances_by_currency']['USD']['outstanding_advance']);
        $this->assertSame((string) $oracleSalUsd, $stmt['balances_by_currency']['USD']['outstanding_payable']);
        $this->assertSame('0', $stmt['balances_by_currency']['ILS']['outstanding_advance']);
        $this->assertSame('0', $stmt['balances_by_currency']['ILS']['outstanding_payable']);

        // Lazy tab loading on EmployeeDetail: overview loads ONLY scalar badges & skips financial tables
        $overviewQueries = $this->captureQueries(function () use ($employee): void {
            Livewire::actingAs($this->owner)
                ->test(EmployeeDetail::class, ['publicId' => $employee->public_id])
                ->assertStatus(200)
                ->assertSet('tab', 'overview')
                ->assertViewHas('advanceCount', 3)
                ->assertViewHas('salaryCount', 2)
                ->assertViewHas('advancePositions', fn ($p) => $p->total() === 0)
                ->assertViewHas('salaryPositions', fn ($p) => $p->total() === 0)
                ->assertViewHas('statementEvents', fn ($p) => $p->total() === 0);
        });
        foreach ($overviewQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('salary_advance_allocations', $sql);
            $this->assertStringNotContainsString('salary_payment_allocations', $sql);
            $this->assertStringNotContainsString('salary_entry_lines', $sql);
            if (str_contains($sql, 'from `salary_payments`')) {
                $this->assertStringContainsString('exists', $sql);
            }
        }

        // Advances tab loads advance dataset + scalar salaryCount only
        $empLw = Livewire::actingAs($this->owner)
            ->test(EmployeeDetail::class, ['publicId' => $employee->public_id])
            ->assertStatus(200);
        $advTabQueries = $this->captureQueries(function () use ($empLw): void {
            $empLw->set('tab', 'advances')
                ->assertStatus(200)
                ->assertSet('tab', 'advances')
                ->assertViewHas('advanceCount', 3)
                ->assertViewHas('advancePositions', fn ($p) => $p->total() === 3)
                ->assertViewHas('salaryPositions', fn ($p) => $p->total() === 0);
        });
        foreach ($advTabQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('salary_payment_allocations', $sql);
            if (str_contains($sql, 'from `salary_payments`')) {
                $this->assertStringContainsString('exists', $sql);
            }
        }

        // Salaries tab loads salary dataset + scalar advanceCount only
        $salTabQueries = $this->captureQueries(function () use ($empLw): void {
            $empLw->set('tab', 'salaries')
                ->assertStatus(200)
                ->assertSet('tab', 'salaries')
                ->assertViewHas('salaryCount', 2)
                ->assertViewHas('salaryPositions', fn ($p) => $p->total() === 2)
                ->assertViewHas('advancePositions', fn ($p) => $p->total() === 0);
        });
        foreach ($salTabQueries as $q) {
            $sql = strtolower($q['query']);
            $this->assertStringNotContainsString('salary_advance_allocations', $sql);
            if (str_contains($sql, 'from `salary_payments`')) {
                $this->assertStringContainsString('exists', $sql);
            }
        }

        // Identity-only user: zero salary/advance/allocation queries & zero salary column selection, even on archived employee with history
        $identityOnly = User::factory()->create(['locale' => 'en']);
        $this->company->users()->attach($identityOnly->id, ['status' => 'active']);
        setPermissionsTeamId($this->company->id);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $idRole = Role::create(['name' => 'IdentityReaderBnd', 'company_id' => $this->company->id, 'guard_name' => 'web']);
        $idRole->givePermissionTo('employees.view');
        $identityOnly->assignRole($idRole);
        $this->activate($identityOnly);

        $employee->delete(); // archive employee with financial history

        foreach (['overview', 'advances', 'salaries', 'statement'] as $requestedTab) {
            $idQueries = $this->captureQueries(function () use ($identityOnly, $employee, $requestedTab): void {
                $lw = Livewire::actingAs($identityOnly)
                    ->test(EmployeeDetail::class, ['publicId' => $employee->public_id]);
                if ($requestedTab !== 'overview') {
                    $lw->set('tab', $requestedTab);
                }

                $lw->assertStatus(200)
                    ->assertSet('tab', 'overview')
                    ->assertViewHas('advanceCount', 0)
                    ->assertViewHas('salaryCount', 0)
                    ->assertViewHas('advancePositions', fn ($p) => $p->total() === 0)
                    ->assertViewHas('salaryPositions', fn ($p) => $p->total() === 0)
                    ->assertViewHas('statementEvents', fn ($p) => $p->total() === 0)
                    ->assertViewHas('employee', fn (Employee $e) => $e->default_salary === null && $e->salary_currency_code === null);

                $this->assertStringNotContainsString('1500.00', json_encode($lw->snapshot));
            });

            foreach ($idQueries as $q) {
                $sql = strtolower($q['query']);
                // Direct table scans of financial tables are forbidden; only EXISTS probes for archived history check exist
                if (str_contains($sql, 'from `salary_entries`')
                    || str_contains($sql, 'from `employee_advances`')
                    || str_contains($sql, 'from `salary_payments`')) {
                    $this->assertStringContainsString('exists', $sql, "Identity-only query touched financial table outside EXISTS check: {$q['query']}");
                }
                $this->assertStringNotContainsString('salary_advance_allocations', $sql);
                $this->assertStringNotContainsString('salary_payment_allocations', $sql);
                $this->assertStringNotContainsString('salary_entry_lines', $sql);
                $this->assertStringNotContainsString('default_salary', $sql);
                $this->assertStringNotContainsString('salary_currency_code', $sql);
            }
        }

        // Archived employee WITHOUT financial history -> 404
        $emptyEmp = Employee::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $this->company->id,
            'code' => 'EMP-EMPTY-DEL',
            'name' => 'No History Employee',
            'default_salary' => '0.000000',
            'salary_currency_code' => 'ILS',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
        $emptyEmp->delete();
        Livewire::actingAs($identityOnly)
            ->test(EmployeeDetail::class, ['publicId' => $emptyEmp->public_id])
            ->assertStatus(404);

        // Mixed-tenant / wrong-company rejection on PayrollReadService
        $otherOwner = User::factory()->create(['locale' => 'en']);
        app(CompanyContext::class)->clear();
        $otherCompany = app(CreateCompanyAction::class)->execute($otherOwner, [
            'name_ar' => 'شركة رواتب أخرى',
            'name_en' => 'Other Payroll Co',
            'base_currency_code' => 'ILS',
        ]);
        app(EnsurePhase7FoundationAction::class)->execute($otherCompany);
        $this->activate($otherOwner, $otherCompany);

        try {
            $payrollRead->advancePositions($employee, $otherOwner);
            $this->fail('Expected AuthorizationException on wrong-company PayrollReadService call.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }

        try {
            $payrollRead->salaryPositions($employee, $otherOwner);
            $this->fail('Expected AuthorizationException on wrong-company PayrollReadService salaryPositions call.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
    }
}
