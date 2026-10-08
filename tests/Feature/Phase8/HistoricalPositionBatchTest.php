<?php

declare(strict_types=1);

namespace Tests\Feature\Phase8;

use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\PostCustomerPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Services\Money\ReceivablePositionAsOf;
use App\Services\Purchasing\PurchasePayableAsOf;
use App\Services\Purchasing\PurchasePostingCommandBuilder;
use App\Services\Purchasing\PurchaseReturnPostingCommandBuilder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class HistoricalPositionBatchTest extends Phase8TestCase
{
    public function test_receivable_batch_preserves_fx_payment_and_later_inverse_with_two_queries(): void
    {
        $one = $this->invoice();
        $two = $this->invoice();
        $payment = app(PostCustomerPaymentAction::class)->execute($this->company, $this->owner, [
            'customer_id' => $one->customer_id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '165', 'exchange_rate' => '1',
            'idempotency_key' => 'batch-ar-payment',
            'allocations' => [['sales_invoice_id' => $one->id, 'allocated_amount' => '50', 'payment_currency_amount' => '165']],
        ]);
        app(ReverseCustomerPaymentAction::class)->execute($payment, $this->owner, 'Later reversal', '2026-10-20');
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $positions = app(ReceivablePositionAsOf::class)->forInvoices([$one, $two], '2026-10-10');
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        $this->assertCount(2, $queries);
        $this->assertSame('50.000000', (string) $positions[$one->id]->toScale(6));
        $this->assertSame('100.000000', (string) $positions[$two->id]->toScale(6));
        $later = app(ReceivablePositionAsOf::class)->forInvoices([$one, $two], '2026-10-20');
        $this->assertSame('100.000000', (string) $later[$one->id]->toScale(6));
        $this->assertSame((string) $positions[$one->id], (string) app(ReceivablePositionAsOf::class)->outstanding($one, '2026-10-10'));
    }

    public function test_payable_interactive_batch_matches_existing_engine_without_deep_replay(): void
    {
        $purchase = $this->createAndPostPurchase();
        $this->createAndPostReturn($purchase);
        $payment = app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => '20', 'exchange_rate' => '1',
            'idempotency_key' => 'batch-ap-payment',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '20']],
        ]);
        app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner, 'Later reversal', '2026-10-20');
        $strict = app(PurchasePayableAsOf::class)->forPurchases([$purchase], Carbon::parse('2026-10-10'));
        $this->app->bind(PurchasePostingCommandBuilder::class, fn () => throw new \LogicException('Deep Purchase replay reached.'));
        $this->app->bind(PurchaseReturnPostingCommandBuilder::class, fn () => throw new \LogicException('Deep Return replay reached.'));
        $light = app(PurchasePayableAsOf::class)->forHistory([$purchase], Carbon::parse('2026-10-10'));
        $this->assertSame('70.000000', (string) $light[$purchase->id]);
        $this->assertSame((string) $strict[$purchase->id], (string) $light[$purchase->id]);
        $later = app(PurchasePayableAsOf::class)->forHistory([$purchase], Carbon::parse('2026-10-20'));
        $this->assertSame('90.000000', (string) $later[$purchase->id]);
    }

    public function test_interactive_payable_batch_rejects_wrong_original_source_identity(): void
    {
        $purchase = $this->createAndPostPurchase();
        DB::table('posting_batches')->where('id', $purchase->posting_batch_id)->update(['source_id' => 999999]);
        $this->expectException(InvalidArgumentException::class);
        app(PurchasePayableAsOf::class)->forHistory([$purchase], Carbon::parse('2026-10-10'));
    }

    public function test_interactive_payable_batch_rejects_wrong_return_provenance(): void
    {
        $purchase = $this->createAndPostPurchase();
        $return = $this->createAndPostReturn($purchase);
        DB::table('posting_batches')->where('id', $return->posting_batch_id)->update(['source_type' => 'manual_journal']);
        $this->expectException(InvalidArgumentException::class);
        app(PurchasePayableAsOf::class)->forHistory([$purchase], Carbon::parse('2026-10-10'));
    }
}
