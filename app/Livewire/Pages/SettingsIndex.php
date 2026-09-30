<?php

namespace App\Livewire\Pages;

use App\Actions\Company\AddCompanyMemberAction;
use App\Actions\Company\ToggleCompanyMemberStatusAction;
use App\Actions\Company\UpdateCompanyCurrenciesAction;
use App\Actions\Company\UpdateCompanyIdentityAction;
use App\Actions\Company\UpdateCompanyLocalizationAction;
use App\Actions\Company\UpdateCompanyMemberRoleAction;
use App\Actions\Company\UpdateCompanySecurityAction;
use App\Actions\Company\UpdateRolePermissionsAction;
use App\Domain\Accounting\Exceptions\BaseCurrencyLockedException;
use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\PostingBatch;
use App\Models\User;
use App\Support\Tenancy\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

#[Layout('layouts.app')]
class SettingsIndex extends Component
{
    public string $activeSection = 'overview';

    // Search query on overview
    public string $search = '';

    // Feedback messages
    public ?string $successMessage = null;

    public ?string $errorMessage = null;

    // Company Identity
    public string $name_ar = '';

    public string $name_en = '';

    public string $legal_name_ar = '';

    public string $legal_name_en = '';

    public string $registration_number = '';

    public string $tax_number = '';

    public string $phone = '';

    public string $whatsapp = '';

    public string $email = '';

    public string $website = '';

    public string $address_ar = '';

    public string $address_en = '';

    // Localization
    public string $default_locale = 'ar';

    public string $timezone = 'Asia/Hebron';

    public bool $english_enabled = true;

    // Currencies
    public string $base_currency = 'ILS';

    /** @var array<string, bool> */
    public array $currencies_enabled = [
        'ILS' => true,
        'USD' => true,
        'JOD' => true,
    ];

    // Security Settings
    public bool $require_2fa_for_owner = true;

    public bool $require_2fa_for_admin = false;

    public ?int $public_share_default_expiry_days = 30;

    // User Administration
    public string $new_user_name = '';

    public string $new_user_email = '';

    public string $new_user_password = '';

    public string $new_user_role = 'Viewer';

    public string $new_user_locale = 'ar';

    // Role Permissions Editor
    public ?string $selectedRoleName = null;

    /** @var array<string, bool> */
    public array $rolePermissions = [];

    protected function resetMessages(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;
    }

    protected function ensurePasswordIsConfirmed(): bool
    {
        $confirmedAt = (int) session('auth.password_confirmed_at', 0);
        $timeout = (int) config('auth.password_timeout', 10800);

        if ($confirmedAt <= 0 || (time() - $confirmedAt) >= $timeout) {
            session()->put('url.intended', route('settings.index'));
            $this->errorMessage = __('settings.password_confirmation_required');
            $this->redirect(route('password.confirm'));

            return false;
        }

        return true;
    }

    public function mount(CompanyContext $context): void
    {
        if (! $context->hasCompany()) {
            return;
        }

        $company = $context->company();

        // Enforce settings read permission
        $this->authorize('view', $company);

        // Identity
        $this->name_ar = (string) $company->name_ar;
        $this->name_en = (string) ($company->name_en ?? '');
        $this->legal_name_ar = (string) ($company->legal_name_ar ?? '');
        $this->legal_name_en = (string) ($company->legal_name_en ?? '');
        $this->registration_number = (string) ($company->registration_number ?? '');
        $this->tax_number = (string) ($company->tax_number ?? '');
        $this->phone = (string) ($company->phone ?? '');
        $this->whatsapp = (string) ($company->whatsapp ?? '');
        $this->email = (string) ($company->email ?? '');
        $this->website = (string) ($company->website ?? '');
        $this->address_ar = (string) ($company->address_ar ?? '');
        $this->address_en = (string) ($company->address_en ?? '');

        // Localization
        $this->default_locale = (string) $company->default_locale;
        $this->timezone = (string) $company->timezone;
        $this->english_enabled = $company->isLanguageEnabled('en');

        // Currencies
        $this->base_currency = (string) $company->base_currency_code;
        $companyCurrencies = $company->companyCurrencies;
        foreach ($companyCurrencies as $cc) {
            $this->currencies_enabled[$cc->currency_code] = (bool) $cc->enabled;
        }

        // Security
        $security = $company->securitySettings;
        if ($security) {
            $this->require_2fa_for_owner = (bool) $security->require_2fa_for_owner;
            $this->require_2fa_for_admin = (bool) $security->require_2fa_for_admin;
            $this->public_share_default_expiry_days = $security->public_share_default_expiry_days;
        }
    }

