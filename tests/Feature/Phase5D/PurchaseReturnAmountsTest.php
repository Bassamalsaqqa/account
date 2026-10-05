<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5D;

use App\Models\CompanyCurrency;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnAllocation;
use Brick\Math\BigDecimal;
use InvalidArgumentException;

class PurchaseReturnAmountsTest extends Phase5DTestCase
{
    /** Scenario 10: ILS partial */
    public function test_scenario_10_ils_partial_return(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'ILS',
            'exchange_rate' => '1',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '4',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $this->assertSame(PurchaseReturn::STATUS_POSTED, $posted->status);
        $returnLine = $posted->lines->sole();
        $this->assertSame('4.000000', $returnLine->quantity);
        $this->assertSame('40.000000', $returnLine->line_subtotal);
        $this->assertSame('40.000000', $returnLine->line_subtotal_base);
        $this->assertSame('40.000000', $returnLine->line_total);
        $this->assertSame('40.000000', $returnLine->line_total_base);
        $this->assertSame('40.000000', $posted->subtotal_currency);
        $this->assertSame('40.000000', $posted->grand_total_currency);
        $this->assertSame('40.000000', $posted->grand_total_base);
    }

    /** Scenario 11: USD partial */
    public function test_scenario_11_usd_partial_return(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '3',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $this->assertSame('USD', $posted->currency_code);
        $this->assertSame('3.5000000000', $posted->exchange_rate);
        $returnLine = $posted->lines->sole();
        $this->assertSame('30.000000', $returnLine->line_subtotal);
        $this->assertSame('105.000000', $returnLine->line_subtotal_base);
        $this->assertSame('30.000000', $posted->grand_total_currency);
        $this->assertSame('105.000000', $posted->grand_total_base);
    }

    /** Scenario 12: JOD3 precision */
    public function test_scenario_12_jod3_precision(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'JOD',
            'exchange_rate' => '5.0000000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '1.250',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '3',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $this->assertSame('JOD', $posted->currency_code);
        $this->assertSame('3.750000', $posted->grand_total_currency);
        $this->assertSame('18.750000', $posted->grand_total_base);
    }

    /** Scenario 13: inclusive VAT */
    public function test_scenario_13_inclusive_vat(): void
    {
        $tax = $this->tax($this->account('tax_input')->id, mode: 'inclusive', code: 'INC16', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '116',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '3',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);
        $returnLine = $posted->lines->sole();

        $this->assertSame('348.000000', $returnLine->line_subtotal);
        $this->assertSame('48.000000', $returnLine->line_tax);
        $this->assertSame('348.000000', $returnLine->line_total);
        $this->assertSame('348.000000', $posted->grand_total_base);
    }

    /** Scenario 14: exclusive VAT */
    public function test_scenario_14_exclusive_vat(): void
    {
        $tax = $this->tax($this->account('tax_input')->id, mode: 'exclusive', code: 'EXC16', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '100',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '3',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);
        $returnLine = $posted->lines->sole();

        $this->assertSame('300.000000', $returnLine->line_subtotal);
        $this->assertSame('48.000000', $returnLine->line_tax);
        $this->assertSame('348.000000', $returnLine->line_total);
        $this->assertSame('348.000000', $posted->grand_total_base);
    }

