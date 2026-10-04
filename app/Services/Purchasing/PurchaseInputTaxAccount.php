<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Models\LedgerAccount;
use InvalidArgumentException;

final class PurchaseInputTaxAccount
{
    public function resolve(int $companyId, ?int $id): ?LedgerAccount
    {
        if ($id === null) {
            return null;
        }
        $account = LedgerAccount::where('company_id', $companyId)->lockForUpdate()->find($id);
        $parent = LedgerAccount::where('company_id', $companyId)->where('system_key', 'tax_input')->lockForUpdate()->first();
        if ($account === null || ! $account->active || $account->is_control
            || $account->account_type !== LedgerAccount::TYPE_ASSET
            || $account->normal_balance !== LedgerAccount::BALANCE_DEBIT
            || $parent === null || ! $parent->active
            || ((int) $account->id !== (int) $parent->id && (int) $account->parent_id !== (int) $parent->id)) {
            throw new InvalidArgumentException(__('purchasing.invalid_purchase_tax_account'));
        }

        return $account;
    }
}