    public function setSection(string $section, CompanyContext $context): void
    {
        $company = $context->company();

        // Authorize per-section read
        /** @var User $user */
        $user = auth()->user();

        match ($section) {
            'overview', 'identity', 'localization', 'currencies', 'security' => $this->authorize('view', $company),
            'users', 'members' => $user->hasAnyPermission(['settings.users.view', 'settings.users.manage'])
                ? true
                : throw new AuthorizationException('Unauthorized to view users.'),
            'roles' => $user->hasAnyPermission(['settings.roles.view', 'settings.roles.manage'])
                ? true
                : throw new AuthorizationException('Unauthorized to view roles.'),
            'audit' => $user->hasPermissionTo('audit.events.view')
                ? true
                : throw new AuthorizationException('Unauthorized to view audit log.'),
            default => throw new AuthorizationException('Invalid settings section.'),
        };

        $this->activeSection = ($section === 'members') ? 'users' : $section;
        $this->resetMessages();

        if ($section === 'roles' && empty($this->selectedRoleName)) {
            $this->selectRole('Administrator', $context);
        }
    }

    public function selectRole(string $roleName, CompanyContext $context): void
    {
        /** @var User $user */
        $user = auth()->user();
        if (! $user->hasAnyPermission(['settings.roles.view', 'settings.roles.manage'])) {
            throw new AuthorizationException('Unauthorized to view role details.');
        }

        $companyId = $context->companyId();
        setPermissionsTeamId($companyId);
        app(PermissionRegistrar::class)->setPermissionsTeamId($companyId);

        $role = Role::where('company_id', $companyId)->where('name', $roleName)->first();
        if (! $role) {
            $this->rolePermissions = [];

            return;
        }

        $this->selectedRoleName = $roleName;
        $this->rolePermissions = [];
        foreach ($role->permissions as $perm) {
            $this->rolePermissions[$perm->name] = true;
        }
    }