    /** Scenario 15: recoverable tax */
    public function test_scenario_15_recoverable_tax(): void
    {
        $taxInputAccount = $this->account('tax_input');
        $tax = $this->tax($taxInputAccount->id, mode: 'exclusive', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '100',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $this->assertNotNull($posted->posting_batch_id);
        $taxCredit = $posted->postingBatch->lines()
            ->where('ledger_account_id', $taxInputAccount->id)
            ->sole();
        $this->assertSame('32.000000', $taxCredit->credit_base);
    }

    /** Scenario 16: nonrecoverable tax */
    public function test_scenario_16_nonrecoverable_tax(): void
    {
        $tax = $this->tax(account: null, mode: 'exclusive', code: 'NONREC16', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '5',
                    'unit_cost' => '100',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        $this->assertNotNull($posted->posting_batch_id);
        $taxLines = $posted->postingBatch->lines()
            ->where('ledger_account_id', $this->account('tax_input')->id)
            ->get();
        $this->assertTrue($taxLines->isEmpty());

        // Full commercial cost was capitalized into inventory
        $this->assertSame('232.000000', $posted->grand_total_base);
    }

    /** Scenario 17: fixed discount */
    public function test_scenario_17_fixed_discount(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10',
                    'discount_type' => 'fixed',
                    'discount_value' => '10',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '4',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);
        $returnLine = $posted->lines->sole();

        $this->assertSame('4.000000', $returnLine->line_discount);
        $this->assertSame('36.000000', $returnLine->line_total);
        $this->assertSame('36.000000', $posted->grand_total_base);
    }

    /** Scenario 18: percent discount */
    public function test_scenario_18_percent_discount(): void
    {
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '20',
                    'discount_type' => 'percent',
                    'discount_value' => '10',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '3',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);
        $returnLine = $posted->lines->sole();

        $this->assertSame('6.000000', $returnLine->line_discount);
        $this->assertSame('54.000000', $returnLine->line_total);
        $this->assertSame('54.000000', $posted->grand_total_base);
    }

