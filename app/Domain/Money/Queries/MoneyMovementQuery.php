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
        // Window functions run before pagination; balances include originals AND their inverses.
        $rows = DB::table('posting_lines as l')->join('posting_batches as b', 'b.id', '=', 'l.posting_batch_id')
            ->where('l.company_id', $account->company_id)->where('b.company_id', $account->company_id)
            ->where('l.ledger_account_id', $account->ledger_account_id)
            ->select('l.id', 'l.description', 'l.debit_base', 'l.credit_base', 'l.transaction_amount', 'l.transaction_currency_code', 'b.posting_date', 'b.source_type', 'b.source_id', 'b.status', 'b.reversal_of_id')
            ->selectRaw('SUM(l.debit_base-l.credit_base) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS running_base')
            ->selectRaw('SUM(CASE WHEN l.transaction_currency_code = ? THEN CASE WHEN l.debit_base > 0 THEN l.transaction_amount ELSE -l.transaction_amount END ELSE 0 END) OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS known_running_currency', [$account->currency_code])
            ->selectRaw('SUM('.MoneyLedgerMetadata::unknownExpression().') OVER (ORDER BY b.posting_date, b.id, l.line_number, l.id) AS unknown_currency_lines', [$account->currency_code, $account->currency_code]);

        return DB::query()->fromSub($rows, 'movements')->orderByDesc('posting_date')->orderByDesc('id')
            ->paginate(max(1, min(100, $perPage)), ['*'], 'page', max(1, $page));
    }
}
