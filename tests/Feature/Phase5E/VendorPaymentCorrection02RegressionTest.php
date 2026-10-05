<?php

declare(strict_types=1);

namespace Tests\Feature\Phase5E;

use App\Actions\Purchasing\ApplyVendorPaymentCreditAction;
use App\Actions\Purchasing\PostVendorPaymentAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Domain\Money\ValueObjects\MoneyAmount;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Livewire\Pages\Purchasing\PaymentDetail;
use App\Models\VendorPayment;
use App\Services\Posting\AccountingPostingService;
use App\Services\Purchasing\PayablesReconciliationService;
use App\Services\Purchasing\VendorPaymentApplicationIntegrityValidator;
use App\Services\Purchasing\VendorPaymentPostedIntegrityValidator;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Independent follow-up: expected-safe behavior, no application source edits. */
final class VendorPaymentCorrection02RegressionTest extends Phase5ETestCase
{
    private function postPayment(array $changes = []): VendorPayment
    {
        return app(PostVendorPaymentAction::class)->execute($this->company, $this->owner, array_replace([
            'vendor_id' => $this->vendor->id,
            'money_account_id' => $this->ilsCashAccount->id,
            'payment_date' => '2026-10-03', 'payment_method' => 'cash',
            'amount' => '100.00', 'exchange_rate' => '1.0000000000',
            'idempotency_key' => 'followup-payment', 'allocations' => [],
        ], $changes));
    }

    private function apply(VendorPayment $payment, int $purchaseId, string $key = 'followup-application')
    {
        return app(ApplyVendorPaymentCreditAction::class)->execute($payment, $this->owner, [
            'application_date' => '2026-10-03', 'idempotency_key' => $key,
            'allocations' => [['purchase_id' => $purchaseId, 'allocated_amount' => '40.00']],
        ]);
    }

    private function assertRejected(callable $operation, string $message): void
    {
        $rejected = false;
        try {
            $operation();
        } catch (\Exception $exception) {
            $rejected = true;
        }
        $this->assertTrue($rejected, $message);
    }

    public function test_zero_fx_event_cannot_be_reversed_independently(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment();
        $event = $this->apply($payment, $purchase->id);
        $this->assertNull($event->posting_batch_id);
        $this->assertRejected(fn () => DB::transaction(fn () => $event->completeCanonicalReversal(null, $this->owner, 'standalone')),
            'Public event reversal independently deactivated allocation while parent Payment stayed active.');
        $this->assertNull($event->fresh()->reversed_at);
    }

    public function test_payment_reversal_requires_persisted_canonical_batch_and_scope(): void
    {
        $payment = $this->postPayment();
        $fake = $payment->postingBatch;
        $fake->reversal_of_id = $payment->posting_batch_id;
        $this->assertRejected(fn () => DB::transaction(fn () => $payment->completeCanonicalReversal($fake, $this->owner, 'forged object')),
            'In-memory reversal_of_id forged Payment reversal metadata with original batch as its own reversal.');
    }

    public function test_payment_validator_checks_ap_transaction_metadata(): void
    {
        $payment = $this->postPayment();
        DB::table('posting_lines')->where('posting_batch_id', $payment->posting_batch_id)->where('debit_base', '>', 0)->update(['transaction_amount' => '70.000000']);
        $this->assertRejected(fn () => app(VendorPaymentPostedIntegrityValidator::class)->validate($payment),
            'AP transaction_amount70 passed for original advance100 while base values stayed100.');
    }

    public function test_application_validator_checks_ap_gl_leg(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $payment = $this->postPayment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']);
        $event = $this->apply($payment, $purchase->id);
        DB::table('posting_lines')->where('posting_batch_id', $event->posting_batch_id)->where('credit_base', '>', 0)->update(['credit_base' => '9.000000']);
        $this->assertRejected(fn () => app(VendorPaymentApplicationIntegrityValidator::class)->validate($event),
            'Application validator ignored wrong AP credit9 while correct FX loss remained4.');
    }

    public function test_application_audit_checks_request_and_date_identity(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment();
        $event = $this->apply($payment, $purchase->id);
        DB::table('vendor_payment_application_events')->where('id', $event->id)->update(['request_hash' => str_repeat('0', 64), 'application_date' => '2020-01-01']);
        $this->assertFalse(app(PayablesReconciliationService::class)->reconcile($this->company)->isHealthy,
            'Audit accepted wrong request identity and application predating both Purchase and Payment.');
    }