    /** Scenario 19: multiple partial totals equal original exactly */
    public function test_scenario_19_multiple_partial_totals_equal_original_exactly(): void
    {
        $tax = $this->tax($this->account('tax_input')->id, mode: 'exclusive', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '33.333333',
                    'discount_type' => 'fixed',
                    'discount_value' => '5.000000',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $line = $purchase->lines->first();

        // Return 1: 3 units
        $r1 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]));

        // Return 2: 3 units
        $r2 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '3']],
        ]));

        // Return 3: remaining 4 units
        $r3 = $this->postReturn($this->createReturnDraft($purchase, [
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => '4']],
        ]));

        $sumGrandBase = BigDecimal::of((string) $r1->grand_total_base)
            ->plus($r2->grand_total_base)
            ->plus($r3->grand_total_base);
        $sumTaxBase = BigDecimal::of((string) $r1->tax_total_base)
            ->plus($r2->tax_total_base)
            ->plus($r3->tax_total_base);
        $sumNetBase = BigDecimal::of((string) $r1->subtotal_base)
            ->plus($r2->subtotal_base)
            ->plus($r3->subtotal_base);

        $this->assertTrue($sumGrandBase->isEqualTo(BigDecimal::of((string) $purchase->grand_total_base)));
        $this->assertTrue($sumTaxBase->isEqualTo(BigDecimal::of((string) $purchase->tax_total_base)));
        $this->assertTrue($sumNetBase->isEqualTo(BigDecimal::of((string) $purchase->subtotal_base)));
    }

    /** Scenario 20: changed current tax rate ignored */
    public function test_scenario_20_changed_current_tax_rate_ignored(): void
    {
        $tax = $this->tax($this->account('tax_input')->id, mode: 'exclusive', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '100',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        // Master data change: update tax rate to 20%
        $tax->update(['rate' => '20.000000']);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);
        $returnLine = $posted->lines->sole();

        // Historical 16% rate from purchase line snapshot is preserved
        $this->assertSame('16.000000', $returnLine->tax_rate_snapshot);
        $this->assertSame('32.000000', $returnLine->line_tax);
    }

    /** Scenario 21: changed current tax account ignored */
    public function test_scenario_21_changed_current_tax_account_ignored(): void
    {
        $originalTaxAccount = $this->account('tax_input');
        $newTaxAccount = $this->account('tax_output');
        $tax = $this->tax($originalTaxAccount->id, mode: 'exclusive', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '100',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        // Master data change: reassign tax rate to a different account
        $tax->update(['purchase_tax_account_id' => $newTaxAccount->id]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        $posted = $this->postReturn($draft);

        // Historical account from purchase line snapshot is used
        $taxLine = $posted->postingBatch->lines()
            ->where('ledger_account_id', $originalTaxAccount->id)
            ->sole();
        $this->assertSame('32.000000', $taxLine->credit_base);
    }

    /** Scenario 22: inactive historical account atomic failure */
    public function test_scenario_22_inactive_historical_account_atomic_failure(): void
    {
        $taxAccount = $this->account('tax_input');
        $tax = $this->tax($taxAccount->id, mode: 'exclusive', rate: '16.000000');
        $purchase = $this->createAndPostPurchase([
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '100',
                    'tax_rate_id' => $tax->id,
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        // Deactivate historical account before posting return
        $taxAccount->update(['active' => false]);

        $beforeState = $this->snapshotState();

        try {
            $this->postReturn($draft);
            $this->fail('Expected InvalidArgumentException for inactive historical account.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('inactive', $e->getMessage());
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState, $afterState);
    }

    /** Scenario 23: disabled original currency atomic failure */
    public function test_scenario_23_disabled_original_currency_atomic_failure(): void
    {
        $purchase = $this->createAndPostPurchase([
            'currency_code' => 'USD',
            'exchange_rate' => '3.5000000000',
            'lines' => [
                [
                    'product_id' => $this->product->id,
                    'quantity' => '10',
                    'unit_cost' => '10',
                ],
            ],
        ]);

        $line = $purchase->lines->first();
        $draft = $this->createReturnDraft($purchase, [
            'lines' => [
                [
                    'purchase_line_id' => $line->id,
                    'quantity' => '2',
                ],
            ],
        ]);

        // Disable USD in company currencies
        CompanyCurrency::where('company_id', $this->company->id)
            ->where('currency_code', 'USD')
            ->update(['enabled' => false]);

        $beforeState = $this->snapshotState();

        try {
            $this->postReturn($draft);
            $this->fail('Expected InvalidArgumentException for disabled currency.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('currency', strtolower($e->getMessage()));
        }

        $afterState = $this->snapshotState();
        $this->assertSame($beforeState, $afterState);
    }

    /** Review regression: Post rejects allocation sum different from line quantity */
    public function test_post_rejects_allocation_sum_different_from_line_quantity(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createReturnDraft($purchase);
        $return->lines->first()->allocations->first()->update(['quantity' => '2', 'quantity_base' => '2']);
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($return);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Posting issued 2 units for a Return line/AP relief of 1 unit.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Review regression: Post rejects corrupt draft component even when grand total unchanged */
    public function test_post_rejects_corrupt_draft_component_even_when_grand_total_unchanged(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createReturnDraft($purchase);
        $return->lines->first()->update(['line_subtotal' => '11']);
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($return);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Post silently rewrote corrupt agreed Draft component while checking only grand total.');
        $this->assertSame($before, $this->snapshotState());
    }

    /** Review regression: Post rejects receipt from different original purchase */
    public function test_post_rejects_receipt_from_different_original_purchase(): void
    {
        $purchaseA = $this->createAndPostPurchase();
        $purchaseB = $this->createAndPostPurchase(['lines' => [['unit_cost' => '20']]]);
        $return = $this->createReturnDraft($purchaseA);
        $line = $return->lines->first();
        $line->allocations->first()->delete();
        PurchaseReturnAllocation::create([
            'company_id' => $this->company->id,
            'purchase_return_id' => $return->id,
            'purchase_return_line_id' => $line->id,
            'original_stock_movement_id' => $purchaseB->lines->first()->stock_movement_id,
            'quantity' => '1',
            'quantity_base' => '1',
        ]);
        $before = $this->snapshotState();
        $error = null;

        try {
            $this->postReturn($return);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $this->assertNotNull($error, 'Return of Purchase A used Purchase B receipt value/provenance.');
        $this->assertSame($before, $this->snapshotState());
    }
}
