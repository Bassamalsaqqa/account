<?php

declare(strict_types=1);

namespace Tests\Feature\Accounting;

use App\Actions\Accounting\PostOpeningTradeItemAction;
use App\Actions\Accounting\ReverseOpeningTradeItemAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\ApplyCustomerPaymentCreditAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Application\Reporting\Queries\CustomerReceivablesAgingReportQuery;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\Money\CustomerApplicationHistory;
use App\Services\Money\PaymentAllocationIntent;
use Brick\Math\BigDecimal;
use InvalidArgumentException;
use Tests\Feature\Phase8\TradeTestCase;

final class OpeningTradeSettlementTest extends TradeTestCase
{
    private Customer $customer;

    private Vendor $testVendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->activateUser($this->owner);
        $this->customer = $this->defaultCustomer;
        $this->testVendor = $this->vendor;
    }

    public function test_customer_payment_can_allocate_to_opening_trade_item(): void
    {
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '1000.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-cust-open-settle',
        );

        $this->assertTrue(BigDecimal::of('1000.00')->isEqualTo($item->calculateOutstanding()));

        $postPaymentAction = app(PostCustomerPaymentAction::class);
        $payment = $postPaymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-10',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '400.00',
            'idempotency_key' => 'pmt-cust-open-settle',
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $item->id,
                    'allocated_amount' => '400.00',
                ],
            ],
        ]);

        $this->assertInstanceOf(CustomerPayment::class, $payment);
        $this->assertCount(1, $payment->allocations);

        /** @var CustomerPaymentAllocation $alloc */
        $alloc = $payment->allocations->first();
        $this->assertSame($item->id, $alloc->opening_trade_item_id);
        $this->assertNull($alloc->sales_invoice_id);
        $this->assertTrue(BigDecimal::of($alloc->allocated_amount)->isEqualTo('400.00'));
        $this->assertSame('400.000000', (string) $alloc->base_amount_applied_to_receivable);

        // Check outstanding after payment
        $this->assertTrue(BigDecimal::of('600.00')->isEqualTo($item->calculateOutstanding()));

        // Check GL entries: Dr Cash 400 / Cr AR 400
        $batch = PostingBatch::with('lines.account')->findOrFail($payment->posting_batch_id);
        $cashLine = $batch->lines->firstWhere('account.id', $this->ilsCashAccount->ledger_account_id);
        $arLine = $batch->lines->firstWhere('account.system_key', 'accounts_receivable');

        $this->assertTrue(BigDecimal::of($cashLine->debit_base)->isEqualTo(400));
        $this->assertTrue(BigDecimal::of($arLine->credit_base)->isEqualTo(400));
    }

    public function test_vendor_payment_can_allocate_to_opening_trade_item(): void
    {
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->testVendor->id,
            openingDate: '2026-01-01',
            amount: '800.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-vend-open-settle',
        );

        $this->assertTrue(BigDecimal::of('800.00')->isEqualTo($item->calculateOutstanding()));

        $postPaymentAction = app(PostVendorPaymentAction::class);
        $payment = $postPaymentAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->testVendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-12',
            'payment_method' => VendorPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '500.00',
            'document_locale' => 'ar',
            'idempotency_key' => 'pmt-vend-open-settle',
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $item->id,
                    'allocated_amount' => '500.00',
                ],
            ],
        ]);

        $this->assertInstanceOf(VendorPayment::class, $payment);
        $this->assertCount(1, $payment->allocations);

        /** @var VendorPaymentAllocation $alloc */
        $alloc = $payment->allocations->first();
        $this->assertSame($item->id, $alloc->opening_trade_item_id);
        $this->assertNull($alloc->purchase_id);
        $this->assertTrue(BigDecimal::of($alloc->allocated_amount)->isEqualTo('500.00'));
        $this->assertSame('500.000000', (string) $alloc->base_amount_applied_to_payable);

        // Check outstanding after payment
        $this->assertTrue(BigDecimal::of('300.00')->isEqualTo($item->calculateOutstanding()));

        // Check GL entries: Dr AP 500 / Cr Cash 500
        $batch = PostingBatch::with('lines.account')->findOrFail($payment->posting_batch_id);
        $cashLine = $batch->lines->firstWhere('account.id', $this->ilsCashAccount->ledger_account_id);
        $apLine = $batch->lines->firstWhere('account.system_key', 'accounts_payable');

        $this->assertTrue(BigDecimal::of($apLine->debit_base)->isEqualTo(500));
        $this->assertTrue(BigDecimal::of($cashLine->credit_base)->isEqualTo(500));
    }

    public function test_multi_target_settlement_handles_invoice_and_opening_item_without_collision(): void
    {
        // 1. Post a normal sales invoice
        $invoice = $this->createAndPostSalesInvoice([
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'Normal line',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);

        // 2. Post an opening trade item
        $openingAction = app(PostOpeningTradeItemAction::class);
        $openingItem = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '200.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-multi-target-open',
        );

        // 3. Post customer payment allocating 50 to invoice and 100 to opening item
        $postPaymentAction = app(PostCustomerPaymentAction::class);
        $payment = $postPaymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-15',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '150.00',
            'idempotency_key' => 'pmt-multi-target',
            'allocations' => [
                [
                    'kind' => 'sales_invoice',
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '50.00',
                ],
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $openingItem->id,
                    'allocated_amount' => '100.00',
                ],
            ],
        ]);

        $this->assertCount(2, $payment->allocations);

        $invAlloc = $payment->allocations->firstWhere('sales_invoice_id', $invoice->id);
        $openAlloc = $payment->allocations->firstWhere('opening_trade_item_id', $openingItem->id);

        $this->assertNotNull($invAlloc);
        $this->assertNotNull($openAlloc);
        $this->assertTrue(BigDecimal::of($invAlloc->allocated_amount)->isEqualTo('50.00'));
        $this->assertTrue(BigDecimal::of($openAlloc->allocated_amount)->isEqualTo('100.00'));

        // Verify remaining balances
        $this->assertTrue(BigDecimal::of('50.00')->isEqualTo($invoice->calculateOutstanding()));
        $this->assertTrue(BigDecimal::of('100.00')->isEqualTo($openingItem->calculateOutstanding()));
    }

    public function test_cross_currency_settlement_and_relief_under_adr_0006(): void
    {
        // Opening USD receivable: 100 USD at rate 3.50 -> book value 350.00 ILS
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '100.00',
            currencyCode: 'USD',
            exchangeRate: '3.5000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-fx-open-adr0006',
        );

        // Payment in USD bank account: 100 USD at spot rate 3.70
        // Settlement: 100 USD * 3.70 = 370.00 ILS
        // Book value relief: 100 USD * 3.50 = 350.00 ILS
        // Realized FX Gain: 370 - 350 = 20.00 ILS
        $postPaymentAction = app(PostCustomerPaymentAction::class);
        $payment = $postPaymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->usdBankAccount->id,
            'payment_date' => '2026-01-20',
            'payment_method' => CustomerPayment::METHOD_BANK,
            'currency_code' => 'USD',
            'exchange_rate' => '3.7000000000',
            'amount' => '100.00',
            'idempotency_key' => 'pmt-fx-open-adr0006',
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $item->id,
                    'allocated_amount' => '100.00',
                ],
            ],
        ]);

        /** @var CustomerPaymentAllocation $alloc */
        $alloc = $payment->allocations->first();
        $this->assertSame('350.000000', (string) $alloc->base_amount_applied_to_receivable);
        $this->assertSame('370.000000', (string) $alloc->settlement_base_value);

        // Check GL batch for realized FX gain
        $batch = PostingBatch::with('lines.account')->findOrFail($payment->posting_batch_id);
        $fxGainLine = $batch->lines->firstWhere('account.system_key', 'fx_gain');

        $this->assertNotNull($fxGainLine, 'Realized FX gain line must exist');
        $this->assertTrue(BigDecimal::of($fxGainLine->credit_base)->isEqualTo(20));

        $this->assertTrue(BigDecimal::of('0.00')->isEqualTo($item->calculateOutstanding()));
    }

    public function test_customer_credit_advance_application_to_opening_trade_item(): void
    {
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '500.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-credit-app-target',
        );

        // Unallocated advance receipt of 300 ILS
        $postPaymentAction = app(PostCustomerPaymentAction::class);
        $payment = $postPaymentAction->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-05',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '300.00',
            'idempotency_key' => 'pmt-advance-receipt',
            'allocations' => [],
        ]);

        $this->assertTrue(BigDecimal::of($payment->unallocated_amount)->isEqualTo('300.00'));

        // Apply credit to opening trade item
        $applyCreditAction = app(ApplyCustomerPaymentCreditAction::class);
        $applyCreditAction->execute($payment, $this->owner, [
            'application_date' => '2026-01-10',
            'idempotency_key' => 'credit-app-key-1',
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $item->id,
                    'allocated_amount' => '300.00',
                ],
            ],
        ]);

        $payment->refresh();
        $this->assertTrue(BigDecimal::of($payment->unallocated_amount)->isEqualTo('0.00'));
        $this->assertTrue(BigDecimal::of('200.00')->isEqualTo($item->calculateOutstanding()));
    }

    public function test_vendor_credit_advance_application_to_opening_trade_item(): void
    {
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->testVendor->id,
            openingDate: '2026-01-01',
            amount: '400.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-vend-credit-target',
        );

        // Unallocated advance payment of 250 ILS
        $postPaymentAction = app(PostVendorPaymentAction::class);
        $payment = $postPaymentAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->testVendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-05',
            'payment_method' => VendorPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '250.00',
            'document_locale' => 'ar',
            'idempotency_key' => 'pmt-vend-advance',
            'allocations' => [],
        ]);

        $this->assertTrue(BigDecimal::of($payment->unallocated_amount)->isEqualTo('250.00'));

        // Apply credit to opening trade item
        $applyCreditAction = app(ApplyVendorPaymentCreditAction::class);
        $applyCreditAction->execute($payment, $this->owner, [
            'application_date' => '2026-01-10',
            'idempotency_key' => 'vend-credit-app-key-1',
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $item->id,
                    'allocated_amount' => '250.00',
                ],
            ],
        ]);

        $payment->refresh();
        $this->assertTrue(BigDecimal::of($payment->unallocated_amount)->isEqualTo('0.00'));
        $this->assertTrue(BigDecimal::of('150.00')->isEqualTo($item->calculateOutstanding()));
    }

    public function test_customer_statement_includes_opening_trade_item_for_opening_only_customer(): void
    {
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '1500.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-statement-cust',
        );

        $query = app(CustomerStatementQuery::class);
        $statement = $query->execute($this->customer, '2026-01-01', '2026-12-31');

        $this->assertNotEmpty($statement['currencies']);
        $ilsData = $statement['currencies']['ILS'] ?? null;
        $this->assertNotNull($ilsData);

        // Find the opening trade item in entries
        $openingLine = collect($ilsData['entries'])->firstWhere('type', 'opening_balance');
        $this->assertNotNull($openingLine);
        $this->assertTrue(BigDecimal::of('1500.00')->isEqualTo($openingLine['debit']));
        $this->assertTrue(BigDecimal::of('1500.00')->isEqualTo($ilsData['closing_balance']));
    }

    public function test_vendor_statement_includes_opening_trade_item_for_opening_only_vendor(): void
    {
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->testVendor->id,
            openingDate: '2026-01-01',
            amount: '900.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-statement-vend',
        );

        $query = app(VendorStatementQuery::class);
        $statement = $query->execute($this->testVendor, '2026-01-01', '2026-12-31');

        $this->assertNotEmpty($statement['currencies']);
        $ilsData = $statement['currencies']['ILS'] ?? null;
        $this->assertNotNull($ilsData);

        $openingLine = collect($ilsData['entries'])->firstWhere('type', 'opening_balance');
        $this->assertNotNull($openingLine);
        $this->assertTrue(BigDecimal::of('900.00')->isEqualTo($openingLine['credit']));
        $this->assertTrue(BigDecimal::of('900.00')->isEqualTo($ilsData['closing_balance']));
    }

    public function test_trade_open_positions_maps_null_due_date_to_unspecified_bucket(): void
    {
        $openingAction = app(PostOpeningTradeItemAction::class);
        $item = $openingAction->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '350.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'idem-unspecified-bucket',
            dueDate: null,
        );

        $reportQuery = app(CustomerReceivablesAgingReportQuery::class);
        $result = $reportQuery->execute($this->company, [
            'period' => ['start_date' => '2026-01-01', 'end_date' => '2026-01-15'],
        ], $this->owner);

        $this->assertNotEmpty($result->rows);
        $customerRow = collect($result->rows)->firstWhere('customer_id', $this->customer->id);
        $this->assertNotNull($customerRow);
        $this->assertTrue(BigDecimal::of($customerRow['unspecified'])->isEqualTo(350));
        $this->assertTrue(BigDecimal::of($customerRow['total_outstanding'])->isEqualTo(350));
    }

    public function test_statements_replay_openings_by_financial_reversal_date_on_both_sides(): void
    {
        foreach ([OpeningTradeItem::SIDE_CUSTOMER, OpeningTradeItem::SIDE_VENDOR] as $side) {
            $party = $side === OpeningTradeItem::SIDE_CUSTOMER ? $this->customer : $this->testVendor;
            $query = app($side === OpeningTradeItem::SIDE_CUSTOMER ? CustomerStatementQuery::class : VendorStatementQuery::class);
            $item = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $party->id,
                openingDate: '2026-01-01', amount: '123.45', currencyCode: 'ILS',
                exchangeRate: '1', actor: $this->owner, idempotencyKey: 'dated-opening-'.$side,
            );
            app(ReverseOpeningTradeItemAction::class)->execute($item, $this->owner, 'Correct opening', '2026-03-01');
            $before = $query->execute($party, '2026-01-01', '2026-02-28')['currencies']['ILS'];
            $after = $query->execute($party, '2026-01-01', '2026-03-01')['currencies']['ILS'];
            $this->assertTrue(BigDecimal::of($before['closing_balance'])->isEqualTo('123.45'));
            $this->assertTrue(BigDecimal::of($after['closing_balance'])->isZero());
            $this->assertCount(1, collect($after['entries'])->where('type', 'reversal'));
            $this->assertSame('2026-03-01', collect($after['entries'])->firstWhere('type', 'reversal')['date']);
        }
    }

    public function test_partial_and_final_fx_credit_applications_replay_and_reverse_on_both_sides(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $customerSide = $side === 'customer';
            $party = $customerSide ? $this->customer : $this->testVendor;
            $post = app($customerSide ? PostCustomerPaymentAction::class : PostVendorPaymentAction::class);
            $apply = app($customerSide ? ApplyCustomerPaymentCreditAction::class : ApplyVendorPaymentCreditAction::class);
            $item = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $party->id, openingDate: '2026-01-01',
                amount: '100.00', currencyCode: 'USD', exchangeRate: '3.3333333333',
                actor: $this->owner, idempotencyKey: 'fx-credit-opening-'.$side,
            );
            $payment = $post->execute($this->company, $this->owner, [
                $customerSide ? 'customer_id' : 'vendor_id' => $party->id,
                'money_account_id' => $this->usdBankAccount->id, 'payment_date' => '2026-01-05',
                'payment_method' => CustomerPayment::METHOD_BANK, 'currency_code' => 'USD', 'exchange_rate' => '3.7',
                'amount' => '100.00', 'document_locale' => 'ar', 'idempotency_key' => 'fx-credit-payment-'.$side,
                'allocations' => [],
            ]);
            foreach (['33.33', '66.67'] as $index => $amount) {
                $data = [
                    'application_date' => '2026-01-10', 'idempotency_key' => 'fx-credit-apply-'.$side.'-'.$index,
                    'allocations' => [['opening_trade_item_id' => $item->id, 'allocated_amount' => $amount]],
                ];
                $event = $apply->execute($payment->fresh(), $this->owner, $data);
                $this->assertSame($event->id, $apply->execute($payment->fresh(), $this->owner, $data)->id);
            }
            $this->assertTrue($item->calculateOutstanding()->isZero());
            $bookColumn = $customerSide ? 'base_amount_applied_to_receivable' : 'base_amount_applied_to_payable';
            $book = BigDecimal::zero();
            foreach ($payment->allocations()->get() as $allocation) {
                $book = $book->plus($allocation->$bookColumn);
            }
            $this->assertTrue($book->isEqualTo($item->carrying_value_base));
            if ($customerSide) {
                app(CustomerApplicationHistory::class)->validate($event->fresh());
            }
            app($customerSide ? ReverseCustomerPaymentAction::class : ReverseVendorPaymentAction::class)
                ->execute($payment->fresh(), $this->owner, 'Reverse credit', '2026-02-01');
            $this->assertTrue($item->calculateOutstanding()->isEqualTo('100'));
            $this->assertTrue($item->calculateOutstanding('2026-01-31')->isZero());
        }
    }

    public function test_opening_allocations_survive_check_history_and_return_on_both_sides(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $incoming = $side === 'customer';
            $party = $incoming ? $this->customer : $this->testVendor;
            $item = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $party->id, openingDate: '2026-01-01',
                amount: '100', currencyCode: 'USD', exchangeRate: '3.5',
                actor: $this->owner, idempotencyKey: 'check-opening-'.$side,
            );
            $check = app($incoming ? ReceiveCheckAction::class : IssueCheckAction::class)
                ->execute($this->company, $this->owner, [
                    'party_id' => $party->id, 'money_account_id' => $this->usdBankAccount->id,
                    'check_number' => 'OPEN-'.$side, 'bank_name' => 'Test bank',
                    'date' => '2026-01-02', 'due_date' => '2026-01-03', 'currency_code' => 'USD',
                    'amount' => '100', 'exchange_rate' => '3.6', 'idempotency_key' => 'check-opening-payment-'.$side,
                    'allocations' => [['opening_trade_item_id' => $item->id, 'allocated_amount' => '100']],
                ]);
            $this->assertTrue($item->calculateOutstanding()->isZero());
            app(TransitionCheckAction::class)->execute($check, $this->owner, [
                'event_type' => $incoming ? 'return' : 'cancel', 'event_date' => '2026-01-04',
                'notes' => 'Opening check correction', 'idempotency_key' => 'check-opening-return-'.$side,
            ]);
            $this->assertTrue($item->calculateOutstanding()->isEqualTo('100'));
            $this->assertTrue($item->calculateOutstanding('2026-01-03')->isZero());
        }
    }

    public function test_zero_fx_credit_applications_dated_as_of_and_reversals_on_both_sides(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $customerSide = $side === 'customer';
            $party = $customerSide ? $this->customer : $this->testVendor;
            $post = app($customerSide ? PostCustomerPaymentAction::class : PostVendorPaymentAction::class);
            $apply = app($customerSide ? ApplyCustomerPaymentCreditAction::class : ApplyVendorPaymentCreditAction::class);
            $stmtQuery = app($customerSide ? CustomerStatementQuery::class : VendorStatementQuery::class);

            // 1. Post Opening Trade Item on 2026-01-01
            $item = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $party->id, openingDate: '2026-01-01',
                amount: '100.00', currencyCode: 'ILS', exchangeRate: '1.0000000000',
                actor: $this->owner, idempotencyKey: 'zerofx-open-'.$side,
            );

            // 2. Post Unapplied Payment on 2026-01-05 (100 ILS cash)
            $payment = $post->execute($this->company, $this->owner, [
                $customerSide ? 'customer_id' : 'vendor_id' => $party->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-01-05',
                'payment_method' => CustomerPayment::METHOD_CASH,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'amount' => '100.00',
                'idempotency_key' => 'zerofx-pmt-'.$side,
                'allocations' => [],
            ]);

            // 3. Apply Credit to Opening Item on 2026-01-10 with zero FX (same base currency, no FX journal batch)
            $event = $apply->execute($payment->fresh(), $this->owner, [
                'application_date' => '2026-01-10',
                'idempotency_key' => 'zerofx-apply-'.$side,
                'allocations' => [
                    [
                        'opening_trade_item_id' => $item->id,
                        'allocated_amount' => '100.00',
                    ],
                ],
            ]);

            // Zero FX means e.posting_batch_id IS NULL
            $this->assertNull($event->posting_batch_id);

            // As-of 2026-01-08: Application has NOT happened yet -> outstanding MUST be 100.00!
            $this->assertTrue(BigDecimal::of('100.00')->isEqualTo($item->calculateOutstanding('2026-01-08')));

            // As-of 2026-01-10: Application has taken effect -> outstanding MUST be 0!
            $this->assertTrue($item->calculateOutstanding('2026-01-10')->isZero());

            // Current outstanding (null cutoff) -> MUST be 0!
            $this->assertTrue($item->calculateOutstanding()->isZero());

            // Statement aging before and after application date
            $stmtBefore = $stmtQuery->execute($party, '2026-01-01', '2026-01-08')['currencies']['ILS'];
            $this->assertTrue(BigDecimal::of($stmtBefore['closing_balance'])->isEqualTo('0.00'));
            $this->assertTrue(BigDecimal::of($stmtBefore['aging']['unspecified'])->isEqualTo('100.00'));

            $stmtAfter = $stmtQuery->execute($party, '2026-01-01', '2026-01-10')['currencies']['ILS'];
            $this->assertTrue(BigDecimal::of($stmtAfter['aging']['unspecified'])->isZero());

            // 4. Reverse the payment on 2026-02-01
            app($customerSide ? ReverseCustomerPaymentAction::class : ReverseVendorPaymentAction::class)
                ->execute($payment->fresh(), $this->owner, 'Reversing zerofx payment', '2026-02-01');

            // As-of 2026-01-15 (before reversal): outstanding is still 0
            $this->assertTrue($item->calculateOutstanding('2026-01-15')->isZero());

            // As-of 2026-02-02 (after reversal) and current: outstanding is restored to 100
            $this->assertTrue(BigDecimal::of('100.00')->isEqualTo($item->calculateOutstanding('2026-02-02')));
            $this->assertTrue(BigDecimal::of('100.00')->isEqualTo($item->calculateOutstanding()));
        }
    }

    public function test_typed_id_42_collision_on_both_sides(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $customerSide = $side === 'customer';
            $party = $customerSide ? $this->customer : $this->testVendor;
            $post = app($customerSide ? PostCustomerPaymentAction::class : PostVendorPaymentAction::class);
            $account = $this->ilsCashAccount;

            if ($customerSide) {
                $doc = $this->createAndPostSalesInvoice([
                    'customer' => $party,
                    'lines' => [['product_id' => null, 'item_description' => 'Service', 'quantity' => '1', 'unit_price' => '200.00']],
                ]);
            } else {
                $doc = $this->createAndPostPurchase([
                    'vendor_id' => $party->id,
                    'lines' => [['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '200.00']],
                ]);
            }

            $opening = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $party->id, openingDate: '2026-01-01',
                amount: '300.00', currencyCode: 'ILS', exchangeRate: '1.0000000000',
                actor: $this->owner, idempotencyKey: 'typed-open-'.$side,
            );

            // Test composite key normalization with simulated collision ID 42
            $testIntent = [
                ['kind' => $customerSide ? 'invoice' : 'purchase', 'id' => 42, 'allocated_amount' => '50.00'],
                ['kind' => 'opening_trade_item', 'id' => 42, 'allocated_amount' => '70.00'],
            ];
            $normalized = PaymentAllocationIntent::normalize($testIntent, $customerSide ? 'sales_invoice_id' : 'purchase_id');
            $this->assertCount(2, $normalized);

            // Post payment allocating to BOTH real document and opening item simultaneously
            $payment = $post->execute($this->company, $this->owner, [
                $customerSide ? 'customer_id' : 'vendor_id' => $party->id,
                'money_account_id' => $account->id,
                'payment_date' => '2026-10-10',
                'payment_method' => CustomerPayment::METHOD_CASH,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'amount' => '150.00',
                'idempotency_key' => 'typed-alloc-pmt-'.$side,
                'allocations' => [
                    [
                        'kind' => $customerSide ? 'invoice' : 'purchase',
                        'id' => $doc->id,
                        'allocated_amount' => '50.00',
                    ],
                    [
                        'kind' => 'opening_trade_item',
                        'id' => $opening->id,
                        'allocated_amount' => '100.00',
                    ],
                ],
            ]);

            $allocs = $payment->allocations()->get();
            $this->assertCount(2, $allocs);
            $docAlloc = $allocs->firstWhere($customerSide ? 'sales_invoice_id' : 'purchase_id', $doc->id);
            $openingAlloc = $allocs->firstWhere('opening_trade_item_id', $opening->id);

            $this->assertNotNull($docAlloc);
            $this->assertNotNull($openingAlloc);
            $this->assertNull($docAlloc->opening_trade_item_id);
            $this->assertNull($openingAlloc->{$customerSide ? 'sales_invoice_id' : 'purchase_id'});
            $this->assertTrue(BigDecimal::of($docAlloc->allocated_amount)->isEqualTo('50.00'));
            $this->assertTrue(BigDecimal::of($openingAlloc->allocated_amount)->isEqualTo('100.00'));
        }
    }

    public function test_cross_currency_omitted_payment_amount_rejected_on_both_sides(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $customerSide = $side === 'customer';
            $party = $customerSide ? $this->customer : $this->testVendor;
            $post = app($customerSide ? PostCustomerPaymentAction::class : PostVendorPaymentAction::class);

            $opening = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $party->id, openingDate: '2026-01-01',
                amount: '100.00', currencyCode: 'USD', exchangeRate: '3.5000000000',
                actor: $this->owner, idempotencyKey: 'cross-reject-open-'.$side,
            );

            try {
                $post->execute($this->company, $this->owner, [
                    $customerSide ? 'customer_id' : 'vendor_id' => $party->id,
                    'money_account_id' => $this->ilsCashAccount->id,
                    'payment_date' => '2026-01-05',
                    'payment_method' => CustomerPayment::METHOD_CASH,
                    'currency_code' => 'ILS',
                    'exchange_rate' => '1.0000000000',
                    'amount' => '350.00',
                    'idempotency_key' => 'cross-reject-pmt-'.$side,
                    'allocations' => [
                        [
                            'kind' => 'opening_trade_item',
                            'opening_trade_item_id' => $opening->id,
                            'allocated_amount' => '100.00',
                        ],
                    ],
                ]);
                $this->fail('Cross-currency allocation with omitted payment amount must be rejected.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Cross-currency allocation requires an explicit payment-currency amount.', $e->getMessage());
            }

            $successPayment = $post->execute($this->company, $this->owner, [
                $customerSide ? 'customer_id' : 'vendor_id' => $party->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-01-05',
                'payment_method' => CustomerPayment::METHOD_CASH,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'amount' => '350.00',
                'idempotency_key' => 'cross-success-pmt-'.$side,
                'allocations' => [
                    [
                        'kind' => 'opening_trade_item',
                        'opening_trade_item_id' => $opening->id,
                        'allocated_amount' => '100.00',
                        'payment_currency_amount' => '350.00',
                    ],
                ],
            ]);
            $this->assertCount(1, $successPayment->allocations);
            $this->assertTrue(BigDecimal::of($successPayment->allocations->first()->payment_currency_amount)->isEqualTo('350.00'));
        }
    }

    public function test_same_currency_omitted_payment_amount_accepted_on_both_sides(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $customerSide = $side === 'customer';
            $party = $customerSide ? $this->customer : $this->testVendor;
            $post = app($customerSide ? PostCustomerPaymentAction::class : PostVendorPaymentAction::class);

            $opening = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $party->id, openingDate: '2026-01-01',
                amount: '150.00', currencyCode: 'ILS', exchangeRate: '1.0000000000',
                actor: $this->owner, idempotencyKey: 'same-curr-open-'.$side,
            );

            $payment = $post->execute($this->company, $this->owner, [
                $customerSide ? 'customer_id' : 'vendor_id' => $party->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-01-05',
                'payment_method' => CustomerPayment::METHOD_CASH,
                'currency_code' => 'ILS',
                'exchange_rate' => '1.0000000000',
                'amount' => '150.00',
                'idempotency_key' => 'same-curr-pmt-'.$side,
                'allocations' => [
                    [
                        'kind' => 'opening_trade_item',
                        'opening_trade_item_id' => $opening->id,
                        'allocated_amount' => '150.00',
                    ],
                ],
            ]);
            $this->assertCount(1, $payment->allocations);
            $alloc = $payment->allocations->first();
            $this->assertTrue(BigDecimal::of($alloc->allocated_amount)->isEqualTo('150.00'));
            $this->assertTrue(BigDecimal::of($alloc->payment_currency_amount)->isEqualTo('150.00'));
        }
    }
}