    public function test_payment_history_rejects_corrupt_original_purchase(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment(['allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '40.00']]]);
        DB::table('purchases')->where('id', $purchase->id)->update(['posting_batch_id' => null]);
        $this->assertRejected(fn () => app(VendorPaymentPostedIntegrityValidator::class)->validate($payment),
            'Historical validator accepted allocated Purchase with missing canonical accounting linkage.');
    }

    public function test_coherent_ap_rounding_residual_is_valid_on_retry(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.3333333333']);
        $data = ['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.3333333333', 'amount' => '0.01',
            'allocations' => [['purchase_id' => $purchase->id, 'allocated_amount' => '0.01']]];
        $this->postPayment($data + ['idempotency_key' => 'residual-first']);
        $payment = $this->postPayment($data + ['idempotency_key' => 'residual-second']);
        $this->assertSame('0.033334', $payment->allocations->first()->base_amount_applied_to_payable);
        app(VendorPaymentPostedIntegrityValidator::class)->validate($payment);
        $this->assertSame($payment->id, $this->postPayment($data + ['idempotency_key' => 'residual-second'])->id);
    }

    public function test_application_allocation_must_belong_to_its_event_payment(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment();
        $event = $this->apply($payment, $purchase->id);
        $other = $this->postPayment(['idempotency_key' => 'other-payment']);
        DB::table('vendor_payment_allocations')->where('application_event_id', $event->id)->update(['vendor_payment_id' => $other->id]);
        $this->assertRejected(fn () => app(VendorPaymentApplicationIntegrityValidator::class)->validate($event),
            'Event validator accepted allocation linked to another advance Payment.');
    }

    public function test_first_reversal_rejects_corrupt_application_history(): void
    {
        $purchase = $this->createAndPostPurchase();
        $payment = $this->postPayment();
        $event = $this->apply($payment, $purchase->id);
        DB::table('vendor_payment_allocations')->where('application_event_id', $event->id)->update(['base_amount_applied_to_payable' => '49.000000']);
        $this->assertRejected(fn () => app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner),
            'First reversal proceeded through corrupted application relief.');
    }

    public function test_reversal_validator_checks_exact_inverse_lines(): void
    {
        $payment = $this->postPayment();
        $reversed = app(ReverseVendorPaymentAction::class)->execute($payment, $this->owner);
        DB::table('posting_lines')->where('posting_batch_id', $reversed->reversal_posting_batch_id)->where('debit_base', '>', 0)->update(['debit_base' => '99.000000']);
        $this->assertRejected(fn () => app(VendorPaymentPostedIntegrityValidator::class)->validate($reversed),
            'Reversal validator accepted non-inverse GL because linkage IDs still matched.');
    }

    public function test_stale_financial_page_rechecks_revoked_permission(): void
    {
        $payment = $this->postPayment();
        $actor = $this->customActor(['money.vendor_payment.create', 'purchasing.cost.view']);
        $this->activate($actor);
        $component = Livewire::test(PaymentDetail::class, ['publicId' => $payment->public_id])->assertOk();
        $actor->roles->first()->revokePermissionTo('purchasing.cost.view');
        $component->call('$refresh')->assertForbidden();
    }

    private function installWrongPostingResponse(string $value): void
    {
        $real = new AccountingPostingService;
        $mock = \Mockery::mock(AccountingPostingService::class);
        $mock->shouldReceive('post')->once()->andReturnUsing(function ($command) use ($real, $value) {
            $lines = [];
            foreach ($command->lines as $line) {
                $lines[] = new PostingLineCommand(
                    lineNumber: $line->lineNumber, ledgerAccountId: $line->ledgerAccountId,
                    debitBase: MoneyAmount::from($line->debitBase->isPositive() ? $value : '0'),
                    creditBase: MoneyAmount::from($line->creditBase->isPositive() ? $value : '0'),
                );
            }

            return $real->post(new PostingCommand(
                company: $command->company, postingDate: $command->postingDate,
                sourceType: $command->sourceType, sourceId: $command->sourceId,
                transactionCurrencyCode: $command->transactionCurrencyCode, baseCurrencyCode: $command->baseCurrencyCode,
                exchangeRate: $command->exchangeRate, idempotencyKey: $command->idempotencyKey,
                postedBy: $command->postedBy, description: $command->description, batchNumber: $command->batchNumber, lines: $lines,
            ));
        });
        app()->instance(AccountingPostingService::class, $mock);
    }

    public function test_payment_completion_rejects_wrong_economic_batch(): void
    {
        $this->installWrongPostingResponse('70.000000');
        $this->assertRejected(fn () => $this->postPayment(),
            'Canonical completion accepted a persisted same-source batch for70 against Payment100.');
    }

    public function test_application_completion_rejects_wrong_economic_batch(): void
    {
        $purchase = $this->createAndPostPurchase(['currency_code' => 'USD', 'exchange_rate' => '3.50']);
        $payment = $this->postPayment(['money_account_id' => $this->usdCashAccount->id, 'exchange_rate' => '3.60']);
        $this->installWrongPostingResponse('9.000000');
        $this->assertRejected(fn () => $this->apply($payment, $purchase->id),
            'Canonical completion accepted a persisted balanced FX/AP9 batch for prepared FX4.');
    }
}
