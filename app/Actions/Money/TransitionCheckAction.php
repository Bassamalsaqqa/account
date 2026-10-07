<?php

declare(strict_types=1);

namespace App\Actions\Money;

use App\Actions\Purchasing\ReverseVendorPaymentAction;
use App\Actions\Sales\ReverseCustomerPaymentAction;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Check;
use App\Models\CheckEvent;
use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\PostingBatch;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\CheckHistory;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyEventOwner;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyValues;
use App\Services\Posting\AccountingPostingService;
use App\Services\Posting\AccountingReversalService;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesDocumentRules;
use Brick\Math\BigDecimal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TransitionCheckAction implements MoneyEventOwner
{
    private ?MoneyEventScope $activeScope = null;

    public function ownsScope(MoneyEventScope $scope): bool
    {
        return $this->activeScope === $scope;
    }

    /** @param array<string, mixed> $data */
    public function execute(Check $check, User $actor, array $data): CheckEvent
    {
        $key = ReceiptRequestValues::key($data['idempotency_key']);
        $type = (string) $data['event_type'];
        if (! in_array($type, ['deposit', 'clear', 'return', 'cancel'], true)) {
            throw new InvalidArgumentException('Unsupported Check transition.');
        }
        $date = (string) $data['event_date'];
        app(SalesDocumentRules::class)->date($date);
        $intent = ['company_id' => (int) $check->company_id, 'check_id' => (int) $check->id, 'actor_id' => (int) $actor->id,
            'event_type' => $type, 'event_date' => $date,
            'money_account_id' => isset($data['money_account_id']) ? ReceiptRequestValues::id($data['money_account_id']) : null,
            'exchange_rate' => $type === 'clear' ? ReceiptRequestValues::decimal($data['exchange_rate'], 10) : null, 'notes' => MoneyValues::text($data['notes'] ?? null)];
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($check, $actor, $key, $type, $date, $intent, $hash): CheckEvent {
            $company = Company::lockForUpdate()->findOrFail($check->company_id);
            if ((int) auth()->id() !== (int) $actor->id) {
                throw new AuthorizationException('Actor mismatch.');
            }
            $check = Check::where('company_id', $company->id)->lockForUpdate()->findOrFail($check->id);
            $incoming = $check->direction === 'incoming';
            app(MoneyActorGuard::class)->authorize((int) $company->id, 'money.check.'.($incoming ? 'incoming' : 'outgoing').'.manage');
            if (! $incoming) {
                app(MoneyActorGuard::class)->authorize((int) $company->id, 'purchasing.cost.view');
            }
            $existing = CheckEvent::where('company_id', $company->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new IdempotencyConflictException('Check transition key already has different intent.');
                }
                app(CheckHistory::class)->validate($check);

                return $existing;
            }
            app(CheckHistory::class)->validate($check);
            $events = $check->events()->lockForUpdate()->get();
            if ($date < $events->last()->event_date->toDateString()) {
                throw new InvalidArgumentException('Check transition cannot precede earlier activity.');
            }
            $bank = null;
            $ledger = null;
            $settlement = null;
            $gain = null;
            if (in_array($type, ['deposit', 'clear'], true)) {
                if ($type === 'deposit' && (! $incoming || $check->status !== 'received')) {
                    throw new InvalidArgumentException('Only an incoming Check in hand may be deposited.');
                }
                if ($type === 'clear' && ($check->status !== ($incoming ? 'deposited' : 'issued') || $date < $check->due_date->toDateString())) {
                    throw new InvalidArgumentException('Only an eligible due Check may clear.');
                }
                $bankId = $incoming ? ($type === 'deposit' ? $intent['money_account_id'] : $events->last()->money_account_id) : $check->drawn_money_account_id;
                if ($intent['money_account_id'] !== null && $type === 'clear' && (int) $intent['money_account_id'] !== (int) $bankId) {
                    throw new InvalidArgumentException('Clearance must use the recorded settlement Bank.');
                }
                $bank = MoneyAccount::where('company_id', $company->id)->lockForUpdate()->findOrFail($bankId);
                $ledger = app(MoneyAccountLedger::class)->validate($bank, true);
                if ($bank->account_type !== 'bank' || $bank->currency_code !== $check->currency_code) {
                    throw new InvalidArgumentException('Check Bank currency mismatch.');
                }
                if ($type === 'clear') {
                    $rate = MoneyValues::rate($intent['exchange_rate'], $check->currency_code, $company->base_currency_code);
                    $settlement = MoneyValues::base(MoneyValues::amount($check->amount, $check->currency_code), $rate);
                    $gain = $incoming ? $settlement->minus($check->amount_base) : BigDecimal::of($check->amount_base)->minus($settlement);
                }
            } elseif (! in_array($check->status, $incoming ? ['received', 'deposited', 'cleared'] : ['issued'], true) || ($check->status === 'cleared' && $type === 'cancel')) {
                throw new InvalidArgumentException('This Check cannot be returned/cancelled in its current state.');
            }
            $to = match ($type) {
                'deposit' => 'deposited', 'clear' => 'cleared', 'return' => 'returned', 'cancel' => 'cancelled'
            };
            $this->activeScope = $scope = app(MoneyEventScope::class);
            try {
                return $scope->within($this, (int) $company->id, $actor, function ($capability) use ($scope, $check, $actor, $key, $type, $date, $intent, $hash, $bank, $ledger, $settlement, $gain, $to, $incoming, $events): CheckEvent {
                    $scope->prepareCheck($capability, $check);
                    $values = ['public_id' => (string) Str::ulid(), 'company_id' => (int) $check->company_id, 'check_id' => (int) $check->id,
                        'event_type' => $type, 'from_status' => $check->status, 'to_status' => $to, 'event_date' => $date,
                        'money_account_id' => $bank?->id, 'ledger_account_id' => $ledger?->id, 'exchange_rate' => $intent['exchange_rate'],
                        'settlement_base' => $settlement === null ? null : (string) $settlement, 'fx_gain_loss_base' => $gain === null ? null : (string) $gain,
                        'idempotency_key' => $key, 'request_hash' => $hash, 'request_payload' => $intent, 'notes' => $intent['notes'], 'actor_id' => (int) $actor->id];
                    $scope->prepareRecord($capability, CheckEvent::class.':new', $values);
                    $event = CheckEvent::record($capability, $values);
                    $completion = ['company_id' => (int) $check->company_id, 'completed_at' => now()];
                    if ($type === 'clear') {
                        $command = app(CheckHistory::class)->clearance($check, $event);
                        $scope->prepareCommand($capability, $command);
                        $completion['posting_batch_id'] = app(AccountingPostingService::class)->post($command)->id;
                    } elseif (in_array($type, ['return', 'cancel'], true)) {
                        $clear = $events->firstWhere('event_type', 'clear');
                        if ($clear !== null) {
                            $original = PostingBatch::where('company_id', $check->company_id)->findOrFail($clear->posting_batch_id);
                            $scope->prepareReversal($capability, $original, $date);
                            $completion['reversal_posting_batch_id'] = app(AccountingReversalService::class)->reverse($original, $actor, $intent['notes'], $date)->id;
                        }
                        $payment = $incoming ? $check->customerPayment()->firstOrFail() : $check->vendorPayment()->firstOrFail();
                        $original = PostingBatch::where('company_id', $check->company_id)->findOrFail($payment->posting_batch_id);
                        $scope->prepareReversal($capability, $original, $date);
                        $payment = $incoming ? app(ReverseCustomerPaymentAction::class)->execute($payment, $actor, $intent['notes'], $date)
                            : app(ReverseVendorPaymentAction::class)->execute($payment, $actor, $intent['notes'], $date);
                        $completion['payment_reversal_posting_batch_id'] = $payment->reversal_posting_batch_id;
                    }
                    $scope->prepareRecord($capability, CheckEvent::class.':'.$event->id, $completion);
                    $event->complete($capability, $completion);
                    $state = ['company_id' => (int) $check->company_id, 'status' => $to];
                    $scope->prepareRecord($capability, Check::class.':'.$check->id, $state);
                    $check->complete($capability, $state);
                    app(CheckHistory::class)->validate($check);
                    app(AuditService::class)->log((int) $check->company_id, 'check.'.$type, 'Check lifecycle transition', (int) $actor->id, $check,
                        meta: ['check_id' => (int) $check->id, 'event_id' => (int) $event->id, 'lifecycle_action' => $type]);

                    return $event;
                });
            } finally {
                $this->activeScope = null;
            }
        });
    }
}