    public function saveIdentity(CompanyContext $context, UpdateCompanyIdentityAction $action): void
    {
        $company = $context->company();
        $this->authorize('update', $company);

        $validated = $this->validate([
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'legal_name_ar' => ['nullable', 'string', 'max:255'],
            'legal_name_en' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:128'],
            'tax_number' => ['nullable', 'string', 'max:128'],
            'phone' => ['nullable', 'string', 'max:64'],
            'whatsapp' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'website' => ['nullable', 'string', 'url', 'max:255'],
            'address_ar' => ['nullable', 'string'],
            'address_en' => ['nullable', 'string'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();
        $action->execute($company, $validated, $actor);

        $this->successMessage = __('settings.identity_saved_success') ?: 'Company details saved successfully.';
    }

    public function updatedEnglishEnabled(bool $value): void
    {
        if (! $value && $this->default_locale === 'en') {
            $this->default_locale = 'ar';
        }
    }

    public function saveLocalization(CompanyContext $context, UpdateCompanyLocalizationAction $action): void
    {
        $company = $context->company();
        $this->authorize('update', $company);

        $validated = $this->validate([
            'default_locale' => ['required', 'string', Rule::in(['ar', 'en'])],
            'timezone' => ['required', 'string', 'timezone'],
            'english_enabled' => ['required', 'boolean'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();
        $action->execute(
            $company,
            $validated['default_locale'],
            $validated['timezone'],
            (bool) $this->english_enabled,
            $actor
        );

        if (! $this->english_enabled) {
            $this->default_locale = 'ar';
        }

        $this->successMessage = __('settings.localization_saved_success') ?: 'Localization settings saved.';
    }

    public function saveCurrencies(CompanyContext $context, UpdateCompanyCurrenciesAction $action): void
    {
        $company = $context->company();
        $this->authorize('update', $company);

        $this->validate([
            'base_currency' => ['required', 'string', Rule::in(['ILS', 'USD', 'JOD'])],
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $action->execute($company, $this->base_currency, $this->currencies_enabled, $actor);
            $this->currencies_enabled[$this->base_currency] = true;
            $this->successMessage = __('settings.currencies_saved_success') ?: 'Currency settings saved.';
            $this->errorMessage = null;
        } catch (BaseCurrencyLockedException $e) {
            $this->errorMessage = __('settings.base_currency_locked_notice') ?: $e->getMessage();
            $this->base_currency = $company->fresh()->base_currency_code;
        }
    }

    public function saveSecurity(CompanyContext $context, UpdateCompanySecurityAction $action): void
    {
        $company = $context->company();
        $this->authorize('update', $company);

        if (! $this->ensurePasswordIsConfirmed()) {
            return;
        }

        $validated = $this->validate([
            'require_2fa_for_owner' => ['required', 'boolean'],
            'require_2fa_for_admin' => ['required', 'boolean'],
            'public_share_default_expiry_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        /** @var User $actor */
        $actor = auth()->user();
        $action->execute($company, $validated, $actor);

        $this->successMessage = __('settings.security_saved_success') ?: 'Security settings saved.';
    }

    public function createUser(CompanyContext $context, AddCompanyMemberAction $action): void
    {
        $this->authorize('create', CompanyUser::class);
        $company = $context->company();

        $validated = $this->validate([
            'new_user_name' => ['required', 'string', 'max:255'],
            'new_user_email' => ['required', 'string', 'email', 'max:255'],
            'new_user_password' => ['required', 'string', 'min:8'],
            'new_user_role' => ['required', 'string', Rule::in(['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'])],
            'new_user_locale' => ['required', 'string', Rule::in(['ar', 'en'])],
        ]);

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $action->execute(
                $company,
                [
                    'name' => $validated['new_user_name'],
                    'email' => $validated['new_user_email'],
                    'password' => $validated['new_user_password'],
                    'locale' => $validated['new_user_locale'],
                ],
                $validated['new_user_role'],
                $actor
            );

            // Reset form
            $this->new_user_name = '';
            $this->new_user_email = '';
            $this->new_user_password = '';
            $this->new_user_role = 'Viewer';

            $this->successMessage = __('settings.user_added_success') ?: 'User successfully added to company.';
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function toggleUserStatus(int $membershipId, CompanyContext $context, ToggleCompanyMemberStatusAction $action): void
    {
        $company = $context->company();

        /** @var CompanyUser|null $membership */
        $membership = CompanyUser::with('user')
            ->where('company_id', $company->id)
            ->where('id', $membershipId)
            ->first();

        if (! $membership) {
            return;
        }

        $this->authorize('update', $membership);
        $this->resetMessages();

        // Destructive account deactivation (or modifying an Owner) requires recent password confirmation
        if ($membership->status === 'active' || $membership->is_owner) {
            if (! $this->ensurePasswordIsConfirmed()) {
                return;
            }
        }

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $updated = $action->execute($company, $membership, $actor);
            $this->successMessage = __('settings.user_status_updated', ['status' => $updated->status])
                ?: "User status updated to {$updated->status}.";
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function updateUserRole(int $membershipId, string $newRoleName, CompanyContext $context, UpdateCompanyMemberRoleAction $action): void
    {
        $company = $context->company();

        /** @var CompanyUser|null $membership */
        $membership = CompanyUser::with('user')
            ->where('company_id', $company->id)
            ->where('id', $membershipId)
            ->first();

        if (! $membership) {
            return;
        }

        $this->authorize('update', $membership);
        $this->resetMessages();

        /** @var User $actor */
        $actor = auth()->user();

        // Non-owner cannot alter owner status
        $actorMembership = CompanyUser::where('company_id', $company->id)
            ->where('user_id', $actor->id)
            ->where('status', 'active')
            ->first();

        if (($membership->is_owner || $newRoleName === 'Owner') && (! $actorMembership || ! $actorMembership->is_owner)) {
            $this->errorMessage = 'Only an existing company owner can assign the Owner role.';

            return;
        }

        // Changing a membership to or from Owner requires recent password confirmation
        if ($membership->is_owner || $newRoleName === 'Owner') {
            if (! $this->ensurePasswordIsConfirmed()) {
                return;
            }
        }

        try {
            $action->execute($company, $membership, $newRoleName, $actor);
            $this->successMessage = __('settings.user_role_updated', ['role' => $newRoleName])
                ?: "Role updated to {$newRoleName}.";
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function toggleRolePermission(string $permissionName, CompanyContext $context, UpdateRolePermissionsAction $action): void
    {
        if (! $this->selectedRoleName) {
            return;
        }

        $company = $context->company();
        setPermissionsTeamId($company->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

        /** @var Role|null $role */
        $role = Role::where('company_id', $company->id)->where('name', $this->selectedRoleName)->first();
        if (! $role) {
            return;
        }

        $this->authorize('update', $role);
        $this->resetMessages();

        $currentState = (bool) ($this->rolePermissions[$permissionName] ?? false);
        $newState = ! $currentState;

        /** @var User $actor */
        $actor = auth()->user();

        try {
            $action->execute($company, $role, $permissionName, $newState, $actor);
            $this->rolePermissions[$permissionName] = $newState;
            $this->successMessage = __('settings.role_permission_updated', ['permission' => $permissionName])
                ?: "Permission '{$permissionName}' updated.";
        } catch (\Exception $e) {
            $this->errorMessage = $e->getMessage();
        }
    }

    public function render(): View
    {
        $context = app(CompanyContext::class);
        $hasCompany = $context->hasCompany();
        $company = $hasCompany ? $context->company() : null;

        $memberships = collect();
        $roles = collect();
        $auditEvents = collect();
        $allPermissions = collect();

        if ($hasCompany && $company && auth()->check()) {
            /** @var User $user */
            $user = auth()->user();

            // Guard member list serialization
            if ($user->hasAnyPermission(['settings.users.view', 'settings.users.manage'])) {
                $memberships = CompanyUser::with('user')
                    ->where('company_id', $company->id)
                    ->orderBy('is_owner', 'desc')
                    ->get();
            }

            // Guard roles & permissions serialization
            if ($user->hasAnyPermission(['settings.roles.view', 'settings.roles.manage'])) {
                setPermissionsTeamId($company->id);
                app(PermissionRegistrar::class)->setPermissionsTeamId($company->id);

                $roles = Role::where('company_id', $company->id)->get();
                $allPermissions = Permission::where('guard_name', 'web')->orderBy('name')->get();
            }

            // Guard audit event serialization
            if ($user->hasPermissionTo('audit.events.view')) {
                $auditEvents = AuditEvent::with('actor')
                    ->where('company_id', $company->id)
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();
            }
        }

        $isBaseCurrencyLocked = false;
        if ($hasCompany && $company) {
            $isBaseCurrencyLocked = PostingBatch::where('company_id', $company->id)->exists();
        }

        return view('livewire.pages.settings-index', [
            'hasCompany' => $hasCompany,
            'company' => $company,
            'memberships' => $memberships,
            'roles' => $roles,
            'allPermissions' => $allPermissions,
            'auditEvents' => $auditEvents,
            'isBaseCurrencyLocked' => $isBaseCurrencyLocked,
        ]);
    }
}
