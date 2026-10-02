<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\Company;
use App\Models\CompanyCurrency;
use App\Models\CompanyUser;
use App\Models\LedgerAccount;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CreateMoneyAccountAction
{
    /**
     * Atomically create a MoneyAccount and its canonical underlying child ledger account.
     *
     * @param  array{
     *     account_type: string,
     *     name_ar: string,
     *     name_en?: ?string,
     *     currency_code: string,
     *     bank_name?: ?string,
     *     account_number?: ?string,
     *     iban?: ?string,
     *     sort_order?: int,
     * }  $data
     */
    public function execute(Company $company, User $user, array $data): MoneyAccount
    {
        return DB::transaction(function () use ($company, $user, $data): MoneyAccount {
            app(SalesActorGuard::class)->lockAndAuthorize((int) $company->id, $user, 'settings.money_accounts.manage');

            return $this->create($company, $user, $data);
        });
    }

    /** Narrow configuration-only provisioning; no arbitrary financial input and an actual active owner is required. */
    public function provisionDefault(Company $company, User $owner): MoneyAccount
    {
        return DB::transaction(function () use ($company, $owner): MoneyAccount {
            Company::whereKey($company->id)->lockForUpdate()->firstOrFail();
            if (! CompanyUser::where('company_id', $company->id)->where('user_id', $owner->id)->where('is_owner', true)->where('status', 'active')->exists()) {
                throw new InvalidArgumentException('Default cash provisioning requires the company active owner.');
            }
            $existing = MoneyAccount::where('company_id', $company->id)->where('account_type', MoneyAccount::TYPE_CASH)->where('currency_code', $company->base_currency_code)->first();
            if ($existing !== null) {
                return $existing;
            }

            return $this->create($company, $owner, ['account_type' => MoneyAccount::TYPE_CASH, 'name_ar' => 'الصندوق - '.$company->base_currency_code, 'name_en' => 'Cash - '.$company->base_currency_code, 'currency_code' => $company->base_currency_code]);
        });
    }

    /** @param array<string, mixed> $data */
    private function create(Company $company, User $user, array $data): MoneyAccount
    {
        $type = $data['account_type'];
        if (! in_array($type, [MoneyAccount::TYPE_CASH, MoneyAccount::TYPE_BANK], true)) {
            throw new InvalidArgumentException("Invalid money account type [{$type}].");
        }

        $currency = strtoupper(trim($data['currency_code']));
        if (! CompanyCurrency::where('company_id', $company->id)->where('currency_code', $currency)->where('enabled', true)->exists()) {
            throw new InvalidArgumentException('Money account currency must be enabled for this company.');
        }
        $nameAr = trim($data['name_ar']);
        if ($nameAr === '' || mb_strlen($nameAr) > 255) {
            throw new InvalidArgumentException('Money account name is required and must fit the schema.');
        }
        $nameEn = isset($data['name_en']) ? trim((string) $data['name_en']) : null;

        // Find parent control account
        $parentSystemKey = $type === MoneyAccount::TYPE_CASH ? 'cash_control' : 'bank_control';
        /** @var LedgerAccount $parentControl */
        $parentControl = LedgerAccount::where('company_id', $company->id)
            ->where('system_key', $parentSystemKey)
            ->lockForUpdate()
            ->firstOrFail();

        // Generate unique child ledger account code
        $existingCount = LedgerAccount::where('company_id', $company->id)
            ->where('parent_id', $parentControl->id)
            ->count();
        $childCode = $parentControl->code.'-'.Str::ulid();

        // Create child ledger account
        $ledgerAccount = LedgerAccount::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $company->id,
            'code' => $childCode,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'account_type' => LedgerAccount::TYPE_ASSET,
            'normal_balance' => LedgerAccount::BALANCE_DEBIT,
            'parent_id' => $parentControl->id,
            'is_control' => false,
            'is_system' => false,
            'active' => true,
        ]);

        // Create MoneyAccount
        return MoneyAccount::create([
            'public_id' => (string) Str::ulid(),
            'company_id' => $company->id,
            'account_type' => $type,
            'name_ar' => $nameAr,
            'name_en' => $nameEn,
            'currency_code' => $currency,
            'ledger_account_id' => $ledgerAccount->id,
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'iban' => $data['iban'] ?? null,
            'is_active' => true,
            'sort_order' => $data['sort_order'] ?? 0,
            'created_by' => $user->id,
        ]);

    }
}
