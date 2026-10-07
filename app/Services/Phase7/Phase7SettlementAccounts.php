<?php

declare(strict_types=1);

namespace App\Services\Phase7;

use App\Models\MoneyAccount;
use App\Services\Money\EligibleMoneyAccounts;
use Illuminate\Database\Eloquent\Builder;

final class Phase7SettlementAccounts
{
    /** @return Builder<MoneyAccount> */
    public function query(int $companyId, ?string $type = null): Builder
    {
        return app(EligibleMoneyAccounts::class)->query($companyId, $type)->whereExists(fn ($q) => $q->selectRaw('1')
            ->from('ledger_accounts as ml')->join('ledger_accounts as mp', 'mp.id', '=', 'ml.parent_id')
            ->whereColumn('ml.id', 'money_accounts.ledger_account_id')->where('ml.company_id', $companyId)->where('mp.company_id', $companyId)
            ->where('ml.active', true)->where('mp.active', true)->where('ml.is_control', false)->where('mp.is_control', true)
            ->where('ml.account_type', 'asset')->where('mp.account_type', 'asset')->where('ml.normal_balance', 'debit')->where('mp.normal_balance', 'debit')
            ->where(fn ($q) => $q->where(fn ($q) => $q->where('money_accounts.account_type', 'cash')->where('mp.system_key', 'cash_control'))
                ->orWhere(fn ($q) => $q->where('money_accounts.account_type', 'bank')->where('mp.system_key', 'bank_control'))));
    }
}
