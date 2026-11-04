<?php

declare(strict_types=1);

namespace Tests\Feature\Phase4;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Actions\Sales\VoidSalesInvoiceAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SalesInvoiceFinancialSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Company $company;

    protected Customer $customer;

    protected MoneyAccount $ilsCashAccount;

    protected MoneyAccount $usdCashAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['locale' => 'ar']);
        $creator = app(CreateCompanyAction::class);

        $this->company = $creator->execute($this->user, [
            'name_ar' => 'شركة الملخص المالي',
            'base_currency_code' => 'ILS',
        ]);

        app(CompanyContext::class)->setCompany($this->company, $this->user);
        $this->actingAs($this->user);

        $this->customer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل الفواتير والمدفوعات',
            'active' => true,
            'created_by' => $this->user->id,
        ]);

        $moneyAccountAction = app(CreateMoneyAccountAction::class);

        $this->ilsCashAccount = $moneyAccountAction->execute($this->company, $this->user, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'ILS',
            'name_ar' => 'صندوق الشيكل',
            'name_en' => 'ILS Cash Box',
        ]);

        $this->usdCashAccount = $moneyAccountAction->execute($this->company, $this->user, [
            'account_type' => MoneyAccount::TYPE_CASH,
            'currency_code' => 'USD',
            'name_ar' => 'صندوق الدولار',
            'name_en' => 'USD Cash Box',
        ]);
    }

    private function createDraftInvoice(string $currency = 'ILS', string $exchangeRate = '1.0000000000', string $amount = '100.000000'): SalesInvoice
    {
        return app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'currency_code' => $currency,
            'exchange_rate' => $exchangeRate,
            'issue_date' => Carbon::now()->toDateString(),
            'due_date' => Carbon::now()->addDays(30)->toDateString(),
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'خدمة برمجية',
                    'quantity' => '1.000000',
                    'unit_price' => $amount,
                    'discount_type' => 'none',
                    'discount_value' => '0.000000',
                    'tax_rate_id' => null,
                ],
            ],
        ]);
    }

    private function postInvoice(SalesInvoice $invoice): SalesInvoice
    {
        return app(PostSalesInvoiceAction::class)->execute($invoice, $this->user);
    }

    public function test_draft_invoice_summary_returns_zero_and_unpaid(): void
    {
        $draft = $this->createDraftInvoice();

        $summary = $draft->calculateFinancialSummary();

        $this->assertTrue($summary['paid']->isZero());
        $this->assertTrue($summary['returned']->isZero());
        $this->assertTrue($summary['outstanding']->isZero());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $summary['payment_status']);

        $this->assertTrue($draft->calculateOutstanding()->isZero());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $draft->derivedPaymentStatus());
    }

    public function test_posted_unpaid_invoice_returns_full_outstanding(): void
    {
        $invoice = $this->postInvoice($this->createDraftInvoice('ILS', '1.0000000000', '150.000000'));

        $summary = $invoice->calculateFinancialSummary();

        $this->assertTrue($summary['paid']->isZero());
        $this->assertTrue($summary['returned']->isZero());
        $this->assertTrue($summary['outstanding']->isEqualTo(BigDecimal::of('150.000000')));
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $summary['payment_status']);
    }

    public function test_partially_and_fully_paid_invoice_summary_states(): void
    {
        $invoice = $this->postInvoice($this->createDraftInvoice('ILS', '1.0000000000', '200.000000'));

        $paymentAction = app(PostCustomerPaymentAction::class);
        $payment1 = $paymentAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'summary-part-'.(string) Carbon::now()->timestamp,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '80.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '80.000000',
                ],
            ],
        ]);

        $summaryPart = $invoice->calculateFinancialSummary();
        $this->assertTrue($summaryPart['paid']->isEqualTo(BigDecimal::of('80.000000')));
        $this->assertTrue($summaryPart['outstanding']->isEqualTo(BigDecimal::of('120.000000')));
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_PARTIALLY_PAID, $summaryPart['payment_status']);

        // Complete remaining 120
        $paymentAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'summary-full-'.(string) Carbon::now()->timestamp,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '120.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '120.000000',
                ],
            ],
        ]);

        $summaryFull = $invoice->calculateFinancialSummary();
        $this->assertTrue($summaryFull['paid']->isEqualTo(BigDecimal::of('200.000000')));
        $this->assertTrue($summaryFull['outstanding']->isZero());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_PAID, $summaryFull['payment_status']);
    }

    public function test_reversed_payment_is_excluded_from_financial_summary(): void
    {
        $invoice = $this->postInvoice($this->createDraftInvoice('ILS', '1.0000000000', '100.000000'));

        $paymentAction = app(PostCustomerPaymentAction::class);
        $payment = $paymentAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'summary-rev-'.(string) Carbon::now()->timestamp,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '100.000000',
            'exchange_rate' => '1.0000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '100.000000',
                ],
            ],
        ]);

        $this->assertSame(SalesInvoice::PAYMENT_STATUS_PAID, $invoice->derivedPaymentStatus());

        // Reverse receipt
        app(ReverseCustomerPaymentAction::class)->execute($payment, $this->user, 'Reversal test');

        $summary = $invoice->calculateFinancialSummary();
        $this->assertTrue($summary['paid']->isZero());
        $this->assertTrue($summary['outstanding']->isEqualTo(BigDecimal::of('100.000000')));
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $summary['payment_status']);
    }

    public function test_void_invoice_summary_returns_zero_and_unpaid(): void
    {
        $invoice = $this->postInvoice($this->createDraftInvoice('ILS', '1.0000000000', '100.000000'));
        $this->assertSame(SalesInvoice::STATUS_POSTED, $invoice->status);

        app(VoidSalesInvoiceAction::class)->execute($invoice, $this->user, 'Cancel invoice');

        $invoice->refresh();
        $this->assertSame(SalesInvoice::STATUS_VOID, $invoice->status);

        $summary = $invoice->calculateFinancialSummary();
        $this->assertTrue($summary['paid']->isZero());
        $this->assertTrue($summary['returned']->isZero());
        $this->assertTrue($summary['outstanding']->isZero());
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_UNPAID, $summary['payment_status']);
    }

    public function test_foreign_currency_invoice_computes_in_invoice_currency(): void
    {
        $usdInvoice = $this->postInvoice($this->createDraftInvoice('USD', '3.5000000000', '100.000000'));

        $paymentAction = app(PostCustomerPaymentAction::class);
        $paymentAction->execute($this->company, $this->user, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->usdCashAccount->id,
            'payment_date' => Carbon::now()->toDateString(),
            'idempotency_key' => 'usd-summary-'.(string) Carbon::now()->timestamp,
            'payment_method' => CustomerPayment::METHOD_CASH,
            'amount' => '40.000000',
            'exchange_rate' => '3.6000000000',
            'allocations' => [
                [
                    'sales_invoice_id' => $usdInvoice->id,
                    'allocated_amount' => '40.000000',
                ],
            ],
        ]);

        $summary = $usdInvoice->calculateFinancialSummary();
        $this->assertTrue($summary['paid']->isEqualTo(BigDecimal::of('40.000000')));
        $this->assertTrue($summary['outstanding']->isEqualTo(BigDecimal::of('60.000000')));
        $this->assertSame(SalesInvoice::PAYMENT_STATUS_PARTIALLY_PAID, $summary['payment_status']);
    }
}
