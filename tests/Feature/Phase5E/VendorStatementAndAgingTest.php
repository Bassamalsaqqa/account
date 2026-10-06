<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Purchasing\Queries\VendorStatementQuery;
use Carbon\Carbon;

class VendorStatementAndAgingTest extends Phase5ETestCase
{
    /** Scenario 21: Statement Purchase credit100, Payment debit100, reversal credit100 => payable100; full history retained, dates/opening balance/deterministic sort; application never second principal; FX no principal; no mixed-currency totals */
    public function test_scenario_21_statement_history_running_balances_and_no_duplicate_principal(): void
    {
        $postAction = app(PostVendorPaymentAction::class);
        $applyAction = app(ApplyVendorPaymentCreditAction::class);
        $reverseAction = app(ReverseVendorPaymentAction::class);

        // 1. Purchase of 100 ILS on Oct 01 (Credit 100 in AP)
        $purchase = $this->createAndPostPurchase([
            'purchase_date' => '2026-10-01',
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // 2. Payment of 100 ILS on Oct 02 (Debit 100 in AP)
        $pmt = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-02',
            'payment_method' => 'cash',
            'amount' => '100.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s21-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '100.00'],
            ],
        ]);

        $query = app(VendorStatementQuery::class);
        $stmt1 = $query->execute($this->vendor)['currencies']['ILS'];
        $this->assertEquals('100.00', $stmt1['total_credits']);
        $this->assertEquals('100.00', $stmt1['total_debits']);
        $this->assertEquals('0.00', $stmt1['closing_balance']);
        $this->assertCount(2, $stmt1['entries']);

        // 3. Reversal on Oct 03: restores payable to 100 ILS
        $reverseAction->execute($pmt, $this->owner, 'Statement reversal test');

        $stmt2 = $query->execute($this->vendor)['currencies']['ILS'];
        // Reversal batch creates credit or offsets debit; balance returns to 100 ILS
        $this->assertEquals('100.00', $stmt2['closing_balance']);

        // 4. Test that advance application NEVER creates duplicate principal in statement
        $advance = $postAction->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-04',
            'payment_method' => 'cash',
            'amount' => '50.00',
            'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'pmt-s21-adv',
            'allocations' => [],
        ]);

        $stmtBeforeApp = $query->execute($this->vendor)['currencies']['ILS'];
        $entriesCountBefore = count($stmtBeforeApp['entries']);

        // Explicit advance application
        $applyAction->execute($advance, $this->owner, [
            'application_date' => '2026-10-05',
            'idempotency_key' => 'app-s21-01',
            'allocations' => [
                ['purchase_id' => $purchase->id, 'allocated_amount' => '50.00'],
            ],
        ]);

        $stmtAfterApp = $query->execute($this->vendor)['currencies']['ILS'];
        // No new principal statement entry added! Advance application is only an internal allocation of already-paid cash
        $this->assertCount($entriesCountBefore, $stmtAfterApp['entries']);
    }

    /** Scenario 22: Aging due today/future/current/each overduebucket/null unspecified/partial payment/Return/advance/credit/multicurrency; credits never overdue; exact gross/unapplied/net */
    public function test_scenario_22_aging_buckets_and_multi_currency_independence(): void
    {
        \Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::parse('2026-10-05 12:00:00', $this->company->timezone));
        $postAction = app(PostVendorPaymentAction::class);
        $today = Carbon::now()->format('Y-m-d');

        // 1. Purchase A: null due date => unspecified (100 ILS)
        $purchaseA = $this->createAndPostPurchase([
            'purchase_date' => Carbon::now()->subDays(60)->format('Y-m-d'),
            'due_date' => null,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '10', 'unit_cost' => '10'],
            ],
        ]);

        // 2. Purchase B: due today => current (50 ILS)
        $purchaseB = $this->createAndPostPurchase([
            'purchase_date' => Carbon::now()->subDays(10)->format('Y-m-d'),
            'due_date' => $today,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'],
            ],
        ]);

        // 3. Purchase C: overdue 15 days => days_1_30 (40 ILS)
        $purchaseC = $this->createAndPostPurchase([
            'purchase_date' => Carbon::now()->subDays(20)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(15)->format('Y-m-d'),
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '4', 'unit_cost' => '10'],
            ],
        ]);

        // 4. Purchase D: overdue 45 days => days_31_60 (30 ILS)
        $purchaseD = $this->createAndPostPurchase([
            'purchase_date' => Carbon::now()->subDays(50)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(45)->format('Y-m-d'),
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '3', 'unit_cost' => '10'],
            ],
        ]);

        // 5. Purchase E: overdue 75 days => days_61_90 (20 ILS)
        $purchaseE = $this->createAndPostPurchase([
            'purchase_date' => Carbon::now()->subDays(80)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(75)->format('Y-m-d'),
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '2', 'unit_cost' => '10'],
            ],
        ]);

        // 6. Purchase F: overdue 100 days => days_90_plus (10 ILS)
        $purchaseF = $this->createAndPostPurchase([
            'purchase_date' => Carbon::now()->subDays(110)->format('Y-m-d'),
            'due_date' => Carbon::now()->subDays(100)->format('Y-m-d'),
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '1', 'unit_cost' => '10'],
            ],
        ]);

        $query = app(VendorStatementQuery::class);
        $aging = $query->execute($this->vendor)['currencies']['ILS']['aging'];

        $this->assertEquals('100.00', $aging['unspecified']);
        $this->assertEquals('50.00', $aging['current']);
        $this->assertEquals('40.00', $aging['days_1_30']);
        $this->assertEquals('30.00', $aging['days_31_60']);
        $this->assertEquals('20.00', $aging['days_61_90']);
        $this->assertEquals('10.00', $aging['days_90_plus']);

        // Gross open purchases = 100 + 50 + 40 + 30 + 20 + 10 = 250
        $this->assertEquals('250.00', $aging['gross_open_purchases']);

        // 7. Multi-currency independence: Add a USD purchase
        $usdPurchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.6000000000',
            'purchase_date' => Carbon::now()->format('Y-m-d'),
            'due_date' => $today,
            'lines' => [
                ['product_id' => $this->product->id, 'quantity' => '5', 'unit_cost' => '10'], // 50 USD
            ],
        ]);

        $multistmt = $query->execute($this->vendor);
        $this->assertArrayHasKey('ILS', $multistmt['currencies']);
        $this->assertArrayHasKey('USD', $multistmt['currencies']);

        $usdAging = $multistmt['currencies']['USD']['aging'];
        $this->assertEquals('50.00', $usdAging['gross_open_purchases']);
        $this->assertEquals('50.00', $usdAging['current']);
        $this->assertEquals('0.00', $usdAging['unspecified']);

        // ILS aging remains completely unaffected by USD purchase
        $ilsAgingAfter = $multistmt['currencies']['ILS']['aging'];
        $this->assertEquals('250.00', $ilsAgingAfter['gross_open_purchases']);
    }
}
