<?php

declare(strict_types=1);

namespace App\Actions\Money;

use App\Exceptions\IdempotencyConflictException;
use App\Models\Company;
use App\Models\DocumentSequence;
use App\Models\MoneyAccount;
use App\Models\MoneyTransfer;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyEventCapability;
use App\Services\Money\MoneyEventOwner;
use App\Services\Money\MoneyEventScope;
use App\Services\Money\MoneyTransferHistory;
use App\Services\Money\MoneyValues;
use App\Services\Posting\AccountingPostingService;
use App\Services\Sales\DocumentSequenceService;
use App\Services\Sales\ReceiptRequestValues;
use App\Services\Sales\SalesActorGuard;
use App\Services\Sales\SalesDocumentRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class PostMoneyTransferAction implements MoneyEventOwner
{
    private ?MoneyEventScope $activeScope = null;

    public function ownsScope(MoneyEventScope $scope): bool
    {
        return $this->activeScope === $scope;
    }

    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, array $data): MoneyTransfer
    {
        app(SalesDocumentRules::class)->date((string) $data['transfer_date']);
        $intent = [
            'company_id' => (int) $company->id, 'actor_id' => (int) $actor->id,
            'from_money_account_id' => ReceiptRequestValues::id($data['from_money_account_id']),
            'to_money_account_id' => ReceiptRequestValues::id($data['to_money_account_id']),
            'transfer_date' => (string) $data['transfer_date'],
            'from_amount' => ReceiptRequestValues::decimal($data['from_amount'], 6),
            'to_amount' => ReceiptRequestValues::decimal($data['to_amount'], 6),
            'from_exchange_rate' => ReceiptRequestValues::decimal($data['from_exchange_rate'], 10),
            'to_exchange_rate' => ReceiptRequestValues::decimal($data['to_exchange_rate'], 10),
            'notes' => MoneyValues::text($data['notes'] ?? null),
        ];
        $key = ReceiptRequestValues::key($data['idempotency_key'] ?? null);
        $hash = hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($company, $actor, $intent, $key, $hash): MoneyTransfer {
            $company = app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'money.transfer.create');
            $existing = MoneyTransfer::where('company_id', $company->id)->where('idempotency_key', $key)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->request_hash !== $hash) {
                    throw new IdempotencyConflictException('Transfer key was already used with different intent.');
                }
                app(MoneyTransferHistory::class)->validate($existing);

                return $existing;
            }
            if ($intent['from_money_account_id'] === $intent['to_money_account_id']) {
                throw new InvalidArgumentException('Transfer accounts must be different.');
            }
            $accounts = MoneyAccount::where('company_id', $company->id)->whereIn('id', [$intent['from_money_account_id'], $intent['to_money_account_id']])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $from = $accounts->get($intent['from_money_account_id']);
            $to = $accounts->get($intent['to_money_account_id']);
            if ($from === null || $to === null) {
                throw new InvalidArgumentException('Transfer requires same-company eligible MoneyAccounts.');
            }
            $fromLedger = app(MoneyAccountLedger::class)->validate($from, true);
            $toLedger = app(MoneyAccountLedger::class)->validate($to, true);
            $fromAmount = MoneyValues::amount($intent['from_amount'], $from->currency_code);
            $toAmount = MoneyValues::amount($intent['to_amount'], $to->currency_code);
            $fromRate = MoneyValues::rate($intent['from_exchange_rate'], $from->currency_code, $company->base_currency_code);
            $toRate = MoneyValues::rate($intent['to_exchange_rate'], $to->currency_code, $company->base_currency_code);
            if ($from->currency_code === $to->currency_code && (! $fromAmount->isEqualTo($toAmount) || ! $fromRate->isEqualTo($toRate))) {
                throw new InvalidArgumentException('Same-currency transfer uses one exact amount and rate.');
            }
            $fromBase = MoneyValues::base($fromAmount, $fromRate);
            $toBase = MoneyValues::base($toAmount, $toRate);
            $number = app(DocumentSequenceService::class)->generateNextNumber((int) $company->id, DocumentSequence::TYPE_MONEY_TRANSFER, (int) substr($intent['transfer_date'], 0, 4));
            $attributes = [
                'public_id' => (string) Str::ulid(), 'company_id' => (int) $company->id, 'transfer_number' => $number,
                'transfer_date' => $intent['transfer_date'], 'from_money_account_id' => (int) $from->id, 'to_money_account_id' => (int) $to->id,
                'from_ledger_account_id' => (int) $fromLedger->id, 'to_ledger_account_id' => (int) $toLedger->id,
                'from_currency_code' => $from->currency_code, 'to_currency_code' => $to->currency_code,
                'from_amount' => (string) $fromAmount, 'to_amount' => (string) $toAmount,
                'from_exchange_rate' => (string) $fromRate, 'to_exchange_rate' => (string) $toRate,
                'from_account_snapshot' => $from->only(['name_ar', 'name_en']), 'to_account_snapshot' => $to->only(['name_ar', 'name_en']),
                'base_currency_code' => $company->base_currency_code, 'base_value_from' => (string) $fromBase, 'base_value_to' => (string) $toBase,
                'fx_gain_loss_base' => (string) $toBase->minus($fromBase)->toScale(6), 'notes' => $intent['notes'],
                'idempotency_key' => $key, 'request_hash' => $hash, 'created_by' => (int) $actor->id,
            ];
            $scope = app(MoneyEventScope::class);
            $this->activeScope = $scope;
            try {
                return $scope->within($this, (int) $company->id, $actor, function (MoneyEventCapability $capability) use ($scope, $attributes, $actor, $company): MoneyTransfer {
                    $scope->prepareRecord($capability, MoneyTransfer::class.':new', $attributes);
                    $transfer = MoneyTransfer::record($capability, $attributes);
                    $command = app(MoneyTransferHistory::class)->command($transfer);
                    $scope->prepareCommand($capability, $command);
                    $batch = app(AccountingPostingService::class)->post($command);
                    $completion = ['company_id' => (int) $company->id, 'posting_batch_id' => (int) $batch->id, 'posted_by' => (int) $actor->id, 'posted_at' => now()];
                    $scope->prepareRecord($capability, MoneyTransfer::class.':'.$transfer->id, $completion);
                    $transfer->complete($capability, $completion);
                    app(MoneyTransferHistory::class)->validate($transfer);
                    app(AuditService::class)->log((int) $company->id, 'money_transfer.posted', 'Money transfer posted', (int) $actor->id, $transfer,
                        meta: ['transfer_id' => (int) $transfer->id, 'transfer_number' => $transfer->transfer_number]);

                    return $transfer->fresh();
                });
            } finally {
                $this->activeScope = null;
            }
        });
    }
}
