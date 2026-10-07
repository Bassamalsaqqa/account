<?php

declare(strict_types=1);

namespace Tests\Feature\Phase6;

use App\Actions\Company\CreateCompanyAction;
use App\Actions\Money\IssueCheckAction;
use App\Actions\Money\PostMoneyTransferAction;
use App\Actions\Money\ReceiveCheckAction;
use App\Actions\Money\TransitionCheckAction;
use App\Actions\Sales\CreateMoneyAccountAction;
use App\Exceptions\NoActiveCompanyException;
use App\Models\Check;
use App\Models\LedgerAccount;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Money\MoneyReconciliationService;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

final class CheckEconomicsAndSecurityTest extends Phase6TestCase
{
    public static function fx(): array
    {
        return [['incoming', '3.60', '10.000000', 'fx_gain'], ['incoming', '3.40', '-10.000000', 'fx_loss'],
            ['outgoing', '3.60', '-10.000000', 'fx_loss'], ['outgoing', '3.40', '10.000000', 'fx_gain']];
    }

    #[DataProvider('fx')]
    public function test_check_clearance_relieves_historical_carrying_value_and_has_directional_fx(string $direction, string $rate, string $gain, string $key): void
    {
        $check = $this->check($direction);
        $this->assertSame(0, DB::table('posting_lines')->where('ledger_account_id', $this->usdBankAccount->ledger_account_id)->count());
        if ($direction === 'incoming') {
            $this->event($check, 'deposit');
        }
        $event = $this->event($check->fresh(), 'clear', $rate);
        $this->assertSame($gain, $event->fx_gain_loss_base);
        $fx = PostingBatch::findOrFail($event->posting_batch_id)->lines()->where('ledger_account_id', LedgerAccount::where('system_key', $key)->sole()->id)->sole();
        $this->assertSame('10.000000', $key === 'fx_gain' ? $fx->credit_base : $fx->debit_base);
        $control = LedgerAccount::where('system_key', $direction === 'incoming' ? 'checks_in_hand' : 'checks_issued')->sole();
        $line = PostingBatch::findOrFail($event->posting_batch_id)->lines()->where('ledger_account_id', $control->id)->sole();
        $this->assertSame('350.000000', $direction === 'incoming' ? $line->credit_base : $line->debit_base);
        $this->assertSame('100.000000', $line->transaction_amount);
        $this->assertSame('3.5000000000', $line->exchange_rate);
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public static function directions(): array
    {
        return [['incoming'], ['outgoing']];
    }

    #[DataProvider('directions')]
    public function test_jod_minimum_check_receipt_issue_and_clearance_remain_exact(string $direction): void
    {
        $bank = app(CreateMoneyAccountAction::class)->execute($this->company, $this->owner, ['account_type' => 'bank', 'currency_code' => 'JOD', 'name_ar' => 'JOD Bank']);
        $data = array_replace($this->checkIntent($direction), ['currency_code' => 'JOD', 'amount' => '0.001', 'exchange_rate' => '4.80', 'money_account_id' => $direction === 'outgoing' ? $bank->id : null]);
        $action = app($direction === 'incoming' ? ReceiveCheckAction::class : IssueCheckAction::class);
        $check = $action->execute($this->company, $this->owner, $data);
        $this->assertSame('0.001000', $check->amount);
        $this->assertSame('0.004800', $check->amount_base);
        $this->assertSame($check->id, $action->execute($this->company, $this->owner, $data)->id);
        if ($direction === 'incoming') {
            app(TransitionCheckAction::class)->execute($check, $this->owner, ['event_type' => 'deposit', 'event_date' => '2026-10-03', 'money_account_id' => $bank->id, 'idempotency_key' => 'jod-deposit']);
        }
        $this->event($check->fresh(), 'clear', '4.90');
        $this->assertTrue(app(MoneyReconciliationService::class)->reconcile($this->company)->isHealthy);
    }

    public static function deniedActors(): array
    {
        return [['guest'], ['mismatch'], ['no-permission'], ['inactive']];
    }

    #[DataProvider('deniedActors')]
    public function test_check_mutations_reject_invalid_actor_with_zero_effects(string $case): void
    {
        $data = $this->checkIntent();
        $actor = $this->customActor(['money.receipt.create', 'money.check.incoming.manage'], 'Check operator');
        if ($case === 'guest') {
            auth()->logout();
        } elseif ($case === 'mismatch') {
            $this->activate($actor);
        } elseif ($case === 'no-permission') {
            $actor->syncRoles([]);
            $this->activate($actor);
        } else {
            $this->activate($actor);
            DB::table('company_user')->where('user_id', $actor->id)->update(['status' => 'inactive']);
        }
        try {
            app(ReceiveCheckAction::class)->execute($this->company, $case === 'guest' || $case === 'mismatch' ? $this->owner : $actor, $data);
            $this->fail('Expected denial');
        } catch (AuthorizationException|NoActiveCompanyException $e) {
            $this->assertNotEmpty($e->getMessage());
        }
        $this->activate($this->owner);
        $this->assertSame(0, Check::count());
        $this->assertSame(0, PostingBatch::count());
    }

    public function test_foreign_account_and_party_fail_without_financial_side_effects(): void
    {
        app(CompanyContext::class)->clear();
        $foreignOwner = User::factory()->create();
        $foreign = app(CreateCompanyAction::class)->execute($foreignOwner, ['name_ar' => 'Other', 'base_currency_code' => 'ILS']);
        app(CompanyContext::class)->setCompany($foreign, $foreignOwner);
        $this->actingAs($foreignOwner);
        $bank = app(CreateMoneyAccountAction::class)->execute($foreign, $foreignOwner, ['account_type' => 'bank', 'currency_code' => 'USD', 'name_ar' => 'Foreign Bank']);
        $this->activate($this->owner);
        try {
            app(IssueCheckAction::class)->execute($this->company, $this->owner, array_replace($this->checkIntent('outgoing'), ['money_account_id' => $bank->id]));
            $this->fail('Foreign Bank');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, Check::count());
        }
        try {
            app(PostMoneyTransferAction::class)->execute($this->company, $this->owner, ['from_money_account_id' => $this->usdCashAccount->id, 'to_money_account_id' => $bank->id,
                'transfer_date' => '2026-10-03', 'from_amount' => '1', 'to_amount' => '1', 'from_exchange_rate' => '3.5', 'to_exchange_rate' => '3.5', 'idempotency_key' => 'foreign']);
            $this->fail('Foreign transfer');
        } catch (\InvalidArgumentException) {
            $this->assertSame(0, PostingBatch::count());
        }
    }
}
