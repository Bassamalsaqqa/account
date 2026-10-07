<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use App\Models\Company;
use App\Models\MoneyAccount;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyLedgerMetadata;
use App\Services\Sales\SalesDocumentRules;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MoneyBalanceQuery
{
    /** @return list<array<string, mixed>> */
    public function forType(Company $company, string $type, ?string $asOf = null): array
    {
        app(MoneyActorGuard::class)->authorize((int) $company->id, match ($type) {
            'cash' => 'money.cash.view', 'bank' => 'money.bank.view',
            default => throw new InvalidArgumentException('Unsupported money account type.'),
        });
        if ($asOf !== null) {
            app(SalesDocumentRules::class)->date($asOf);
        }
        $accounts = MoneyAccount::withTrashed()->where('company_id', $company->id)->where('account_type', $type)
            ->with('ledgerAccount.parent')->orderBy('sort_order')->orderBy('id')->get();
        $query = DB::table('posting_lines as l')->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->join('money_accounts as m', 'm.ledger_account_id', '=', 'l.ledger_account_id')->where('m.company_id', $company->id)
            ->where('l.company_id', $company->id)->where('b.company_id', $company->id)
            ->whereIn('l.ledger_account_id', $accounts->pluck('ledger_account_id'));
        if ($asOf !== null) {
            $query->where('b.posting_date', '<=', $asOf);
        }
        $totals = (clone $query)->selectRaw('l.ledger_account_id, SUM(l.debit_base-l.credit_base) AS base_balance')
            ->selectRaw('SUM('.str_replace('?', 'm.currency_code', MoneyLedgerMetadata::unknownExpression()).') AS unknown_count')
            ->groupBy('l.ledger_account_id')->get()->keyBy('ledger_account_id');
        $currencyTotals = (clone $query)->selectRaw('l.ledger_account_id, l.transaction_currency_code, SUM(CASE WHEN l.debit_base > 0 THEN l.transaction_amount ELSE -l.transaction_amount END) AS currency_balance, COUNT(*) AS line_count')
            ->groupBy('l.ledger_account_id', 'l.transaction_currency_code')->get()->groupBy('ledger_account_id');
        $rows = [];
        foreach ($accounts as $account) {
            $ledger = $account->ledgerAccount;
            $parent = $ledger?->parent;
            // Use eager-loaded identity, avoiding one validation query for every account.
            $expectedParent = $type === 'cash' ? 'cash_control' : 'bank_control';
            if ($ledger === null || (int) $ledger->company_id !== (int) $company->id || $ledger->is_control
                || $ledger->account_type !== 'asset' || $ledger->normal_balance !== 'debit'
                || $parent === null || (int) $parent->company_id !== (int) $company->id
                || ! $parent->is_control || $parent->system_key !== $expectedParent || $parent->account_type !== 'asset' || $parent->normal_balance !== 'debit') {
                throw new InvalidArgumentException('Invalid money account ledger provenance.');
            }
            $base = BigDecimal::of((string) ($totals->get($account->ledger_account_id)->base_balance ?? '0'))->toScale(6);
            $currency = BigDecimal::zero();
            $available = true;
            $hasForeignMetadata = false;
            foreach ($currencyTotals->get($account->ledger_account_id, collect()) as $group) {
                if ($group->transaction_currency_code !== $account->currency_code) {
                    $available = false;
                    $hasForeignMetadata = $hasForeignMetadata || $group->transaction_currency_code !== null;
                } else {
                    $currency = $currency->plus((string) $group->currency_balance);
                }
            }
            // A base-currency account's base ledger balance is its native balance even for old base-only opening entries.
            if ($account->currency_code === $company->base_currency_code && ! $hasForeignMetadata) {
                $currency = $base;
                $available = true;
            } elseif (! $hasForeignMetadata && (int) ($totals->get($account->ledger_account_id)->unknown_count ?? 0) === 0) {
                $available = true;
            }
            $rows[] = [
                'public_id' => $account->public_id, 'name' => $account->displayName(), 'account_type' => $type,
                'currency_code' => $account->currency_code, 'base_currency_code' => $company->base_currency_code,
                'balance_base' => (string) $base, 'balance_currency' => $available ? (string) $currency->toScale(6) : null,
                'currency_balance_available' => $available,
                'is_active' => $account->is_active && ! $account->trashed(),
                'bank_name' => $account->bank_name,
            ];
        }

        return $rows;
    }
}
