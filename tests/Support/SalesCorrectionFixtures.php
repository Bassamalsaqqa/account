<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Actions\Sales\CreateQuotationAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\MoneyAccount;
use App\Models\Quotation;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

trait SalesCorrectionFixtures
{
    protected User $owner;

    protected Company $company;

    protected Customer $customer;

    protected MoneyAccount $cash;

    protected function salesFixtures(): void
    {
        $this->owner = User::factory()->create(['locale' => 'en']);
        $this->company = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'شركة الاختبار', 'name_en' => 'Test company', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($this->company, $this->owner);
        $this->actingAs($this->owner);
        $this->customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل الاختبار', 'name_en' => 'Test customer', 'preferred_locale' => 'en', 'created_by' => $this->owner->id]);
        $this->cash = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['account_type' => 'cash', 'name_ar' => 'الصندوق', 'name_en' => 'Cash USD', 'currency_code' => 'USD']);
    }

    /** @param array<string, mixed> $extra */
    protected function invoice(string $amount = '100', string $rate = '3.50', string $currency = 'USD', array $extra = []): SalesInvoice
    {
        $invoice = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, $extra + [
            'customer_id' => $this->customer->id, 'currency_code' => $currency, 'exchange_rate' => $rate, 'issue_date' => '2026-10-02', 'due_date' => null,
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => $amount]],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($invoice, $this->owner);
    }

    /** @param list<array{sales_invoice_id: int, allocated_amount: string}> $allocations */
    protected function receipt(string $amount = '100', string $rate = '3.60', array $allocations = [], string $key = 'receipt'): CustomerPayment
    {
        return app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id, 'money_account_id' => $this->cash->id, 'payment_date' => '2026-10-02',
            'payment_method' => 'cash', 'amount' => $amount, 'exchange_rate' => $rate, 'idempotency_key' => $key, 'allocations' => $allocations,
        ]);
    }

    /** @param array<string, mixed> $extra */
    protected function quote(array $extra = []): Quotation
    {
        return app(CreateQuotationAction::class)->execute($this->company, $this->owner, $extra + [
            'customer_id' => $this->customer->id, 'currency_code' => 'ILS', 'exchange_rate' => '1', 'issue_date' => '2026-10-02',
            'lines' => [['item_description' => 'Service', 'quantity' => '1', 'unit_price' => '100']],
        ]);
    }
}
