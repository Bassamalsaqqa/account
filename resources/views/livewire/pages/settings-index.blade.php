<div class="space-y-6" x-data="{
    searchQuery: '',
    matches(text) {
        if (!this.searchQuery.trim()) return true;
        return text.toLowerCase().includes(this.searchQuery.toLowerCase().trim());
    }
}">

    <!-- Alerts & Feedback -->
    @if ($successMessage)
        <div class="p-3.5 rounded-card bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-bold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2">
                <x-icon name="check" class="w-4 h-4 text-emerald-600" />
                <span>{{ $successMessage }}</span>
            </div>
            <button type="button" wire:click="$set('successMessage', null)" class="text-emerald-700 hover:text-emerald-900 font-extrabold text-sm">&times;</button>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-3.5 rounded-card bg-rose-50 border border-rose-200 text-rose-800 text-xs font-bold flex items-center justify-between shadow-xs">
            <div class="flex items-center gap-2 flex-wrap">
                <x-icon name="warning" class="w-4 h-4 text-rose-600 shrink-0" />
                <span>{{ $errorMessage }}</span>
                @if ($errorMessage === __('settings.password_confirmation_required'))
                    <a href="{{ route('password.confirm') }}" class="underline ms-2 font-bold text-rose-900 hover:text-rose-950">{{ __('settings.confirm_password_action') }} &rarr;</a>
                @endif
            </div>
            <button type="button" wire:click="$set('errorMessage', null)" class="text-rose-700 hover:text-rose-900 font-extrabold text-sm">&times;</button>
        </div>
    @endif

    @if ($activeSection === 'overview')
        @if (auth()->user()->hasPermissionTo('ai.settings.manage'))
            <a href="{{ route('settings.ai') }}" class="mb-4 inline-flex min-h-11 items-center gap-2 rounded-control border border-border bg-surface px-4 text-sm font-semibold text-primary hover:bg-primary-50">{{ __('assistant_v2.settings_title') }}</a>
        @endif
        <!-- Page Header -->
        <div class="flex flex-col md:flex-row md:items-start justify-between gap-4">
            <div>
                <!-- Breadcrumb -->
                <nav class="flex items-center gap-1.5 text-[11px] text-text-muted mb-2 font-medium">
                    <a href="{{ route('dashboard') }}" class="hover:text-primary transition-colors">{{ __('settings.breadcrumb_dashboard') }}</a>
                    <span class="text-text-muted/60">/</span>
                    <span class="text-text-secondary font-bold">{{ __('settings.breadcrumb_settings') }}</span>
                </nav>

                <!-- Title & Subtitle -->
                <h1 class="text-2xl sm:text-[26px] font-extrabold text-text-primary tracking-tight leading-tight">
                    {{ __('settings.title') }}
                </h1>
                <p class="text-xs text-text-secondary mt-1.5 max-w-2xl leading-relaxed">
                    {{ __('settings.subtitle') }}
                </p>
            </div>
        </div>

        @if (! $hasCompany)
            <!-- Zero Company Bootstrap Notice -->
            <div class="p-4 sm:p-6 rounded-card bg-amber-50 border border-amber-200 text-amber-900 shadow-panel">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-control bg-amber-100 text-amber-700 grid place-items-center shrink-0">
                        <x-icon name="store" class="w-5 h-5" />
                    </div>
                    <div>
                        <h2 class="text-sm font-extrabold">{{ __('settings.no_active_company') }}</h2>
                        <p class="text-xs text-amber-800 mt-1 leading-relaxed">
                            {{ __('settings.no_company_bootstrap_notice') }}
                        </p>
                    </div>
                </div>
            </div>
        @else
            <!-- Real Active Company Profile Cards -->
            <section class="grid grid-cols-1 md:grid-cols-12 gap-3.5">
                <!-- 1. Real Active Company Card (7 cols on md/lg) -->
                <div class="md:col-span-7 bg-white border border-border rounded-card p-4 sm:p-5 shadow-panel flex flex-col justify-between">
                    <div>
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <div class="w-11 h-11 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 border border-primary-100">
                                    <x-icon name="store" class="w-6 h-6" />
                                </div>
                                <div class="min-w-0">
                                    <h2 class="text-sm font-extrabold text-text-primary truncate">
                                        {{ $company->displayName() }}
                                    </h2>
                                    <p class="text-[11px] text-text-muted mt-0.5 truncate">
                                        {{ $company->legal_name_ar ?: ($company->legal_name_en ?: __('settings.sec_identity_title')) }}
                                    </p>
                                </div>
                            </div>
                            <button type="button" wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('identity')"
                                    class="px-2.5 py-1.5 rounded-control border border-border bg-surface-soft hover:bg-surface-blue hover:border-primary text-text-secondary hover:text-primary text-xs font-bold shrink-0 transition-colors cursor-pointer">
                                {{ __('settings.edit_company_data') }}
                            </button>
                        </div>
                    </div>

                    <!-- Company Meta Row -->
                    <div class="mt-4 pt-3 border-t border-border/60 flex flex-wrap items-center gap-x-4 gap-y-2 text-[11px] text-text-secondary">
                        <span class="inline-flex items-center gap-1.5">
                            <x-icon name="file" class="w-3.5 h-3.5 text-text-muted shrink-0" />
                            <span>{{ __('settings.tax_number') }} <b class="num text-text-primary font-bold">{{ $company->tax_number ?: '-' }}</b></span>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <x-icon name="phone" class="w-3.5 h-3.5 text-text-muted shrink-0" />
                            <b class="num text-text-primary font-bold">{{ $company->phone ?: '-' }}</b>
                        </span>
                        <span class="inline-flex items-center gap-1.5">
                            <x-icon name="pin" class="w-3.5 h-3.5 text-text-muted shrink-0" />
                            <span>{{ (app()->getLocale() === 'ar' ? $company->address_ar : $company->address_en) ?: '-' }}</span>
                        </span>
                    </div>
                </div>

                <!-- 2. Real Company Operational Scope Card (5 cols on md/lg) -->
                <div class="md:col-span-5 bg-white border border-border rounded-card p-4 sm:p-5 shadow-panel flex flex-col justify-between">
                    <div>
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-[10px] font-bold text-text-muted uppercase tracking-wider">{{ __('settings.aside_status_title') }}</span>
                            <x-badge variant="success">{{ __('settings.status_active') }}</x-badge>
                        </div>
                        <div class="grid grid-cols-2 gap-3 text-xs pt-1">
                            <div>
                                <span class="text-[10px] text-text-muted block">{{ __('settings.status_base_currency') }}</span>
                                <span class="text-sm font-extrabold text-text-primary font-mono">{{ $company->base_currency_code }}</span>
                            </div>
                            <div>
                                <span class="text-[10px] text-text-muted block">{{ __('settings.usage_users') }}</span>
                                <span class="text-sm font-extrabold text-text-primary num">{{ $memberships->count() }}</span>
                            </div>
                            <div>
                                <span class="text-[10px] text-text-muted block">{{ __('settings.card_localization_title') }}</span>
                                <span class="text-xs font-bold text-text-primary uppercase">{{ $company->default_locale }} · {{ Str::after($company->timezone, '/') }}</span>
                            </div>
                            <div>
                                <span class="text-[10px] text-text-muted block">{{ __('settings.status_2fa') }}</span>
                                <span class="text-xs font-bold text-text-secondary">{{ __('settings.policy_preference_only') }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="mt-3 pt-2 border-t border-border/60 flex items-center justify-between text-[11px] text-text-muted font-mono">
                        <span>ULID: {{ Str::limit($company->public_id, 12) }}</span>
                        <span>{{ $company->status }}</span>
                    </div>
                </div>
            </section>
        @endif

        <!-- Interactive Search for Settings Cards -->
        <div class="relative">
            <div class="flex items-center h-10 px-3.5 rounded-card bg-white border border-border focus-within:border-primary focus-within:shadow-focus-primary transition-all">
                <x-icon name="search" class="w-4 h-4 text-text-muted shrink-0" />
                <input type="text"
                       x-model="searchQuery"
                       aria-label="{{ __('app.search_placeholder') }}"
                       placeholder="{{ __('app.search_placeholder') }}"
                       class="w-full bg-transparent border-0 text-xs px-2.5 text-text-primary placeholder:text-text-muted focus:ring-0 focus:outline-none" />
                <button x-show="searchQuery"
                        @click="searchQuery = ''"
                        type="button"
                        aria-label="{{ __('app.clear_search') }}"
                        class="text-xs text-text-muted hover:text-text-primary font-bold">
                    &times;
                </button>
            </div>
        </div>

        <!-- Main Workspace (Settings Grid + Aside) -->
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-5 items-start">

            <!-- Main Column: Categorized Settings Cards (8 cols on lg) -->
            <div class="lg:col-span-8 space-y-6">

                <!-- Section 1: Identity & General -->
                <section class="space-y-3">
                    <div>
                        <h3 class="text-xs sm:text-sm font-extrabold text-text-primary tracking-tight">
                            {{ __('settings.sec_identity_title') }}
                        </h3>
                        <p class="text-[11px] text-text-muted mt-0.5">
                            {{ __('settings.sec_identity_desc') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <!-- Company Details -->
                        <div role="button" tabindex="0" wire:keydown.enter="setSection('identity')" wire:keydown.space.prevent="setSection('identity')" x-show="matches('{{ __('settings.card_company_title') }} {{ __('settings.card_company_desc') }}')"
                             wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('identity')"
                             class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer focus-visible:outline-2 focus-visible:outline-primary">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                    <x-icon name="store" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                        {{ __('settings.card_company_title') }}
                                    </h4>
                                    <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                        {{ __('settings.card_company_desc') }}
                                    </p>
                                </div>
                            </div>
                            <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                        </div>

                        <!-- Languages & Region -->
                        <div role="button" tabindex="0" wire:keydown.enter="setSection('localization')" wire:keydown.space.prevent="setSection('localization')" x-show="matches('{{ __('settings.card_localization_title') }} {{ __('settings.card_localization_desc') }}')"
                             wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('localization')"
                             class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer focus-visible:outline-2 focus-visible:outline-primary">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                    <x-icon name="globe" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                        {{ __('settings.card_localization_title') }}
                                    </h4>
                                    <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                        {{ __('settings.card_localization_desc') }}
                                    </p>
                                </div>
                            </div>
                            <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                        </div>

                        <!-- Currencies & FX -->
                        <div role="button" tabindex="0" wire:keydown.enter="setSection('currencies')" wire:keydown.space.prevent="setSection('currencies')" x-show="matches('{{ __('settings.card_currencies_title') }} {{ __('settings.card_currencies_desc') }} ILS USD JOD')"
                             wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('currencies')"
                             class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer focus-visible:outline-2 focus-visible:outline-primary">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                    <x-icon name="money" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                        {{ __('settings.card_currencies_title') }}
                                    </h4>
                                    <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                        {{ __('settings.card_currencies_desc') }}
                                    </p>
                                    <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 font-mono text-[9px] font-bold">
                                        ILS &bull; USD &bull; JOD
                                    </span>
                                </div>
                            </div>
                            <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                        </div>

                        <!-- Taxes -->
                        @if (auth()->user()->can('settings.taxes.manage'))
                            <a href="{{ route('settings.taxes') }}"
                               x-show="matches('{{ __('settings.card_taxes_title') }} {{ __('settings.card_taxes_desc') }}')"
                               class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="percent" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_taxes_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                            {{ __('settings.card_taxes_desc') }}
                                        </p>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </a>
                        @else
                            <div x-show="matches('{{ __('settings.card_taxes_title') }} {{ __('settings.card_taxes_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="percent" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_taxes_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_taxes_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endif
                    </div>
                </section>

                <!-- Section 2: Operations (Future Phases) -->
                <section class="space-y-3">
                    <div>
                        <h3 class="text-xs sm:text-sm font-extrabold text-text-primary tracking-tight">
                            {{ __('settings.sec_ops_title') }}
                        </h3>
                        <p class="text-[11px] text-text-muted mt-0.5">
                            {{ __('settings.sec_ops_desc') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <!-- Purchases -->
                        @can('settings.purchases.manage')
                        <a href="{{ route('settings.purchases') }}" x-show="matches('{{ __('settings.card_purchases_title') }} {{ __('settings.card_purchases_desc') }}')"
                             class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 flex items-start justify-between gap-3">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-control bg-primary-soft text-primary grid place-items-center shrink-0">
                                    <x-icon name="cart" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0">
                                    <div class="flex items-center gap-1.5">
                                        <h4 class="text-xs font-extrabold text-text-primary">{{ __('settings.card_purchases_title') }}</h4>
                                    </div>
                                    <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_purchases_desc') }}</p>
                                </div>
                            </div>
                        </a>
                        @endcan

                        <!-- Banking -->
                        @if (auth()->user()->can('settings.money_accounts.manage'))
                            <a href="{{ route('settings.money-accounts') }}"
                               x-show="matches('{{ __('settings.card_banking_title') }} {{ __('settings.card_banking_desc') }}')"
                               class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="bank" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_banking_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_banking_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </a>
                        @else
                            <div x-show="matches('{{ __('settings.card_banking_title') }} {{ __('settings.card_banking_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="bank" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_banking_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_banking_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endif
                    </div>
                </section>

                <!-- Section 3: Users & Security -->
                <section class="space-y-3">
                    <div>
                        <h3 class="text-xs sm:text-sm font-extrabold text-text-primary tracking-tight">
                            {{ __('settings.sec_users_title') }}
                        </h3>
                        <p class="text-[11px] text-text-muted mt-0.5">
                            {{ __('settings.sec_users_desc') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        @canany(['settings.users.view', 'settings.users.manage'])
                            <!-- Users -->
                            <div role="button" tabindex="0" wire:keydown.enter="setSection('users')" wire:keydown.space.prevent="setSection('users')" x-show="matches('{{ __('settings.card_users_title') }} {{ __('settings.card_users_desc') }}')"
                                 wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('users')"
                                 class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer focus-visible:outline-2 focus-visible:outline-primary">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="users" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_users_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                            {{ __('settings.card_users_desc') }}
                                        </p>
                                        <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 text-[9px] font-bold num">
                                            {{ $memberships->count() }} {{ __('settings.usage_users') }}
                                        </span>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </div>
                        @else
                            <div x-show="matches('{{ __('settings.card_users_title') }} {{ __('settings.card_users_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="users" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_users_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_users_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endcanany

                        @canany(['settings.roles.view', 'settings.roles.manage'])
                            <!-- Roles & Permissions -->
                            <div role="button" tabindex="0" wire:keydown.enter="setSection('roles')" wire:keydown.space.prevent="setSection('roles')" x-show="matches('{{ __('settings.card_roles_title') }} {{ __('settings.card_roles_desc') }}')"
                                 wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('roles')"
                                 class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer focus-visible:outline-2 focus-visible:outline-primary">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="shield" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_roles_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                            {{ __('settings.card_roles_desc') }}
                                        </p>
                                        <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-success-bg text-success text-[9px] font-bold">
                                            {{ $roles->count() }} {{ __('settings.card_roles_title') }}
                                        </span>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </div>
                        @else
                            <div x-show="matches('{{ __('settings.card_roles_title') }} {{ __('settings.card_roles_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="shield" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_roles_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_roles_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endcanany

                        <!-- Security -->
                        <div role="button" tabindex="0" wire:keydown.enter="setSection('security')" wire:keydown.space.prevent="setSection('security')" x-show="matches('{{ __('settings.card_security_title') }} {{ __('settings.card_security_desc') }}')"
                             wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('security')"
                             class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer focus-visible:outline-2 focus-visible:outline-primary">
                            <div class="flex items-start gap-3 min-w-0">
                                <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                    <x-icon name="lock" class="w-5 h-5" />
                                </div>
                                <div class="min-w-0">
                                    <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                        {{ __('settings.card_security_title') }}
                                    </h4>
                                    <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                        {{ __('settings.card_security_desc') }}
                                    </p>
                                </div>
                            </div>
                            <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                        </div>

                        @can('audit.events.view')
                            <!-- Audit Log -->
                            <div role="button" tabindex="0" wire:keydown.enter="setSection('audit')" wire:keydown.space.prevent="setSection('audit')" x-show="matches('{{ __('settings.card_audit_title') }} {{ __('settings.card_audit_desc') }}')"
                                 wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('audit')"
                                 class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer focus-visible:outline-2 focus-visible:outline-primary">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="history" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_audit_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                            {{ __('settings.card_audit_desc') }}
                                        </p>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </div>
                        @else
                            <div x-show="matches('{{ __('settings.card_audit_title') }} {{ __('settings.card_audit_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="history" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_audit_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_audit_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endcan
                    </div>
                </section>

                <!-- Section 4: Documents & Data -->
                <section class="space-y-3">
                    <div>
                        <h3 class="text-xs sm:text-sm font-extrabold text-text-primary tracking-tight">
                            {{ __('settings.sec_docs_title') }}
                        </h3>
                        <p class="text-[11px] text-text-muted mt-0.5">
                            {{ __('settings.sec_docs_desc') }}
                        </p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <!-- Document Numbering -->
                        @if (auth()->user()->can('settings.sequences.manage'))
                            <a href="{{ route('settings.sequences') }}"
                               x-show="matches('{{ __('settings.card_numbering_title') }} {{ __('settings.card_numbering_desc') }}')"
                               class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="number" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_numbering_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_numbering_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </a>
                        @else
                            <div x-show="matches('{{ __('settings.card_numbering_title') }} {{ __('settings.card_numbering_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="number" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_numbering_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_numbering_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endif

                        <!-- Print & PDF -->
                        @if (auth()->user()->can('settings.documents.manage') && auth()->user()->can('settings.company.view'))
                            <a href="{{ route('settings.documents') }}"
                               wire:navigate
                               x-show="matches('{{ __('settings.card_print_title') }} {{ __('settings.card_print_desc') }}')"
                               class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="print" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_print_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_print_desc') }}</p>
                                        <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 text-[9px] font-bold">
                                            {{ __('settings.card_print_future_note') }}
                                        </span>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </a>
                        @else
                            <div x-show="matches('{{ __('settings.card_print_title') }} {{ __('settings.card_print_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="print" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_print_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_print_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endif

                        <!-- Sharing & Links -->
                        @if (auth()->user()->can('catalogs.view') && auth()->user()->hasAnyPermission(['inventory.stock.view', 'inventory.product.manage']))
                            <a href="{{ route('catalogs.index') }}"
                               wire:navigate
                               x-show="matches('{{ __('settings.card_share_title') }} {{ __('settings.card_share_desc') }}')"
                               class="bg-white border border-border hover:border-primary/60 rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group cursor-pointer">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                        <x-icon name="share" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                            {{ __('settings.card_share_title') }}
                                        </h4>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_share_desc') }}</p>
                                        <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-slate-100 text-slate-700 text-[9px] font-bold">
                                            {{ __('settings.card_share_future_note') }}
                                        </span>
                                    </div>
                                </div>
                                <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                            </a>
                        @else
                            <div x-show="matches('{{ __('settings.card_share_title') }} {{ __('settings.card_share_desc') }}')"
                                 class="bg-slate-50/70 border border-border/80 rounded-card p-3.5 flex items-start justify-between gap-3 opacity-60 cursor-not-allowed">
                                <div class="flex items-start gap-3 min-w-0">
                                    <div class="w-9 h-9 rounded-control bg-slate-200 text-slate-400 grid place-items-center shrink-0">
                                        <x-icon name="share" class="w-5 h-5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5">
                                            <h4 class="text-xs font-extrabold text-text-muted">{{ __('settings.card_share_title') }}</h4>
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-200 text-text-muted font-bold">{{ __('settings.restricted_card_notice') }}</span>
                                        </div>
                                        <p class="text-[11px] text-text-muted mt-0.5 leading-snug">{{ __('settings.card_share_desc') }}</p>
                                    </div>
                                </div>
                                <x-icon name="lock" class="w-4 h-4 text-text-muted shrink-0 mt-1" />
                            </div>
                        @endif

                        @if ($isCompanyOwner && \Illuminate\Support\Facades\Route::has('settings.company-data.download'))
                            <section class="bg-white border border-border rounded-card p-4 space-y-3" aria-labelledby="company-data-title">
                                <h4 id="company-data-title" class="text-sm font-bold">{{ __('settings.company_data_title') }}</h4>
                                <p class="text-xs text-text-secondary">{{ __('settings.company_data_description') }}</p>
                                @if ($passwordConfirmed)
                                    <form method="POST" action="{{ route('settings.company-data.download') }}" x-data="companyDataDownload({preparing: @js(__('settings.company_data_preparing')), failed: @js(__('settings.company_data_failed')), started: @js(__('settings.company_data_started')), confirmRequired: @js(__('settings.password_confirmation_required'))})" x-on:submit.prevent="download($el)">
                                        @csrf
                                        <button type="submit" class="min-h-11 px-4 py-2 rounded-control bg-primary text-white font-bold text-sm focus-visible:ring-2" x-bind:disabled="preparing">{{ __('settings.company_data_title') }}</button>
                                        <p x-cloak x-show="message" x-text="message" role="status" class="text-xs mt-2"></p>
                                        <p x-cloak x-show="error" x-text="error" role="alert" class="text-xs mt-2 text-rose-700"></p>
                                        <button x-cloak x-show="needsConfirmation" type="button" wire:click="prepareCompanyDataDownload" wire:loading.attr="disabled" class="min-h-11 px-3 py-2 rounded-control border border-primary text-sm text-primary">{{ __('settings.confirm_password_action') }}</button>
                                    </form>
                                @else
                                    <button type="button" wire:click="prepareCompanyDataDownload" wire:loading.attr="disabled" class="min-h-11 px-4 py-2 rounded-control border border-primary text-primary font-bold text-sm">{{ __('settings.confirm_password_action') }}</button>
                                @endif
                                @if (session('company_data_export_error'))
                                    <p role="alert" class="text-sm text-rose-700">{{ session('company_data_export_error') }}</p>
                                @endif
                            </section>
                        @endif
                    </div>
                </section>
                <section class="bg-white border border-border rounded-card p-4 space-y-3">
                    <h3 class="text-sm font-bold">{{ __('settings.existing_configuration') }}</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <a href="{{ route('profile') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.personal_security') }}</a>
                        @can('settings.taxes.manage')
                            <a href="{{ route('settings.taxes') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_taxes') }}</a>
                        @endcan
                        @can('settings.sequences.manage')
                            <a href="{{ route('settings.sequences') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_sequences') }}</a>
                        @endcan
                        @can('settings.purchases.manage')
                            <a href="{{ route('settings.purchases') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_purchases') }}</a>
                        @endcan
                        @can('settings.money_accounts.manage')
                            <a href="{{ route('settings.money-accounts') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_money_accounts') }}</a>
                        @endcan
                        @can('inventory.product.manage')
                            <a href="{{ route('warehouses.index') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_warehouses') }}</a>
                        @endcan
                        @can('inventory.product.manage')
                            <a href="{{ route('units.index') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_units') }}</a>
                        @endcan
                        @can('inventory.product.manage')
                            <a href="{{ route('categories.index') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_categories') }}</a>
                        @endcan
                        @can('inventory.product.manage')
                            <a href="{{ route('brands.index') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_brands') }}</a>
                        @endcan
                        @can('money.expense.manage')
                            <a href="{{ route('expenses.categories') }}" class="min-h-11 flex items-center rounded-control border border-border px-3 py-2 text-sm font-semibold hover:bg-primary-50 focus-visible:ring-2">{{ __('settings.nav_expense_categories') }}</a>
                        @endcan
                    </div>
                </section>
            </div>

            <!-- Aside Column: Overview, Activity, Permissions (4 cols on lg) -->
            <aside class="lg:col-span-4 space-y-4">

                <!-- Card 1: Real Configuration Overview -->
                <div class="bg-white border border-border rounded-card p-4 shadow-panel">
                    <h4 class="text-xs font-extrabold text-text-primary mb-3 flex items-center justify-between gap-2">
                        <span>{{ __('settings.aside_status_title') }}</span>
                        <x-badge variant="neutral">{{ __('settings.configuration_summary') }}</x-badge>
                    </h4>
                    <div class="divide-y divide-border/60 text-xs">
                        <div class="py-2 flex items-center justify-between first:pt-0">
                            <span class="text-text-muted">{{ __('settings.status_base_currency') }}</span>
                            <span class="font-bold text-text-primary font-mono">{{ $company?->base_currency_code ?? 'ILS' }}</span>
                        </div>
                        <div class="py-2 flex items-center justify-between">
                            <span class="text-text-muted">{{ __('settings.card_localization_title') }}</span>
                            <span class="font-bold text-text-primary uppercase">{{ $company?->default_locale ?? 'ar' }}</span>
                        </div>
                        <div class="py-2 flex items-center justify-between">
                            <span class="text-text-muted">{{ __('settings.personal_security') }}</span>
                            <span class="font-bold text-text-secondary">{{ $personalTwoFactorEnabled ? __('settings.personal_2fa_enabled') : __('settings.personal_2fa_not_enabled') }}</span>
                        </div>
                        <div class="py-2 flex items-center justify-between last:pb-0">
                            <span class="text-text-muted">{{ __('settings.usage_users') }}</span>
                            <span class="font-bold text-text-primary num">{{ $memberships->count() }}</span>
                        </div>
                    </div>
                </div>

                <!-- Card 2: Real Recent Activity / Audit Log -->
                <div class="bg-white border border-border rounded-card p-4 shadow-panel">
                    <h4 class="text-xs font-extrabold text-text-primary mb-3 flex items-center justify-between gap-2">
                        <span>{{ __('settings.aside_activity_title') }}</span>
                        @can('audit.events.view')
                            <button type="button" wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('audit')" class="min-h-11 px-3 rounded-control border border-border text-xs text-primary hover:bg-primary-50 font-bold">
                                {{ __('settings.card_audit_title') }} &rarr;
                            </button>
                        @endcan
                    </h4>
                    <div class="divide-y divide-border/60 text-xs">
                        @forelse ($auditEvents->take(4) as $event)
                            <div class="py-2 first:pt-0">
                                <div class="font-bold text-text-primary text-[11px] truncate">{{ $event->summary }}</div>
                                <div class="text-[10px] text-text-muted mt-0.5 flex items-center justify-between">
                                    <span>{{ $event->actor?->name ?? __('settings.actor_system') }}</span>
                                    <span class="font-mono text-[9px]">{{ $event->created_at->diffForHumans() }}</span>
                                </div>
                            </div>
                        @empty
                            <div class="py-3 text-center text-text-muted text-[11px]">
                                {{ __('settings.no_recent_activity') }}
                            </div>
                        @endforelse
                    </div>
                </div>

                <!-- Card 3: Permissions Advisory Callout -->
                <div class="bg-surface-blue border border-[#dce7fb] rounded-card p-3.5 text-xs text-[#51647d] leading-relaxed">
                    <div class="font-bold text-[#2f4c77] text-xs mb-1 flex items-center gap-1.5">
                        <x-icon name="shield" class="w-4 h-4 text-primary" />
                        <span>{{ __('settings.hint_title') }}</span>
                    </div>
                    <p class="text-[11px] text-[#51647d]">
                        {{ __('settings.hint_body') }}
                    </p>
                </div>
            </aside>
        </div>

    @else
        <!-- Sub-Section View (Identity, Localization, Currencies, Security, Users, Roles, Audit) -->
        <div class="space-y-4">
            <!-- Back Button & Section Title -->
            <div class="flex items-center justify-between gap-3 pb-3 border-b border-border">
                <button type="button" wire:loading.attr="disabled" wire:target="setSection" wire:click="setSection('overview')"
                        class="inline-flex items-center gap-2 text-xs font-bold text-primary hover:underline cursor-pointer">
                    <x-icon name="chevron" class="w-3.5 h-3.5 ltr:rotate-180" />
                    <span>{{ __('settings.back_to_all_settings') }}</span>
                </button>
                <span class="text-xs font-extrabold text-text-muted uppercase tracking-wider">
                    {{ $company?->displayName() }}
                </span>
            </div>

            @if ($activeSection === 'identity')
                <!-- 1. Company Identity Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-4xl space-y-5">
                    <div>
                        <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_company_title') }}</h2>
                        <p class="text-xs text-text-muted mt-0.5">{{ __('settings.card_company_desc') }}</p>
                    </div>

                    <form wire:submit="saveIdentity" class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">الاسم التجاري (عربي) *</label>
                                <input type="text" wire:model="name_ar" required
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                @error('name_ar') <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">Company Name (English)</label>
                                <input type="text" wire:model="name_en" dir="ltr"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                @error('name_en') <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">الاسم القانوني / المسجل (عربي)</label>
                                <input type="text" wire:model="legal_name_ar"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">Legal Registered Name (English)</label>
                                <input type="text" wire:model="legal_name_en" dir="ltr"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.tax_number') }}</label>
                                <input type="text" wire:model="tax_number" dir="ltr"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">رقم السجل التجاري</label>
                                <input type="text" wire:model="registration_number" dir="ltr"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">رقم الهاتف</label>
                                <input type="text" wire:model="phone" dir="ltr"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">واتساب</label>
                                <input type="text" wire:model="whatsapp" dir="ltr"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">البريد الإلكتروني</label>
                                <input type="email" wire:model="email" dir="ltr"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">الموقع الإلكتروني</label>
                                <input type="url" wire:model="website" dir="ltr" placeholder="https://"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">العنوان (عربي)</label>
                            <textarea wire:model="address_ar" rows="2"
                                      class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary"></textarea>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">Address (English)</label>
                            <textarea wire:model="address_en" dir="ltr" rows="2"
                                      class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary"></textarea>
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveIdentity">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveIdentity">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'localization')
                <!-- 2. Localization & Timezone Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div>
                        <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_localization_title') }}</h2>
                        <p class="text-xs text-text-muted mt-0.5">{{ __('settings.card_localization_desc') }}</p>
                    </div>

                    <form wire:submit="saveLocalization" class="space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-2">{{ __('settings.company_languages') }}</label>
                            <div class="divide-y divide-border border border-border rounded-control p-3 bg-canvas/30 space-y-2">
                                <div class="flex items-center justify-between py-1">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" checked disabled class="rounded border-border text-primary opacity-60 w-4 h-4" />
                                        <span class="text-xs font-bold text-text-primary">العربية (Arabic)</span>
                                    </div>
                                    <span class="text-[11px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full border border-emerald-200">
                                        {{ __('settings.always_enabled') }}
                                    </span>
                                </div>

                                <div class="flex items-center justify-between py-1 pt-2">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" id="english_enabled" wire:model.live="english_enabled"
                                               class="rounded border-border text-primary focus:ring-primary w-4 h-4 cursor-pointer" />
                                        <label for="english_enabled" class="text-xs font-bold text-text-primary cursor-pointer">
                                            English (الإنجليزية)
                                        </label>
                                    </div>
                                    <span class="text-[11px] font-semibold {{ $english_enabled ? 'text-primary' : 'text-text-muted' }}">
                                        {{ $english_enabled ? __('settings.status_enabled') : __('settings.status_disabled') }}
                                    </span>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.default_company_language') }}</label>
                            <select wire:model="default_locale" class="w-full text-xs rounded-control border border-border p-2.5 bg-white">
                                <option value="ar">العربية (Arabic - RTL)</option>
                                @if ($english_enabled)
                                    <option value="en">English (LTR)</option>
                                @endif
                            </select>
                            <x-input-error :messages="$errors->get('default_locale')" class="mt-1" />
                        </div>

                        <div>
                            <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.timezone') }}</label>
                            <select wire:model="timezone" class="w-full text-xs rounded-control border border-border p-2.5 bg-white font-mono text-[11px]">
                                <option value="Asia/Hebron">Asia/Hebron (القدس / فلسطين - GMT+2/3)</option>
                                <option value="Asia/Jerusalem">Asia/Jerusalem</option>
                                <option value="Asia/Amman">Asia/Amman (الأردن)</option>
                                <option value="Asia/Riyadh">Asia/Riyadh (السعودية)</option>
                                <option value="Asia/Dubai">Asia/Dubai (الإمارات)</option>
                                <option value="UTC">UTC (Universal Coordinated Time)</option>
                            </select>
                            <x-input-error :messages="$errors->get('timezone')" class="mt-1" />
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveLocalization">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveLocalization">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'currencies')
                <!-- 3. Currencies Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div>
                        <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_currencies_title') }}</h2>
                        <p class="text-xs text-text-muted mt-0.5">{{ __('settings.card_currencies_desc') }}</p>
                    </div>

                    @if ($isBaseCurrencyLocked)
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-control flex items-start gap-2.5 text-amber-800 text-xs">
                            <x-icon name="lock" class="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />
                            <div>
                                <span class="font-bold">{{ __('settings.base_currency_locked_notice') }}</span>
                                <p class="text-[11px] text-amber-700 mt-0.5">{{ __('settings.base_currency_locked_desc') }}</p>
                            </div>
                        </div>
                    @endif

                    <form wire:submit="saveCurrencies" class="space-y-4">
                        <div class="divide-y divide-border">
                            @foreach (['ILS' => ['name' => 'الشيكل الإسرائيلي (ILS)', 'symbol' => '₪'], 'USD' => ['name' => 'الدولار الأمريكي (USD)', 'symbol' => '$'], 'JOD' => ['name' => 'الدينار الأردني (JOD)', 'symbol' => 'د.أ']] as $code => $info)
                                <div class="py-3 flex items-center justify-between gap-3">
                                    <div class="flex items-center gap-3">
                                        <input type="checkbox" id="curr_{{ $code }}"
                                               wire:model="currencies_enabled.{{ $code }}"
                                               {{ $base_currency === $code ? 'disabled checked' : '' }}
                                               class="rounded border-border text-primary focus:ring-primary w-4 h-4 cursor-pointer" />
                                        <label for="curr_{{ $code }}" class="text-xs font-bold text-text-primary cursor-pointer">
                                             {{ $info['name'] }}
                                            <span class="font-mono text-text-muted">({{ $info['symbol'] }})</span>
                                        </label>
                                    </div>

                                    <div class="flex items-center gap-2">
                                        <input type="radio" id="base_{{ $code }}" name="base_curr" value="{{ $code }}"
                                               wire:model.live="base_currency"
                                               {{ $isBaseCurrencyLocked ? 'disabled' : '' }}
                                               class="text-primary focus:ring-primary w-4 h-4 {{ $isBaseCurrencyLocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}" />
                                        <label for="base_{{ $code }}" class="text-[11px] font-semibold text-text-secondary {{ $isBaseCurrencyLocked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer' }}">
                                            {{ __('settings.status_base_currency') }}
                                        </label>
                                        @if ($isBaseCurrencyLocked && $base_currency === $code)
                                            <span class="text-[9px] px-1.5 py-0.5 rounded bg-slate-100 text-text-muted font-bold">
                                                {{ __('settings.base_currency_locked_badge') }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveCurrencies">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveCurrencies">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'security')
                <!-- 4. Security Policies Form -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel max-w-2xl space-y-5">
                    <div>
                        <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_security_title') }}</h2>
                        <p class="text-xs text-text-muted mt-0.5">{{ __('settings.card_security_desc') }}</p>
                    </div>

                    <form wire:submit="saveSecurity" class="space-y-4">
                        <div class="p-3 rounded-card bg-surface-soft border border-border text-text-secondary text-[11px] leading-relaxed">
                            <span class="font-bold text-text-primary block mb-0.5">{{ __('settings.stored_policy_only_badge') }}</span>
                        </div>

                        <div class="space-y-3">
                            <p class="text-sm text-amber-900 bg-amber-50 rounded-control p-3" role="note">{{ __('settings.policy_not_enforced') }} <a href="{{ route('profile') }}" class="underline font-semibold">{{ __('settings.personal_security') }}</a></p>
                            <label class="flex items-start gap-3 p-3 rounded-control border border-border hover:bg-surface-soft cursor-pointer">
                                <input type="checkbox" wire:model="require_2fa_for_owner"
                                       class="rounded border-border text-primary focus:ring-primary w-4 h-4 mt-0.5" />
                                <div>
                                    <span class="text-xs font-bold text-text-primary block">{{ __('settings.require_2fa_owner_label') }}</span>
                                    <span class="text-[11px] text-text-muted block mt-0.5">{{ __('settings.require_2fa_owner_desc') }}</span>
                                </div>
                            </label>

                            <label class="flex items-start gap-3 p-3 rounded-control border border-border hover:bg-surface-soft cursor-pointer">
                                <input type="checkbox" wire:model="require_2fa_for_admin"
                                       class="rounded border-border text-primary focus:ring-primary w-4 h-4 mt-0.5" />
                                <div>
                                    <span class="text-xs font-bold text-text-primary block">{{ __('settings.require_2fa_admin_label') }}</span>
                                    <span class="text-[11px] text-text-muted block mt-0.5">{{ __('settings.require_2fa_admin_desc') }}</span>
                                </div>
                            </label>

                            <div>
                                <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.public_share_expiry_label') }}</label>
                                <input type="number" min="1" max="365" wire:model="public_share_default_expiry_days"
                                       class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary num" />
                            </div>
                        </div>

                        <div class="p-3 rounded-control bg-surface-soft border border-border flex items-center justify-between text-xs">
                            <span class="text-text-secondary">{{ __('settings.link_personal_2fa') }}</span>
                            <a href="{{ route('profile') }}" class="text-primary font-bold hover:underline">
                                {{ __('settings.go_to_profile_security') }} &rarr;
                            </a>
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="submit" wire:loading.attr="disabled"
                                    class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                <span wire:loading.remove wire:target="saveSecurity">{{ __('settings.save_changes') }}</span>
                                <span wire:loading wire:target="saveSecurity">{{ __('settings.saving') }}</span>
                            </button>
                        </div>
                    </form>
                </div>

            @elseif ($activeSection === 'users')
                <!-- 5. Users Administration View -->
                <div class="space-y-6 max-w-5xl">
                    <!-- Members List Table -->
                    <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_users_title') }}</h2>
                                <p class="text-xs text-text-muted mt-0.5">{{ __('settings.card_users_desc') }}</p>
                            </div>
                            <span class="text-xs font-bold text-text-secondary num">
                                {{ $memberships->count() }} {{ __('settings.usage_users') }}
                            </span>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="w-full text-xs text-start">
                                <thead>
                                    <tr class="border-b border-border text-text-muted text-[11px]">
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_name') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_email') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_role') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_status') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.joined_at') }}</th>
                                        <th class="py-2.5 px-3 font-bold text-end">{{ __('settings.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border/60">
                                    @foreach ($memberships as $m)
                                        @php
                                            $u = $m->user;
                                            $userRole = $u->roles->first();
                                        @endphp
                                        <tr class="hover:bg-surface-soft/60 transition-colors">
                                            <td class="py-3 px-3 font-bold text-text-primary">
                                                <div class="flex items-center gap-2">
                                                    <span>{{ $u->name }}</span>
                                                    @if ($m->is_owner)
                                                        <span class="px-1.5 py-0.5 rounded bg-amber-100 text-amber-800 text-[9px] font-bold">
                                                            {{ __('settings.role_owner') }}
                                                        </span>
                                                    @endif
                                                </div>
                                            </td>
                                            <td class="py-3 px-3 font-mono text-[11px] text-text-secondary">{{ $u->email }}</td>
                                            <td class="py-3 px-3">
                                                @if ($m->is_owner)
                                                    <span class="font-bold text-text-primary">{{ __('settings.role_owner') }}</span>
                                                @elseif (auth()->user()?->can('settings.users.manage'))
                                                    <select wire:change="updateUserRole({{ $m->id }}, $event.target.value)"
                                                            class="text-[11px] py-1 px-2 rounded border border-border bg-white font-medium">
                                                        @foreach (['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'] as $rName)
                                                            <option value="{{ $rName }}" {{ $userRole?->name === $rName ? 'selected' : '' }}>
                                                                {{ __('settings.roles_labels.'.$rName) }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                @else
                                                    <span class="text-[11px] font-medium text-text-secondary">{{ $userRole ? (\Illuminate\Support\Facades\Lang::has('settings.roles_labels.'.$userRole->name) ? __('settings.roles_labels.'.$userRole->name) : $userRole->name) : '—' }}</span>
                                                @endif
                                            </td>
                                            <td class="py-3 px-3">
                                                <x-badge :variant="$m->status === 'active' ? 'success' : 'neutral'">
                                                    {{ $m->status === 'active' ? __('settings.status_active') : __('settings.status_inactive') }}
                                                </x-badge>
                                            </td>
                                            <td class="py-3 px-3 text-[11px] text-text-muted num">
                                                {{ $m->joined_at ? $m->joined_at->format('Y-m-d') : '-' }}
                                            </td>
                                            <td class="py-3 px-3 text-end">
                                                @can('settings.users.manage')
                                                    @if (! $m->is_owner || $memberships->where('is_owner', true)->count() > 1)
                                                        <button type="button" wire:loading.attr="disabled" wire:target="toggleUserStatus" wire:click="toggleUserStatus({{ $m->id }})"
                                                                class="min-h-11 px-3 rounded-control border border-border text-xs font-bold text-primary hover:bg-primary-50 cursor-pointer">
                                                            {{ $m->status === 'active' ? __('settings.status_inactive') : __('settings.status_active') }}
                                                        </button>
                                                    @endif
                                                @else
                                                    <span class="text-text-muted text-[11px]">—</span>
                                                @endcan
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Add New User Form -->
                    @can('settings.users.manage')
                    <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <h3 class="text-sm font-extrabold text-text-primary">{{ __('settings.add_new_user') }}</h3>
                        <form wire:submit="createUser" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_name') }} *</label>
                                    <input type="text" wire:model="new_user_name" required
                                           class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                    @error('new_user_name') <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_email') }} *</label>
                                    <input type="email" wire:model="new_user_email" required dir="ltr"
                                           class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                    @error('new_user_email') <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_password') }} *</label>
                                    <input type="password" wire:model="new_user_password" required dir="ltr"
                                           class="w-full text-xs rounded-control border border-border p-2.5 focus:border-primary focus:ring-1 focus:ring-primary" />
                                    @error('new_user_password') <span class="text-[11px] text-rose-600">{{ $message }}</span> @enderror
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_role') }} *</label>
                                    <select wire:model="new_user_role" class="w-full text-xs rounded-control border border-border p-2.5 bg-white">
                                        @foreach (['Administrator', 'Manager', 'Sales', 'Purchasing', 'Warehouse', 'Cashier', 'Viewer'] as $roleOption)
                                            <option value="{{ $roleOption }}">{{ $roleOption }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold text-text-secondary mb-1">{{ __('settings.user_language') }}</label>
                                    <select wire:model="new_user_locale" class="w-full text-xs rounded-control border border-border p-2.5 bg-white">
                                        <option value="ar">العربية</option>
                                        <option value="en">English</option>
                                    </select>
                                </div>
                            </div>

                            <div class="pt-2 flex justify-end">
                                <button type="submit" wire:loading.attr="disabled"
                                        class="px-4 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                                    <span wire:loading.remove wire:target="createUser">{{ __('settings.add_new_user') }}</span>
                                    <span wire:loading wire:target="createUser">{{ __('settings.saving') }}</span>
                                </button>
                            </div>
                        </form>
                    </div>
                    @endcan
                </div>

            @elseif ($activeSection === 'roles')
                <!-- 6. Roles & Permissions View -->
                <div class="space-y-5 max-w-5xl">
                    <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_roles_title') }}</h2>
                            <p class="text-xs text-text-muted mt-0.5">{{ __('settings.card_roles_desc') }}</p>
                        </div>

                        <!-- Role Selector Pills -->
                        <div class="flex flex-wrap gap-2 pt-2">
                            @foreach ($roles as $role)
                                <button type="button" wire:click="selectRole('{{ $role->name }}')"
                                        class="min-h-11 px-3 py-2 rounded-control text-xs font-bold transition-all cursor-pointer
                                               {{ $selectedRoleName === $role->name ? 'bg-primary text-white shadow-xs' : 'bg-surface-soft text-text-secondary hover:bg-slate-200' }}">
                                    {{ \Illuminate\Support\Facades\Lang::has('settings.roles_labels.'.$role->name) ? __('settings.roles_labels.'.$role->name) : $role->name }}
                                    @if ($role->name === 'Owner')
                                        <span class="text-[9px] opacity-80">({{ __('settings.role_owner') }})</span>
                                    @endif
                                </button>
                            @endforeach
                        </div>

                        @if ($selectedRoleName === 'Owner')
                            <div class="p-3.5 rounded-card bg-amber-50 border border-amber-200 text-amber-800 text-xs">
                                <b>{{ __('settings.protected_role_notice') }}</b>
                            </div>
                        @endif

                        <!-- Grouped Permissions List -->
                        <div class="mt-4 pt-4 border-t border-border space-y-4">
                            <h3 class="text-xs font-extrabold text-text-primary">
                                {{ __('settings.role_permissions_for') }} <span class="text-primary font-mono">{{ $selectedRoleName }}</span>
                            </h3>

                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                                @php
                                    $groups = [
                                        __('settings.perm_group_settings') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'settings.') || str_starts_with($p->name, 'audit.')),
                                        __('settings.perm_group_sales') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'sales.')),
                                        __('settings.perm_group_purchasing') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'purchasing.')),
                                        __('settings.perm_group_inventory') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'inventory.')),
                                        __('settings.perm_group_parties') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'customers.') || str_starts_with($p->name, 'vendors.')),
                                        __('settings.perm_group_money_reports') => $allPermissions->filter(fn($p) => str_starts_with($p->name, 'money.') || str_starts_with($p->name, 'reports.')),
                                    ];
                                @endphp

                                @foreach ($groups as $groupTitle => $groupPerms)
                                    @if ($groupPerms->isNotEmpty())
                                        <div class="p-3 rounded-card bg-surface-soft/60 border border-border space-y-2">
                                            <h4 class="text-[11px] font-extrabold text-text-secondary uppercase tracking-wider">{{ $groupTitle }}</h4>
                                            <div class="space-y-1.5">
                                                @foreach ($groupPerms as $perm)
                                                    <label class="flex items-center justify-between gap-2 text-[11px] text-text-secondary cursor-pointer hover:text-text-primary">
                                                        <span class="font-mono text-[10px] truncate" title="{{ $perm->name }}">{{ $perm->name }}</span>
                                                        <input type="checkbox"
                                                               {{ !empty($rolePermissions[$perm->name]) ? 'checked' : '' }}
                                                               {{ $selectedRoleName === 'Owner' ? 'disabled' : '' }}
                                                               wire:loading.attr="disabled" wire:target="toggleRolePermission" wire:click="toggleRolePermission('{{ $perm->name }}')"
                                                               class="rounded border-border text-primary focus:ring-primary w-3.5 h-3.5" />
                                                    </label>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>

            @elseif ($activeSection === 'audit')
                <!-- 7. Audit Log View -->
                <div class="bg-white border border-border rounded-card p-5 sm:p-6 shadow-panel space-y-4 max-w-5xl">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-extrabold text-text-primary">{{ __('settings.card_audit_title') }}</h2>
                            <p class="text-xs text-text-muted mt-0.5">{{ __('settings.card_audit_desc') }}</p>
                        </div>
                    </div>

                    <form wire:submit="applyAuditFilters" class="grid grid-cols-1 sm:grid-cols-4 gap-3">
                        <label class="text-xs font-semibold">{{ __('settings.audit_from') }}<input type="date" wire:model="auditFrom" class="mt-1 w-full min-h-11 rounded-control border-border" />@error('auditFrom') <span role="alert" class="text-rose-700">{{ $message }}</span> @enderror</label>
                        <label class="text-xs font-semibold">{{ __('settings.audit_until') }}<input type="date" wire:model="auditUntil" class="mt-1 w-full min-h-11 rounded-control border-border" />@error('auditUntil') <span role="alert" class="text-rose-700">{{ $message }}</span> @enderror</label>
                        <label class="text-xs font-semibold">{{ __('settings.audit_event_prefix') }}<input type="text" wire:model="auditEvent" maxlength="80" dir="ltr" class="mt-1 w-full min-h-11 rounded-control border-border" />@error('auditEvent') <span role="alert" class="text-rose-700">{{ $message }}</span> @enderror</label>
                        <button type="submit" wire:loading.attr="disabled" class="min-h-11 self-end rounded-control border border-primary px-3 py-2 font-bold text-primary">{{ __('settings.audit_apply') }}</button>
                    </form>
                    <div class="md:hidden space-y-3">
                        @forelse ($auditEvents as $event)
                            <article class="rounded-control border border-border p-3 space-y-2 text-sm" wire:key="audit-card-{{ $event->id }}">
                                <div class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="font-bold text-text-primary break-words">{{ $event->actor?->name ?? __('settings.actor_system') }}</span>
                                    <time class="font-mono text-xs text-text-muted" dir="ltr">{{ $event->created_at->format('Y-m-d H:i') }}</time>
                                </div>
                                <p class="font-mono text-xs text-primary break-all" dir="ltr">{{ $event->event_key }}</p>
                                <p class="text-text-secondary break-words">{{ $event->summary }}</p>
                            </article>
                        @empty
                            <p class="py-6 text-center text-text-muted">{{ __('settings.no_audit_events') }}</p>
                        @endforelse
                    </div>
                    <div class="hidden md:block overflow-x-auto">
                        <table class="w-full text-xs text-start">
                            <thead>
                                <tr class="border-b border-border text-text-muted text-[11px]">
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_occurred_at') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.user_name') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_event') }}</th>
                                    <th class="py-2.5 px-3 font-bold text-start">{{ __('settings.audit_details') }}</th>

                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border/60">
                                @forelse ($auditEvents as $event)
                                    <tr class="hover:bg-surface-soft/60 transition-colors">
                                        <td class="py-3 px-3 font-mono text-[11px] text-text-muted whitespace-nowrap">
                                            {{ $event->created_at->format('Y-m-d H:i') }}
                                        </td>
                                        <td class="py-3 px-3 font-bold text-text-primary">
                                            {{ $event->actor?->name ?? __('settings.actor_system') }}
                                        </td>
                                        <td class="py-3 px-3 font-mono text-[11px] text-primary">
                                            {{ $event->event_key }}
                                        </td>
                                        <td class="py-3 px-3 text-text-secondary">
                                            {{ $event->summary }}
                                        </td>

                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="py-6 text-center text-text-muted">
                                            {{ __('settings.no_audit_events') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    {{ $auditEvents->links() }}
                </div>
            @endif
        </div>
    @endif
</div>
