<?php

declare(strict_types=1);

namespace App\Services\Purchasing;

use App\Support\Tenancy\CompanyContext;

/** Shared Phase 5E financial-read policy, including fresh role and membership checks. */
final class VendorFinancialRead
{
    public function allows(int $companyId): bool
    {
        $context = app(CompanyContext::class);
        $user = auth()->user();
        if ($user === null || ! $context->hasCompany() || (int) $context->companyId() !== $companyId
            || ! $user->fresh()?->belongsToCompany($companyId)) {
            return false;
        }
        setPermissionsTeamId($companyId);
        $user->unsetRelation('roles')->unsetRelation('permissions');

        return $user->hasPermissionTo('purchasing.cost.view') && ($user->hasPermissionTo('money.vendor_payment.create')
            || $user->hasPermissionTo('money.vendor_payment.allocate') || $user->hasPermissionTo('money.vendor_payment.reverse')
            || $user->hasPermissionTo('vendors.statement.view'));
    }
}
