<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Domain\Posting\Exceptions\PostingValidationException;
use App\Models\Check;
use App\Models\CompanyCurrency;
use App\Models\LedgerAccount;
use App\Services\Money\CheckHistory;
use App\Services\Posting\AccountingPostingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class CheckFrozenBankValidationTest extends Phase6TestCase
{
    private function retire(string $mode): void
    {
        match ($mode) {
            'inactive' => $this->usdBankAccount->update(['is_active' => false]),
            'deleted' => $this->usdBankAccount->delete(),
            'currency' => CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]),
            'ledger' => LedgerAccount::whereKey($this->usdBankAccount->ledger_account_id)->update(['active' => false]),
            'parent' => LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'bank_control')->update(['active' => false]),
        };
    }

    private function snapshot(): array
    {
        $state = [];
        foreach (['checks', 'check_events', 'posting_batches', 'posting_lines', 'customer_payments', 'vendor_payments', 'audit_events', 'document_sequences'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }

    public static function retirementModes(): array
    {
        $cases = [];
        foreach (['incoming', 'outgoing'] as $direction) {
            foreach (['inactive', 'deleted', 'currency', 'ledger', 'parent'] as $mode) {
                $cases[$direction.'/'.$mode] = [$direction, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('retirementModes')]
    public function test_clearance_uses_frozen_bank_after_retirement(string $direction, string $mode): void
    {
        $check = $this->check($direction);
        if ($direction === 'incoming') {
            $this->event($check, 'deposit');
        }
        $ledgerId = $this->usdBankAccount->ledger_account_id;
        $this->retire($mode);
        $event = $this->event($check->fresh(), 'clear', '3.60');

        $this->assertSame('cleared', $check->fresh()->status);
        $this->assertSame($this->usdBankAccount->id, $event->money_account_id);
        $this->assertSame($ledgerId, $event->ledger_account_id);
        $this->assertSame('360.000000', $event->settlement_base);
        $this->assertSame($direction === 'incoming' ? '10.000000' : '-10.000000', $event->fx_gain_loss_base);
        $bankLine = DB::table('posting_lines')->where('posting_batch_id', $event->posting_batch_id)->where('ledger_account_id', $ledgerId)->sole();
        $this->assertSame($direction === 'incoming' ? '360.000000' : '0.000000', $bankLine->debit_base);
        $this->assertSame($direction === 'incoming' ? '0.000000' : '360.000000', $bankLine->credit_base);
        $this->assertSame('100.000000', $bankLine->transaction_amount);
        $this->assertSame('USD', $bankLine->transaction_currency_code);
        $this->assertEquals(DB::table('posting_lines')->where('posting_batch_id', $event->posting_batch_id)->sum('debit_base'),
            DB::table('posting_lines')->where('posting_batch_id', $event->posting_batch_id)->sum('credit_base'));
        app(CheckHistory::class)->validate($check->fresh());
        // Clearance does not reactivate retired configuration.
        $this->assertSame($mode === 'deleted', $this->usdBankAccount->fresh()->trashed());
        if ($mode === 'inactive') {
            $this->assertFalse($this->usdBankAccount->fresh()->is_active);
        } elseif ($mode === 'currency') {
            $this->assertFalse(CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->sole()->enabled);
        } elseif ($mode === 'ledger' || $mode === 'parent') {
            $ledger = $mode === 'ledger' ? LedgerAccount::findOrFail($ledgerId) : LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'bank_control')->sole();
            $this->assertFalse($ledger->active);
        }
    }

    #[DataProvider('retirementModes')]
    public function test_retired_bank_is_still_rejected_for_new_selection(string $direction, string $mode): void
    {
        $check = $direction === 'incoming' ? $this->check() : null;
        $intent = $direction === 'outgoing' ? $this->checkIntent('outgoing') : null;
        $this->retire($mode);
        $before = $this->snapshot();
        try {
            if ($check !== null) {
                app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => 'deposit', 'event_date' => '2026-10-03',
                    'money_account_id' => $this->usdBankAccount->id, 'idempotency_key' => 'strict-bank']);
            } else {
                app(IssueCheckAction::class)->execute($this->company, $this->owner, $intent);
            }
            $this->fail('Retired Bank cannot be selected for a new route.');
        } catch (InvalidArgumentException|ModelNotFoundException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public static function corruptRoutes(): array
    {
        $cases = [];
        foreach (['incoming', 'outgoing'] as $direction) {
            foreach (['type', 'currency', 'ledger', 'parent', 'substitution'] as $mode) {
                $cases[$direction.'/'.$mode] = [$direction, $mode];
            }
        }

        return $cases;
    }

    #[DataProvider('corruptRoutes')]
    public function test_structural_corruption_or_bank_substitution_fails_closed(string $direction, string $mode): void
    {
        $check = $this->check($direction);
        if ($direction === 'incoming') {
            $this->event($check, 'deposit');
        }
        $data = ['event_type' => 'clear', 'event_date' => '2026-10-03', 'exchange_rate' => '3.60', 'idempotency_key' => 'corrupt-bank'];
        if ($mode === 'type') {
            $this->usdBankAccount->update(['account_type' => 'cash']);
        } elseif ($mode === 'currency') {
            $this->usdBankAccount->update(['currency_code' => 'ILS']);
        } elseif ($mode === 'ledger') {
            $this->usdBankAccount->update(['ledger_account_id' => LedgerAccount::where('company_id', $this->company->id)->where('system_key', 'cash_control')->sole()->id]);
        } elseif ($mode === 'parent') {
            LedgerAccount::whereKey($this->usdBankAccount->ledger_account_id)->update(['parent_id' => null]);
        } else {
            $other = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['account_type' => 'bank', 'currency_code' => 'USD', 'name_ar' => 'Substitute']);
            $data['money_account_id'] = $other->id;
        }
        $before = $this->snapshot();
        try {
            app(TransitionCheckAction::class)->execute($check->fresh(), $this->owner, $data);
            $this->fail('Corrupt or substituted route must fail closed.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($direction === 'incoming' ? 'deposited' : 'issued', Check::findOrFail($check->id)->status);
    }

    public static function unrelatedInactiveLedgers(): array
    {
        return [['incoming', 'checks_in_hand'], ['incoming', 'fx_gain'], ['outgoing', 'checks_issued'], ['outgoing', 'fx_loss']];
    }

    #[DataProvider('unrelatedInactiveLedgers')]
    public function test_frozen_route_exception_does_not_relax_unrelated_ledger_eligibility(string $direction, string $key): void
    {
        $check = $this->check($direction);
        if ($direction === 'incoming') {
            $this->event($check, 'deposit');
        }
        $this->retire('ledger');
        LedgerAccount::where('company_id', $this->company->id)->where('system_key', $key)->update(['active' => false]);
        $before = $this->snapshot();
        try {
            $this->event($check->fresh(), 'clear', '3.60');
            $this->fail('Only the frozen Bank ledger may bypass current-active eligibility.');
        } catch (PostingValidationException $exception) {
            $this->assertStringContainsString('inactive', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_clearance_command_cannot_bypass_canonical_transition_authority(): void
    {
        $check = $this->check();
        $this->event($check, 'deposit');
        $clear = $this->event($check->fresh(), 'clear', '3.60');
        $this->retire('ledger');
        $before = $this->snapshot();
        try {
            DB::transaction(fn () => app(AccountingPostingService::class)->post(app(CheckHistory::class)->clearance($check->fresh(), $clear)));
            $this->fail('Source type alone must not authorize historical-route posting.');
        } catch (\LogicException $exception) {
            $this->assertStringContainsString('capability', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot());
    }
}
