<?php

declare(strict_types=1);

namespace App\Services\Money;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\MoneyAccount;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;

final class MoneyActorGuard
{
    public function authorize(int $companyId, string $permission): User
    {
        $context = app(CompanyContext::class);
        $actor = auth()->user();
        if (! $actor instanceof User || ! $context->hasCompany()
            || (int) $context->companyId() !== $companyId
            || (int) $context->user()?->id !== (int) $actor->id
            || ! Company::whereKey($companyId)->where('status', 'active')->exists()
            || ! CompanyUser::where('company_id', $companyId)->where('user_id', $actor->id)->where('status', 'active')->exists()) {
            throw new AuthorizationException('Active matching company membership is required.');
        }
        setPermissionsTeamId($companyId);
        $actor->unsetRelation('roles')->unsetRelation('permissions');
        if (! $actor->hasPermissionTo($permission)) {
            throw new AuthorizationException('Money permission is required.');
        }

        return $actor;
    }

    public function account(MoneyAccount $account): void
    {
        $this->authorize((int) $account->company_id, match ($account->account_type) {
            MoneyAccount::TYPE_CASH => 'money.cash.view',
            MoneyAccount::TYPE_BANK => 'money.bank.view',
            default => throw new AuthorizationException('Invalid money account type.'),
        });
    }
}
