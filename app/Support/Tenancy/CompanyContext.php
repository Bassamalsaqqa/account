<?php

namespace App\Support\Tenancy;

use App\Exceptions\NoActiveCompanyException;
use App\Models\Company;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\PermissionRegistrar;

class CompanyContext
{
    protected ?Company $activeCompany = null;

    protected ?User $explicitUser = null;

    /**
     * Get the currently active company.
     *
     * @throws NoActiveCompanyException
     */
    public function company(): Company
    {
        if ($this->activeCompany === null) {
            throw new NoActiveCompanyException('An active company context is required for this action.');
        }

        return $this->activeCompany;
    }

    /**
     * Get the currently active company ID.
     *
     * @throws NoActiveCompanyException
     */
    public function companyId(): int
    {
        return (int) $this->company()->id;
    }

    /**
     * Check if an active company is set.
     */
    public function hasCompany(): bool
    {
        return $this->activeCompany !== null;
    }

    /**
     * Get the authenticated user.
     */
    public function user(): ?User
    {
        /** @var User|null $user */
        $user = auth()->user() ?? $this->explicitUser;

        return $user;
    }

    /**
     * Set the active company on this context and configure Spatie team ID.
     * Verifies that the target user is an active member of this company.
     */
    public function setCompany(Company $company, ?User $user = null): void
    {
        if ($company->status !== 'active') {
            throw new AuthorizationException('Company is not active.');
        }

        $targetUser = $user ?? $this->user();
        if (! $targetUser) {
            throw new AuthorizationException('Cannot activate company context without a verified user.');
        }

        $isMember = $targetUser->activeCompanies()
            ->where('companies.id', $company->id)
            ->exists();

        if (! $isMember) {
            throw new AuthorizationException('User is not an active member of this company.');
        }

        $this->activeCompany = $company;
        $this->explicitUser = $targetUser;

        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        $targetUser->unsetRelation('roles')->unsetRelation('permissions');
    }

    /**
     * Clear the company context and reset Spatie team ID.
     */
    public function clear(): void
    {
        $this->activeCompany = null;
        $this->explicitUser = null;

        setPermissionsTeamId(null);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $user = $this->user();
        if ($user) {
            $user->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    /**
     * Resolve the active company for the given user using deliberate rules.
     * Does NOT accept ambient request parameters.
     */
    public function resolveForUser(User $user): ?Company
    {
        // 1. Check session active company ID
        $sessionCompanyId = session('active_company_id');
        if ($sessionCompanyId) {
            /** @var Company|null $sessionCompany */
            $sessionCompany = $user->activeCompanies()
                ->where('companies.id', $sessionCompanyId)
                ->where('companies.status', 'active')
                ->first();

            if ($sessionCompany) {
                return $sessionCompany;
            }

            session()->forget('active_company_id');
        }

        // 2. Check user last active company ID
        if ($user->last_active_company_id) {
            /** @var Company|null $lastCompany */
            $lastCompany = $user->activeCompanies()
                ->where('companies.id', $user->last_active_company_id)
                ->where('companies.status', 'active')
                ->first();

            if ($lastCompany) {
                session(['active_company_id' => $lastCompany->id]);

                return $lastCompany;
            }

            $user->updateQuietly(['last_active_company_id' => null]);
        }

        // 3. Auto-activation for single-company users (persists session & last_active_company_id)
        $activeCompanies = $user->activeCompanies()
            ->where('companies.status', 'active')
            ->get();

        if ($activeCompanies->count() === 1) {
            /** @var Company $single */
            $single = $activeCompanies->first();

            session(['active_company_id' => $single->id]);

            if ($user->last_active_company_id !== $single->id) {
                $user->updateQuietly(['last_active_company_id' => $single->id]);
            }

            return $single;
        }

        // Multiple companies require selection, zero requires bootstrap
        return null;
    }

    /**
     * Switch the user's active company explicitly.
     *
     * @throws AuthorizationException
     */
    public function switchCompany(User $user, Company $targetCompany): void
    {
        if ($targetCompany->status !== 'active') {
            throw new AuthorizationException('Target company is not active.');
        }

        $membership = $user->memberships()
            ->where('company_id', $targetCompany->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            throw new AuthorizationException('User is not an active member of this company.');
        }

        session(['active_company_id' => $targetCompany->id]);

        $user->updateQuietly(['last_active_company_id' => $targetCompany->id]);
        $membership->updateQuietly(['last_accessed_at' => now()]);

        $this->setCompany($targetCompany, $user);

        // Evict permission cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        app(AuditService::class)->log(
            companyId: $targetCompany->id,
            eventKey: 'company.switched',
            summary: "User {$user->name} switched to company {$targetCompany->displayName()}",
            actorUserId: $user->id,
            subject: $targetCompany
        );
    }

    /**
     * Switch by company public ULID.
     */
    public function switchByPublicId(User $user, string $publicId): Company
    {
        /** @var Company $targetCompany */
        $targetCompany = Company::where('public_id', $publicId)->firstOrFail();

        $this->switchCompany($user, $targetCompany);

        return $targetCompany;
    }
}
