<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Sales\SalesActorGuard;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Role;

final class ProtectedDocumentDelegation
{
    /** Call inside the company-serialized mutation transaction. */
    public function authorize(int $companyId, User $actor, string $permission, bool $protected): void
    {
        app(SalesActorGuard::class)->lockAndAuthorize($companyId, $actor, $permission);
        if ($protected && ! CompanyUser::where('company_id', $companyId)->where('user_id', $actor->id)
            ->where('status', 'active')->where('is_owner', true)->exists()) {
            throw new AuthorizationException('Only a current company Owner may delegate protected publication capabilities.');
        }
    }

    public function roleIsProtected(Role $role): bool
    {
        return $role->permissions()->whereIn('name', CompanyRoleService::PROTECTED_PERMISSIONS)->exists();
    }
}
