<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

class ProductPolicy
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

    public function view(User $user, Product $product): bool
    {
        if (! $this->checkMembership($user, $product->company_id)) {
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

    public function update(User $user, Product $product): bool
    {
        if (! $this->checkMembership($user, $product->company_id)) {
            return false;
        }

        return $user->hasPermissionTo('inventory.product.manage');
    }

    public function delete(User $user, Product $product): bool
    {
        if (! $this->checkMembership($user, $product->company_id)) {
            return false;
        }

        return $user->hasPermissionTo('inventory.product.manage');
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
