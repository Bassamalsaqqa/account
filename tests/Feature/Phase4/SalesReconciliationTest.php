<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\CreateSalesReturnDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\PostSalesReturnAction;
use App\Domain\Inventory\DTO\StockMovementCommand;
use App\Domain\Inventory\DTO\StockMovementLineCommand;
use App\Domain\Inventory\ValueObjects\Quantity;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\StockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Sales\SalesReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected Warehouse $warehouse;

    protected Product $product;

    protected ProductUnit $productUnit;

    protected MoneyAccount $moneyAccount;

    protected SalesReconciliationService $reconciler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة المطابقة والمراجعة',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل التدقيق الداخلي',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->warehouse = Warehouse::where('company_id', $this->company->id)->where('is_default', true)->firstOrFail();
        $unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'منتج تدقيق المبيعات',
            'sku' => 'REC-PROD-01',
            'base_unit_id' => $unitPiece->id,
            'product_type' => 'stock',
            'track_stock' => true,
            'track_expiry' => false,
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->productUnit = ProductUnit::create([
            'company_id' => $this->company->id,
            'product_id' => $this->product->id,
            'unit_id' => $unitPiece->id,
            'conversion_to_base' => '1.000000',
            'is_base' => true,
            'is_default_sale' => true,
            'is_default_purchase' => true,
            'suggested_sale_price' => '100.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        // Seed stock
        app(InventoryMovementService::class)->record(new StockMovementCommand(
            companyId: $this->company->id,
            movementType: StockMovement::TYPE_OPENING_BALANCE,
            movementDate: Carbon::now()->toDateString(),
            lines: [
                new StockMovementLineCommand(
                    productId: $this->product->id,
                    warehouseId: $this->warehouse->id,
                    quantity: Quantity::of('20.000000'),
                    unitId: $unitPiece->id,
                    unitCostBase: '40.000000',
                ),
            ],
            sourceType: 'manual_seed',
            sourceId: 1,
            idempotencyKey: 'seed_rec_stock',
            createdBy: $this->user->id,
            reason: 'Seed stock for reconciliation test',
        ));

        $this->moneyAccount = app(CreateMoneyAccountAction::class)->execute($this->company, $this->user, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق تدقيق الحسابات',
            'name_en' => 'Audit Cash Box',
        ]);

        $this->reconciler = app(SalesReconciliationService::class);
    }

    public function test_reconciliation_passes_for_clean_healthy_company(): void
    {
        // 1. Post Invoice
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postInvoiceAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'منتج تدقيق المبيعات',
                    'quantity' => '5.000000',
                    'unit_price' => '100.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $postedInvoice = $postInvoiceAction->execute($invoice, $this->user);

        // 2. Post Return of 1 unit
        $returnDraftAction = app(CreateSalesReturnDraftAction::class);
        $postReturnAction = app(PostSalesReturnAction::class);

        $return = $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $postedInvoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'sales_invoice_line_id' => $postedInvoice->lines()->firstOrFail()->id,
                    'quantity' => '1.000000',
                ],
            ],
        ]);
        $postReturnAction->execute($return, $this->user);

        // 3. Post Receipt of 400 ILS fully allocating remaining balance
        $paymentAction = app(PostCustomerPaymentAction::class);
        $paymentAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->moneyAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '400.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $postedInvoice->id,
                    'allocated_amount' => '400.000000',
                ],
            ],
        ]);

        // Run reconciliation
        $report = $this->reconciler->reconcile($this->company);

        $this->assertTrue($report->isHealthy);
        $this->assertEmpty($report->violations);
        $this->assertSame(1, $report->stats['invoices_count']);
        $this->assertSame(1, $report->stats['returns_count']);
        $this->assertSame(1, $report->stats['payments_count']);
    }

    public function test_reconciliation_detects_draft_invoice_side_effects(): void
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'مسودة غير مكتملة',
                    'quantity' => '1.000000',
                    'unit_price' => '50.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        // Artificially corrupt the draft by giving it a fake posted_at timestamp
        DB::table('sales_invoices')->where('id', $invoice->id)->update([
            'posted_at' => Carbon::now(),
        ]);

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertNotEmpty($report->violations);
        $this->assertStringContainsString('Draft invoice', $report->violations[0]);
        $this->assertStringContainsString('has a non-null posted_at timestamp', $report->violations[0]);
    }

    public function test_reconciliation_detects_missing_invoice_number_on_posted_invoice(): void
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postInvoiceAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'خدمة',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
        $posted = $postInvoiceAction->execute($invoice, $this->user);

        // Artificially remove invoice number from posted invoice
        DB::table('sales_invoices')->where('id', $posted->id)->update([
            'invoice_number' => null,
        ]);

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertStringContainsString('without an invoice_number', implode(' | ', $report->violations));
    }

    public function test_reconciliation_detects_cross_tenant_customer_reference(): void
    {
        // Clear active context to create Company B
        app(CompanyContext::class)->clear();

        $userB = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);
        $companyB = $creator->execute($userB, [
            'name_ar' => 'شركة أجنبية',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($companyB, $userB);

        $foreignCustomer = Customer::create([
            'company_id' => $companyB->id,
            'name_ar' => 'عميل الشركة الأجنبية',
            'active' => true,
            'created_by' => $userB->id,
        ]);

        // Restore active context to Company A
        app(CompanyContext::class)->setCompany($this->company, $this->user);

        // Insert an invoice in Company A referencing Customer from Company B
        DB::table('sales_invoices')->insert([
            'public_id' => '01AN4Z07BY79KA1307SR9X4MV4',
            'company_id' => $this->company->id,
            'invoice_number' => 'INV-LEAK-001',
            'customer_id' => $foreignCustomer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->toDateString(),
            'status' => SalesInvoice::STATUS_DRAFT,
            'created_by' => $this->user->id,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $report = $this->reconciler->reconcile($this->company);

        $this->assertFalse($report->isHealthy);
        $this->assertStringContainsString('referencing customers from another company', implode(' | ', $report->violations));
    }

    public function test_sales_reconcile_artisan_command_returns_success_for_clean_system(): void
    {
        // Reconcile healthy company via command
        $exitCode = Artisan::call('sales:reconcile', [
            'companyPublicId' => $this->company->public_id,
        ]);

        $this->assertSame(0, $exitCode);
    }
}
