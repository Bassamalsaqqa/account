<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Warehouse;
use App\Support\Tenancy\CompanyContext;

class WarehousePolicy
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

        return $user->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage']);
    }

    public function view(User $user, Warehouse $warehouse): bool
    {
        if (! $this->checkMembership($user, $warehouse->company_id)) {
            return false;
        }

        return $user->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage']);
    }

    public function create(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        if (! $this->checkMembership($user, $context->companyId())) {
            return false;
        }

        return $user->hasPermissionTo('inventory.product.manage');
    }

    public function update(User $user, Warehouse $warehouse): bool
    {
        if (! $this->checkMembership($user, $warehouse->company_id)) {
            return false;
        }

        return $user->hasPermissionTo('inventory.product.manage');
    }

    public function delete(User $user, Warehouse $warehouse): bool
    {
        if (! $this->checkMembership($user, $warehouse->company_id)) {
            return false;
        }

        return $user->hasPermissionTo('inventory.product.manage');
    }
}
