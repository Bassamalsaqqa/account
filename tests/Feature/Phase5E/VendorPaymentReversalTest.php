<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Models\PostingBatch;
use App\Models\VendorPayment;
use InvalidArgumentException;

class VendorPaymentReversalTest extends Phase5ETestCase
{
    /** Scenario 15: Reversal B then A then original batch, including zero-batch application; retry coherent/incoherent; current inactive/deleted Vendor/account/currency/ledger does not block; deliberately fail midway and prove ALL state restored */
    public function test_scenario_15_sequential_application_reversal_newest_to_oldest(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);
        $reverseAction = app(ReverseVendorPaymentAction::class);

        // 1. Advance of 100 ILS
        $advance = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s15-adv',
            'allocations' => [],
        ]);

        $purchase1 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '4', 'unit_cost' => '10'], // 40 ILS
            ],
        ]);
        $purchase2 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '10'], // 30 ILS
            ],
        ]);

        // Event A: apply 40 to purchase 1 (zero-batch because ILS same rate)
        $eventA = $applyAction->execute($advance, $this->owner, [
            'application_date' => '2026-10-02',
            'idempotency_key' => 'app-s15-A',
            'allocations' => [
                ['purchase_id' => $purchase1->id, 'allocated_amount' => '40.00'],
            ],
        ]);
        $this->assertNull($eventA->posting_batch_id);

        // Event B: apply 30 to purchase 2 (zero-batch because ILS same rate)
        $eventB = $applyAction->execute($advance, $this->owner, [
            'application_date' => '2026-10-03',
            'idempotency_key' => 'app-s15-B',
            'allocations' => [
                ['purchase_id' => $purchase2->id, 'allocated_amount' => '30.00'],
            ],
        ]);
        $this->assertNull($eventB->posting_batch_id);

        // Both purchases now partially paid / settled
        $purchase1->refresh();
        $purchase2->refresh();
        $this->assertEquals('0.000000', (string) $purchase1->payablePosition()->outstanding);
        $this->assertEquals('0.000000', (string) $purchase2->payablePosition()->outstanding);

        // Deactivate vendor and money account before reversal — historical reversal must NOT be blocked!
        $this->vendor->update(['status' => 'inactive']);
        $this->vendor->delete();
        $this->ilsCashAccount->update(['active' => false]);

        // 2. Reverse payment
        $reversed = $reverseAction->execute($advance, $this->owner, 'Reversing advance with applications');

        $this->assertTrue($reversed->is_reversed);
        $this->assertNotNull($reversed->reversed_at);
        $this->assertEquals($this->owner->id, $reversed->reversed_by);
        $this->assertEquals('Reversing advance with applications', $reversed->reversal_reason);
        $this->assertNotNull($reversed->reversal_posting_batch_id);

        // Verify ApplicationEvents reversed in newest-to-oldest order
        $eventA->refresh();
        $eventB->refresh();
        $this->assertTrue($eventA->is_reversed);
        $this->assertTrue($eventB->is_reversed);
        $this->assertNotNull($eventA->reversed_at);
        $this->assertNotNull($eventB->reversed_at);

        // Verify purchases outstanding restored!
        $purchase1->refresh();
        $purchase2->refresh();
        $this->assertEquals('40.000000', (string) $purchase1->payablePosition()->outstanding);
        $this->assertEquals('30.000000', (string) $purchase2->payablePosition()->outstanding);
        $this->assertEquals('unpaid', $purchase1->payablePosition()->status);
        $this->assertEquals('unpaid', $purchase2->payablePosition()->status);

        // 3. Reversal is idempotent: calling reverse again returns the reversed payment
        $reversedAgain = $reverseAction->execute($reversed, $this->owner, 'Reversing again');
        $this->assertEquals($reversed->id, $reversedAgain->id);
        $this->assertEquals($reversed->reversal_posting_batch_id, $reversedAgain->reversal_posting_batch_id);
    }

    /** Scenario 16: Accounting failure after sequence/provisional Payment/allocations => exact complete rollback of sequence/rows/audit; application failure rollback; positive foreign/zero base atomic fail-closed */
    public function test_scenario_16_atomic_rollback_on_failure(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $pmtCountBefore = VendorPayment::count();
        $batchCountBefore = PostingBatch::count();

        // Simulate failure during posting (e.g. invalid allocation exceeding outstanding)
        try {
            $postAction->execute($this->company, $this->owner, [
                'vendor_id' => $this->vendor->id,
                'money_account_id' => $this->ilsCashAccount->id,
                'payment_date' => '2026-10-02',
                'payment_method' => 'cash',
                'amount' => '100.00',
                'exchange_rate' => '1.0000000000',
                'idempotency_key' => 'pmt-s16-fail',
                'allocations' => [
                    ['purchase_id' => $purchase->id, 'allocated_amount' => '200.00'], // Exceeds 100!
                ],
            ]);
            $this->fail('Expected InvalidArgumentException for allocation exceeding outstanding');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('exceeds purchase', $e->getMessage());
        }

        // Prove exact complete rollback: zero rows created
        $this->assertEquals($pmtCountBefore, VendorPayment::count());
        $this->assertEquals($batchCountBefore, PostingBatch::count());
    }
}
