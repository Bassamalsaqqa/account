<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Customer;
use App\Models\PostingBatch;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Sales\SalesHistoryAudit;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase5E\Phase5ETestCase;

class CheckLifecycleTest extends Phase5ETestCase
{
    private function outgoing(): Check
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);

        return app(IssueCheckAction::class)->execute($this->company, $this->owner, [
            'party_id' => $this->vendor->id, 'money_account_id' => $this->usdBankAccount->id, 'check_number' => 'OUT-123', 'bank_name' => 'Palestine Bank',
            'date' => '2026-10-02', 'due_date' => '2026-10-03', 'currency_code' => 'USD', 'amount' => '100', 'exchange_rate' => '3.60',
            'idempotency_key' => 'outgoing-check', 'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '100']],
        ]);
    }

    private function incoming(): Check
    {
        $customer = Customer::create(['company_id' => $this->company->id, 'name_ar' => 'عميل شيك', 'active' => true, 'created_by' => $this->owner->id]);

        return app(ReceiveCheckAction::class)->execute($this->company, $this->owner, [
            'party_id' => $customer->id, 'check_number' => 'IN-123', 'bank_name' => 'Palestine Bank',
            'date' => '2026-10-02', 'due_date' => '2026-10-03', 'currency_code' => 'USD', 'amount' => '100', 'exchange_rate' => '3.50',
            'idempotency_key' => 'incoming-check', 'allocations' => [],
        ]);
    }

    private function transition(Check $check, string $type, string $date, ?string $rate = null): CheckEvent
    {
        return app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => $type, 'event_date' => $date,
            'idempotency_key' => $check->public_id.':'.$type, 'money_account_id' => $type === 'deposit' ? $this->usdBankAccount->id : null,
            'exchange_rate' => $rate]);
    }

    public function test_outgoing_issue_replaces_ap_then_clear_moves_bank_with_opposite_fx_orientation(): void
    {
        $check = $this->outgoing();
        $this->assertSame(0, DB::table('posting_lines')->where('ledger_account_id', $this->usdBankAccount->ledger_account_id)->count());
        $payment = $check->vendorPayment;
        $this->assertNull($payment->money_account_id);
        $this->assertSame('10.000000', $payment->allocations->sole()->realized_fx_gain_loss_base);
        $event = $this->transition($check, 'clear', '2026-10-03', '3.70');
        $this->assertSame('-10.000000', $event->fx_gain_loss_base);
        $this->assertSame('cleared', $check->fresh()->status);
        $this->assertSame($event->id, $this->transition($check->fresh(), 'clear', '2026-10-03', '3.70')->id);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_incoming_deposit_has_no_gl_and_return_after_clear_inverts_clearance_before_receipt(): void
    {
        $check = $this->incoming();
        $before = PostingBatch::count();
        $deposit = $this->transition($check, 'deposit', '2026-10-03');
        $this->assertNull($deposit->posting_batch_id);
        $this->assertSame($before, PostingBatch::count());
        $clear = $this->transition($check->fresh(), 'clear', '2026-10-04', '3.60');
        $this->assertSame('10.000000', $clear->fx_gain_loss_base);
        $returned = $this->transition($check->fresh(), 'return', '2026-10-05');
        $this->assertLessThan($returned->payment_reversal_posting_batch_id, $returned->reversal_posting_batch_id);
        $this->assertSame('returned', $check->fresh()->status);
        $this->assertTrue($check->customerPayment()->firstOrFail()->is_reversed);
        $this->assertSame($returned->id, $this->transition($check->fresh(), 'return', '2026-10-05')->id);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertSame([], app(SalesHistoryAudit::class)->audit($this->company->id));
    }

    public function test_outgoing_cancellation_restores_purchase_outstanding_without_bank_movement(): void
    {
        $check = $this->outgoing();
        $purchase = $check->vendorPayment->allocations->sole()->purchase;
        $this->assertTrue($purchase->calculateOutstanding()->isZero());
        $event = $this->transition($check, 'cancel', '2026-10-04');
        $this->assertNull($event->reversal_posting_batch_id);
        $this->assertTrue($purchase->fresh()->calculateOutstanding()->isEqualTo('100'));
        $this->assertSame(0, DB::table('posting_lines')->where('ledger_account_id', $this->usdBankAccount->ledger_account_id)->count());
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }
}
