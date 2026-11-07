<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        try {
            document.documentElement.dataset.sidebarCollapsed = localStorage.getItem('accounting.sidebar.collapsed') === 'true' ? 'true' : 'false';
        } catch { document.documentElement.dataset.sidebarCollapsed = 'false'; }
    </script>

    <title>{{ $title ?? __('app.app_name') }} — {{ __('app.app_subtitle') }}</title>

    <!-- Favicons -->
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-touch-icon.png') }}">

    <!-- Open Graph & Social Cards -->
    <meta property="og:title" content="{{ $title ?? __('app.app_name') }} — {{ __('app.app_subtitle') }}">
    <meta property="og:description" content="{{ __('app.app_subtitle') }}">
    <meta property="og:type" content="website">
    <meta property="og:image" content="{{ asset('og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ asset('og-image.png') }}">

    <!-- Styles & Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles

    @if (config('pwa.enabled', false))
        <meta name="pwa-enabled" content="true">
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
        <meta name="theme-color" content="{{ config('pwa.theme_color', '#255fd6') }}">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('pwa.short_name', 'التاجر الصغير') }}">
        <link rel="apple-touch-icon" href="{{ asset('icons/icon-192.png') }}">
    @endif
</head>
<body class="business-app bg-canvas text-text-primary antialiased font-arabic selection:bg-primary-100 selection:text-primary min-h-screen"
      x-data="accountingMobileShell"
      @resize.window="mobileLayout = window.innerWidth < 1024"
      @keydown.escape.window="if (mobileMenuOpen) { mobileMenuOpen = false; $refs.navToggle?.focus(); }">

    @php
        $isRtl = app()->getLocale() === 'ar';
        $companyContext = app(\App\Support\Tenancy\CompanyContext::class);
        $activeCompany = $activeCompany ?? ($companyContext->hasCompany() ? $companyContext->company() : null);
        $englishEnabled = $englishEnabled ?? ($activeCompany ? $activeCompany->isLanguageEnabled('en') : true);
        $isSupportView = app(\App\Application\Platform\SupportViewContext::class)->active();
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
    <aside id="app-sidebar" class="app-sidebar-shell fixed top-0 bottom-0 z-50 bg-surface flex flex-col
                  {{ $isRtl ? 'right-0 border-l border-border' : 'left-0 border-r border-border' }}
                  {{ $isRtl ? 'max-lg:translate-x-full' : 'max-lg:-translate-x-full' }}"
           :class="[
               mobileMenuOpen ? 'translate-x-0!' : '',
               'w-[264px]'
           ]"
           :inert="mobileLayout && !mobileMenuOpen" x-trap.inert.noscroll="mobileLayout && mobileMenuOpen">

        <!-- Sidebar Header / Brand -->
        <div class="p-4 pb-3 border-b border-border transition-colors">
            <div class="flex items-center gap-3" :class="sidebarCollapsed ? 'lg:justify-center' : ''">
                <div class="w-[42px] h-[42px] rounded-card bg-primary text-white grid place-items-center shadow-[0_6px_16px_rgba(37,95,214,.18)] shrink-0"
                     :title="sidebarCollapsed ? '{{ __('app.app_name') }}' : ''">
                    <x-icon name="store" class="w-6 h-6" />
                </div>
                <div class="min-w-0 flex-1" :class="sidebarCollapsed ? 'lg:hidden' : ''">
                    <div class="text-[15px] font-extrabold leading-tight truncate text-text-primary">
                        {{ __('app.app_name') }}
                    </div>
                    <div class="text-xs text-text-muted mt-0.5 truncate">
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
            <div class="mt-3 px-3 py-2 rounded-control bg-surface-soft flex items-center justify-between gap-2 text-xs text-text-secondary border border-border/50"
                 :class="sidebarCollapsed ? 'lg:hidden' : ''">
                <div class="flex items-center gap-2 min-w-0">
                    <x-icon name="store" class="w-4 h-4 text-primary shrink-0" />
                    @if($sidebarCompany)
                        <span class="truncate font-bold text-text-primary">{{ $sidebarCompany->displayName() }}</span>
                    @else
                        <span class="truncate text-text-muted">{{ __('settings.no_active_company') }}</span>
                    @endif
                </div>
                @if (! $isSupportView && $userActiveCompanies->count() > 1)
                    <a href="{{ route('companies.select') }}" aria-current="{{ request()->routeIs('companies.select') ? 'page' : 'false' }}" class="text-sm text-primary hover:underline font-bold shrink-0">
                        {{ __('settings.activate') }}
                    </a>
                @endif
            </div>
        </div>

        <!-- Sidebar Navigation Items with Group Collapse & Scroll Persistence -->
        <div id="app-sidebar-nav"
             x-data="accountingSidebarNavigation"
             x-init="$nextTick(() => restoreScroll())"
             @scroll.debounce.150ms="saveScroll()"
             @click.capture="saveScroll()"
             class="flex-1 overflow-y-auto px-3 py-3 space-y-3 text-sm"
             :class="sidebarCollapsed ? 'lg:px-2' : ''">

            <!-- Overview & Reports -->
            <div class="space-y-0.5">
                <a href="{{ route('dashboard') }}" aria-current="{{ request()->routeIs('dashboard') ? 'page' : 'false' }}"
                   :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                   title="{{ __('app.nav_dashboard') }}" aria-label="{{ __('app.nav_dashboard') }}"
                   class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                          {{ request()->routeIs('dashboard') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                    <div class="flex items-center gap-2.5">
                        <x-icon name="dashboard" class="w-4.5 h-4.5 shrink-0" />
                        <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_dashboard') }}</span>
                    </div>
                </a>
                @if($sidebarCompany && app(\App\Application\Reporting\Presentation\ReportRegistry::class)->hasVisible($sidebarCompany))
                    <a href="{{ route('reports.index') }}" aria-current="{{ request()->routeIs('reports.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('reports.title') }}" aria-label="{{ __('reports.title') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors
                              {{ request()->routeIs('reports.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="chart" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('reports.title') }}</span>
                        </div>
                    </a>
                @endif
            </div>

            <!-- Sales & Customers -->
            @php
                $salesActive = request()->routeIs('invoices.*', 'quotations.*', 'returns.*', 'payments.*', 'customers.*');
            @endphp
            <div class="space-y-1">
                <button type="button" @click="toggleGroup('sales', {{ $salesActive ? 'true' : 'false' }})" :aria-expanded="isGroupOpen('sales', {{ $salesActive ? 'true' : 'false' }}).toString()" aria-controls="sidebar-group-sales"
                        :class="sidebarCollapsed ? 'lg:hidden' : ''"
                        class="w-full flex items-center justify-between px-3 py-1 text-xs font-bold tracking-wider text-text-muted uppercase hover:text-text-primary transition-colors cursor-pointer select-none">
                    <span>{{ __('app.nav_group_sales_customers') }}</span>
                    <x-icon name="chevron" class="w-3.5 h-3.5 transition-transform duration-150"
                            ::class="isGroupOpen('sales', {{ $salesActive ? 'true' : 'false' }}) ? 'rotate-90 rtl:-rotate-90' : ''" />
                </button>
                <div id="sidebar-group-sales" x-show="isGroupOpen('sales', {{ $salesActive ? 'true' : 'false' }})"
                     class="space-y-0.5">
                    <a href="{{ route('invoices.index') }}" aria-current="{{ request()->routeIs('invoices.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_sales_invoices') }}" aria-label="{{ __('app.nav_sales_invoices') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('invoices.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="receipt" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_sales_invoices') }}</span>
                        </div>
                    </a>
                    <a href="{{ route('quotations.index') }}" aria-current="{{ request()->routeIs('quotations.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_quotes') }}" aria-label="{{ __('app.nav_quotes') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('quotations.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="quote" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_quotes') }}</span>
                        </div>
                    </a>
                    <a href="{{ route('returns.index') }}" aria-current="{{ request()->routeIs('returns.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_sales_returns') }}" aria-label="{{ __('app.nav_sales_returns') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('returns.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="history" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_sales_returns') }}</span>
                        </div>
                    </a>
                    <a href="{{ route('payments.index') }}" aria-current="{{ request()->routeIs('payments.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_customer_payments') }}" aria-label="{{ __('app.nav_customer_payments') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('payments.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="money" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_customer_payments') }}</span>
                        </div>
                    </a>
                    <a href="{{ route('customers.index') }}" aria-current="{{ request()->routeIs('customers.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_customers') }}" aria-label="{{ __('app.nav_customers') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('customers.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="users" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_customers') }}</span>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Purchases & Inventory -->
            @php
                $purchasingActive = request()->routeIs('purchases.*', 'purchase-returns.*', 'vendor-payments.*', 'vendors.*', 'products.*', 'brands.*', 'catalogs.*', 'labels.*', 'inventory.*');
            @endphp
            <div class="space-y-1">
                <button type="button" @click="toggleGroup('purchasing', {{ $purchasingActive ? 'true' : 'false' }})" :aria-expanded="isGroupOpen('purchasing', {{ $purchasingActive ? 'true' : 'false' }}).toString()" aria-controls="sidebar-group-purchasing"
                        :class="sidebarCollapsed ? 'lg:hidden' : ''"
                        class="w-full flex items-center justify-between px-3 py-1 text-xs font-bold tracking-wider text-text-muted uppercase hover:text-text-primary transition-colors cursor-pointer select-none">
                    <span>{{ __('app.nav_group_purchasing_inventory') }}</span>
                    <x-icon name="chevron" class="w-3.5 h-3.5 transition-transform duration-150"
                            ::class="isGroupOpen('purchasing', {{ $purchasingActive ? 'true' : 'false' }}) ? 'rotate-90 rtl:-rotate-90' : ''" />
                </button>
                <div id="sidebar-group-purchasing" x-show="isGroupOpen('purchasing', {{ $purchasingActive ? 'true' : 'false' }})"
                     class="space-y-0.5">
                    @can('purchasing.purchase.view')
                    <a href="{{ route('purchases.index') }}" aria-current="{{ request()->routeIs('purchases.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_purchase_invoices') }}" aria-label="{{ __('app.nav_purchase_invoices') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('purchases.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="cart" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_purchase_invoices') }}</span>
                        </div>
                    </a>
                    <a href="{{ route('purchase-returns.index') }}" aria-current="{{ request()->routeIs('purchase-returns.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_purchase_returns') }}" aria-label="{{ __('app.nav_purchase_returns') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('purchase-returns.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="history" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_purchase_returns') }}</span>
                        </div>
                    </a>
                    @endcan
                    @if(auth()->check() && auth()->user()->can('purchasing.cost.view') && (
                        auth()->user()->can('money.vendor_payment.create') ||
                        auth()->user()->can('money.vendor_payment.allocate') ||
                        auth()->user()->can('money.vendor_payment.reverse') ||
                        auth()->user()->can('vendors.statement.view')
                    ))
                    <a href="{{ route('vendor-payments.index') }}" aria-current="{{ request()->routeIs('vendor-payments.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_vendor_payments') }}" aria-label="{{ __('app.nav_vendor_payments') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('vendor-payments.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="money" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_vendor_payments') }}</span>
                        </div>
                    </a>
                    @endif
                    @can('vendors.view')
                    <a href="{{ route('vendors.index') }}" aria-current="{{ request()->routeIs('vendors.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_vendors') }}" aria-label="{{ __('app.nav_vendors') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('vendors.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="truck" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_vendors') }}</span>
                        </div>
                    </a>
                    @endcan
                    <a href="{{ route('products.index') }}" aria-current="{{ request()->routeIs('products.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_products') }}" aria-label="{{ __('app.nav_products') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('products.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="box" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_products') }}</span>
                        </div>
                    </a>
                    @if(auth()->user()->can('inventory.product.manage'))
                    <a href="{{ route('brands.index') }}" aria-current="{{ request()->routeIs('brands.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('inventory.brands') }}" aria-label="{{ __('inventory.brands') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('brands.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="box" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('inventory.brands') }}</span>
                        </div>
                    </a>
                    @endif
                    @if(auth()->user()->can('catalogs.view') && auth()->user()->hasAnyPermission(['inventory.stock.view','inventory.product.manage']))
                    <a href="{{ route('catalogs.index') }}" aria-current="{{ request()->routeIs('catalogs.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('catalogs.catalogs') }}" aria-label="{{ __('catalogs.catalogs') }}"
                       class="flex min-h-11 items-center gap-2.5 px-3 text-sm rounded-control hover:bg-surface-soft hover:text-text-primary text-text-secondary font-semibold transition-colors">
                        <x-icon name="file" class="w-4.5 h-4.5 shrink-0" />
                        <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('catalogs.catalogs') }}</span>
                    </a>
                    @endif
                    @if(auth()->user()->can('inventory.barcode_labels.print') && auth()->user()->hasAnyPermission(['inventory.stock.view','inventory.product.manage']))
                    <a href="{{ route('labels.index') }}" aria-current="{{ request()->routeIs('labels.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('labels.title') }}" aria-label="{{ __('labels.title') }}"
                       class="flex min-h-11 items-center gap-2.5 px-3 text-sm rounded-control hover:bg-surface-soft hover:text-text-primary text-text-secondary font-semibold transition-colors">
                        <x-icon name="barcode" class="w-4.5 h-4.5 shrink-0" />
                        <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('labels.title') }}</span>
                    </a>
                    @endif
                    <a href="{{ route('inventory.overview') }}" aria-current="{{ request()->routeIs('inventory.overview') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_inventory_overview') }}" aria-label="{{ __('app.nav_inventory_overview') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('inventory.overview', 'inventory.movements', 'inventory.adjustments.*', 'inventory.transfers.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="store" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_inventory_overview') }}</span>
                        </div>
                    </a>
                    <a href="{{ route('inventory.expiry') }}" aria-current="{{ request()->routeIs('inventory.expiry') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_expiry_center') }}" aria-label="{{ __('app.nav_expiry_center') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('inventory.expiry') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="expiry" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_expiry_center') }}</span>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Treasury & Banking -->
            @php
                $treasuryActive = request()->routeIs('money.*', 'expenses.*');
            @endphp
            <div class="space-y-1">
                <button type="button" @click="toggleGroup('treasury', {{ $treasuryActive ? 'true' : 'false' }})" :aria-expanded="isGroupOpen('treasury', {{ $treasuryActive ? 'true' : 'false' }}).toString()" aria-controls="sidebar-group-treasury"
                        :class="sidebarCollapsed ? 'lg:hidden' : ''"
                        class="w-full flex items-center justify-between px-3 py-1 text-xs font-bold tracking-wider text-text-muted uppercase hover:text-text-primary transition-colors cursor-pointer select-none">
                    <span>{{ __('app.nav_group_treasury_reports') }}</span>
                    <x-icon name="chevron" class="w-3.5 h-3.5 transition-transform duration-150"
                            ::class="isGroupOpen('treasury', {{ $treasuryActive ? 'true' : 'false' }}) ? 'rotate-90 rtl:-rotate-90' : ''" />
                </button>
                <div id="sidebar-group-treasury" x-show="isGroupOpen('treasury', {{ $treasuryActive ? 'true' : 'false' }})"
                     class="space-y-0.5">
                    @if(auth()->user()->can('money.cash.view') || auth()->user()->can('money.bank.view'))
                    <a href="{{ route('money.overview') }}" aria-current="{{ request()->routeIs('money.overview') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_treasury_banks') }}" aria-label="{{ __('app.nav_treasury_banks') }}"
                       class="flex items-center gap-2.5 min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors">
                        <x-icon name="bank" class="w-4.5 h-4.5 shrink-0" />
                        <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_treasury_banks') }}</span>
                    </a>
                    @endif
                    @can('money.check.view')
                    <a href="{{ route('money.checks.index') }}" aria-current="{{ request()->routeIs('money.checks.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_checkbook') }}" aria-label="{{ __('app.nav_checkbook') }}"
                       class="flex items-center gap-2.5 min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors">
                        <x-icon name="check" class="w-4.5 h-4.5 shrink-0" />
                        <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_checkbook') }}</span>
                    </a>
                    @endcan
                    @can('money.expense.view')
                    <a href="{{ route('expenses.index') }}" aria-current="{{ request()->routeIs('expenses.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_expenses') }}" aria-label="{{ __('app.nav_expenses') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('expenses.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="receipt" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_expenses') }}</span>
                        </div>
                    </a>
                    @endcan
                </div>
            </div>

            <!-- HR & Payroll -->
            @can('employees.view')
            @php
                $hrActive = request()->routeIs('employees.*', 'payroll.*');
            @endphp
            <div class="space-y-1">
                <button type="button" @click="toggleGroup('hr', {{ $hrActive ? 'true' : 'false' }})" :aria-expanded="isGroupOpen('hr', {{ $hrActive ? 'true' : 'false' }}).toString()" aria-controls="sidebar-group-hr"
                        :class="sidebarCollapsed ? 'lg:hidden' : ''"
                        class="w-full flex items-center justify-between px-3 py-1 text-xs font-bold tracking-wider text-text-muted uppercase hover:text-text-primary transition-colors cursor-pointer select-none">
                    <span>{{ __('app.nav_group_hr_payroll') }}</span>
                    <x-icon name="chevron" class="w-3.5 h-3.5 transition-transform duration-150"
                            ::class="isGroupOpen('hr', {{ $hrActive ? 'true' : 'false' }}) ? 'rotate-90 rtl:-rotate-90' : ''" />
                </button>
                <div id="sidebar-group-hr" x-show="isGroupOpen('hr', {{ $hrActive ? 'true' : 'false' }})"
                     class="space-y-0.5">
                    <a href="{{ route('employees.index') }}" aria-current="{{ request()->routeIs('employees.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_employees') }}" aria-label="{{ __('app.nav_employees') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-text-primary font-semibold transition-colors
                              {{ request()->routeIs('employees.*', 'payroll.*') ? 'bg-primary-50 text-primary! font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : '' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="users" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_employees') }}</span>
                        </div>
                    </a>
                </div>
            </div>
            @endcan

            <!-- System -->
            @php
                $systemActive = request()->routeIs('settings.*', 'profile', 'platform.*');
            @endphp
            <div class="space-y-1 pt-1">
                <button type="button" @click="toggleGroup('system', {{ $systemActive ? 'true' : 'false' }})" :aria-expanded="isGroupOpen('system', {{ $systemActive ? 'true' : 'false' }}).toString()" aria-controls="sidebar-group-system"
                        :class="sidebarCollapsed ? 'lg:hidden' : ''"
                        class="w-full flex items-center justify-between px-3 py-1 text-xs font-bold tracking-wider text-text-muted uppercase hover:text-text-primary transition-colors cursor-pointer select-none">
                    <span>{{ __('app.nav_group_system') }}</span>
                    <x-icon name="chevron" class="w-3.5 h-3.5 transition-transform duration-150"
                            ::class="isGroupOpen('system', {{ $systemActive ? 'true' : 'false' }}) ? 'rotate-90 rtl:-rotate-90' : ''" />
                </button>
                <div id="sidebar-group-system" x-show="isGroupOpen('system', {{ $systemActive ? 'true' : 'false' }})"
                     class="space-y-0.5">
                    @if(\App\Models\PlatformOperatorGrant::query()->where('user_id', auth()->id())->where('active', true)->whereJsonContains('permissions', 'company.read')->exists())
                        <a href="{{ route('platform.index') }}" aria-current="{{ request()->routeIs('platform.*') ? 'page' : 'false' }}"
                           :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                           title="{{ __('platform.title') }}" aria-label="{{ __('platform.title') }}"
                           class="flex min-h-11 items-center gap-2.5 px-3 rounded-control text-text-secondary hover:bg-surface-soft hover:text-primary font-semibold transition-colors">
                            <x-icon name="shield" class="w-4.5 h-4.5 shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('platform.title') }}</span>
                        </a>
                    @endif
                    <a href="{{ route('settings.index') }}" aria-current="{{ request()->routeIs('settings.*') ? 'page' : 'false' }}"
                       :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
                       title="{{ __('app.nav_settings') }}" aria-label="{{ __('app.nav_settings') }}"
                       class="flex items-center justify-between min-h-11 px-3 rounded-control font-semibold transition-colors
                              {{ request()->routeIs('settings.*', 'profile') ? 'bg-primary-50 text-primary font-bold shadow-[inset_3px_0_0_#255fd6] rtl:shadow-[inset_-3px_0_0_#255fd6]' : 'text-text-secondary hover:bg-surface-soft hover:text-text-primary' }}">
                        <div class="flex items-center gap-2.5">
                            <x-icon name="settings" class="w-4.5 h-4.5 text-primary shrink-0" />
                            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.nav_settings') }}</span>
                        </div>
                    </a>
                </div>
            </div>
        </div>

        <div class="p-3 px-4 border-t border-border text-xs text-text-muted bg-surface-soft/40 flex items-center justify-between">
            <span :class="sidebarCollapsed ? 'lg:hidden' : ''">{{ __('app.workspace') }}</span>
            <button @click="toggleSidebarCollapse"
                    type="button"
                    class="hidden lg:flex items-center justify-center w-7 h-7 rounded hover:bg-surface text-text-secondary hover:text-text-primary transition-colors cursor-pointer"
                    :title="sidebarCollapsed ? '{{ __('app.expand_sidebar') }}' : '{{ __('app.collapse_sidebar') }}'"
                    aria-label="{{ __('app.toggle_sidebar') }}">
                <x-icon name="chevron" class="w-4 h-4 transition-transform duration-200"
                        ::class="sidebarCollapsed ? '{{ $isRtl ? 'rotate-180' : '' }}' : '{{ $isRtl ? '' : 'rotate-180' }}'" />
            </button>
        </div>
    </aside>

    <!-- Top Bar -->
    <header class="app-topbar fixed top-0 z-30 h-[64px] bg-surface border-b border-border flex items-center justify-between px-4 sm:px-6 gap-4">

        <!-- Left / Start Area (Mobile button + Search) -->
        <div class="flex items-center gap-3 flex-1 min-w-0">
            <button x-ref="navToggle" @click="mobileMenuOpen = !mobileMenuOpen" :aria-expanded="mobileMenuOpen.toString()" aria-controls="app-sidebar" aria-label="{{ __('app.open_navigation') }}"
                    type="button"
                    class="lg:hidden w-11 h-11 rounded-control border border-border flex items-center justify-center text-text-secondary hover:bg-surface-soft">
                <x-icon name="menu" class="w-5 h-5" />
            </button>

            <!-- Desktop Collapse Toggle -->
            <button @click="toggleSidebarCollapse"
                    type="button"
                    class="hidden lg:flex w-10 h-10 rounded-control border border-border items-center justify-center text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors cursor-pointer shrink-0"
                    :aria-expanded="(!sidebarCollapsed).toString()"
                    :title="sidebarCollapsed ? '{{ __('app.expand_sidebar') }}' : '{{ __('app.collapse_sidebar') }}'"
                    aria-label="{{ __('app.toggle_sidebar') }}">
                <x-icon name="menu" class="w-4.5 h-4.5" />
            </button>

            <a href="{{ route('dashboard') }}" class="min-w-0 truncate text-sm font-bold text-text-primary hover:text-primary">
                {{ $activeCompany?->displayName() ?? __('app.app_name') }}
            </a>
        </div>

        <!-- Right / End Area (Actions + User Profile) -->
        <div class="flex items-center gap-2 sm:gap-3 shrink-0">

            @if($isSupportView)
                <span class="rounded-control border border-primary/20 bg-primary-50 px-3 py-2 text-xs font-bold text-primary">{{ __('support.title') }}</span>
            @endif

            @if(auth()->user()->can('sales.invoice.create') || auth()->user()->can('purchasing.purchase.create'))
                <details class="relative app-quick-add"
                         x-data="accountingQuickAdd"
                         x-ref="quickAdd"
                         @click.outside="close(false)"
                         @keydown="handleKeydown($event)">
                    <summary x-ref="trigger" aria-label="{{ __('app.quick_add') }}" class="app-quick-add-trigger">
                        <x-icon name="plus" class="w-4 h-4" aria-hidden="true" />
                        <span class="hidden sm:inline">{{ __('app.quick_add') }}</span>
                    </summary>
                    <div class="absolute end-0 mt-2 w-52 rounded-card border border-border bg-surface p-2 shadow-panel z-50"
                         @click="close(false)">
                        @can('sales.invoice.create')
                            <a href="{{ route('invoices.create') }}" class="block rounded-control px-3 py-3 text-sm hover:bg-surface-soft">{{ __('dashboard.new_sale') }}</a>
                        @endcan
                        @can('purchasing.purchase.create')
                            <a href="{{ route('purchases.create') }}" class="block rounded-control px-3 py-3 text-sm hover:bg-surface-soft">{{ __('dashboard.new_purchase') }}</a>
                        @endcan
                    </div>
                </details>
            @endif

            <livewire:global-search />

            @if (! $isSupportView)
            <!-- Language Switcher -->
            @if ($isRtl)
                @if ($englishEnabled)
                    <form method="POST" action="{{ route('locale.switch') }}" class="inline">
                        @csrf
                        <input type="hidden" name="locale" value="en">
                        <button type="submit"
                           class="min-h-11 px-2.5 rounded-control border border-border bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary flex items-center gap-1.5 transition-colors cursor-pointer"
                           title="{{ __('app.switch_language') }}">
                            <x-icon name="globe" class="w-4 h-4 text-text-muted" />
                            <span class="font-bold">EN</span>
                        </button>
                    </form>
                @endif
            @else
                <form method="POST" action="{{ route('locale.switch') }}" class="inline">
                    @csrf
                    <input type="hidden" name="locale" value="ar">
                    <button type="submit"
                       class="min-h-11 px-2.5 rounded-control border border-border bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary flex items-center gap-1.5 transition-colors cursor-pointer"
                       title="{{ __('app.switch_language') }}">
                        <x-icon name="globe" class="w-4 h-4 text-text-muted" />
                        <span class="font-bold">عربي</span>
                    </button>
                </form>
            @endif

            <!-- User Menu Dropdown -->
            <div class="relative" x-data="accountingUserMenu" @click.outside="close(false)"
                 @keydown="handleKeydown($event)">
                <button @click="toggle()"
                        x-ref="userMenuTrigger" :aria-expanded="openUserMenu.toString()"
                        type="button"
                        class="flex items-center gap-2.5 py-1 px-1 sm:px-2 rounded-control hover:bg-surface-soft transition-colors text-right">
                    <div class="w-[34px] h-[34px] rounded-full bg-slate-200 text-slate-700 font-extrabold text-xs grid place-items-center shrink-0 border border-slate-300">
                        {{ mb_substr(auth()->user()->name ?? 'AM', 0, 2) }}
                    </div>
                    <div class="hidden md:block text-start leading-tight">
                        <div class="text-xs font-extrabold text-text-primary truncate max-w-[120px]">
                            {{ auth()->user()->name ?? __('app.user_default_name') }}
                        </div>
                        <div class="text-xs text-text-muted">
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
                     class="absolute {{ $isRtl ? 'left-0' : 'right-0' }} mt-2 w-48 rounded-card bg-surface shadow-xl border border-border py-1 text-xs z-50"
                     style="display: none;"
                     @click="close(false)">
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
            @endif
        </div>
    </header>

    <!-- Main Content Wrapper -->
    <div class="app-content-shell min-h-screen pt-[64px]">
        <a href="#app-main" class="app-skip-link">{{ __('app.skip_content') }}</a>
        <main id="app-main" tabindex="-1" class="max-w-[1440px] mx-auto p-4 sm:p-6 lg:p-8">
            @include('platform.subscriptions.tenant-notice')
            @if($isSupportView)
                @include('platform.support-banner')
            @endif
            @if (\App\Application\AI\AiGovernance::isPresentationEligible($activeCompany))
                <livewire:ai.assistant-launcher :context="request()->routeIs('invoices.create') ? 'sales' : (request()->routeIs('purchases.create') ? 'purchase' : 'auto')" />
            @endif
            {{ $slot }}
        </main>
    </div>

    @include('layouts.mobile-navigation')

    @livewireScripts
</body>
</html>
