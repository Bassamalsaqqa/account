<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Domain\Money\ValueObjects\ExchangeRate;
use App\Domain\Posting\DTO\PostingCommand;
use App\Domain\Posting\DTO\PostingLineCommand;
use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\MoneyTransfer;
use App\Models\PostingBatch;
use App\Models\User;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use InvalidArgumentException;

final class MoneyTransferHistory
{
    public function command(MoneyTransfer $transfer): PostingCommand
    {
        $company = Company::findOrFail($transfer->company_id);
        $intent = ['company_id' => (int) $transfer->company_id, 'actor_id' => (int) $transfer->created_by,
            'from_money_account_id' => (int) $transfer->from_money_account_id, 'to_money_account_id' => (int) $transfer->to_money_account_id,
            'transfer_date' => $transfer->transfer_date->toDateString(), 'from_amount' => $transfer->from_amount, 'to_amount' => $transfer->to_amount,
            'from_exchange_rate' => $transfer->from_exchange_rate, 'to_exchange_rate' => $transfer->to_exchange_rate, 'notes' => $transfer->notes];
        if (hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)) !== $transfer->request_hash) {
            throw new InvalidArgumentException('Transfer request identity is incoherent.');
        }
        if ($transfer->from_money_account_id === $transfer->to_money_account_id || ! $transfer->transfer_number
            || $transfer->base_currency_code !== $company->base_currency_code || ! $transfer->idempotency_key) {
            throw new InvalidArgumentException('Transfer identity is incoherent.');
        }
        foreach (['from', 'to'] as $side) {
            $account = MoneyAccount::withTrashed()->where('company_id', $company->id)->findOrFail($transfer->getAttribute($side.'_money_account_id'));
            $ledger = app(MoneyAccountLedger::class)->validate($account);
            if ((int) $ledger->id !== (int) $transfer->getAttribute($side.'_ledger_account_id') || $account->currency_code !== $transfer->getAttribute($side.'_currency_code')) {
                throw new InvalidArgumentException('Transfer account identity has changed.');
            }
        }
        $from = MoneyValues::amount($transfer->from_amount, $transfer->from_currency_code);
        $to = MoneyValues::amount($transfer->to_amount, $transfer->to_currency_code);
        $fromRate = MoneyValues::rate($transfer->from_exchange_rate, $transfer->from_currency_code, $company->base_currency_code);
        $toRate = MoneyValues::rate($transfer->to_exchange_rate, $transfer->to_currency_code, $company->base_currency_code);
        $fromBase = MoneyValues::base($from, $fromRate);
        $toBase = MoneyValues::base($to, $toRate);
        $delta = $toBase->minus($fromBase);
        if (! $fromBase->isEqualTo($transfer->base_value_from) || ! $toBase->isEqualTo($transfer->base_value_to)
            || ! $delta->isEqualTo($transfer->fx_gain_loss_base)
            || ($transfer->from_currency_code === $transfer->to_currency_code && (! $from->isEqualTo($to) || ! $fromRate->isEqualTo($toRate)))) {
            throw new InvalidArgumentException('Transfer amount/rate/base economics are incoherent.');
        }
        $lines = [
            PostingLineCommand::debit(1, (int) $transfer->to_ledger_account_id, (string) $toBase, $transfer->to_currency_code, (string) $to, (string) $toRate),
            PostingLineCommand::credit(2, (int) $transfer->from_ledger_account_id, (string) $fromBase, $transfer->from_currency_code, (string) $from, (string) $fromRate),
        ];
        if (! $delta->isZero()) {
            $fx = LedgerAccount::where('company_id', $company->id)->where('system_key', $delta->isPositive() ? 'fx_gain' : 'fx_loss')->firstOrFail();
            $lines[] = $delta->isPositive()
                ? PostingLineCommand::credit(3, (int) $fx->id, (string) $delta)
                : PostingLineCommand::debit(3, (int) $fx->id, (string) $delta->abs());
        }

        return new PostingCommand(
            company: $company, postingDate: Carbon::parse($transfer->transfer_date), sourceType: 'money_transfer', sourceId: (int) $transfer->id,
            transactionCurrencyCode: $transfer->from_currency_code, baseCurrencyCode: $transfer->base_currency_code,
            exchangeRate: ExchangeRate::from((string) $fromRate), idempotencyKey: 'money-transfer-'.$transfer->public_id,
            postedBy: User::findOrFail($transfer->created_by), description: 'Money Transfer '.$transfer->transfer_number,
            lines: $lines,
        );
    }

    public function validate(MoneyTransfer $transfer): void
    {
        $batch = PostingBatch::where('company_id', $transfer->company_id)->findOrFail($transfer->posting_batch_id);
        if ($transfer->posted_at === null || (int) $transfer->posted_by !== (int) $transfer->created_by
            || $batch->idempotency_key !== 'money-transfer-'.$transfer->public_id
            || (int) $batch->posted_by !== (int) $transfer->posted_by || ! $this->command($transfer)->matchesBatch($batch)) {
            throw new InvalidArgumentException('Transfer canonical posting history is incoherent.');
        }
        if ($transfer->is_reversed) {
            $reversal = PostingBatch::where('company_id', $transfer->company_id)->findOrFail($transfer->reversal_posting_batch_id);
            if ($transfer->reversed_at === null || $transfer->reversed_by === null || $transfer->reversal_date === null
                || $batch->status !== 'reversed' || (int) $batch->reversed_by_batch_id !== (int) $reversal->id
                || (int) $reversal->reversal_of_id !== (int) $batch->id || $reversal->source_type !== 'reversal' || $reversal->status !== 'posted'
                || (int) $reversal->source_id !== (int) $batch->id || $reversal->reversed_by_batch_id !== null
                || $reversal->posting_date->toDateString() !== $transfer->reversal_date->toDateString()
                || $transfer->reversal_date->lt($transfer->transfer_date) || (int) $reversal->posted_by !== (int) $transfer->reversed_by) {
                throw new InvalidArgumentException('Transfer reversal provenance is incoherent.');
            }
            $originalLines = $batch->lines()->orderBy('line_number')->get();
            $inverseLines = $reversal->lines()->orderBy('line_number')->get();
            if ($originalLines->count() !== $inverseLines->count()) {
                throw new InvalidArgumentException('Transfer reversal line count is incoherent.');
            }
            foreach ($originalLines as $index => $line) {
                $inverse = $inverseLines[$index];
                if ((int) $inverse->company_id !== (int) $transfer->company_id || (int) $inverse->ledger_account_id !== (int) $line->ledger_account_id
                    || ! BigDecimal::of($line->debit_base)->isEqualTo($inverse->credit_base) || ! BigDecimal::of($line->credit_base)->isEqualTo($inverse->debit_base)
                    || $line->transaction_currency_code !== $inverse->transaction_currency_code || $line->transaction_amount !== $inverse->transaction_amount
                    || $line->exchange_rate !== $inverse->exchange_rate) {
                    throw new InvalidArgumentException('Transfer reversal is not an exact inverse.');
                }
            }
        } elseif ($batch->status !== 'posted' || $batch->reversed_by_batch_id !== null || $transfer->reversal_posting_batch_id !== null
            || $transfer->reversal_date !== null || $transfer->reversed_at !== null || $transfer->reversed_by !== null) {
            throw new InvalidArgumentException('Transfer has unexpected reversal metadata.');
        }
    }
}
