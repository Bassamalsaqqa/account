<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReverseMoneyTransferAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Domain\Money\Queries\MoneyBalanceQuery;
use App\Exceptions\IdempotencyConflictException;
use App\Models\CompanyCurrency;
use App\Models\DocumentSequence;
use App\Models\MoneyTransfer;
use App\Models\PostingBatch;
use App\Services\Money\MoneyEventCapability;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyReconciliationService;
use App\Services\Money\MoneyTransferHistory;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Phase5E\Phase5ETestCase;

class MoneyTransfersTest extends Phase5ETestCase
{
    public function test_same_currency_transfer_exact_gl_idempotency_and_reversal(): void
    {
        $transfer = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent());
        $lines = $transfer->postingBatch->lines()->orderBy('line_number')->get();
        $this->assertSame('12.345000', $lines[0]->debit_base);
        $this->assertSame('12.345000', $lines[1]->credit_base);
        $this->assertSame('3.450000', $lines[0]->transaction_amount);
        $this->assertSame($this->usdBankAccount->ledger_account_id, $lines[0]->ledger_account_id);
        $this->assertSame($this->usdCashAccount->ledger_account_id, $lines[1]->ledger_account_id);
        $this->assertSame('0.000000', $transfer->fx_gain_loss_base);
        $this->assertStringStartsWith('TRF-2026-', $transfer->transfer_number);
        $retry = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent());
        $this->assertSame($transfer->id, $retry->id);
        $this->assertSame(1, MoneyTransfer::count());
        $this->assertSame(2, DocumentSequence::where('document_type', DocumentSequence::TYPE_MONEY_TRANSFER)->firstOrFail()->next_number);
        $reversed = app(ReverseMoneyTransferAction::class)->execute($transfer, $this->owner, '2026-10-08', 'Operator correction');
        $this->assertTrue($reversed->is_reversed);
        $this->assertSame(2, PostingBatch::count());
        $this->assertSame($transfer->id, app(ReverseMoneyTransferAction::class)->execute($transfer, $this->owner)->id);
        $this->assertSame($transfer->id, app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent())->id);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $row = collect(app(MoneyBalanceQuery::class)->forType($this->company, 'cash'))->firstWhere('public_id', $this->usdCashAccount->public_id);
        $this->assertSame('0.000000', $row['balance_currency']);
    }

    public function test_base_cash_to_bank_transfer(): void
    {
        $bank = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, [
            'account_type' => 'bank', 'currency_code' => 'ILS', 'name_ar' => 'بنك',
        ]);
        $transfer = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, array_replace($this->intent(), [
            'from_money_account_id' => $this->ilsCashAccount->id, 'to_money_account_id' => $bank->id,
            'from_amount' => '0.01', 'to_amount' => '0.01', 'from_exchange_rate' => '1', 'to_exchange_rate' => '1',
        ]));
        $this->assertSame('0.010000', $transfer->base_value_from);
        $this->assertSame('0.000000', $transfer->fx_gain_loss_base);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public function test_failure_after_numbering_and_provisional_record_rolls_back_all_effects(): void
    {
        $this->mock(AccountingPostingService::class)->shouldReceive('post')->once()->andThrow(new \RuntimeException('Injected accounting failure'));
        try {
            app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent());
            $this->fail('Expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Injected accounting failure', $e->getMessage());
        }
        $this->assertSame(0, MoneyTransfer::count());
        $this->assertSame(0, PostingBatch::count());
        $this->assertSame(1, DocumentSequence::where('document_type', DocumentSequence::TYPE_MONEY_TRANSFER)->sole()->next_number);
        $this->expectException(\LogicException::class);
        app(MoneyEventScope::class)->assert(new MoneyEventCapability, (int) $this->company->id);
    }

    public function test_direct_batch_and_reversal_bypasses_are_rejected(): void
    {
        $transfer = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent());
        try {
            DB::transaction(fn () => app(AccountingPostingService::class)->post(app(MoneyTransferHistory::class)->command($transfer)));
            $this->fail('Direct batch bypass');
        } catch (\LogicException) {
            $this->assertSame(1, PostingBatch::count());
        }
        try {
            DB::transaction(fn () => app(AccountingReversalService::class)->reverse($transfer->postingBatch, $this->owner));
            $this->fail('Direct reversal bypass');
        } catch (\LogicException) {
            $this->assertFalse($transfer->fresh()->is_reversed);
        }
        $this->expectException(\LogicException::class);
        (new MoneyTransfer)->forceFill(['company_id' => $this->company->id])->save();
    }

    public function test_exact_retry_after_account_retirement_and_disabled_currency_and_corrupt_history_fails_closed(): void
    {
        $transfer = app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent());
        $this->usdCashAccount->delete();
        CompanyCurrency::where('currency_code', 'USD')->update(['enabled' => false]);
        $this->assertSame($transfer->id, app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent())->id);
        DB::table('money_transfers')->where('id', $transfer->id)->update(['base_value_from' => '99']);
        $this->assertFalse(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
        $this->expectException(\InvalidArgumentException::class);
        app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent());
    }

    public function test_changed_intent_conflicts_without_effects(): void
    {
        app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, $this->intent());
        try {
            app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, array_replace($this->intent(), ['notes' => 'Changed']));
            $this->fail('Expected conflict');
        } catch (IdempotencyConflictException) {
            $this->assertSame(1, MoneyTransfer::count());
            $this->assertSame(1, PostingBatch::count());
        }
    }

    /** @return array<string, mixed> */
    private function intent(): array
    {
        return [
            'from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $this->usdBankAccount->id,
            'transfer_date' => '2026-10-07', 'from_amount' => '3.45', 'to_amount' => '3.45',
            'from_exchange_rate' => '3.5782608696', 'to_exchange_rate' => '3.5782608696',
            'idempotency_key' => 'transfer-test',
        ];
    }
}
