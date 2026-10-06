<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Purchasing\Queries\VendorBalanceQuery;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use App\Models\PostingBatch;
use App\Models\Purchase;

class VendorPaymentDerivedPositionTest extends Phase5ETestCase
{
    /** Scenario 1: Derived Purchase raw/outstanding/credit/base/states with Returns, active initial/later payments, reversal */
    public function test_scenario_01_derived_payable_positions_across_lifecycle(): void
    {
        // 1. Posted Purchase of 100 ILS (10 units @ 10)
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $pos = $purchase->payablePosition();
        $this->assertEquals('100.000000', (string) $pos->rawPosition);
        $this->assertEquals('100.000000', (string) $pos->outstanding);
        $this->assertEquals('0.000000', (string) $pos->credit);
        $this->assertEquals('unpaid', $pos->status);
        $this->assertTrue($pos->hasOutstanding());

        // 2. Partial Payment of 40 ILS
        $postAction = app(PostVendorPaymentAction::class);
        $payment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '40.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '40.00'],
            ],
        ]);

        $purchase->refresh();
        $pos2 = $purchase->payablePosition();
        $this->assertEquals('60.000000', (string) $pos2->rawPosition);
        $this->assertEquals('60.000000', (string) $pos2->outstanding);
        $this->assertEquals('partially_paid', $pos2->status);

        // 3. Purchase Return of 2 units (20 ILS)
        $return = $this->createAndPostReturn($purchase, [
            'return_date' => '2026-10-03',
            'lines' => [
                ['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '2'],
            ],
        ]);

        $purchase->refresh();
        $pos3 = $purchase->payablePosition();
        $this->assertEquals('40.000000', (string) $pos3->rawPosition);
        $this->assertEquals('40.000000', (string) $pos3->outstanding);
        $this->assertEquals('partially_paid', $pos3->status);

        // 4. Advance Payment of 50 ILS with 40 allocated and 10 unallocated advance
        $payment2 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-04',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-02',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '40.00'],
            ],
        ]);

        $purchase->refresh();
        $pos4 = $purchase->payablePosition();
        $this->assertEquals('0.000000', (string) $pos4->rawPosition);
        $this->assertEquals('0.000000', (string) $pos4->outstanding);
        $this->assertEquals('0.000000', (string) $pos4->credit);
        $this->assertEquals('settled', $pos4->status);

        // 5. Reversal of payment2 restores outstanding
        app(ReverseVendorPaymentAction::class)->execute($payment2, $this->owner, 'Reversal test');
        $purchase->refresh();
        $pos5 = $purchase->payablePosition();
        $this->assertEquals('40.000000', (string) $pos5->outstanding);
        $this->assertEquals('partially_paid', $pos5->status);

        // Prove no columns on purchase were modified
        $this->assertEquals('posted', $purchase->status);
        $this->assertFalse(isset($purchase->paid_amount));
        $this->assertFalse(isset($purchase->payment_status));
    }

    /** Scenario 19: Purchase 100, Payment 100, Return 30 => outstanding 0, credit 30, Vendor balance -30, gross aging 0, net credit 30 */
    public function test_scenario_19_purchase_payment_return_credit_state(): void
    {
        // 1. Purchase 100
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // 2. Full Payment 100
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s19-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        $purchase->refresh();
        $pos1 = $purchase->payablePosition();
        $this->assertEquals('settled', $pos1->status);

        // 3. Later Return of 3 units (30 ILS)
        $return = $this->createAndPostReturn($purchase, [
            'return_date' => '2026-10-03',
            'lines' => [
                ['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '3'],
            ],
        ]);

        $purchase->refresh();
        $pos2 = $purchase->payablePosition();
        $this->assertEquals('-30.000000', (string) $pos2->rawPosition);
        $this->assertEquals('0.000000', (string) $pos2->outstanding);
        $this->assertEquals('30.000000', (string) $pos2->credit);
        $this->assertEquals('credit', $pos2->status);

        // Check vendor balance query
        $balances = app(VendorBalanceQuery::class)->execute([$this->vendor->id]);
        $ilsBalance = $balances[$this->vendor->id]['ILS'];
        $this->assertEquals('100.000000', $ilsBalance['purchased']);
        $this->assertEquals('30.000000', $ilsBalance['returned']);
        $this->assertEquals('100.000000', $ilsBalance['paid']);
        // Purchases (100) - Returns (30) - Payments (100) = -30
        $this->assertEquals('-30.000000', $ilsBalance['balance']);

        // Check vendor statement and aging
        $statement = app(VendorStatementQuery::class)->execute($this->vendor);
        $ilsStmt = $statement['currencies']['ILS'];
        $this->assertEquals('-30.00', $ilsStmt['closing_balance']);
        $aging = $ilsStmt['aging'];
        $this->assertEquals('0.00', $aging['gross_open_purchases']);
        $this->assertEquals('-30.00', $aging['signed_vendor_balance']);
        $this->assertEquals('30.00', $aging['unapplied_credit_position']);
        $this->assertEquals('0.00', $aging['net_payable']);
        $this->assertEquals('30.00', $aging['net_vendor_credit']);
        $this->assertEquals('0.00', $aging['current']);
        $this->assertEquals('0.00', $aging['days_1_30']);
    }

    /** Scenario 20: Credit 30 plus unpaid Purchase 50 => A outstanding 0, B 50, gross 50, unapplied 30, net payable 20. Advance 40 + Purchase 100 before application: gross 100, unapplied 40, net 60; after explicit application: outstanding 60, unallocated 0, no cash */
    public function test_scenario_20_coexisting_credits_and_invoices_with_explicit_advance_application(): void
    {
        // 1. Purchase A: 100 ILS, Paid 100, Returned 30 => Credit 30
        $purchaseA = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);
        app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s20-01',
            'allocations' => [
                ['purchase_id' => $purchaseA->id, 'allocated_amount' => '100.00'],
            ],
        ]);
        $this->createAndPostReturn($purchaseA, [
            'return_date' => '2026-10-02',
            'lines' => [
                ['purchase_line_id' => $purchaseA->lines->first()->id, 'quantity' => '3'],
            ],
        ]);

        // 2. Purchase B: 50 ILS unpaid
        $purchaseB = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'],
            ],
        ]);

        $purchaseA->refresh();
        $purchaseB->refresh();
        $this->assertEquals('0.000000', (string) $purchaseA->payablePosition()->outstanding);
        $this->assertEquals('30.000000', (string) $purchaseA->payablePosition()->credit);
        $this->assertEquals('50.000000', (string) $purchaseB->payablePosition()->outstanding);

        $statement = app(VendorStatementQuery::class)->execute($this->vendor);
        $aging = $statement['currencies']['ILS']['aging'];
        $this->assertEquals('50.00', $aging['gross_open_purchases']);
        $this->assertEquals('20.00', $aging['signed_vendor_balance']);
        $this->assertEquals('30.00', $aging['unapplied_credit_position']);
        $this->assertEquals('20.00', $aging['net_payable']);
        $this->assertEquals('0.00', $aging['net_vendor_credit']);

        // 3. Now test Advance 40 + Purchase C 100 before application:
        // Advance of 40 ILS unallocated
        $advancePayment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '40.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s20-02',
            'allocations' => [],
        ]);

        // Purchase C: 100 ILS
        $purchaseC = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $statement2 = app(VendorStatementQuery::class)->execute($this->vendor);
        $aging2 = $statement2['currencies']['ILS']['aging'];
        // Gross open purchases: B(50) + C(100) = 150
        $this->assertEquals('150.00', $aging2['gross_open_purchases']);
        // Signed balance: 150 - 30 (return) - 40 (advance) = 80
        $this->assertEquals('80.00', $aging2['signed_vendor_balance']);
        $this->assertEquals('70.00', $aging2['unapplied_credit_position']); // 30 return + 40 advance
        $this->assertEquals('80.00', $aging2['net_payable']);

        // 4. After explicit application of 40 to Purchase C:
        $initialBatchCount = PostingBatch::count();
        app(ApplyVendorPaymentCreditAction::class)->execute($advancePayment, $this->owner, [
            'application_date' => '2026-10-04',
            'idempotency_key' => 'app-c-40',
            'allocations' => [
                ['purchase_id' => $purchaseC->id, 'allocated_amount' => '40.00'],
            ],
        ]);

        $purchaseC->refresh();
        $posC = $purchaseC->payablePosition();
        $this->assertEquals('60.000000', (string) $posC->outstanding);
        $this->assertEquals('partially_paid', $posC->status);

        $advancePayment->refresh();
        $this->assertEquals('0.000000', (string) $advancePayment->unallocated_amount);
        $this->assertEquals('40.000000', (string) $advancePayment->allocated_amount);

        // ILS same rate (1.0) means delta = 0, so NO new posting batch was created (null posting_batch_id)
        $this->assertEquals($initialBatchCount, PostingBatch::count());
    }
}
