<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Livewire\Pages\Money\CheckDetail;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\CompanyCurrency;
use App\Models\PostingBatch;
use App\Services\Money\CheckHistory;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;

class CheckDepositValidationTest extends Phase6TestCase
{
    private function intent(mixed $bankId = null, string $type = 'deposit'): array
    {
        return ['event_type' => $type, 'event_date' => '2026-10-03', 'idempotency_key' => 'deposit-boundary',
            'money_account_id' => $bankId, 'exchange_rate' => '3.60'];
    }

    private function snapshot(Check $check): array
    {
        $tables = ['check_events', 'posting_batches', 'posting_lines', 'customer_payments',
            'customer_payment_allocations', 'customer_payment_application_events', 'stock_movements', 'audit_events', 'document_sequences'];
        $state = ['check' => DB::table('checks')->where('id', $check->id)->get()->toJson()];
        foreach ($tables as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $state;
    }

    public static function missingSelections(): array
    {
        return [[null, 'required'], [0, 'min']];
    }

    #[DataProvider('missingSelections')]
    public function test_livewire_deposit_requires_bank_without_consuming_request_or_mutating_history(?int $bankId, string $rule): void
    {
        $check = $this->check();
        $page = Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')->set('bankId', $bankId);
        $key = $page->get('requestKey');
        $before = $this->snapshot($check);
        $page->call('recordTransition', 'deposit')->assertStatus(200)->assertHasErrors(['bankId' => $rule])
            ->assertSet('requestKey', $key);
        $this->assertSame($before, $this->snapshot($check));
        $this->assertSame('received', $check->fresh()->status);
        $this->assertFalse(CheckEvent::where('idempotency_key', $key)->exists());

        // The same UI request key remains usable after correcting the incomplete selection.
        $page->set('bankId', $this->usdBankAccount->id)->call('recordTransition', 'deposit')->assertHasNoErrors();
        $this->assertSame('deposited', $check->fresh()->status);
        $this->assertSame($this->usdBankAccount->id, CheckEvent::where('idempotency_key', $key)->sole()->money_account_id);
    }

    public static function malformedDomainIds(): array
    {
        return [['omitted'], ['null'], ['zero'], ['text']];
    }

    #[DataProvider('malformedDomainIds')]
    public function test_domain_rejects_absent_or_malformed_bank_with_zero_effects(string $kind): void
    {
        $check = $this->check();
        $data = $this->intent();
        if ($kind === 'omitted') {
            unset($data['money_account_id']);
        } elseif ($kind === 'zero') {
            $data['money_account_id'] = 0;
        } elseif ($kind === 'text') {
            $data['money_account_id'] = 'not-a-bank';
        }
        $before = $this->snapshot($check);
        try {
            app(TransitionCheckAction::class)->execute($check, $this->owner, $data);
            $this->fail('Invalid Bank must not deposit.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($check));
        $this->assertFalse(CheckEvent::where('idempotency_key', 'deposit-boundary')->exists());

        $event = app(TransitionCheckAction::class)->execute($check, $this->owner, $this->intent($this->usdBankAccount->id));
        $this->assertSame($this->usdBankAccount->id, $event->money_account_id);
    }

    public function test_valid_livewire_deposit_is_custody_only_and_clear_uses_recorded_bank_without_selection(): void
    {
        $check = $this->check();
        $batches = PostingBatch::count();
        $lines = DB::table('posting_lines')->count();
        $events = CheckEvent::count();
        $page = Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')
            ->set('bankId', $this->usdBankAccount->id)->call('recordTransition', 'deposit')->assertStatus(200)->assertHasNoErrors();
        $deposit = $check->events()->where('event_type', 'deposit')->sole();
        $this->assertSame('deposited', $check->fresh()->status);
        $this->assertSame($this->usdBankAccount->id, $deposit->money_account_id);
        $this->assertSame($this->usdBankAccount->ledger_account_id, $deposit->ledger_account_id);
        $this->assertNull($deposit->posting_batch_id);
        $this->assertSame($batches, PostingBatch::count());
        $this->assertSame($lines, DB::table('posting_lines')->count());
        $this->assertSame($events + 1, CheckEvent::count());
        $retry = app(TransitionCheckAction::class)->execute($check->fresh(), $this->owner,
            array_replace($this->intent($this->usdBankAccount->id), ['idempotency_key' => $deposit->idempotency_key, 'exchange_rate' => '']));
        $this->assertSame($deposit->id, $retry->id);
        $page->set('bankId', null)->set('eventDate', '2026-10-04')->set('rate', '3.60')->call('recordTransition', 'clear')->assertHasNoErrors();
        $clear = $check->events()->where('event_type', 'clear')->sole();
        $this->assertSame('cleared', $check->fresh()->status);
        $this->assertSame($deposit->money_account_id, $clear->money_account_id);
        $this->assertSame('360.000000', $clear->settlement_base);
        $this->assertSame('10.000000', $clear->fx_gain_loss_base);
        $this->assertSame($batches + 1, PostingBatch::count());
        $bankLine = $clear->posting_batch_id === null ? null : DB::table('posting_lines')->where('posting_batch_id', $clear->posting_batch_id)
            ->where('ledger_account_id', $this->usdBankAccount->ledger_account_id)->sole();
        $this->assertNotNull($bankLine);
        $this->assertSame('360.000000', $bankLine->debit_base);
        $this->assertSame('0.000000', $bankLine->credit_base);
        app(CheckHistory::class)->validate($check->fresh());
    }

    public static function invalidBanks(): array
    {
        return [['foreign'], ['cash'], ['wrong_currency'], ['inactive'], ['deleted'], ['disabled_currency']];
    }

    #[DataProvider('invalidBanks')]
    public function test_invalid_bank_selection_reports_controlled_error_without_effects(string $kind): void
    {
        $check = $this->check();
        $bank = $this->usdBankAccount;
        if ($kind === 'foreign') {
            app(CompanyContext::class)->clear();
            $other = app(CreateCompanyAction::class)->execute($this->owner, ['name_ar' => 'Other', 'base_currency_code' => 'ILS']);
            app(CompanyContext::class)->setCompany($other, $this->owner);
            $bank = app(CreateMoneyAccountAction::class)->execute($other, $this->owner, ['account_type' => 'bank', 'currency_code' => 'USD', 'name_ar' => 'Other Bank']);
            $this->activate($this->owner);
        } elseif ($kind === 'cash') {
            $bank = $this->usdCashAccount;
        } elseif ($kind === 'wrong_currency') {
            $bank = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['account_type' => 'bank', 'currency_code' => 'ILS', 'name_ar' => 'ILS Bank']);
        } elseif ($kind === 'inactive') {
            $bank->update(['is_active' => false]);
        } elseif ($kind === 'deleted') {
            $bank->delete();
        } else {
            CompanyCurrency::where('company_id', $this->company->id)->where('currency_code', 'USD')->update(['enabled' => false]);
        }
        $before = $this->snapshot($check);
        Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')
            ->set('bankId', $bank->id)->call('recordTransition', 'deposit')->assertStatus(200)->assertHasErrors('check');
        $this->assertSame($before, $this->snapshot($check));
        try {
            app(TransitionCheckAction::class)->execute($check, $this->owner, $this->intent($bank->id));
            $this->fail('Invalid Bank must not be accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot($check));
    }

    public function test_corrupt_deposit_without_bank_fails_integrity_and_clearance_with_no_repair(): void
    {
        $check = $this->check();
        $deposit = $this->event($check, 'deposit');
        // Disposable DB corruption only; immutable production history is never edited.
        DB::table('check_events')->where('id', $deposit->id)->update(['money_account_id' => null]);
        $before = $this->snapshot($check);
        foreach (['history', 'action'] as $path) {
            try {
                if ($path === 'history') {
                    app(CheckHistory::class)->validate($check->fresh());
                } else {
                    app(TransitionCheckAction::class)->execute($check->fresh(), $this->owner, $this->intent(null, 'clear'));
                }
                $this->fail('Corrupt deposit must fail closed.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('Check deposit history is missing its settlement Bank.', $exception->getMessage());
            }
        }
        Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')->set('rate', '3.60')
            ->call('recordTransition', 'clear')->assertStatus(200)->assertHasErrors('check');
        $this->assertSame($before, $this->snapshot($check));
        $this->assertSame('deposited', $check->fresh()->status);
    }

    public static function terminalTypes(): array
    {
        return [['return', 'returned'], ['cancel', 'cancelled']];
    }

    #[DataProvider('terminalTypes')]
    public function test_bank_selection_is_not_required_for_unrelated_terminal_transitions(string $type, string $status): void
    {
        $check = $this->check();
        Livewire::test(CheckDetail::class, ['publicId' => $check->public_id])->set('eventDate', '2026-10-03')->assertSet('bankId', null)
            ->call('recordTransition', $type)->assertStatus(200)->assertHasNoErrors();
        $this->assertSame($status, $check->fresh()->status);
    }
}
