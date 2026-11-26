<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\Route;

class SettingsNavigation
{
    public const GROUPS = [
        'business' => 'settings.group_business',
        'sales_purchasing' => 'settings.group_sales_purchasing',
        'documents_printing' => 'settings.group_documents_printing',
        'inventory_setup' => 'settings.group_inventory_setup',
        'money' => 'settings.group_money',
        'team_access' => 'settings.group_team_access',
        'integrations_data' => 'settings.group_integrations_data',
        'personal' => 'settings.group_personal',
    ];

    /**
     * Build the canonical list of settings sections permitted for the user and company context.
     *
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     icon: string,
     *     url: string,
     *     is_livewire_section: bool,
     *     group: string
     * }>
     */
    public static function sections(?Company $company = null, ?User $user = null): array
    {
        /** @var User|null $user */
        $user = $user ?? auth()->user();
        if (! $user) {
            return [];
        }

        $sections = [];
        $hasCompany = $company !== null;

        if ($hasCompany) {
            $canCompanyView = $user->can('view', $company);
            $isCompanyOwner = CompanyUser::query()
                ->where('company_id', $company->id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->where('is_owner', true)
                ->exists();

            // 1. Business settings
            if ($canCompanyView) {
                $sections['identity'] = [
                    'key' => 'identity',
                    'label' => __('settings.card_company_title'),
                    'icon' => 'branch',
                    'url' => route('settings.index', ['section' => 'identity']),
                    'is_livewire_section' => true,
                    'group' => 'business',
                ];
                $sections['localization'] = [
                    'key' => 'localization',
                    'label' => __('settings.card_localization_title'),
                    'icon' => 'globe',
                    'url' => route('settings.index', ['section' => 'localization']),
                    'is_livewire_section' => true,
                    'group' => 'business',
                ];
                $sections['currencies'] = [
                    'key' => 'currencies',
                    'label' => __('settings.card_currencies_title'),
                    'icon' => 'money',
                    'url' => route('settings.index', ['section' => 'currencies']),
                    'is_livewire_section' => true,
                    'group' => 'business',
                ];
            }

            if ($user->can('settings.taxes.manage')) {
                $sections['taxes'] = [
                    'key' => 'taxes',
                    'label' => __('settings.nav_taxes'),
                    'icon' => 'percent',
                    'url' => route('settings.taxes'),
                    'is_livewire_section' => false,
                    'group' => 'business',
                ];
            }

            // 2. Sales & purchasing
            if ($user->can('settings.purchases.manage')) {
                $sections['purchases'] = [
                    'key' => 'purchases',
                    'label' => __('purchasing.purchase_settings'),
                    'icon' => 'cart',
                    'url' => route('settings.purchases'),
                    'is_livewire_section' => false,
                    'group' => 'sales_purchasing',
                ];
            }

            if ($user->can('settings.sequences.manage')) {
                $sections['sequences'] = [
                    'key' => 'sequences',
                    'label' => __('settings.nav_sequences'),
                    'icon' => 'number',
                    'url' => route('settings.sequences'),
                    'is_livewire_section' => false,
                    'group' => 'sales_purchasing',
                ];
            }

            // 3. Documents & printing (requires BOTH settings.documents.manage AND settings.company.view)
            if ($user->can('settings.documents.manage') && $user->can('settings.company.view')) {
                $sections['documents'] = [
                    'key' => 'documents',
                    'label' => __('documents.title'),
                    'icon' => 'print',
                    'url' => route('settings.index', ['section' => 'documents']),
                    'is_livewire_section' => true,
                    'group' => 'documents_printing',
                ];
            }

            // 4. Inventory & product setup
            if ($user->can('inventory.product.manage')) {
                foreach ([
                    'warehouses' => ['settings.nav_warehouses', 'store', 'warehouses.index'],
                    'units' => ['settings.nav_units', 'box', 'units.index'],
                    'categories' => ['settings.nav_categories', 'dashboard', 'categories.index'],
                    'brands' => ['settings.nav_brands', 'box', 'brands.index'],
                ] as $key => [$label, $icon, $route]) {
                    $sections[$key] = [
                        'key' => $key,
                        'label' => __($label),
                        'icon' => $icon,
                        'url' => route($route),
                        'is_livewire_section' => false,
                        'group' => 'inventory_setup',
                    ];
                }
            }

            // 5. Money
            if ($user->can('settings.money_accounts.manage')) {
                $sections['money_accounts'] = [
                    'key' => 'money_accounts',
                    'label' => __('sales.settings_money_accounts'),
                    'icon' => 'bank',
                    'url' => route('settings.money-accounts'),
                    'is_livewire_section' => false,
                    'group' => 'money',
                ];
            }

            if ($user->can('money.expense.manage')) {
                $sections['expense_categories'] = [
                    'key' => 'expense_categories',
                    'label' => __('settings.nav_expense_categories'),
                    'icon' => 'receipt',
                    'url' => route('expenses.categories'),
                    'is_livewire_section' => false,
                    'group' => 'money',
                ];
            }

            // 6. Team & access
            if ($user->hasAnyPermission(['settings.users.view', 'settings.users.manage'])) {
                $sections['users'] = [
                    'key' => 'users',
                    'label' => __('settings.card_users_title'),
                    'icon' => 'users',
                    'url' => route('settings.index', ['section' => 'users']),
                    'is_livewire_section' => true,
                    'group' => 'team_access',
                ];
            }

            if ($user->hasAnyPermission(['settings.roles.view', 'settings.roles.manage'])) {
                $sections['roles'] = [
                    'key' => 'roles',
                    'label' => __('settings.card_roles_title'),
                    'icon' => 'shield',
                    'url' => route('settings.index', ['section' => 'roles']),
                    'is_livewire_section' => true,
                    'group' => 'team_access',
                ];
            }

            if ($canCompanyView) {
                $sections['security'] = [
                    'key' => 'security',
                    'label' => __('settings.card_security_title'),
                    'icon' => 'lock',
                    'url' => route('settings.index', ['section' => 'security']),
                    'is_livewire_section' => true,
                    'group' => 'team_access',
                ];
            }

            // 7. Integrations & data
            if ($user->can('ai.settings.manage')) {
                $sections['ai'] = [
                    'key' => 'ai',
                    'label' => __('assistant_v2.settings_title'),
                    'icon' => 'sparkles',
                    'url' => route('settings.index', ['section' => 'ai']),
                    'is_livewire_section' => true,
                    'group' => 'integrations_data',
                ];
            }

            if ($user->hasPermissionTo('audit.events.view')) {
                $sections['audit'] = [
                    'key' => 'audit',
                    'label' => __('settings.card_audit_title'),
                    'icon' => 'history',
                    'url' => route('settings.index', ['section' => 'audit']),
                    'is_livewire_section' => true,
                    'group' => 'integrations_data',
                ];
            }

            if ($isCompanyOwner && Route::has('settings.company-data.download')) {
                $sections['data'] = [
                    'key' => 'data',
                    'label' => __('settings.company_data_title'),
                    'icon' => 'database',
                    'url' => route('settings.index', ['section' => 'data']),
                    'is_livewire_section' => true,
                    'group' => 'integrations_data',
                ];
            }
        }

        // 8. Personal Account (always accessible to any authenticated user)
        $sections['profile'] = [
            'key' => 'profile',
            'label' => __('settings.personal_security'),
            'icon' => 'users',
            'url' => route('profile'),
            'is_livewire_section' => false,
            'group' => 'personal',
        ];

        return $sections;
    }

    /**
     * Group permitted settings sections into non-empty ordered navigation groups.
     *
     * @param  array<string, array{key: string, label: string, icon: string, url: string, is_livewire_section: bool, group: string}>|null  $sections
     * @return list<array{
     *     key: string,
     *     label: string,
     *     items: list<array{
     *         key: string,
     *         label: string,
     *         icon: string,
     *         url: string,
     *         is_livewire_section: bool,
     *         group: string
     *     }>
     * }>
     */
    public static function groupedSections(?Company $company = null, ?User $user = null, ?array $sections = null): array
    {
        $sections ??= self::sections($company, $user);
        $byGroup = [];

        foreach ($sections as $section) {
            $byGroup[$section['group']][] = $section;
        }

        $grouped = [];
        foreach (self::GROUPS as $groupKey => $groupLabelKey) {
            if (! empty($byGroup[$groupKey])) {
                $grouped[] = [
                    'key' => $groupKey,
                    'label' => __($groupLabelKey),
                    'items' => $byGroup[$groupKey],
                ];
            }
        }

        return $grouped;
    }
}
