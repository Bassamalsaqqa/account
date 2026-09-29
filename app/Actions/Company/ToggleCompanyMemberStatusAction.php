<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ToggleCompanyMemberStatusAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    public function execute(Company $company, CompanyUser $membership, User $actor): CompanyUser
    {
        if ($membership->company_id !== $company->id) {
            throw new AuthorizationException('Membership does not belong to active company.');
        }

        return DB::transaction(function () use ($company, $membership, $actor) {
            // Lock the company row to serialize all member invariant changes for this company
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

            // Prevent non-owner from modifying/deactivating an existing Owner
            if ($lockedMembership->is_owner && ! $actorMembership->is_owner) {
                throw new AuthorizationException('Only an existing company owner can modify an owner\'s status.');
            }

            $beforeStatus = $lockedMembership->status;
            $newStatus = $beforeStatus === 'active' ? 'inactive' : 'active';

            // Protect against deactivating the last active owner under row lock
            if ($newStatus === 'inactive' && $lockedMembership->is_owner) {
                $activeOwnersCount = CompanyUser::where('company_id', $company->id)
                    ->where('is_owner', true)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->count();

                if ($activeOwnersCount <= 1) {
                    throw new InvalidArgumentException('Cannot deactivate the last remaining company owner.');
                }
            }

            $lockedMembership->update(['status' => $newStatus]);

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'user.status_updated',
                summary: "Membership status for user '{$lockedMembership->user->name}' changed to {$newStatus}",
                subject: $lockedMembership->user,
                before: ['status' => $beforeStatus],
                after: ['status' => $newStatus],
                actorUserId: $actor->id
            );

            return $lockedMembership;
        });
    }
}
