<div class="space-y-6" x-data="{
    searchQuery: '',
    matches(text) {
        if (!this.searchQuery.trim()) return true;
        return text.toLowerCase().includes(this.searchQuery.toLowerCase().trim());
    }
}">
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
            <div class="mt-2.5 inline-flex items-center gap-2 px-2.5 py-1 rounded bg-amber-50 border border-amber-200 text-amber-800 text-[11px] font-medium">
                <span class="font-bold shrink-0">[{{ __('settings.phase0_proof_tag') }}]</span>
                <span>{{ __('settings.phase0_proof_notice') }}</span>
            </div>
        </div>

    </div>

    <!-- Context Cards Grid (Company, Plan, Quota) -->
    <section class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-12 gap-3.5">

        <!-- 1. Company Profile Card (5 cols on lg) -->
        <div class="lg:col-span-5 bg-white border border-border rounded-card p-4 sm:p-5 shadow-panel flex flex-col justify-between">
            <div>
                <div class="flex items-start justify-between gap-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-11 h-11 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 border border-primary-100">
                            <x-icon name="store" class="w-6 h-6" />
                        </div>
                        <div class="min-w-0">
                            <h2 class="text-sm font-extrabold text-text-primary truncate">
                                {{ __('settings.demo_company_name') }}
                            </h2>
                            <p class="text-[11px] text-text-muted mt-0.5 truncate">
                                {{ __('settings.demo_company_desc') }}
                            </p>
                        </div>
                    </div>
                    <button type="button" disabled aria-disabled="true"
                            class="px-2.5 py-1.5 rounded-control border border-border text-text-muted text-xs font-bold shrink-0 cursor-not-allowed">
                        {{ __('settings.edit_company_data') }}
                    </button>
                </div>
            </div>

            <!-- Company Meta Row -->
            <div class="mt-4 pt-3 border-t border-border/60 flex flex-wrap items-center gap-x-4 gap-y-2 text-[11px] text-text-secondary">
                <span class="inline-flex items-center gap-1.5">
                    <x-icon name="file" class="w-3.5 h-3.5 text-text-muted shrink-0" />
                    <span>{{ __('settings.tax_number') }} <b class="num text-text-primary font-bold">{{ __('settings.demo_tax_number') }}</b></span>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <x-icon name="phone" class="w-3.5 h-3.5 text-text-muted shrink-0" />
                    <b class="num text-text-primary font-bold">{{ __('settings.demo_phone') }}</b>
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <x-icon name="pin" class="w-3.5 h-3.5 text-text-muted shrink-0" />
                    <span>{{ __('settings.demo_address') }}</span>
                </span>
            </div>
        </div>

        <!-- 2. Subscription Plan Card (3 cols on lg) -->
        <div class="lg:col-span-3 bg-white border border-border rounded-card p-4 sm:p-5 shadow-panel flex flex-col justify-between">
            <div>
                <div class="flex items-center justify-between gap-2 mb-1">
                    <span class="text-[10px] font-bold text-text-muted uppercase tracking-wider">{{ __('settings.current_plan') }}</span>
                    <span class="text-[9px] bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-bold">{{ __('settings.demo_badge') }}</span>
                </div>
                <div class="text-base font-extrabold text-text-primary">
                    {{ __('settings.plan_name') }}
                </div>
                <p class="text-[11px] text-text-muted mt-1 leading-snug">
                    {{ __('settings.plan_specs') }}
                </p>
            </div>
            <button type="button" disabled aria-disabled="true"
                    class="mt-4 w-full h-[34px] rounded-control bg-primary-50 text-primary/60 text-xs font-bold cursor-not-allowed">
                {{ __('settings.manage_subscription') }}
            </button>
        </div>

        <!-- 3. Plan Usage / Health Card (4 cols on lg) -->
        <div class="md:col-span-2 lg:col-span-4 bg-white border border-border rounded-card p-4 sm:p-5 shadow-panel flex flex-col justify-between">
            <div class="flex items-center justify-between gap-2 mb-3">
                <span class="text-xs font-extrabold text-text-primary">{{ __('settings.plan_usage') }}</span>
                <x-badge variant="success">{{ __('settings.within_limits') }}</x-badge>
            </div>

            <!-- Progress Rows -->
            <div class="space-y-2.5 text-xs text-text-secondary">
                <!-- Products -->
                <div class="grid grid-cols-12 items-center gap-2">
                    <span class="col-span-4 font-semibold text-[11px] truncate">{{ __('settings.usage_products') }}</span>
                    <div class="col-span-5 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-primary" style="width: 25%"></div>
                    </div>
                    <span class="col-span-3 text-end num text-[10px] text-text-muted font-bold">1,245 / 5k</span>
                </div>

                <!-- Invoices -->
                <div class="grid grid-cols-12 items-center gap-2">
                    <span class="col-span-4 font-semibold text-[11px] truncate">{{ __('settings.usage_invoices') }}</span>
                    <div class="col-span-5 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-primary" style="width: 17%"></div>
                    </div>
                    <span class="col-span-3 text-end num text-[10px] text-text-muted font-bold">342 / 2k</span>
                </div>

                <!-- Users -->
                <div class="grid grid-cols-12 items-center gap-2">
                    <span class="col-span-4 font-semibold text-[11px] truncate">{{ __('settings.usage_users') }}</span>
                    <div class="col-span-5 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full bg-primary" style="width: 40%"></div>
                    </div>
                    <span class="col-span-3 text-end num text-[10px] text-text-muted font-bold">4 / 10</span>
                </div>
            </div>
        </div>
    </section>

    <!-- Interactive Local Search for Settings -->
    <div class="relative">
        <div class="flex items-center h-10 px-3.5 rounded-card bg-white border border-border focus-within:border-primary focus-within:shadow-focus-primary transition-all">
            <x-icon name="search" class="w-4 h-4 text-text-muted shrink-0" />
            <input type="text"
                   x-model="searchQuery"
                   placeholder="{{ __('app.search_placeholder') }}"
                   class="w-full bg-transparent border-0 text-xs px-2.5 text-text-primary placeholder:text-text-muted focus:ring-0 focus:outline-none" />
            <button x-show="searchQuery"
                    @click="searchQuery = ''"
                    type="button"
                    class="text-xs text-text-muted hover:text-text-primary font-bold">
                âœ•
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
                    <!-- Company & Branches -->
                    <div x-show="matches('{{ __('settings.card_company_title') }} {{ __('settings.card_company_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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
                    <div x-show="matches('{{ __('settings.card_localization_title') }} {{ __('settings.card_localization_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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
                    <div x-show="matches('{{ __('settings.card_currencies_title') }} {{ __('settings.card_currencies_desc') }} ILS USD JOD')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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
                                    ILS â€¢ USD â€¢ JOD
                                </span>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Taxes -->
                    <div x-show="matches('{{ __('settings.card_taxes_title') }} {{ __('settings.card_taxes_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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
                    </div>
                </div>
            </section>

            <!-- Section 2: Operations (Sales, Purchases, Inventory, Banking) -->
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
                    <!-- Sales Settings -->
                    <div x-show="matches('{{ __('settings.card_sales_title') }} {{ __('settings.card_sales_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="receipt" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_sales_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_sales_desc') }}
                                </p>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Purchases Settings -->
                    <div x-show="matches('{{ __('settings.card_purchases_title') }} {{ __('settings.card_purchases_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="cart" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_purchases_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_purchases_desc') }}
                                </p>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Inventory Settings -->
                    <div x-show="matches('{{ __('settings.card_inventory_title') }} {{ __('settings.card_inventory_desc') }} {{ __('settings.tag_cost_locked') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="box" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_inventory_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_inventory_desc') }}
                                </p>
                                <span class="inline-block mt-2 px-2 py-0.5 rounded-md bg-warning-bg text-warning text-[9px] font-bold">
                                    {{ __('settings.tag_cost_locked') }}
                                </span>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Cash & Banking -->
                    <div x-show="matches('{{ __('settings.card_banking_title') }} {{ __('settings.card_banking_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="bank" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_banking_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_banking_desc') }}
                                </p>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>
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
                    <!-- Users -->
                    <div x-show="matches('{{ __('settings.card_users_title') }} {{ __('settings.card_users_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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
                                    4 / 10
                                </span>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Roles & Permissions -->
                    <div x-show="matches('{{ __('settings.card_roles_title') }} {{ __('settings.card_roles_desc') }} {{ __('settings.tag_owner_only') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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
                                    {{ __('settings.tag_owner_only') }}
                                </span>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Security -->
                    <div x-show="matches('{{ __('settings.card_security_title') }} {{ __('settings.card_security_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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

                    <!-- Audit Log -->
                    <div x-show="matches('{{ __('settings.card_audit_title') }} {{ __('settings.card_audit_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
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
                    <div x-show="matches('{{ __('settings.card_numbering_title') }} {{ __('settings.card_numbering_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="number" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_numbering_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_numbering_desc') }}
                                </p>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Print & PDF -->
                    <div x-show="matches('{{ __('settings.card_print_title') }} {{ __('settings.card_print_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="print" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_print_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_print_desc') }}
                                </p>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Sharing & Links -->
                    <div x-show="matches('{{ __('settings.card_share_title') }} {{ __('settings.card_share_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="share" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_share_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_share_desc') }}
                                </p>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>

                    <!-- Data Export & Backups -->
                    <div x-show="matches('{{ __('settings.card_backup_title') }} {{ __('settings.card_backup_desc') }}')"
                         class="bg-white border border-border hover:border-[#c7d3e4] rounded-card p-3.5 shadow-panel hover:shadow-panel-hover hover:-translate-y-0.5 transition-all flex items-start justify-between gap-3 group">
                        <div class="flex items-start gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0 group-hover:bg-primary group-hover:text-white transition-colors">
                                <x-icon name="database" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <h4 class="text-xs font-extrabold text-text-primary group-hover:text-primary transition-colors">
                                    {{ __('settings.card_backup_title') }}
                                </h4>
                                <p class="text-[11px] text-text-muted mt-0.5 leading-snug">
                                    {{ __('settings.card_backup_desc') }}
                                </p>
                            </div>
                        </div>
                        <x-icon name="chevron" class="w-4 h-4 text-text-muted shrink-0 mt-1 rtl:rotate-180 group-hover:text-primary transition-colors" />
                    </div>
                </div>
            </section>
        </div>

        <!-- Aside Column: Overview, Activity, Permissions (4 cols on lg) -->
        <aside class="lg:col-span-4 space-y-4">

            <!-- Card 1: Configuration Overview -->
            <div class="bg-white border border-border rounded-card p-4 shadow-panel">
                <h4 class="text-xs font-extrabold text-text-primary mb-3 flex items-center justify-between gap-2">
                    <span>{{ __('settings.aside_status_title') }}</span>
                    <span class="text-[9px] text-text-muted bg-surface-soft rounded px-1.5 py-0.5">{{ __('settings.demo_badge') }}</span>
                </h4>
                <div class="divide-y divide-border/60 text-xs">
                    <div class="py-2 flex items-center justify-between first:pt-0">
                        <span class="text-text-muted">{{ __('settings.status_base_currency') }}</span>
                        <span class="font-bold text-text-primary font-mono">ILS</span>
                    </div>
                    <div class="py-2 flex items-center justify-between">
                        <span class="text-text-muted">{{ __('settings.status_taxes') }}</span>
                        <span class="font-bold text-success">{{ __('settings.status_taxes_value') }}</span>
                    </div>
                    <div class="py-2 flex items-center justify-between">
                        <span class="text-text-muted">{{ __('settings.status_2fa') }}</span>
                        <span class="font-bold text-warning">{{ __('settings.status_2fa_value') }}</span>
                    </div>
                    <div class="py-2 flex items-center justify-between last:pb-0">
                        <span class="text-text-muted">{{ __('settings.status_last_backup') }}</span>
                        <span class="font-bold text-text-primary">{{ __('settings.status_last_backup_value') }}</span>
                    </div>
                </div>
            </div>

            <!-- Card 2: Recent Activity / Audit Log -->
            <div class="bg-white border border-border rounded-card p-4 shadow-panel">
                <h4 class="text-xs font-extrabold text-text-primary mb-3 flex items-center justify-between gap-2">
                    <span>{{ __('settings.aside_activity_title') }}</span>
                    <span class="text-[9px] text-text-muted bg-surface-soft rounded px-1.5 py-0.5">{{ __('settings.demo_badge') }}</span>
                </h4>
                <div class="divide-y divide-border/60 text-xs">
                    <div class="py-2 first:pt-0">
                        <div class="font-bold text-text-primary text-[11px]">{{ __('settings.activity_1_title') }}</div>
                        <div class="text-[10px] text-text-muted mt-0.5">{{ __('settings.activity_1_meta') }}</div>
                    </div>
                    <div class="py-2">
                        <div class="font-bold text-text-primary text-[11px]">{{ __('settings.activity_2_title') }}</div>
                        <div class="text-[10px] text-text-muted mt-0.5">{{ __('settings.activity_2_meta') }}</div>
                    </div>
                    <div class="py-2 last:pb-0">
                        <div class="font-bold text-text-primary text-[11px]">{{ __('settings.activity_3_title') }}</div>
                        <div class="text-[10px] text-text-muted mt-0.5">{{ __('settings.activity_3_meta') }}</div>
                    </div>
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
</div>
