<?php

declare(strict_types=1);

namespace App\Livewire\Pages\Money\Concerns;

use App\Models\Company;
use App\Services\Money\MoneyActorGuard;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Attributes\Locked;

trait AuthorizesMoneyPages
{
    #[Locked]
    public int $pageCompanyId;

    public function boot(CompanyContext $context): void
    {
        if (! $context->hasCompany()) {
            throw new AuthorizationException('Active Company required.');
        }
        if (isset($this->pageCompanyId) && $this->pageCompanyId !== (int) $context->companyId()) {
            throw new AuthorizationException('Company changed.');
        }
        $this->pageCompanyId = (int) $context->companyId();
    }

    protected function authorizeMoney(string $permission): Company
    {
        app(MoneyActorGuard::class)->authorize($this->pageCompanyId, $permission);

        return app(CompanyContext::class)->company();
    }

    protected function canMoney(string $permission): bool
    {
        try {
            $this->authorizeMoney($permission);

            return true;
        } catch (AuthorizationException) {
            return false;
        }
    }

    protected function authorizeCheckDirection(string $direction, bool $manage = false): Company
    {
        if (! in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new AuthorizationException('Invalid Check direction.');
        }
        $company = $this->authorizeMoney($manage ? 'money.check.'.$direction.'.manage' : 'money.check.view');
        if ($direction === 'outgoing') {
            $this->authorizeMoney('purchasing.cost.view');
        }

        return $company;
    }
}
