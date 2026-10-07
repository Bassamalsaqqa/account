<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\CompanyCurrency;
use App\Models\CustomerPayment;
use App\Models\DocumentSequence;
use App\Models\MoneyTransfer;
use App\Models\PostingBatch;
use App\Models\VendorPayment;
use App\Services\Money\CheckHistory;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class MoneySecurityAndIntegrityTest extends Phase6TestCase
{
    public static function directions(): array
    {
        return [['incoming'], ['outgoing']];
    }

    #[DataProvider('directions')]
    public function test_check_creation_accounting_failure_rolls_back_instrument_payment_events_and_sequence(string $direction): void
    {
        $data = $this->checkIntent($direction);
        $sequences = DocumentSequence::pluck('next_number', 'id')->all();
        $this->mock(AccountingPostingService::class)->shouldReceive('post')->once()->andThrow(new \RuntimeException('Injected failure'));
        try {
            app($direction === 'incoming' ? ReceiveCheckAction::class : IssueCheckAction::class)->execute($this->company, $this->owner, $data);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected failure', $e->getMessage());
        }
        $this->assertSame(0, Check::count());
        $this->assertSame(0, CheckEvent::count());
        $this->assertSame(0, CustomerPayment::count());
        $this->assertSame(0, VendorPayment::count());
        $this->assertSame(0, PostingBatch::count());
        $this->assertSame($sequences, DocumentSequence::pluck('next_number', 'id')->all());
    }

    #[DataProvider('directions')]
    public function test_check_payment_cannot_be_reversed_independently_of_lifecycle(string $direction): void
    {
        $check = $this->check($direction);
        $count = PostingBatch::count();
        try {
            $direction === 'incoming' ? app(ReverseCustomerPaymentAction::class)->execute($check->customerPayment, $this->owner)
                : app(ReverseVendorPaymentAction::class)->execute($check->vendorPayment, $this->owner);
            $this->fail('Expected lifecycle boundary');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('Money event', $e->getMessage());
        }
        $this->assertSame($count, PostingBatch::count());
        $this->assertSame($direction === 'incoming' ? 'received' : 'issued', $check->fresh()->status);
    }

    #[DataProvider('directions')]
    public function test_exact_check_retry_and_terminal_transition_survive_disabled_currency_and_retired_bank(string $direction): void
    {
        $data = $this->checkIntent($direction);
        $action = app($direction === 'incoming' ? ReceiveCheckAction::class : IssueCheckAction::class);
        $check = $action->execute($this->company, $this->owner, $data);
        $this->usdBankAccount->delete();
        CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]);
        $this->assertSame($check->id, $action->execute($this->company, $this->owner, $data)->id);
        $this->event($check, 'cancel');
        app(CheckHistory::class)->validate($check->fresh());
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->assertSame($check->id, $action->execute($this->company, $this->owner, $data)->id);
    }

    public function test_return_after_clearance_failure_rolls_back_first_inverse_and_every_projection(): void
    {
        $check = $this->check();
        $this->event($check, 'deposit');
        $this->event($check->fresh(), 'clear', '3.60');
        $batchCount = PostingBatch::count();
        $eventCount = CheckEvent::count();
        $real = app(AccountingReversalService::class);
        $this->mock(AccountingReversalService::class)->shouldReceive('reverse')->once()->andReturnUsing(fn (...$args) => $real->reverse(...$args));
        app(AccountingReversalService::class)->shouldReceive('reverse')->once()->andThrow(new \RuntimeException('After clearance inverse'));
        try {
            $this->event($check->fresh(), 'return');
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('After clearance inverse', $e->getMessage());
        }
        $this->assertSame($batchCount, PostingBatch::count());
        $this->assertSame($eventCount, CheckEvent::count());
        $this->assertSame('cleared', $check->fresh()->status);
        $this->assertFalse($check->customerPayment->fresh()->is_reversed);
        app(CheckHistory::class)->validate($check->fresh());
    }

    public static function invalidTransitions(): array
    {
        return [['incoming', 'clear'], ['outgoing', 'deposit']];
    }

    #[DataProvider('invalidTransitions')]
    public function test_impossible_transition_has_no_effect(string $direction, string $type): void
    {
        $check = $this->check($direction);
        $count = PostingBatch::count();
        try {
            $this->event($check, $type);
            $this->fail('Expected rejection');
        } catch (\InvalidArgumentException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->assertSame($count, PostingBatch::count());
        $this->assertSame(1, $check->events()->count());
    }

    public function test_clearing_before_due_date_and_backdated_terminal_event_are_rejected(): void
    {
        $check = $this->check();
        $this->event($check, 'deposit', date: '2026-10-02');
        foreach (['clear', 'return'] as $type) {
            try {
                $this->event($check->fresh(), $type, date: $type === 'clear' ? '2026-10-02' : '2026-10-01');
                $this->fail('Expected chronology rejection');
            } catch (\InvalidArgumentException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
        $this->assertSame('deposited', $check->fresh()->status);
    }

    public static function corruptCheckFields(): array
    {
        return [['amount_base', '351.000000'], ['request_hash', str_repeat('a', 64)], ['status', 'cleared'], ['bank_name', 'Substituted bank']];
    }

    #[DataProvider('corruptCheckFields')]
    public function test_money_reconciliation_detects_check_corruption(string $field, string $value): void
    {
        $check = $this->check();
        DB::table('checks')->where('id', $check->id)->update([$field => $value]);
        $this->assertFalse(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public static function guardedModels(): array
    {
        return [[Check::class], [CheckEvent::class], [MoneyTransfer::class]];
    }

    #[DataProvider('guardedModels')]
    public function test_direct_financial_history_creation_fails(string $model): void
    {
        $this->expectException(\LogicException::class);
        $instance = new $model;
        $instance->forceFill(['company_id' => $this->company->id]);
        $instance->save();
    }
}
