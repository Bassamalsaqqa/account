<?php

declare(strict_types=1);

namespace App\Domain\Money\Queries;

use App\Models\MoneyAccount;
use App\Services\Money\MoneyActorGuard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class MoneyActivityQuery
{
    /** @param list<string> $types
     * @return Collection<int, \stdClass>
     */
    public function recent(int $companyId, array $types): Collection
    {
        foreach ($types as $type) {
            app(MoneyActorGuard::class)->authorize($companyId, match ($type) {
                'cash' => 'money.cash.view', 'bank' => 'money.bank.view', default => throw new \InvalidArgumentException('Unknown Money account type.')
            });
        }
        $accounts = MoneyAccount::withTrashed()->where('company_id', $companyId)->whereIn('account_type', $types)->pluck('ledger_account_id');

        return DB::table('posting_batches as b')->join('posting_lines as l', 'l.posting_batch_id', '=', 'b.id')
            ->join('money_accounts as m', 'm.ledger_account_id', '=', 'l.ledger_account_id')
            ->where('b.company_id', $companyId)->where('l.company_id', $companyId)->where('m.company_id', $companyId)->whereIn('l.ledger_account_id', $accounts)
            ->select('l.id', 'm.public_id as account_public_id', 'm.name_ar', 'm.name_en', 'm.currency_code', 'b.posting_date', 'b.source_type', 'b.source_id', 'b.reversal_of_id', 'l.description', 'l.debit_base', 'l.credit_base', 'l.transaction_amount', 'l.transaction_currency_code')
            ->orderByDesc('b.posting_date')->orderByDesc('b.id')->orderByDesc('l.id')->limit(12)->get();
    }
}
