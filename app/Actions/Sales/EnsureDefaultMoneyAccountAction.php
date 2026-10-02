<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Company;
use App\Models\MoneyAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class EnsureDefaultMoneyAccountAction
{
    public function __construct(
        protected CreateMoneyAccountAction $createMoneyAccountAction,
    ) {}

    /**
     * Idempotently ensure the company has at least a default base-currency cash account.
     */
    public function execute(Company $company, ?User $user = null): MoneyAccount
    {
        return DB::transaction(function () use ($company, $user): MoneyAccount {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            $existing = MoneyAccount::where('company_id', $company->id)
                ->where('account_type', MoneyAccount::TYPE_CASH)
                ->where('currency_code', $company->base_currency_code)
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $owner = $user ?? $company->users()->wherePivot('is_owner', true)->wherePivot('status', 'active')->first();
            if ($owner === null) {
                throw new \InvalidArgumentException('Company active owner is required for default cash configuration.');
            }

            return $this->createMoneyAccountAction->provisionDefault($company, $owner);
        });
    }
}
