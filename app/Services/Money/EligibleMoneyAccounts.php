<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\MoneyAccount;
use Illuminate\Database\Eloquent\Builder;

final class EligibleMoneyAccounts
{
    /** @return Builder<MoneyAccount> */
    public function query(int $companyId, ?string $type = null): Builder
    {
        return MoneyAccount::where('company_id', $companyId)->where('is_active', true)
            ->when($type !== null, fn ($q) => $q->where('account_type', $type))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('company_currencies as mc')->where('mc.company_id', $companyId)
                ->whereColumn('mc.currency_code', 'money_accounts.currency_code')->where('mc.enabled', true))
            ->orderBy('id');
    }
}
