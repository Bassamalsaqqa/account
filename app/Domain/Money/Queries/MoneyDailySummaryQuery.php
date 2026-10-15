<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use App\Models\Company;
use App\Models\MoneyAccount;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyLedgerMetadata;
use App\Services\Sales\SalesDocumentRules;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class MoneyDailySummaryQuery
{
    /**
     * @param  list<string>  $types
     * @return list<array<string, mixed>>
     */
    public function forTypes(Company $company, array $types, string $date): array
    {
        foreach ($types as $type) {
            app(MoneyActorGuard::class)->authorize((int) $company->id, match ($type) {
                'cash' => 'money.cash.view',
                'bank' => 'money.bank.view',
                default => throw new InvalidArgumentException('Unsupported money account type.'),
            });
        }
        app(SalesDocumentRules::class)->date($date);

        if ($types === []) {
            return [];
        }

        $company = Company::whereKey($company->id)->where('status', 'active')->firstOrFail();

        $accounts = MoneyAccount::withTrashed()
            ->where('company_id', $company->id)
            ->whereIn('account_type', $types)
            ->with('ledgerAccount.parent')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return $this->buildSummaries($company, $accounts, $date);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forType(Company $company, string $type, string $date): array
    {
        return $this->forTypes($company, [$type], $date);
    }

    /**
     * @return array<string, mixed>
     */
    public function forAccount(MoneyAccount $account, string $date): array
    {
        app(MoneyActorGuard::class)->account($account);
        app(SalesDocumentRules::class)->date($date);
        $account = MoneyAccount::withTrashed()->where('company_id', $account->company_id)->whereKey($account->id)->firstOrFail();
        app(MoneyActorGuard::class)->account($account);
        $company = Company::whereKey($account->company_id)->firstOrFail();

        $account->loadMissing('ledgerAccount.parent');

        $summaries = $this->buildSummaries($company, collect([$account]), $date);

        if ($summaries === []) {
            throw new InvalidArgumentException('Account summary not available.');
        }

        return $summaries[0];
    }

    /**
     * @param  Collection<int, MoneyAccount>  $accounts
     * @return list<array<string, mixed>>
     */
    private function buildSummaries(Company $company, Collection $accounts, string $date): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        foreach ($accounts as $account) {
            $ledger = $account->ledgerAccount;
            $parent = $ledger?->parent;
            $expectedParent = $account->account_type === 'cash' ? 'cash_control' : 'bank_control';
            if ($ledger === null || (int) $ledger->company_id !== (int) $company->id || $ledger->is_control
                || $ledger->account_type !== 'asset' || $ledger->normal_balance !== 'debit'
                || $parent === null || (int) $parent->company_id !== (int) $company->id
                || ! $parent->is_control || $parent->system_key !== $expectedParent || $parent->account_type !== 'asset' || $parent->normal_balance !== 'debit') {
                throw new InvalidArgumentException('Invalid money account ledger provenance.');
            }
        }

        $ledgerAccountIds = $accounts->pluck('ledger_account_id');
        $visible = app(MoneyMovementVisibility::class)->visibleBatchIds((int) $company->id);

        $query = DB::table('posting_lines as l')
            ->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->join('money_accounts as m', 'm.ledger_account_id', '=', 'l.ledger_account_id')
            ->leftJoinSub($visible, 'vis', 'vis.id', '=', 'b.id')
            ->where('m.company_id', $company->id)
            ->where('l.company_id', $company->id)
            ->where('b.company_id', $company->id)
            ->whereIn('l.ledger_account_id', $ledgerAccountIds)
            ->where('b.posting_date', '<=', $date);

        $aggregates = $query->groupBy('l.ledger_account_id')
            ->select('l.ledger_account_id')
            ->selectRaw('SUM(CASE WHEN vis.id IS NULL THEN 1 ELSE 0 END) AS hidden_count')
            ->selectRaw('SUM(CASE WHEN b.posting_date < ? THEN l.debit_base - l.credit_base ELSE 0 END) AS opening_base', [$date])
            ->selectRaw('SUM(CASE WHEN b.posting_date = ? THEN l.debit_base ELSE 0 END) AS inflow_base', [$date])
            ->selectRaw('SUM(CASE WHEN b.posting_date = ? THEN l.credit_base ELSE 0 END) AS outflow_base', [$date])
            ->selectRaw('SUM(l.debit_base - l.credit_base) AS closing_base')
            ->selectRaw('SUM(CASE WHEN l.transaction_currency_code IS NOT NULL AND l.transaction_currency_code <> m.currency_code THEN 1 ELSE 0 END) AS foreign_metadata_count')
            ->selectRaw('SUM('.str_replace('?', 'm.currency_code', MoneyLedgerMetadata::unknownExpression()).') AS unknown_count')
            ->selectRaw('SUM(CASE WHEN b.posting_date < ? AND l.transaction_currency_code = m.currency_code THEN CASE WHEN l.debit_base > 0 THEN l.transaction_amount ELSE -l.transaction_amount END ELSE 0 END) AS opening_currency', [$date])
            ->selectRaw('SUM(CASE WHEN b.posting_date = ? AND l.transaction_currency_code = m.currency_code AND l.debit_base > 0 THEN l.transaction_amount ELSE 0 END) AS inflow_currency', [$date])
            ->selectRaw('SUM(CASE WHEN b.posting_date = ? AND l.transaction_currency_code = m.currency_code AND l.credit_base > 0 THEN l.transaction_amount ELSE 0 END) AS outflow_currency', [$date])
            ->selectRaw('SUM(CASE WHEN l.transaction_currency_code = m.currency_code THEN CASE WHEN l.debit_base > 0 THEN l.transaction_amount ELSE -l.transaction_amount END ELSE 0 END) AS closing_currency')
            ->get()
            ->keyBy('ledger_account_id');

        $rows = [];
        foreach ($accounts as $account) {
            $agg = $aggregates->get($account->ledger_account_id);
            $hiddenCount = (int) ($agg->hidden_count ?? 0);
            $isAvailable = $hiddenCount === 0;

            $openingBase = BigDecimal::of((string) ($agg->opening_base ?? '0'))->toScale(6);
            $inflowBase = BigDecimal::of((string) ($agg->inflow_base ?? '0'))->toScale(6);
            $outflowBase = BigDecimal::of((string) ($agg->outflow_base ?? '0'))->toScale(6);
            $closingBase = BigDecimal::of((string) ($agg->closing_base ?? '0'))->toScale(6);

            $foreignMetadataCount = (int) ($agg->foreign_metadata_count ?? 0);
            $unknownCount = (int) ($agg->unknown_count ?? 0);

            $currencyAvailable = false;
            $openingCurrency = null;
            $inflowCurrency = null;
            $outflowCurrency = null;
            $closingCurrency = null;

            if ($isAvailable) {
                if ($account->currency_code === $company->base_currency_code && $foreignMetadataCount === 0) {
                    $currencyAvailable = true;
                    $openingCurrency = $openingBase;
                    $inflowCurrency = $inflowBase;
                    $outflowCurrency = $outflowBase;
                    $closingCurrency = $closingBase;
                } elseif ($foreignMetadataCount === 0 && $unknownCount === 0) {
                    $currencyAvailable = true;
                    $openingCurrency = BigDecimal::of((string) ($agg->opening_currency ?? '0'))->toScale(6);
                    $inflowCurrency = BigDecimal::of((string) ($agg->inflow_currency ?? '0'))->toScale(6);
                    $outflowCurrency = BigDecimal::of((string) ($agg->outflow_currency ?? '0'))->toScale(6);
                    $closingCurrency = BigDecimal::of((string) ($agg->closing_currency ?? '0'))->toScale(6);
                }
            }

            $rows[] = [
                'public_id' => $account->public_id,
                'account_id' => (int) $account->id,
                'name' => $account->displayName(),
                'account_type' => $account->account_type,
                'currency_code' => $account->currency_code,
                'base_currency_code' => $company->base_currency_code,
                'date' => $date,
                'is_available' => $isAvailable,
                'currency_available' => $currencyAvailable,
                'opening_base' => $isAvailable ? (string) $openingBase : null,
                'inflow_base' => $isAvailable ? (string) $inflowBase : null,
                'outflow_base' => $isAvailable ? (string) $outflowBase : null,
                'closing_base' => $isAvailable ? (string) $closingBase : null,
                'opening_currency' => ($isAvailable && $currencyAvailable) ? (string) $openingCurrency : null,
                'inflow_currency' => ($isAvailable && $currencyAvailable) ? (string) $inflowCurrency : null,
                'outflow_currency' => ($isAvailable && $currencyAvailable) ? (string) $outflowCurrency : null,
                'closing_currency' => ($isAvailable && $currencyAvailable) ? (string) $closingCurrency : null,
                'is_active' => (bool) $account->is_active && ! $account->trashed(),
                'bank_name' => $account->bank_name,
            ];
        }

        return $rows;
    }
}
