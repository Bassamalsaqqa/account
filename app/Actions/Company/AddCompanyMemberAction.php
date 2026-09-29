<?php

namespace App\Actions\Company;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class AddCompanyMemberAction
{
    public function __construct(
        protected AuditService $audit
    ) {}

    /**
     * @param  array{name: string, email: string, password: string, locale: string}  $userData
     */
    public function execute(Company $company, array $userData, string $roleName, User $actor): CompanyUser
    {
        // Enforce role boundary: cannot assign Owner via member addition
        $allowedRoles = ['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'];
        if (! in_array($roleName, $allowedRoles, true)) {
            throw new InvalidArgumentException("Cannot assign role '{$roleName}'. Allowed roles: ".implode(', ', $allowedRoles));
        }

        return DB::transaction(function () use ($company, $userData, $roleName, $actor) {
            // Truthful new user creation: reject existing user emails to protect credentials and avoid silent password discarding
            if (User::where('email', $userData['email'])->exists()) {
                throw new InvalidArgumentException(__('settings.error_user_email_already_registered') ?: 'A user with this email address already exists. To preserve account security, credentials cannot be overwritten via new user creation. Please use the add-existing user flow.');
            }

            $user = User::create([
                'name' => $userData['name'],
                'email' => $userData['email'],
                'password' => Hash::make($userData['password']),
                'locale' => $userData['locale'],
                'last_active_company_id' => $company->id,
            ]);

            // Check if already a member
            $existing = CompanyUser::where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                throw new InvalidArgumentException('User is already a member of this company.');
            }

            /** @var CompanyUser $membership */
            $membership = CompanyUser::create([
                'company_id' => $company->id,
                'user_id' => $user->id,
                'status' => 'active',
                'is_owner' => false,
                'joined_at' => now(),
                'last_accessed_at' => null,
            ]);

            // Assign company-scoped role
            setPermissionsTeamId($company->id);
            app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

            /** @var Role $role */
            $role = Role::where('company_id', $company->id)->where('name', $roleName)->firstOrFail();
            $user->assignRole($role);

            $this->audit->log(
                companyId: $company->id,
                eventKey: 'user.added',
                summary: "User '{$user->name}' added to company with role '{$role->name}' by {$actor->name}",
                actorUserId: $actor->id,
                subject: $user,
                after: [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'role' => $role->name,
                ]
            );

            return $membership;
        });
    }
}
