<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\CompanyCurrency;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use InvalidArgumentException;

/** Exact routing validation; historical reads do not require currently active configuration. */
final class MoneyAccountLedger
{
    public function validate(MoneyAccount $account, bool $forNewPosting = false): LedgerAccount
    {
        $key = match ($account->account_type) {
            MoneyAccount::TYPE_CASH => 'cash_control',
            MoneyAccount::TYPE_BANK => 'bank_control',
            default => throw new InvalidArgumentException('Unsupported money account type.'),
        };
        $ledger = LedgerAccount::where('company_id', $account->company_id)->whereKey($account->ledger_account_id)->first();
        $parent = $ledger === null ? null : LedgerAccount::where('company_id', $account->company_id)->whereKey($ledger->parent_id)->first();
        if ($ledger === null || $ledger->is_control || $ledger->account_type !== LedgerAccount::TYPE_ASSET
            || $ledger->normal_balance !== LedgerAccount::BALANCE_DEBIT || $parent === null
            || ! $parent->is_control || $parent->system_key !== $key
            || $parent->account_type !== LedgerAccount::TYPE_ASSET || $parent->normal_balance !== LedgerAccount::BALANCE_DEBIT) {
            throw new InvalidArgumentException('Money account must reference its exact same-company Cash/Bank asset child ledger.');
        }
        if ($forNewPosting && ($account->trashed() || ! $account->is_active || ! $ledger->active || ! $parent->active
            || ! CompanyCurrency::where('company_id', $account->company_id)->where('currency_code', $account->currency_code)->where('enabled', true)->exists())) {
            throw new InvalidArgumentException('Money account, ledger and currency must be enabled for new posting.');
        }

        return $ledger;
    }
}
