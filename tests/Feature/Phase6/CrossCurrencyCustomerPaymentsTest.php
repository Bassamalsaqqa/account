<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\SalesInvoice;
use App\Services\Sales\SalesCorrectionAudit;
use App\Services\Sales\SalesHistoryAudit;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CrossCurrencyCustomerPaymentsTest extends Phase5ETestCase
{
    private function invoice(): SalesInvoice
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل', 'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => 'USD', 'exchange_rate' => '3.50', 'issue_date' => '2026-10-01',
            'lines' => [['product_id' => null, 'item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    private function pay(SalesInvoice $invoice, array $allocations, string $key): CustomerPayment
    {
        return app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $invoice->customer_id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '330',
            'exchange_rate' => '1', 'idempotency_key' => $key, 'allocations' => $allocations,
        ]);
    }

    public function test_cross_currency_customer_receipt_consumes_ils_and_relieves_usd_with_fx_loss(): void
    {
        $invoice = $this->invoice();
        $intent = [['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330']];
        $payment = $this->pay($invoice, $intent, 'cross-receipt');
        $this->assertSame('-20.000000', $payment->allocations->sole()->realized_fx_gain_loss_base);
        $this->assertSame('0.000000', $payment->unallocated_amount);
        $this->assertTrue($invoice->calculateOutstanding()->isZero());
        $this->assertSame($payment->id, $this->pay($invoice, $intent, 'cross-receipt')->id);
        $this->assertSame([], app(SalesHistoryAudit::class)->audit($this->company->id));
        $this->assertSame([], app(SalesCorrectionAudit::class)->audit($this->company->id));
    }

    public function test_later_cross_currency_customer_credit_preserves_exact_payment_residual(): void
    {
        $invoice = $this->invoice();
        $payment = $this->pay($invoice, [], 'cross-customer-advance');
        $data = ['application_date' => '2026-10-04', 'idempotency_key' => 'customer-cross-apply', 'allocations' => [
            ['sales_invoice_id' => $invoice->id, 'allocated_amount' => '100', 'payment_currency_amount' => '330'],
        ]];
        $event = app(ApplyCustomerPaymentCreditAction::class)->execute($payment, $this->owner, $data);
        $this->assertSame('-20.000000', $event->allocations->sole()->realized_fx_gain_loss_base);
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount);
        $this->assertTrue($invoice->calculateOutstanding()->isZero());
        $this->assertSame($event->id, app(ApplyCustomerPaymentCreditAction::class)->execute($payment, $this->owner, $data)->id);
        $this->assertSame([], app(SalesHistoryAudit::class)->audit($this->company->id));
        $this->assertSame([], app(SalesCorrectionAudit::class)->audit($this->company->id));
    }
}
