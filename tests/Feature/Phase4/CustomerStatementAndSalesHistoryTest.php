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
use App\Domain\Sales\Queries\CustomerProductSalesHistoryQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Domain\Sales\Queries\ProductSalesHistoryQuery;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerStatementAndSalesHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected MoneyAccount $ilsCashAccount;

    protected MoneyAccount $usdCashAccount;

    protected Product $product;

    protected ProductUnit $productUnit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة كشوف الحساب وسجل الأسعار',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل كشف الحساب المعتمد',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $unitPiece = Unit::where('company_id', $this->company->id)->where('code', 'piece')->firstOrFail();

        $this->product = Product::create([
            'company_id' => $this->company->id,
            'name_ar' => 'خدمة تصميم مواقع',
            'sku' => 'WEB-DEV-01',
            'base_unit_id' => $unitPiece->id,
            'product_type' => 'service',
            'track_stock' => false,
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
            'suggested_sale_price' => '500.000000',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $moneyAccountAction = app(CreateMoneyAccountAction::class);
        $this->ilsCashAccount = $moneyAccountAction->execute($this->company, $this->user, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق الشيكل',
            'name_en' => 'ILS Cash',
        ]);

        $this->usdCashAccount = $moneyAccountAction->execute($this->company, $this->user, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'USD',
            'name_ar' => 'صندوق الدولار',
            'name_en' => 'USD Cash',
        ]);
    }

    private function postInvoice(string $currency, string $rate, string $amount, ?string $dueDate = null): SalesInvoice
    {
        $draftAction = app(CreateSalesInvoiceDraftAction::class);
        $postAction = app(PostSalesInvoiceAction::class);

        $invoice = $draftAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'exchange_rate' => $rate,
            'issue_date' => $dueDate !== null && $dueDate < Carbon::now()->toDateString() ? Carbon::parse($dueDate)->subDays(30)->toDateString() : Carbon::now()->toDateString(),
            'due_date' => $dueDate ?? Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'product_unit_id' => $this->productUnit->id,
                    'item_description' => 'خدمة تصميم مواقع',
                    'quantity' => '1.000000',
                    'unit_price' => $amount,
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);

        return $postAction->execute($invoice, $this->user);
    }

    public function test_customer_statement_groups_currencies_and_computes_running_balance(): void
    {
        // 1. Invoice of 500 ILS
        $invoice = $this->postInvoice('ILS', '1.0000000000', '500.000000');

        // 2. Return of 100 ILS
        $returnDraftAction = app(CreateSalesReturnDraftAction::class);
        $postReturnAction = app(PostSalesReturnAction::class);
        $return = $returnDraftAction->execute($this->company, $this->user, [
            'sales_invoice_id' => $invoice->id,
            'issue_date' => Carbon::now()->toDateString(),
            'lines' => [
                [
                    'sales_invoice_line_id' => $invoice->lines()->firstOrFail()->id,
                    'quantity' => '0.200000', // 0.2 of 500 = 100 ILS
                ],
            ],
        ]);
        $postReturnAction->execute($return, $this->user);

        // 3. Payment of 200 ILS
        $paymentAction = app(PostCustomerPaymentAction::class);
        $paymentAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'receipt-'.__FUNCTION__,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '200.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '200.000000',
                ],
            ],
        ]);

        $query = app(CustomerStatementQuery::class);
        $statement = $query->execute($this->customer);

        $this->assertArrayHasKey('ILS', $statement['currencies']);
        $ilsBlock = $statement['currencies']['ILS'];

        // 3 entries: invoice (Dr 500), return (Cr 100), payment (Cr 200)
        $this->assertCount(3, $ilsBlock['entries']);

        // Check running balances: 500 -> 400 -> 200
        $this->assertSame('500.00', $ilsBlock['entries'][0]['balance']);
        $this->assertSame('400.00', $ilsBlock['entries'][1]['balance']);
        $this->assertSame('200.00', $ilsBlock['entries'][2]['balance']);

        // Closing balance
        $this->assertSame('200.00', $ilsBlock['closing_balance']);
        $this->assertSame('500.00', $ilsBlock['total_debits']);
        $this->assertSame('300.00', $ilsBlock['total_credits']);
    }

    public function test_customer_statement_multi_currency_never_sums_unlike_currencies(): void
    {
        // 500 ILS invoice
        $this->postInvoice('ILS', '1.0000000000', '500.000000');

        // 100 USD invoice
        $this->postInvoice('USD', '3.5000000000', '100.000000');

        $query = app(CustomerStatementQuery::class);
        $statement = $query->execute($this->customer);

        $this->assertArrayHasKey('ILS', $statement['currencies']);
        $this->assertArrayHasKey('USD', $statement['currencies']);

        $ilsBlock = $statement['currencies']['ILS'];
        $usdBlock = $statement['currencies']['USD'];

        $this->assertSame('500.00', $ilsBlock['closing_balance']);
        $this->assertSame('100.00', $usdBlock['closing_balance']);
    }

    public function test_customer_statement_aging_buckets(): void
    {
        // Invoice overdue by 45 days (bucket 31-60 days)
        $overdueDate = Carbon::now()->subDays(45)->toDateString();
        $this->postInvoice('ILS', '1.0000000000', '350.000000', $overdueDate);

        $query = app(CustomerStatementQuery::class);
        $statement = $query->execute($this->customer);

        $ilsBlock = $statement['currencies']['ILS'];
        $this->assertSame('350.00', $ilsBlock['aging']['days_31_60']);
        $this->assertSame('350.00', $ilsBlock['aging']['total']);
    }

    public function test_customer_product_sales_history_and_product_sales_history_queries(): void
    {
        // 1st sale at 450.00
        $this->postInvoice('ILS', '1.0000000000', '450.000000');

        // 2nd sale at 480.00
        $this->postInvoice('ILS', '1.0000000000', '480.000000');

        $customerHistoryQuery = app(CustomerProductSalesHistoryQuery::class);
        $customerLines = $customerHistoryQuery->execute($this->customer, $this->product);

        $this->assertCount(2, $customerLines);
        // Most recent first
        $this->assertSame('480.000000', $customerLines->first()->unit_price);
        $this->assertSame('450.000000', $customerLines->last()->unit_price);

        $productHistoryQuery = app(ProductSalesHistoryQuery::class);
        $productLines = $productHistoryQuery->execute($this->product);

        $this->assertCount(2, $productLines);
        $this->assertSame('480.000000', $productLines->first()->unit_price);
    }
}
