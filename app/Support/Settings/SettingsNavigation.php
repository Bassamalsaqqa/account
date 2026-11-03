<?php

declare(strict_types=1);

namespace App\Support\Settings;

use App\Models\Company;
use App\Models\User;

class SettingsNavigation
{
    /**
     * Build the canonical list of settings sections permitted for the user and company context.
     *
     * @return array<string, array{
     *     key: string,
     *     label: string,
     *     icon: string,
     *     url: string,
     *     is_livewire_section: bool
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

            if ($canCompanyView) {
                $sections['identity'] = [
                    'key' => 'identity',
                    'label' => __('settings.card_company_title'),
                    'icon' => 'branch',
                    'url' => route('settings.index', ['section' => 'identity']),
                    'is_livewire_section' => true,
                ];
                $sections['localization'] = [
                    'key' => 'localization',
                    'label' => __('settings.card_localization_title'),
                    'icon' => 'globe',
                    'url' => route('settings.index', ['section' => 'localization']),
                    'is_livewire_section' => true,
                ];
                $sections['currencies'] = [
                    'key' => 'currencies',
                    'label' => __('settings.card_currencies_title'),
                    'icon' => 'money',
                    'url' => route('settings.index', ['section' => 'currencies']),
                    'is_livewire_section' => true,
                ];
            }

            // Document & Print Settings requires BOTH settings.documents.manage AND settings.company.view
            if ($user->can('settings.documents.manage') && $user->can('settings.company.view')) {
                $sections['documents'] = [
                    'key' => 'documents',
                    'label' => __('documents.title'),
                    'icon' => 'file',
                    'url' => route('settings.index', ['section' => 'documents']),
                    'is_livewire_section' => true,
                ];
            }

            if ($user->can('ai.settings.manage')) {
                $sections['ai'] = [
                    'key' => 'ai',
                    'label' => __('assistant_v2.settings_title'),
                    'icon' => 'sparkles',
                    'url' => route('settings.index', ['section' => 'ai']),
                    'is_livewire_section' => true,
                ];
            }

            if ($canCompanyView) {
                $sections['security'] = [
                    'key' => 'security',
                    'label' => __('settings.card_security_title'),
                    'icon' => 'shield',
                    'url' => route('settings.index', ['section' => 'security']),
                    'is_livewire_section' => true,
                ];
            }

            if ($user->hasAnyPermission(['settings.users.view', 'settings.users.manage'])) {
                $sections['users'] = [
                    'key' => 'users',
                    'label' => __('settings.card_users_title'),
                    'icon' => 'users',
                    'url' => route('settings.index', ['section' => 'users']),
                    'is_livewire_section' => true,
                ];
            }

            if ($user->hasAnyPermission(['settings.roles.view', 'settings.roles.manage'])) {
                $sections['roles'] = [
                    'key' => 'roles',
                    'label' => __('settings.card_roles_title'),
                    'icon' => 'shield',
                    'url' => route('settings.index', ['section' => 'roles']),
                    'is_livewire_section' => true,
                ];
            }

            if ($user->hasPermissionTo('audit.events.view')) {
                $sections['audit'] = [
                    'key' => 'audit',
                    'label' => __('settings.card_audit_title'),
                    'icon' => 'history',
                    'url' => route('settings.index', ['section' => 'audit']),
                    'is_livewire_section' => true,
                ];
            }

            foreach ([
                'sequences' => ['settings.sequences.manage', 'settings.nav_sequences', 'file', 'settings.sequences'],
                'money_accounts' => ['settings.money_accounts.manage', 'sales.settings_money_accounts', 'money', 'settings.money-accounts'],
                'purchases' => ['settings.purchases.manage', 'purchasing.purchase_settings', 'file', 'settings.purchases'],
            ] as $key => [$permission, $label, $icon, $route]) {
                if ($user->can($permission)) {
                    $sections[$key] = [
                        'key' => $key,
                        'label' => __($label),
                        'icon' => $icon,
                        'url' => route($route),
                        'is_livewire_section' => false,
                    ];
                }
            }

            if ($user->can('settings.taxes.manage')) {
                $sections['taxes'] = [
                    'key' => 'taxes',
                    'label' => __('settings.nav_taxes'),
                    'icon' => 'percent',
                    'url' => route('settings.taxes'),
                    'is_livewire_section' => false,
                ];
            }

            if ($user->can('catalogs.view') && $user->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage'])) {
                $sections['catalogs'] = [
                    'key' => 'catalogs',
                    'label' => __('settings.card_share_title'),
                    'icon' => 'share',
                    'url' => route('catalogs.index'),
                    'is_livewire_section' => false,
                ];
            }
        }

        // Personal Security is always accessible to any authenticated user
        $sections['profile'] = [
            'key' => 'profile',
            'label' => __('settings.personal_security'),
            'icon' => 'users',
            'url' => route('profile'),
            'is_livewire_section' => false,
        ];

        return $sections;
    }
}
