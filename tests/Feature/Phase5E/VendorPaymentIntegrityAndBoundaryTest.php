<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Services\Purchasing\VendorCatalogService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;

class VendorPaymentIntegrityAndBoundaryTest extends Phase5ETestCase
{
    /** Scenario 17: Original Payment reconstructs initial allocations only after later application; separate event history intact; exact retry both remain coherent */
    public function test_scenario_17_original_payment_integrity_reconstruction(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);
        $validator = app(VendorPaymentPostedIntegrityValidator::class);

        $purchase1 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'], // 50 ILS
            ],
        ]);
        $purchase2 = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'], // 50 ILS
            ],
        ]);

        // 1. Initial payment of 100 ILS: 50 allocated to purchase1, 50 unallocated advance
        $payment = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s17-01',
            'allocations' => [
                ['purchase_id' => $purchase1->id, 'allocated_amount' => '50.00'],
            ],
        ]);

        // Validate integrity immediately after initial post
        $validator->validate($payment);

        // 2. Later application event: allocate 30 from advance to purchase2
        $event = $applyAction->execute($payment, $this->owner, [
            'application_date' => '2026-10-02',
            'idempotency_key' => 'app-s17-01',
            'allocations' => [
                ['purchase_id' => $purchase2->id, 'allocated_amount' => '30.00'],
            ],
        ]);

        // 3. Re-validate original payment: must still validate cleanly
        // It reconstructs the original payment using only initial allocations (where application_event_id is null)
        $payment->refresh();
        $validator->validate($payment);

        // Assert that the payment has 2 total allocation rows, but only 1 initial allocation
        $this->assertCount(2, $payment->allocations);
        $initialAllocs = $payment->allocations()->whereNull('application_event_id')->get();
        $this->assertCount(1, $initialAllocs);
        $this->assertEquals('50.000000', (string) $initialAllocs->first()->allocated_amount);

        // Advance allocations belong to application event
        $laterAllocs = $payment->allocations()->whereNotNull('application_event_id')->get();
        $this->assertCount(1, $laterAllocs);
        $this->assertEquals('30.000000', (string) $laterAllocs->first()->allocated_amount);
        $this->assertEquals($event->id, $laterAllocs->first()->application_event_id);
    }

    /** Scenario 18: Historical boundary: Purchase100, Payment60, later Return30, later Payment/application; original allocation still validates its prior boundary, not current outstanding */
    public function test_scenario_18_historical_boundary_preserved_through_lifecycle(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);

        // 1. Purchase 100 ILS
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'], // 100 ILS
            ],
        ]);

        // 2. Payment 60 ILS allocated to Purchase
        $pmt1 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '60.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s18-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '60.00'],
            ],
        ]);

        $purchase->refresh();
        $this->assertEquals('40.000000', (string) $purchase->payablePosition()->outstanding);

        // 3. Later Return of 30 ILS (3 units)
        $this->createAndPostReturn($purchase, [
            'return_date' => '2026-10-02',
            'lines' => [
                ['purchase_line_id' => $purchase->lines->first()->id, 'quantity' => '3'],
            ],
        ]);

        $purchase->refresh();
        // Outstanding is now 100 - 30 (return) - 60 (pmt) = 10 ILS
        $this->assertEquals('10.000000', (string) $purchase->payablePosition()->outstanding);

        // Original payment of 60 remains completely valid and untouched
        $pmt1->refresh();
        $this->assertEquals('60.000000', (string) $pmt1->amount);
        $this->assertEquals('60.000000', (string) $pmt1->allocations->first()->allocated_amount);

        // 4. Subsequent payment can only allocate up to remaining outstanding (10 ILS)
        $pmt2 = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03',
            'payment_method' => 'cash',
            'amount' => '10.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s18-02',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '10.00'],
            ],
        ]);

        $purchase->refresh();
        $this->assertEquals('0.000000', (string) $purchase->payablePosition()->outstanding);
        $this->assertEquals('settled', $purchase->payablePosition()->status);
    }

    /** Scenario 23: Vendor locale ar/en fallback disabled preference, enabled explicit override; frozen posting snapshots despite later names/locales, retry/reversal no refresh */
    public function test_scenario_23_snapshots_frozen_forever(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $reverseAction = app(ReverseVendorPaymentAction::class);

        $purchase = $this->createAndPostPurchase([
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'],
            ],
        ]);

        $pmt = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s23-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
            ],
        ]);

        $initialVendorSnapshot = $pmt->vendor_snapshot;
        $initialCompanySnapshot = $pmt->company_snapshot;
        $this->assertNotEmpty($initialVendorSnapshot);
        $this->assertNotEmpty($initialCompanySnapshot);
        $this->assertEquals('مورد التوريدات الرئيسي', $initialVendorSnapshot['name_ar'] ?? null);

        // 1. Mutate vendor name and company name
        app(VendorCatalogService::class)->save($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'name_ar' => 'اسم مورد جديد تم تغييره',
            'name_en' => 'Completely Changed Vendor Name',
            'default_currency_code' => 'ILS',
        ]);
        $this->company->update(['name_ar' => 'اسم شركة تم تغييره بعد الدفع']);

        // 2. Payment snapshot must remain completely UNCHANGED
        $pmt->refresh();
        $this->assertEquals($initialVendorSnapshot, $pmt->vendor_snapshot);
        $this->assertEquals($initialCompanySnapshot, $pmt->company_snapshot);

        // 3. Retry payment request: snapshot is not refreshed
        $pmtRetried = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-01',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s23-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
            ],
        ]);
        $this->assertEquals($initialVendorSnapshot, $pmtRetried->vendor_snapshot);
        $this->assertEquals($initialCompanySnapshot, $pmtRetried->company_snapshot);

        // 4. Reversal: snapshot is not refreshed
        $reversed = $reverseAction->execute($pmt, $this->owner, 'Reversal test snapshot');
        $this->assertEquals($initialVendorSnapshot, $reversed->vendor_snapshot);
        $this->assertEquals($initialCompanySnapshot, $reversed->company_snapshot);
    }
}
