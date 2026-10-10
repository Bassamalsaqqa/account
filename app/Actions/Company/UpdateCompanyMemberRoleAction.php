<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Tenancy\ProtectedDocumentDelegation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UpdateCompanyMemberRoleAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    public function execute(Company $company, CompanyUser $membership, string $newRoleName, User $actor): void
    {
        if ($membership->company_id !== $company->id) {
            throw new AuthorizationException('Membership does not belong to active company.');
        }

        DB::transaction(function () use ($company, $membership, $newRoleName, $actor) {
            // Lock the company row to serialize all member/role invariant operations
            Company::where('id', $company->id)->lockForUpdate()->firstOrFail();

            /** @var CompanyUser $lockedMembership */
            $lockedMembership = CompanyUser::with('user')
                ->where('company_id', $company->id)
                ->where('id', $membership->id)
                ->lockForUpdate()
                ->firstOrFail();

            $actorMembership = CompanyUser::where('company_id', $company->id)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->first();

            if (! $actorMembership) {
                throw new AuthorizationException('Actor is not an active member of this company.');
            }

            // Prevent unauthorized Owner assignment / self-promotion
            if ($newRoleName === 'Owner' && ! $actorMembership->is_owner) {
                throw new AuthorizationException('Only an existing company owner can assign the Owner role.');
            }

            // Prevent non-owner from modifying/demoting an existing Owner
            if ($lockedMembership->is_owner && ! $actorMembership->is_owner) {
                throw new AuthorizationException('Only an existing company owner can modify an owner\'s role.');
            }

            // Preserve at least one genuine company Owner under lock
            if ($lockedMembership->is_owner && $newRoleName !== 'Owner') {
                $ownerCount = CompanyUser::where('company_id', $company->id)
                    ->where('is_owner', true)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->count();

                if ($ownerCount <= 1) {
                    throw new InvalidArgumentException('Cannot demote the last remaining company owner.');
                }
            }

            $companyId = $company->id;
            setPermissionsTeamId($companyId);
            app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

            /** @var Role $role */
            $role = Role::where('company_id', $companyId)->where('name', $newRoleName)->firstOrFail();
            $delegation = app(ProtectedDocumentDelegation::class);
            $delegation->authorize((int) $companyId, $actor, 'settings.users.manage', $delegation->roleIsProtected($role));

            $targetUser = $lockedMembership->user;
            $targetUser->syncRoles([$role]);

            if ($newRoleName === 'Owner') {
                $lockedMembership->update(['is_owner' => true]);
            } elseif ($lockedMembership->is_owner) {
                $lockedMembership->update(['is_owner' => false]);
            }

            // Invalidate cache
            app(PermissionRegistrar::class)->forgetCachedPermissions();

            $this->audit->log(
                companyId: $companyId,
                eventKey: 'role.assigned',
                summary: "Role '{$newRoleName}' assigned to user '{$targetUser->name}' by {$actor->name}",
                actorUserId: $actor->id,
                subject: $targetUser,
                after: ['role' => $newRoleName]
            );
        });
    }
}
