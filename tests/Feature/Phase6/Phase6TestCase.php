<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Customer;
use App\Models\SalesInvoice;
use Tests\Feature\Phase5E\Phase5ETestCase;

abstract class Phase6TestCase extends Phase5ETestCase
{
    protected function invoice(string $currency = 'USD', string $rate = '3.50', string $amount = '100'): SalesInvoice
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل الشيكات', 'name_en' => 'Check Customer', 'active' => true, 'created_by' => $this->owner->id]);
        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $customer->id, 'currency_code' => $currency, 'exchange_rate' => $rate, 'issue_date' => '2026-10-01',
            'lines' => [['product_id' => null, 'item_description' => 'Service', 'quantity' => '1', 'unit_price' => $amount]],
        ]);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }

    /** @return array<string, mixed> */
    protected function checkIntent(string $direction = 'incoming', array $allocations = []): array
    {
        $customer = $direction === 'incoming' ? Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل', 'active' => true, 'created_by' => $this->owner->id]) : null;

        return ['party_id' => $customer?->id ?? $this->vendor->id, 'money_account_id' => $direction === 'outgoing' ? $this->usdBankAccount->id : null,
            'check_number' => 'CHECK-'.uniqid(), 'bank_name' => 'Test Bank', 'date' => '2026-10-02', 'due_date' => '2026-10-03',
            'currency_code' => 'USD', 'amount' => '100', 'exchange_rate' => '3.50', 'idempotency_key' => 'check-'.uniqid(), 'allocations' => $allocations];
    }

    protected function check(string $direction = 'incoming'): Check
    {
        $data = $this->checkIntent($direction);

        return $direction === 'incoming' ? app(ReceiveCheckAction::class)->execute($this->company, $this->owner, $data)
            : app(IssueCheckAction::class)->execute($this->company, $this->owner, $data);
    }

    protected function event(Check $check, string $type, string $rate = '3.50', string $date = '2026-10-03'): CheckEvent
    {
        return app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => $type, 'event_date' => $date,
            'idempotency_key' => 'event-'.uniqid(), 'money_account_id' => $type === 'deposit' ? $this->usdBankAccount->id : null, 'exchange_rate' => $rate]);
    }
}
