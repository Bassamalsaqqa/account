<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? __('app.app_name') }} — {{ __('app.app_subtitle') }}</title>

    <!-- Google Fonts: IBM Plex Sans Arabic -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Styles & Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-canvas text-text-primary antialiased font-arabic selection:bg-primary-100 selection:text-primary min-h-screen"
      x-data="{ mobileMenuOpen: false }" @keydown.escape.window="mobileMenuOpen = false">

    @php
        $isRtl = app()->getLocale() === 'ar';
    @endphp

    <!-- Mobile Drawer Overlay -->
    <div x-show="mobileMenuOpen"
         x-transition:enter="transition-opacity ease-linear duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition-opacity ease-linear duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-40 bg-slate-900/40 backdrop-blur-xs lg:hidden"
         @click="mobileMenuOpen = false"
         style="display: none;"></div>

    <!-- Sidebar Navigation -->
    <aside id="app-sidebar" class="fixed top-0 bottom-0 z-50 w-[264px] bg-white transition-transform duration-200 ease-in-out flex flex-col
                  {{ $isRtl ? 'right-0 border-l border-border' : 'left-0 border-r border-border' }}
                  {{ $isRtl ? 'max-lg:translate-x-full' : 'max-lg:-translate-x-full' }}"
           :class="mobileMenuOpen ? 'translate-x-0!' : ''">

        <!-- Sidebar Header / Brand -->
        <div class="p-4 pb-3 border-b border-border">
            <div class="flex items-center gap-3">
                <div class="w-[42px] h-[42px] rounded-card bg-primary text-white grid place-items-center shadow-[0_6px_16px_rgba(37,95,214,.18)] shrink-0">
                    <x-icon name="store" class="w-6 h-6" />
                </div>
                <div class="min-w-0 flex-1">
                    <div class="text-[15px] font-extrabold leading-tight truncate text-text-primary">
                        {{ __('app.app_name') }}
                    </div>
                    <div class="text-[11px] text-text-muted mt-0.5 truncate">
                        {{ __('app.app_subtitle') }}
                    </div>
                </div>
            </div>

            <!-- Active Company Pill -->
            @php
                $sidebarContext = app(\App\Support\Tenancy\CompanyContext::class);
                $sidebarCompany = $sidebarContext->hasCompany() ? $sidebarContext->company() : null;
                $userActiveCompanies = auth()->check() ? auth()->user()->activeCompanies : collect();
            @endphp
            <div class="mt-3 px-3 py-2 rounded-control bg-surface-soft flex items-center justify-between gap-2 text-xs text-text-secondary border border-border/50">
                <div class="flex items-center gap-2 min-w-0">
                    <x-icon name="store" class="w-4 h-4 text-primary shrink-0" />
                    @if($sidebarCompany)
                        <span class="truncate font-bold text-text-primary">{{ $sidebarCompany->displayName() }}</span>
                    @else
                        <span class="truncate text-text-muted">{{ __('settings.no_active_company') }}</span>
                    @endif
                </div>
                @if ($userActiveCompanies->count() > 1)
                    <a href="{{ route('companies.select') }}" class="text-[10px] text-primary hover:underline font-bold shrink-0">
                        {{ __('settings.activate') }}
                    </a>
                @endif
            </div>
        </div>

        <!-- Sidebar Navigation Items -->
        <div class="flex-1 overflow-y-auto px-3 py-3 space-y-4 text-xs">

            <!-- Dashboard -->
            <div class="space-y-0.5">
                <a href="{{ route('dashboard') }}"
                   class="flex items-center justify-between h-[39px] px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                          {{ request()->routeIs('dashboard') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                    <div class="flex items-center gap-2.5">
                        <x-icon name="dashboard" class="w-4.5 h-4.5" />
                        <span>{{ __('app.nav_dashboard') }}</span>
                    </div>
                </a>
            </div>

            <!-- Sales & Customers -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold tracking-wider text-text-muted uppercase">
                    {{ __('app.nav_group_sales_customers') }}
                </div>
                <div class="space-y-0.5">
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="receipt" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_sales_invoices') }}</span>
                        </div>
                    </span>
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="quote" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_quotes') }}</span>
                        </div>
                    </span>
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="users" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_customers') }}</span>
                        </div>
                    </span>
                </div>
            </div>

            <!-- Purchases & Inventory -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold tracking-wider text-text-muted uppercase">
                    {{ __('app.nav_group_purchasing_inventory') }}
                </div>
                <div class="space-y-0.5">
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="cart" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_purchase_invoices') }}</span>
                        </div>
                    </span>
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="truck" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_vendors') }}</span>
                        </div>
                    </span>
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="box" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_products') }}</span>
                        </div>
                    </span>
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="expiry" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_expiry_center') }}</span>
                        </div>
                        <x-badge variant="alert">3</x-badge>
                    </span>
                </div>
            </div>

            <!-- Treasury & Reports -->
            <div class="space-y-1">
                <div class="px-3 text-[10px] font-bold tracking-wider text-text-muted uppercase">
                    {{ __('app.nav_group_treasury_reports') }}
                </div>
                <div class="space-y-0.5">
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="bank" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_treasury_banks') }}</span>
                        </div>
                    </span>
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="check" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_checkbook') }}</span>
                        </div>
                        <x-badge>5</x-badge>
                    </span>
                    <span title="{{ __('app.future_module_notice') }}" class="flex items-center justify-between h-[38px] px-3 rounded-control text-text-muted opacity-60 cursor-not-allowed">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="chart" class="w-4.5 h-4.5" />
                            <span>{{ __('app.nav_financial_reports') }}</span>
                        </div>
                    </span>
                </div>
            </div>

            <!-- System -->
            <div class="space-y-1 pt-1">
                <div class="px-3 text-[10px] font-bold tracking-wider text-text-muted uppercase">
                    {{ __('app.nav_group_system') }}
                </div>
                <div class="space-y-0.5">
                    <a href="{{ route('settings.index') }}"
                       class="flex items-center justify-between h-[39px] px-3 rounded-control font-semibold transition-colors
                              {{ request()->routeIs('settings.*') ? 'bg-primary-50 text-primary font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : 'text-text-secondary hover:bg-surface-soft hover:text-text-primary' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="settings" class="w-4.5 h-4.5 text-primary" />
                            <span>{{ __('app.nav_settings') }}</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <div class="p-3 px-4 border-t border-border text-[11px] text-text-muted bg-surface-soft/40">
            {{ __('app.phase_zero_preview') }}
        </div>
    </aside>

    <!-- Top Bar -->
    <header class="fixed top-0 z-30 h-[64px] bg-white/95 backdrop-blur-md border-b border-border flex items-center justify-between px-4 sm:px-6 gap-4
                   {{ $isRtl ? 'right-[264px] left-0' : 'left-[264px] right-0' }}
                   max-lg:right-0! max-lg:left-0!">

        <!-- Left / Start Area (Mobile button + Search) -->
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <button @click="mobileMenuOpen = !mobileMenuOpen" :aria-expanded="mobileMenuOpen.toString()" aria-controls="app-sidebar" aria-label="{{ __('app.open_navigation') }}"
                    type="button"
                    class="lg:hidden w-10 h-10 rounded-control border border-border flex items-center justify-center text-text-secondary hover:bg-surface-soft">
                <x-icon name="menu" class="w-5 h-5" />
            </button>

            <!-- Search Field -->
            <div class="relative w-full max-w-[480px]">
                <div class="flex items-center h-10 px-3 rounded-control bg-surface-soft border border-transparent focus-within:border-primary/40 focus-within:bg-white focus-within:shadow-focus-primary transition-all">
                    <x-icon name="search" class="w-4.5 h-4.5 text-text-muted shrink-0" />
                    <input type="text"
                           id="globalSearchInput"
                           placeholder="{{ __('app.search_placeholder') }}"
                           class="w-full bg-transparent border-0 text-xs px-2.5 text-text-primary placeholder:text-text-muted focus:ring-0 focus:outline-none" />
                    <kbd class="hidden sm:inline-block text-[10px] text-text-muted bg-white border border-border rounded px-1.5 py-0.5 font-mono shadow-2xs shrink-0">
                        {{ __('app.search_kbd') }}
                    </kbd>
                </div>
            </div>
        </div>

        <!-- Right / End Area (Actions + User Profile) -->
        <div class="flex items-center gap-2 sm:gap-3 shrink-0">

            <!-- Quick Add Button -->
            <button type="button" disabled aria-disabled="true"
                    title="{{ __('app.future_module_notice') }}"
                    class="hidden sm:flex items-center gap-1.5 h-[38px] px-3 rounded-control bg-primary/55 text-white text-xs font-bold cursor-not-allowed">
                <x-icon name="plus" class="w-4 h-4" />
                <span>{{ __('app.quick_add') }}</span>
                <x-icon name="expand" class="w-3 h-3 opacity-80" />
            </button>

            <!-- Language Switcher -->
            <form method="POST" action="{{ route('locale.switch') }}" class="inline">
                @csrf
                <input type="hidden" name="locale" value="{{ $isRtl ? 'en' : 'ar' }}">
                <button type="submit"
                   class="h-[38px] px-2.5 rounded-control border border-border bg-white text-xs font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary flex items-center gap-1.5 transition-colors cursor-pointer"
                   title="{{ $isRtl ? 'Switch to English' : 'التحويل إلى العربية' }}">
                    <x-icon name="globe" class="w-4 h-4 text-text-muted" />
                    <span class="font-bold">{{ $isRtl ? 'EN' : 'عربي' }}</span>
                </button>
            </form>

            <!-- Notifications Button -->
            <button type="button" disabled aria-disabled="true" title="{{ __('app.future_module_notice') }}"
                    class="relative w-[38px] h-[38px] rounded-control text-text-muted opacity-60 cursor-not-allowed flex items-center justify-center">
                <x-icon name="bell" class="w-5 h-5" />
            </button>

            <!-- Help Button -->
            <button type="button" disabled aria-disabled="true" title="{{ __('app.future_module_notice') }}"
                    class="hidden sm:flex w-[38px] h-[38px] rounded-control text-text-muted opacity-60 cursor-not-allowed items-center justify-center">
                <x-icon name="help" class="w-5 h-5" />
            </button>

            <!-- User Menu Dropdown -->
            <div class="relative" x-data="{ openUserMenu: false }" @click.outside="openUserMenu = false">
                <button @click="openUserMenu = !openUserMenu"
                        type="button"
                        class="flex items-center gap-2.5 py-1 px-1 sm:px-2 rounded-control hover:bg-surface-soft transition-colors text-right">
                    <div class="w-[34px] h-[34px] rounded-full bg-slate-200 text-slate-700 font-extrabold text-xs grid place-items-center shrink-0 border border-slate-300">
                        {{ mb_substr(auth()->user()->name ?? 'AM', 0, 2) }}
                    </div>
                    <div class="hidden md:block text-start leading-tight">
                        <div class="text-xs font-extrabold text-text-primary truncate max-w-[120px]">
                            {{ auth()->user()->name ?? __('app.user_default_name') }}
                        </div>
                        <div class="text-[10px] text-text-muted">
                            {{ auth()->user()->email }}
                        </div>
                    </div>
                </button>

                <!-- Dropdown panel -->
                <div x-show="openUserMenu"
                     x-transition:enter="transition ease-out duration-100"
                     x-transition:enter-start="transform opacity-0 scale-95"
                     x-transition:enter-end="transform opacity-100 scale-100"
                     x-transition:leave="transition ease-in duration-75"
                     x-transition:leave-start="transform opacity-100 scale-100"
                     x-transition:leave-end="transform opacity-0 scale-95"
                     class="absolute {{ $isRtl ? 'left-0' : 'right-0' }} mt-2 w-48 rounded-card bg-white shadow-xl border border-border py-1 text-xs z-50"
                     style="display: none;">
                    <a href="{{ route('profile') }}" class="block px-4 py-2 hover:bg-surface-soft text-text-secondary hover:text-text-primary">
                        {{ __('app.profile') }}
                    </a>
                    <div class="border-t border-border my-1"></div>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full text-start px-4 py-2 hover:bg-danger-bg text-danger font-semibold">
                            {{ __('app.logout') }}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Content Wrapper -->
    <div class="min-h-screen pt-[64px] {{ $isRtl ? 'lg:mr-[264px]' : 'lg:ml-[264px]' }}">
        <main class="max-w-[1440px] mx-auto p-4 sm:p-6 lg:p-8">
            {{ $slot }}
        </main>
    </div>

    @livewireScripts
</body>
</html>
