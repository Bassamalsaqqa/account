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
use App\Domain\Accounting\Exceptions\ActiveSettlementBlockedException;
use App\Domain\Accounting\Exceptions\ImmutableRecordException;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Domain\Sales\Queries\CustomerStatementQuery;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\CustomerPaymentAllocation;
use App\Models\OpeningTradeItem;
use App\Models\PostingBatch;
use App\Models\Purchase;
use App\Models\SalesInvoice;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\Accounting\AccountingReconciliationService;
use App\Services\Accounting\Phase7ReconciliationService;
use App\Services\Inventory\InventoryReconciliationService;
use App\Services\Money\CustomerApplicationHistory;
use App\Services\Money\CustomerPaymentHistory;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentHistoryCommands;
use App\Services\Sales\SalesHistoryAudit;
use App\Services\Sales\SalesReconciliationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Phase8\TradeTestCase;

final class OpeningTradeSettlementTest extends TradeTestCase
{
    private Customer $customer;

    private Vendor $testVendor;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->activateUser($this->owner);
        $this->customer = $this->defaultCustomer;
        $this->testVendor = $this->vendor;
    }

    public function test_active_payments_reject_a_reversed_opening_target_in_history(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            $partyId = $side === 'customer' ? $this->customer->id : $this->testVendor->id;
            $item = app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side, partyId: $partyId,
                openingDate: '2026-01-01', amount: '100.00', currencyCode: 'ILS',
                exchangeRate: '1', actor: $this->owner, idempotencyKey: 'history-guard-'.$side,
            );
            $data = [
                $side === 'customer' ? 'customer_id' : 'vendor_id' => $partyId,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-01-10', 'payment_method' => 'cash',
                'currency_code' => 'ILS', 'exchange_rate' => '1', 'amount' => '100.00',
                'idempotency_key' => 'history-payment-'.$side,
                'allocations' => [['kind' => 'opening_trade_item', 'opening_trade_item_id' => $item->id, 'allocated_amount' => '100.00']],
            ];
            $payment = $side === 'customer'
                ? app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, $data)
                : app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, $data);
            $this->assertFalse((bool) $payment->is_reversed);

            // Deliberately corrupt the fixture to prove history validation fails closed.
            DB::table('opening_trade_items')->where('id', $item->id)->update(['status' => OpeningTradeItem::STATUS_REVERSED]);
            try {
                if ($payment instanceof CustomerPayment) {
                    app(CustomerPaymentHistory::class)->validate($payment->fresh());
                } else {
                    app(VendorPaymentHistoryCommands::class)->payment($payment->fresh());
                }
                $this->fail('An active payment must reject a reversed opening target.');
            } catch (InvalidArgumentException|ImmutableRecordException $exception) {
                $this->assertStringContainsString($side === 'customer' ? 'provenance is incoherent' : 'must be posted', $exception->getMessage());
            } finally {
                DB::table('opening_trade_items')->where('id', $item->id)->update(['status' => OpeningTradeItem::STATUS_POSTED]);
            }
        }
    }

    public function test_new_opening_fixtures_pass_all_six_reconciliations(): void
    {
        foreach (['customer', 'vendor'] as $side) {
            app(PostOpeningTradeItemAction::class)->execute(
                company: $this->company, side: $side,
                partyId: $side === 'customer' ? $this->customer->id : $this->testVendor->id,
                openingDate: '2026-01-01', amount: '123.45', currencyCode: 'ILS',
                actor: $this->owner, idempotencyKey: 'six-reconcile-'.$side,
            );
        }
        $this->assertAllSixReconciliations();
    }

    private function assertAllSixReconciliations(): void
    {
        foreach ([
            AccountingReconciliationService::class,
            SalesReconciliationService::class,
            PayablesReconciliationService::class,
            MoneyReconciliationService::class,
            Phase7ReconciliationService::class,
        ] as $service) {
            $report = app($service)->reconcile($this->company);
            $this->assertTrue($report->isHealthy, $service.': '.json_encode($report));
        }
        $report = app(InventoryReconciliationService::class)->auditCompany($this->company);
        $this->assertTrue($report->isHealthy, json_encode($report));
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

        $this->get(route('payments.show', $payment->public_id))->assertOk()->assertSee($item->public_id);
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

        $this->get(route('vendor-payments.show', $payment->public_id))->assertOk()->assertSee($item->public_id);
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
            try {
                app(ReverseOpeningTradeItemAction::class)->execute($item->fresh(), $this->owner, 'Blocked while settled', '2026-02-02');
                $this->fail('Active credit application must block reversal');
            } catch (ActiveSettlementBlockedException) {
                $this->assertFalse($item->fresh()->isReversed());
            }
            app($customerSide ? ReverseCustomerPaymentAction::class : ReverseVendorPaymentAction::class)
                ->execute($payment->fresh(), $this->owner, 'Reverse credit', '2026-02-01');
            $this->assertTrue($item->calculateOutstanding()->isEqualTo('100'));
            $this->assertTrue($item->calculateOutstanding('2026-01-31')->isZero());
            $reversed = app(ReverseOpeningTradeItemAction::class)->execute($item->fresh(), $this->owner, 'Correct opening after payment reversal', '2026-02-02');
            $this->assertTrue($reversed->isReversed());
            $this->assertTrue($reversed->calculateOutstanding()->isZero());
            $this->assertTrue($reversed->calculateOutstanding('2026-02-01')->isEqualTo('100'));
            $this->assertAllSixReconciliations();

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

            // 4. Reversal of opening item is blocked while active credit application settlement exists
            try {
                app(ReverseOpeningTradeItemAction::class)->execute($item->fresh(), $this->owner, 'Blocked while settled', '2026-02-02');
                $this->fail('Active credit application must block opening item reversal');
            } catch (ActiveSettlementBlockedException) {
                $this->assertFalse($item->fresh()->isReversed());
            }

            // 5. Reverse the canonical PARENT payment on 2026-02-01
            app($customerSide ? ReverseCustomerPaymentAction::class : ReverseVendorPaymentAction::class)
                ->execute($payment->fresh(), $this->owner, 'Reversing zerofx payment', '2026-02-01');

            // As-of 2026-01-15 (before reversal): outstanding is still 0
            $this->assertTrue($item->calculateOutstanding('2026-01-15')->isZero());

            // As-of 2026-02-02 (after reversal) and current: outstanding is restored to 100
            $this->assertTrue(BigDecimal::of('100.00')->isEqualTo($item->calculateOutstanding('2026-02-02')));
            $this->assertTrue(BigDecimal::of('100.00')->isEqualTo($item->calculateOutstanding()));

            // 6. With parent payment reversed, reversing opening trade item is now permitted
            $reversed = app(ReverseOpeningTradeItemAction::class)->execute($item->fresh(), $this->owner, 'Correct opening after zerofx payment reversal', '2026-02-02');
            $this->assertTrue($reversed->isReversed());
            $this->assertTrue($reversed->calculateOutstanding()->isZero());
            $this->assertTrue($reversed->calculateOutstanding('2026-02-01')->isEqualTo('100'));
            $this->assertAllSixReconciliations();
        }
    }

    public static function collisionSides(): array
    {
        return [['customer'], ['vendor']];
    }

    #[DataProvider('collisionSides')]
    public function test_typed_id_42_collision_on_both_sides(string $side): void
    {
        $dispatcher = OpeningTradeItem::getEventDispatcher();
        OpeningTradeItem::setEventDispatcher(clone $dispatcher);
        foreach ([OpeningTradeItem::class, SalesInvoice::class, Purchase::class] as $model) {
            $model::creating(static function ($record): void {
                $record->id = 42;
            });
        }
        try {
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

            $this->assertSame(42, $doc->id);
            $this->assertSame(42, $opening->id);

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
        } finally {
            OpeningTradeItem::setEventDispatcher($dispatcher);
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

    public function test_later_credit_application_enforces_target_lifecycle_guard_rejecting_corrupt_reversed_target_until_parent_reversed(): void
    {
        // 1. Customer side: unallocated customer receipt, later credit allocation
        $custOpening = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company, side: 'customer', partyId: $this->customer->id, openingDate: '2026-01-01',
            amount: '100.00', currencyCode: 'ILS', exchangeRate: '1.0000000000',
            actor: $this->owner, idempotencyKey: 'cust-later-credit-opening',
        );

        $custPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-05',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '100.00',
            'idempotency_key' => 'cust-later-credit-pmt',
            'allocations' => [],
        ]);

        $custEvent = app(ApplyCustomerPaymentCreditAction::class)->execute($custPayment->fresh(), $this->owner, [
            'application_date' => '2026-01-10',
            'idempotency_key' => 'cust-later-credit-event',
            'allocations' => [
                ['opening_trade_item_id' => $custOpening->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // Clean validation while active
        app(CustomerApplicationHistory::class)->validate($custEvent->fresh());

        // Deliberately corrupt target opening item to reversed while parent payment is active
        DB::table('opening_trade_items')->where('id', $custOpening->id)->update(['status' => 'reversed']);

        try {
            app(CustomerApplicationHistory::class)->validate($custEvent->fresh());
            $this->fail('CustomerApplicationHistory must reject reversed opening trade item when parent payment is active');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('provenance mismatch', $e->getMessage());
        }

        // Restore posted status to reverse parent payment canonically
        DB::table('opening_trade_items')->where('id', $custOpening->id)->update(['status' => 'posted']);
        app(ReverseCustomerPaymentAction::class)->execute($custPayment->fresh(), $this->owner, 'Reverse parent receipt', '2026-01-15');

        // Now reverse opening item canonically
        app(ReverseOpeningTradeItemAction::class)->execute($custOpening->fresh(), $this->owner, 'Reverse opening', '2026-01-20');

        // Now reversed opening item is permitted because parent payment is reversed
        app(CustomerApplicationHistory::class)->validate($custEvent->fresh());

        // 2. Vendor side: unallocated advance, later credit allocation
        $vendOpening = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company, side: 'vendor', partyId: $this->testVendor->id, openingDate: '2026-01-01',
            amount: '100.00', currencyCode: 'ILS', exchangeRate: '1.0000000000',
            actor: $this->owner, idempotencyKey: 'vend-later-credit-opening',
        );

        $vendPayment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->testVendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-05',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '100.00',
            'idempotency_key' => 'vend-later-credit-pmt',
            'allocations' => [],
        ]);

        $vendEvent = app(ApplyVendorPaymentCreditAction::class)->execute($vendPayment->fresh(), $this->owner, [
            'application_date' => '2026-01-10',
            'idempotency_key' => 'vend-later-credit-event',
            'allocations' => [
                ['opening_trade_item_id' => $vendOpening->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        // Clean validation while active
        app(VendorPaymentHistoryCommands::class)->application($vendEvent->fresh());

        // Deliberately corrupt vendor target opening item to reversed while parent payment is active
        DB::table('opening_trade_items')->where('id', $vendOpening->id)->update(['status' => 'reversed']);

        try {
            app(VendorPaymentHistoryCommands::class)->application($vendEvent->fresh());
            $this->fail('VendorPaymentHistoryCommands must reject reversed opening trade item when parent payment is active');
        } catch (InvalidArgumentException|ImmutableRecordException $e) {
            $this->assertStringContainsString('Opening trade item must be posted or belong to a reversed payment', $e->getMessage());
        }

        // Restore posted status to reverse parent vendor payment canonically
        DB::table('opening_trade_items')->where('id', $vendOpening->id)->update(['status' => 'posted']);
        app(ReverseVendorPaymentAction::class)->execute($vendPayment->fresh(), $this->owner, 'Reverse parent vendor advance', '2026-01-15');

        // Now reverse vendor opening item canonically
        app(ReverseOpeningTradeItemAction::class)->execute($vendOpening->fresh(), $this->owner, 'Reverse opening', '2026-01-20');

        // Now reversed opening item is permitted because parent vendor payment is reversed
        app(VendorPaymentHistoryCommands::class)->application($vendEvent->fresh());

        // Assert all six canonical reconciliation services pass
        $this->assertAllSixReconciliations();
    }

    public function test_opening_reversal_date_must_respect_effective_settlement_and_parent_payment_reversal_dates_on_both_sides(): void
    {
        $this->activateUser($this->owner);

        // 1. Customer Side
        $custOpening = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '500.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'cust-open-rev-date-'.Str::ulid(),
        );

        $custPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-15',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '200.00',
            'idempotency_key' => 'pmt-cust-date-test-'.Str::ulid(),
            'allocations' => [],
        ]);

        app(ApplyCustomerPaymentCreditAction::class)->execute($custPayment->fresh(), $this->owner, [
            'application_date' => '2026-01-15',
            'idempotency_key' => 'pmt-cust-apply-date-'.Str::ulid(),
            'allocations' => [
                [
                    'opening_trade_item_id' => $custOpening->id,
                    'allocated_amount' => '200.00',
                ],
            ],
        ]);

        // Reverse payment with effective posting date 2026-02-10
        app(ReverseCustomerPaymentAction::class)->execute($custPayment->fresh(), $this->owner, 'Reverse pmt', '2026-02-10');

        // Attempt opening reversal on 2026-02-05: must be rejected because payment reversal took effect 2026-02-10
        try {
            app(ReverseOpeningTradeItemAction::class)->execute($custOpening->fresh(), $this->owner, 'Early reversal', '2026-02-05');
            $this->fail('Opening reversal on 2026-02-05 must be rejected when payment reversal took effect 2026-02-10');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot precede prerequisite settlement reversal date [2026-02-10]', $e->getMessage());
        }

        // Legitimate correction on 2026-02-10 succeeds
        $reversedCust = app(ReverseOpeningTradeItemAction::class)->execute($custOpening->fresh(), $this->owner, 'Valid reversal', '2026-02-10');
        $this->assertTrue($reversedCust->isReversed());

        // 2. Vendor Side
        $vendOpening = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_VENDOR,
            partyId: $this->testVendor->id,
            openingDate: '2026-01-01',
            amount: '600.00',
            currencyCode: 'ILS',
            actor: $this->owner,
            idempotencyKey: 'vend-open-rev-date-'.Str::ulid(),
        );

        $vendPayment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->testVendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-15',
            'payment_method' => VendorPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '300.00',
            'idempotency_key' => 'pmt-vend-date-test-'.Str::ulid(),
            'allocations' => [],
        ]);

        app(ApplyVendorPaymentCreditAction::class)->execute($vendPayment->fresh(), $this->owner, [
            'application_date' => '2026-01-15',
            'idempotency_key' => 'pmt-vend-apply-date-'.Str::ulid(),
            'allocations' => [
                [
                    'opening_trade_item_id' => $vendOpening->id,
                    'allocated_amount' => '300.00',
                ],
            ],
        ]);

        // Reverse vendor payment with effective posting date 2026-02-10
        app(ReverseVendorPaymentAction::class)->execute($vendPayment->fresh(), $this->owner, 'Reverse vend pmt', '2026-02-10');

        // Attempt opening reversal on 2026-02-05: rejected
        try {
            app(ReverseOpeningTradeItemAction::class)->execute($vendOpening->fresh(), $this->owner, 'Early reversal', '2026-02-05');
            $this->fail('Opening reversal on 2026-02-05 must be rejected when vendor payment reversal took effect 2026-02-10');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot precede prerequisite settlement reversal date [2026-02-10]', $e->getMessage());
        }

        // Legitimate correction on 2026-02-11 succeeds
        $reversedVend = app(ReverseOpeningTradeItemAction::class)->execute($vendOpening->fresh(), $this->owner, 'Valid reversal', '2026-02-11');
        $this->assertTrue($reversedVend->isReversed());

        $this->assertAllSixReconciliations();
    }

    public function test_real_initial_customer_payment_allocation_to_opening_with_fx_collision_reversal_and_six_reconciliations(): void
    {
        $this->activateUser($this->owner);

        // 1. Create a posted sales invoice for the customer to establish invoice flow and potential ID collision
        $invoice = $this->createAndPostSalesInvoice([
            'customer' => $this->customer,
            'issue_date' => '2026-01-01',
            'due_date' => '2026-01-15',
            'lines' => [
                [
                    'product_id' => null,
                    'item_description' => 'Service for Collision',
                    'quantity' => '1.000000',
                    'unit_price' => '100.000000',
                ],
            ],
        ]);
        $this->assertTrue($invoice->isPosted());

        // 2. Create customer opening trade items: one foreign currency (USD) and one base currency (ILS)
        $usdOpening = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '100.00',
            currencyCode: 'USD',
            exchangeRate: '3.5000000000',
            actor: $this->owner,
            idempotencyKey: 'cust-usd-open-init-'.Str::ulid(),
        );

        $ilsOpening = app(PostOpeningTradeItemAction::class)->execute(
            company: $this->company,
            side: OpeningTradeItem::SIDE_CUSTOMER,
            partyId: $this->customer->id,
            openingDate: '2026-01-01',
            amount: '200.00',
            currencyCode: 'ILS',
            exchangeRate: '1.0000000000',
            actor: $this->owner,
            idempotencyKey: 'cust-ils-open-init-'.Str::ulid(),
        );

        // 3. Payment 1: Initial allocation combining partial FX (USD 50.00 = 175.00 ILS) and same currency (ILS 200.00)
        $initPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-10',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '375.00',
            'idempotency_key' => 'pmt-init-alloc-multi-'.Str::ulid(),
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $usdOpening->id,
                    'allocated_amount' => '50.00',
                    'payment_currency_amount' => '175.00',
                ],
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $ilsOpening->id,
                    'allocated_amount' => '200.00',
                    'payment_currency_amount' => '200.00',
                ],
            ],
        ]);
        $this->assertFalse((bool) $initPayment->is_reversed);
        $this->assertCount(2, $initPayment->allocations);

        // 4. Payment 2: Initial allocation targeting invoice (verifying distinct ID handling and provenance isolation)
        $invPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-11',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '100.00',
            'idempotency_key' => 'pmt-inv-target-'.Str::ulid(),
            'allocations' => [
                [
                    'sales_invoice_id' => $invoice->id,
                    'allocated_amount' => '100.00',
                ],
            ],
        ]);
        $this->assertFalse((bool) $invPayment->is_reversed);

        // 5. Payment 3: Final allocation consuming remaining USD balance (USD 50.00 = 175.00 ILS)
        $finalPayment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $this->customer->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-01-12',
            'payment_method' => CustomerPayment::METHOD_CASH,
            'currency_code' => 'ILS',
            'exchange_rate' => '1.0000000000',
            'amount' => '175.00',
            'idempotency_key' => 'pmt-final-usd-alloc-'.Str::ulid(),
            'allocations' => [
                [
                    'kind' => 'opening_trade_item',
                    'opening_trade_item_id' => $usdOpening->id,
                    'allocated_amount' => '50.00',
                    'payment_currency_amount' => '175.00',
                ],
            ],
        ]);
        $this->assertFalse((bool) $finalPayment->is_reversed);

        // Both opening items are now fully allocated
        $this->assertTrue(BigDecimal::zero()->isEqualTo($usdOpening->calculateOutstanding()));
        $this->assertTrue(BigDecimal::zero()->isEqualTo($ilsOpening->calculateOutstanding()));

        // 6. Reverse parent payments lawfully
        app(ReverseCustomerPaymentAction::class)->execute($finalPayment->fresh(), $this->owner, 'Reverse final payment', '2026-02-01');
        app(ReverseCustomerPaymentAction::class)->execute($initPayment->fresh(), $this->owner, 'Reverse initial payment', '2026-02-05');

        $this->assertTrue($finalPayment->fresh()->is_reversed);
        $this->assertTrue($initPayment->fresh()->is_reversed);

        // 7. Chronology enforcement: Attempt opening reversal before prerequisite date 2026-02-05 must fail
        try {
            app(ReverseOpeningTradeItemAction::class)->execute($usdOpening->fresh(), $this->owner, 'Early reversal', '2026-02-03');
            $this->fail('Opening reversal on 2026-02-03 must be rejected when payment reversal took effect 2026-02-05');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot precede prerequisite settlement reversal date [2026-02-05]', $e->getMessage());
        }

        // 8. Lawful opening reversals on or after prerequisite date 2026-02-05 succeed
        $revUsd = app(ReverseOpeningTradeItemAction::class)->execute($usdOpening->fresh(), $this->owner, 'Valid USD reversal', '2026-02-05');
        $revIls = app(ReverseOpeningTradeItemAction::class)->execute($ilsOpening->fresh(), $this->owner, 'Valid ILS reversal', '2026-02-06');

        $this->assertTrue($revUsd->isReversed());
        $this->assertTrue($revIls->isReversed());

        // Invoice flows and its payment remain active and intact (immutable provenance)
        $this->assertTrue($invoice->fresh()->isPosted());
        $this->assertFalse((bool) $invPayment->fresh()->is_reversed);

        // History validation passes on reversed payments
        app(CustomerPaymentHistory::class)->validate($initPayment->fresh());
        app(CustomerPaymentHistory::class)->validate($finalPayment->fresh());
        app(CustomerPaymentHistory::class)->validate($invPayment->fresh());

        // SalesHistoryAudit must have zero errors
        $auditErrors = app(SalesHistoryAudit::class)->audit((int) $this->company->id);
        $this->assertSame([], $auditErrors, 'SalesHistoryAudit failed with: '.json_encode($auditErrors));

        // 9. Assert all six canonical reconciliation services pass
        $this->assertAllSixReconciliations();
    }
}
