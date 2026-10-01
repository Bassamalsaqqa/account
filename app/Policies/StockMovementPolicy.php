<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Tenancy\CompanyContext;

class StockMovementPolicy
{
    protected function checkMembership(User $user, int $companyId): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $context->companyId() !== $companyId) {
            return false;
        }

        return $user->memberships()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->exists();
    }

    public function viewAny(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        if (! $this->checkMembership($user, $context->companyId())) {
            return false;
        }

        return $user->hasPermissionTo('inventory.stock.view');
    }

    public function adjust(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        if (! $this->checkMembership($user, $context->companyId())) {
            return false;
        }

        return $user->hasPermissionTo('inventory.stock.adjust');
    }

    public function transfer(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        if (! $this->checkMembership($user, $context->companyId())) {
            return false;
        }

        return $user->hasPermissionTo('inventory.stock.transfer');
    }

    public function viewCost(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        if (! $this->checkMembership($user, $context->companyId())) {
            return false;
        }

        return $user->hasPermissionTo('inventory.cost.view');
    }
}
