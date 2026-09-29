<?php

namespace App\Policies;

use App\Models\CompanyUser;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;

class CompanyUserPolicy
{
    /**
     * Determine whether the user can view company users.
     */
    public function viewAny(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        return $user->hasAnyPermission(['settings.users.view', 'settings.users.manage']);
    }

    /**
     * Determine whether the user can add a user to the company.
     */
    public function create(User $user): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany()) {
            return false;
        }

        return $user->hasPermissionTo('settings.users.manage');
    }

    /**
     * Determine whether the user can update a member.
     */
    public function update(User $user, CompanyUser $targetMembership): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $targetMembership->company_id !== $context->companyId()) {
            return false;
        }

        if (! $user->hasPermissionTo('settings.users.manage')) {
            return false;
        }

        // An Owner membership can only be modified by an active company Owner
        if ($targetMembership->is_owner) {
            $actorMembership = CompanyUser::where('company_id', $context->companyId())
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if (! $actorMembership || ! $actorMembership->is_owner) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether the user can remove a member.
     */
    public function delete(User $user, CompanyUser $targetMembership): bool
    {
        $context = app(CompanyContext::class);
        if (! $context->hasCompany() || $targetMembership->company_id !== $context->companyId()) {
            return false;
        }

        // Cannot remove yourself through user admin
        if ($targetMembership->user_id === $user->id) {
            return false;
        }

        if (! $user->hasPermissionTo('settings.users.manage')) {
            return false;
        }

        // An Owner membership can only be removed by an active company Owner, and cannot remove the last active owner
        if ($targetMembership->is_owner) {
            $actorMembership = CompanyUser::where('company_id', $context->companyId())
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if (! $actorMembership || ! $actorMembership->is_owner) {
                return false;
            }

            $otherActiveOwners = CompanyUser::where('company_id', $context->companyId())
                ->where('is_owner', true)
                ->where('status', 'active')
                ->where('id', '!=', $targetMembership->id)
                ->exists();

            if (! $otherActiveOwners) {
                return false;
            }
        }

        return true;
    }
}
