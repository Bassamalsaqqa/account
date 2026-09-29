<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\CompanyRoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UpdateRolePermissionsAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    public function execute(Company $company, Role $role, string $permissionName, bool $enable, User $actor): void
    {
        if ((int) $role->getAttribute('company_id') !== (int) $company->id) {
            throw new AuthorizationException('Role does not belong to active company.');
        }

        // Owner permissions are immutable
        if ($role->name === 'Owner') {
            throw new AuthorizationException('Owner role permissions are immutable.');
        }

        // Validate permission against the static catalog (never create arbitrary permission names)
        if (! in_array($permissionName, CompanyRoleService::allPermissions(), true)) {
            throw new InvalidArgumentException("Permission '{$permissionName}' is not defined in the static catalog.");
        }

        DB::transaction(function () use ($company, $role, $permissionName, $enable, $actor) {
            setPermissionsTeamId($company->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

            /** @var Permission $perm */
            $perm = Permission::where('name', $permissionName)->where('guard_name', 'web')->firstOrFail();

            if ($enable) {
                $role->givePermissionTo($perm);
            } else {
                $role->revokePermissionTo($perm);
            }

            // Invalidate cache
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'role.permissions_updated',
                summary: "Permission '{$permissionName}' ".($enable ? 'granted to' : 'revoked from')." role '{$role->name}' by {$actor->name}",
                actorUserId: $actor->id,
                subject: $role,
                after: [
                    'role' => $role->name,
                    'permission' => $permissionName,
                    'granted' => $enable,
                ]
            );
        });
    }
}
