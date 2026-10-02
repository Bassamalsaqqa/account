<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Company;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class UpdateMoneyAccountAction
{
    /** @param array<string, mixed> $data */
    public function execute(Company $company, User $actor, int $id, array $data): MoneyAccount
    {
        return DB::transaction(function () use ($company, $actor, $id, $data): MoneyAccount {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $actor, 'settings.money_accounts.manage');
            $account = MoneyAccount::where('company_id', $company->id)->lockForUpdate()->findOrFail($id);
            foreach (['ledger_account_id', 'account_type', 'currency_code'] as $identity) {
                if (array_key_exists($identity, $data) && (string) $data[$identity] !== (string) $account->$identity) {
                    throw new \InvalidArgumentException('Money account identity, type and currency cannot change.');
                }
            }
            $values = Validator::make($data, [
                'name_ar' => ['required', 'string', 'max:255'],
                'name_en' => ['nullable', 'string', 'max:255'],
                'bank_name' => ['nullable', 'string', 'max:255'],
                'account_number' => ['nullable', 'string', 'max:100'],
                'iban' => ['nullable', 'string', 'max:100'],
                'sort_order' => ['required', 'integer', 'min:0'],
                'is_active' => ['required', 'boolean'],
            ])->validate();
            if (trim($values['name_ar']) === '') {
                throw new \InvalidArgumentException('Money account name is required.');
            }
            $ledger = LedgerAccount::where('company_id', $company->id)->lockForUpdate()->findOrFail($account->ledger_account_id);
            $parent = LedgerAccount::where('company_id', $company->id)->where('system_key', $account->account_type === 'cash' ? 'cash_control' : 'bank_control')->firstOrFail();
            if ($ledger->is_control || $ledger->account_type !== LedgerAccount::TYPE_ASSET || $ledger->normal_balance !== LedgerAccount::BALANCE_DEBIT || (int) $ledger->parent_id !== (int) $parent->id) {
                throw new \InvalidArgumentException('Money account ledger linkage is inconsistent.');
            }
            $before = $account->only(array_keys($values));
            $account->update($values);
            $ledger->update(['name_ar' => $account->name_ar, 'name_en' => $account->name_en]);
            app(AuditService::class)->log((int) $company->id, 'settings.money_account.updated', 'Money account configuration updated', $actor->id, $account, $before, $values);

            return $account;
        });
    }
}
