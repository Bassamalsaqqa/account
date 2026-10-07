<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use App\Models\MoneyAccount;
use App\Services\Money\MoneyAccountLedger;
use App\Services\Money\MoneyActorGuard;
use App\Services\Money\MoneyLedgerMetadata;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class MoneyMovementQuery
{
    /** @return LengthAwarePaginator<int, \stdClass> */
    public function forAccount(MoneyAccount $account, int $page = 1, int $perPage = 30): LengthAwarePaginator
    {
        app(MoneyActorGuard::class)->account($account);
        app(MoneyAccountLedger::class)->validate($account);
        $visible = app(MoneyMovementVisibility::class)->visibleBatchIds((int) $account->company_id);
        $history = DB::table('posting_lines as l')->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->where('l.company_id', $account->company_id)->where('b.company_id', $account->company_id)
            ->where('l.ledger_account_id', $account->ledger_account_id);
        // Visibility and full-history windows share one SQL snapshot, including concurrent new legs.
        $rows = $history
            ->select('l.id', 'l.description', 'l.debit_base', 'l.credit_base', 'l.transaction_amount', 'l.transaction_currency_code', 'b.posting_date', 'b.source_type', 'b.source_id', 'b.status', 'b.reversal_of_id', 'b.id as visibility_batch_id')
            ->selectRaw('MAX(CASE WHEN b.id IN ('.$visible->toSql().') THEN 0 ELSE 1 END) OVER () AS has_hidden_history', $visible->getBindings())
            ->selectRaw('SUM(l.debit_base-l.credit_base) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS running_base')
            ->selectRaw('SUM(CASE WHEN l.transaction_currency_code = ? THEN CASE WHEN l.debit_base > 0 THEN l.transaction_amount ELSE -l.transaction_amount END ELSE 0 END) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS known_running_currency', [$account->currency_code])
            ->selectRaw('SUM('.MoneyLedgerMetadata::unknownExpression().') OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS unknown_currency_lines', [$account->currency_code, $account->currency_code]);

        // The public projection contains neither restricted rows nor raw window/internal visibility values.
        return DB::query()->fromSub($rows, 'movements')->whereIn('visibility_batch_id', $visible)
            ->select('id', 'description', 'debit_base', 'credit_base', 'transaction_amount', 'transaction_currency_code', 'posting_date', 'source_type', 'source_id', 'status', 'reversal_of_id')
            ->selectRaw('CASE WHEN has_hidden_history = 0 THEN running_base ELSE NULL END AS running_base')
            ->selectRaw('CASE WHEN has_hidden_history = 0 THEN known_running_currency ELSE NULL END AS known_running_currency')
            ->selectRaw('CASE WHEN has_hidden_history = 0 THEN unknown_currency_lines ELSE NULL END AS unknown_currency_lines')
            ->orderByDesc('posting_date')->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)), ['*'], 'page', max(1, $page));
    }
}
