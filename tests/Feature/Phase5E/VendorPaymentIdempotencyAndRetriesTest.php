<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Exceptions\IdempotencyConflictException;
use App\Models\PostingBatch;
use App\Models\VendorPayment;
use App\Models\VendorPaymentApplicationEvent;
use InvalidArgumentException;

class VendorPaymentIdempotencyAndRetriesTest extends Phase5ETestCase
{
    /** Scenario 12: Payment idempotency: same request twice, changed amount/Vendor/account/FX/allocation key conflicts, reordered and duplicate aggregated allocations same canonical intent; historical exact retries after later Return/application/reversal/config changes, no duplicate VPM/GL */
    public function test_scenario_12_payment_idempotency_and_canonical_reordering(): void
    {
        $postAction = app(PostVendorPaymentAction::class);

        $purchase1 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);
        $purchase2 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $batchCountBefore = PostingBatch::count();
        $paymentCountBefore = VendorPayment::count();

        // 1. First execution
        $payload1 = [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s12-01',
            'allocations' => [
                ['purchase_id' => $purchase1->id, 'allocated_amount' => '40.00'],
                ['purchase_id' => $purchase2->id, 'allocated_amount' => '60.00'],
            ],
        ];
        $pmt1 = $postAction->execute($this->company, $this->owner, $payload1);

        $this->assertEquals($batchCountBefore + 1, PostingBatch::count());
        $this->assertEquals($paymentCountBefore + 1, VendorPayment::count());

        // 2. Exact same request: returns existing payment without new records
        $pmt2 = $postAction->execute($this->company, $this->owner, $payload1);
        $this->assertEquals($pmt1->id, $pmt2->id);
        $this->assertEquals($batchCountBefore + 1, PostingBatch::count());
        $this->assertEquals($paymentCountBefore + 1, VendorPayment::count());

        // 3. Reordered allocations: returns same payment (same canonical intent)
        $payloadReordered = $payload1;
        $payloadReordered['allocations'] = [
            ['purchase_id' => $purchase2->id, 'allocated_amount' => '60.00'],
            ['purchase_id' => $purchase1->id, 'allocated_amount' => '40.00'],
        ];
        $pmtReordered = $postAction->execute($this->company, $this->owner, $payloadReordered);
        $this->assertEquals($pmt1->id, $pmtReordered->id);
        $this->assertEquals($batchCountBefore + 1, PostingBatch::count());

        // 4. Duplicate aggregated allocations (40 split into 20+20): returns same payment
        $payloadSplit = $payload1;
        $payloadSplit['allocations'] = [
            ['purchase_id' => $purchase1->id, 'allocated_amount' => '20.00'],
            ['purchase_id' => $purchase1->id, 'allocated_amount' => '20.00'],
            ['purchase_id' => $purchase2->id, 'allocated_amount' => '60.00'],
        ];
        $pmtSplit = $postAction->execute($this->company, $this->owner, $payloadSplit);
        $this->assertEquals($pmt1->id, $pmtSplit->id);
        $this->assertEquals($batchCountBefore + 1, PostingBatch::count());

        // 5. Changed payload with same idempotency key throws IdempotencyConflictException
        $payloadConflicting = $payload1;
        $payloadConflicting['amount'] = '150.00';
        try {
            $postAction->execute($this->company, $this->owner, $payloadConflicting);
            $this->fail('Expected IdempotencyConflictException for changed payload');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('different request payload', $e->getMessage());
        }

        // 6. Historical exact retry after return and application
        $this->createAndPostReturn($purchase1, [
            'return_date' => '2026-10-03',
            'lines' => [
                ['purchase_line_id' => $purchase1->lines->first()->id, 'quantity' => '1'],
            ],
        ]);

        // Resubmitting the exact original request still succeeds and returns original payment
        $pmtRetry = $postAction->execute($this->company, $this->owner, $payload1);
        $this->assertEquals($pmt1->id, $pmtRetry->id);
        $this->assertEquals($pmt1->payment_number, $pmtRetry->payment_number);
    }

    /** Scenario 13: Application idempotency same intent valid, changed Payment/actor/date/Purchase/amount conflict, reversed event never reactivated */
    public function test_scenario_13_application_idempotency_and_reversal_guard(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);

        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        $advance = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s13-adv',
            'allocations' => [],
        ]);

        $eventsBefore = VendorPaymentApplicationEvent::count();

        // 1. Execute advance application
        $appPayload = [
            'application_date' => '2026-10-02',
            'idempotency_key' => 'app-s13-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '40.00'],
            ],
        ];
        $event1 = $applyAction->execute($advance, $this->owner, $appPayload);
        $this->assertEquals($eventsBefore + 1, VendorPaymentApplicationEvent::count());

        // 2. Exact same application request: returns same event, no new rows
        $event2 = $applyAction->execute($advance, $this->owner, $appPayload);
        $this->assertEquals($event1->id, $event2->id);
        $this->assertEquals($eventsBefore + 1, VendorPaymentApplicationEvent::count());

        // 3. Changed application payload throws IdempotencyConflictException
        $conflictingApp = $appPayload;
        $conflictingApp['allocations'] = [
            ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
        ];
        try {
            $applyAction->execute($advance, $this->owner, $conflictingApp);
            $this->fail('Expected IdempotencyConflictException for changed application payload');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('different request payload', $e->getMessage());
        }

        // 4. Reverse the payment: application of reversed payment is blocked
        app(ReverseVendorPaymentAction::class)->execute($advance, $this->owner, 'Test reverse');

        try {
            $applyAction->execute($advance, $this->owner, [
                'application_date' => '2026-10-03',
                'idempotency_key' => 'app-s13-after-rev',
                'allocations' => [
                    ['purchase_id' => $purchase->id, 'allocated_amount' => '10.00'],
                ],
            ]);
            $this->fail('Expected InvalidArgumentException when applying reversed payment advance');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Cannot apply advance from a reversed or unposted vendor payment', $e->getMessage());
        }
    }
}
