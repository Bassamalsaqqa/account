<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Models\VendorPayment;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CrossCurrencyVendorPaymentsTest extends Phase5ETestCase
{
    private function pay(string $key, array $allocations = [], string $amount = '360'): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, [
            'vendor_id' => $this->vendor->id, 'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash', 'amount' => $amount,
            'exchange_rate' => '1', 'idempotency_key' => $key, 'allocations' => $allocations,
        ]);
    }

    public function test_purchase_currency_relief_and_payment_currency_consumption_are_independent(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $intent = [['purchase_id' => $purchase->id, 'allocated_amount' => '100', 'payment_currency_amount' => '360']];
        $payment = $this->pay('cross-vendor', $intent);
        $row = $payment->allocations->sole();
        $this->assertSame('100.000000', $row->allocated_amount);
        $this->assertSame('360.000000', $row->payment_currency_amount);
        $this->assertSame('350.000000', $row->base_amount_applied_to_payable);
        $this->assertSame('10.000000', $row->realized_fx_gain_loss_base);
        $this->assertSame('0.000000', $payment->unallocated_amount);
        $this->assertTrue($purchase->calculateOutstanding()->isZero());
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $this->assertSame($payment->id, $this->pay('cross-vendor', $intent)->id);
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_later_cross_currency_advance_application_does_not_move_cash_again(): void
    {
        $payment = $this->pay('cross-advance');
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $before = DB::table('posting_lines')->where('ledger_account_id', $this->ilsCashAccount->ledger_account_id)->count();
        $data = ['application_date' => '2026-10-04', 'idempotency_key' => 'apply-cross', 'allocations' => [
            ['purchase_id' => $purchase->id, 'allocated_amount' => '100', 'payment_currency_amount' => '360'],
        ]];
        $event = app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, $data);
        $this->assertSame('10.000000', $event->allocations->sole()->realized_fx_gain_loss_base);
        $this->assertSame($before, DB::table('posting_lines')->where('ledger_account_id', $this->ilsCashAccount->ledger_account_id)->count());
        $this->assertSame('0.000000', $payment->fresh()->unallocated_amount);
        $this->assertSame($event->id, app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, $data)->id);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment->fresh());
        $this->assertTrue(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
