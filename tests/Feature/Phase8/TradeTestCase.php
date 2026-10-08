<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Sales\CreateSalesInvoiceDraftAction;
use App\Actions\Sales\PostSalesInvoiceAction;
use App\Models\Customer;
use App\Models\SalesInvoice;

abstract class TradeTestCase extends Phase8TestCase
{
    protected Customer $defaultCustomer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaultCustomer = Customer::create([
            'company_id' => $this->company->id,
            'name_ar' => 'عميل المبيعات الرئيسي',
            'name_en' => 'Main Sales Customer',
            'active' => true,
            'created_by' => $this->owner->id,
        ]);
    }

    protected function createAndPostSalesInvoice(array $overrides = []): SalesInvoice
    {
        $customer = $overrides['customer'] ?? $this->defaultCustomer;
        unset($overrides['customer']);

        $lines = $overrides['lines'] ?? [
            [
                'product_id' => $this->product->id,
                'item_description' => 'سلعة غذائية تجريبية',
                'quantity' => '2.000000',
                'unit_price' => '50.000000',
            ],
        ];

        foreach ($lines as $i => $l) {
            if (! isset($l['item_description'])) {
                $lines[$i]['item_description'] = 'سلعة تجريبية';
            }
        }
        $overrides['lines'] = $lines;

        $data = array_replace_recursive([
            'customer_id' => $customer->id,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'issue_date' => '2026-10-01',
            'due_date' => '2026-10-15',
            'lines' => $lines,
        ], $overrides);

        $draft = app(CreateSalesInvoiceDraftAction::class)->execute($this->company, $this->owner, $data);

        return app(PostSalesInvoiceAction::class)->execute($draft, $this->owner);
    }
}
