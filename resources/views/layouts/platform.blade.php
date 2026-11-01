<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ __('platform.title') }} — {{ __('platform.scope') }}</title>

    <!-- Google Fonts: IBM Plex Sans Arabic -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Styles & Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="platform-admin bg-slate-50 text-slate-900 antialiased font-arabic selection:bg-primary-100 selection:text-primary min-h-screen flex flex-col">
    <a href="#platform-main" class="app-skip-link">{{ __('platform.skip_to_main') }}</a>

    @php
        $platformPermissions = request()->attributes->get('platform_permissions', []);
    @endphp

    <!-- Fixed Desktop Sidebar (width ~240px / w-60) -->
    <aside id="platform-sidebar" class="hidden lg:flex lg:flex-col lg:w-60 lg:fixed lg:inset-y-0 lg:start-0 lg:z-30 bg-white border-e border-slate-200" aria-label="{{ __('platform.title') }}">
        <!-- Control Plane Identity -->
        <div class="h-16 flex items-center gap-3 px-5 border-b border-slate-100 shrink-0">
            <div class="w-8 h-8 rounded-lg bg-primary text-white grid place-items-center shadow-xs shrink-0">
                <x-icon name="shield" class="w-4 h-4" />
            </div>
            <div class="min-w-0">
                <span class="text-xs font-bold text-slate-900 truncate block leading-tight">{{ __('platform.title') }}</span>
                <span class="text-[11px] font-medium text-slate-400 truncate block leading-tight mt-0.5">{{ __('platform.operator_console') }}</span>
            </div>
        </div>

        <!-- Desktop Navigation Groups -->
        <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6" aria-label="{{ __('platform.title') }}">
            <!-- Core Group -->
            <div class="space-y-1">
                <span class="px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400 block mb-1.5">{{ __('platform.nav_core') }}</span>
                @if(in_array('company.read', $platformPermissions, true))
                    <a href="{{ route('platform.index') }}"
                       @if(request()->routeIs('platform.index', 'platform.companies.show', 'platform.companies.subscription.*')) aria-current="page" @endif
                       class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('platform.index', 'platform.companies.show', 'platform.companies.subscription.*') ? 'bg-primary-50 text-primary font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                        <x-icon name="store" class="w-4 h-4 shrink-0" />
                        <span>{{ __('platform.companies') }}</span>
                    </a>
                @endif

                @if(in_array('company.provision', $platformPermissions, true))
                    <a href="{{ route('platform.companies.create') }}"
                       @if(request()->routeIs('platform.companies.create')) aria-current="page" @endif
                       class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('platform.companies.create') ? 'bg-primary-50 text-primary font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                        <x-icon name="plus" class="w-4 h-4 shrink-0" />
                        <span>{{ __('platform.create') }}</span>
                    </a>
                @endif
            </div>

            <!-- Governance Group -->
            @if(in_array('audit.read', $platformPermissions, true))
                <div class="space-y-1">
                    <span class="px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400 block mb-1.5">{{ __('platform.nav_governance') }}</span>
                    <a href="{{ route('platform.audit') }}"
                       @if(request()->routeIs('platform.audit')) aria-current="page" @endif
                       class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('platform.audit') ? 'bg-primary-50 text-primary font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                        <x-icon name="history" class="w-4 h-4 shrink-0" />
                        <span>{{ __('platform.audit') }}</span>
                    </a>
                </div>
            @endif

            <!-- Future Modules Group (Explicitly quiet/unavailable) -->
            <div class="space-y-1">
                <span class="px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400 block mb-1.5">{{ __('platform.nav_future') }}</span>
                <div class="flex items-center justify-between px-2.5 py-2 rounded-lg text-sm text-slate-400 cursor-not-allowed select-none">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <x-icon name="chart" class="w-4 h-4 shrink-0 opacity-60" />
                        <span class="truncate">{{ __('platform.nav_system_health') }}</span>
                    </div>
                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200 shrink-0">{{ __('platform.coming_soon') }}</span>
                </div>
                <div class="flex items-center justify-between px-2.5 py-2 rounded-lg text-sm text-slate-400 cursor-not-allowed select-none">
                    <div class="flex items-center gap-2.5 min-w-0">
                        <x-icon name="shield" class="w-4 h-4 shrink-0 opacity-60" />
                        <span class="truncate">{{ __('platform.nav_global_policies') }}</span>
                    </div>
                    <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200 shrink-0">{{ __('platform.coming_soon') }}</span>
                </div>
            </div>
        </nav>

        <!-- Sidebar Footer Controls -->
        <div class="p-3 border-t border-slate-100 space-y-1 shrink-0">
            <a href="{{ route('dashboard') }}" class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors">
                <x-icon name="dashboard" class="w-4 h-4 shrink-0 text-slate-400" />
                <span>{{ __('platform.tenant_portal') }}</span>
            </a>
            <a href="{{ route('profile') }}" class="flex items-center gap-2.5 px-2.5 py-2 rounded-lg text-xs font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors">
                <x-icon name="settings" class="w-4 h-4 shrink-0 text-slate-400" />
                <span>{{ __('platform.profile') }}</span>
            </a>
            @if(auth()->check())
                <div class="pt-2 mt-2 border-t border-slate-100 flex items-center gap-2.5 px-2 py-1">
                    <div class="w-7 h-7 rounded-full bg-slate-100 text-slate-700 font-bold text-xs grid place-items-center shrink-0 border border-slate-200">
                        {{ mb_substr(auth()->user()->name ?? 'OP', 0, 2) }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-xs font-bold text-slate-800 truncate leading-tight">{{ auth()->user()->name }}</p>
                        <p class="text-[10px] font-medium text-slate-400 truncate leading-tight">{{ __('platform.operator_badge') }}</p>
                    </div>
                </div>
            @endif
        </div>
    </aside>

    <!-- Mobile Navigation Drawer (<dialog> Modal Sheet) -->
    <dialog aria-label="{{ __('platform.title') }}" id="platform-mobile-drawer" style="display: none;" class="fixed inset-0 z-50 p-0 m-0 h-full max-h-none w-full max-w-none bg-black/40 backdrop-blur-xs backdrop:bg-black/40 border-0 outline-none">
        <div id="platform-drawer-content" class="w-72 max-w-[85vw] h-full bg-white shadow-xl flex flex-col outline-none ms-0 me-auto">
            <!-- Drawer Header -->
            <div class="h-16 flex items-center justify-between px-4 border-b border-slate-100 shrink-0">
                <div class="flex items-center gap-2.5 min-w-0">
                    <div class="w-8 h-8 rounded-lg bg-primary text-white grid place-items-center shadow-xs shrink-0">
                        <x-icon name="shield" class="w-4 h-4" />
                    </div>
                    <span class="text-xs font-bold text-slate-900 truncate">{{ __('platform.title') }}</span>
                </div>
                <button type="button"
                        id="platform-drawer-close"
                        aria-label="{{ __('platform.close_menu') }}"
                        class="min-h-11 min-w-11 inline-flex items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 transition-colors cursor-pointer">
                    <x-icon name="close" class="w-5 h-5" />
                </button>
            </div>

            <!-- Drawer Navigation -->
            <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-6" aria-label="{{ __('platform.title') }}">
                <div class="space-y-1">
                    <span class="px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400 block mb-1.5">{{ __('platform.nav_core') }}</span>
                    @if(in_array('company.read', $platformPermissions, true))
                        <a href="{{ route('platform.index') }}"
                           @if(request()->routeIs('platform.index', 'platform.companies.show', 'platform.companies.subscription.*')) aria-current="page" @endif
                           class="flex min-h-11 items-center gap-3 px-3 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('platform.index', 'platform.companies.show', 'platform.companies.subscription.*') ? 'bg-primary-50 text-primary font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                            <x-icon name="store" class="w-5 h-5 shrink-0" />
                            <span>{{ __('platform.companies') }}</span>
                        </a>
                    @endif

                    @if(in_array('company.provision', $platformPermissions, true))
                        <a href="{{ route('platform.companies.create') }}"
                           @if(request()->routeIs('platform.companies.create')) aria-current="page" @endif
                           class="flex min-h-11 items-center gap-3 px-3 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('platform.companies.create') ? 'bg-primary-50 text-primary font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                            <x-icon name="plus" class="w-5 h-5 shrink-0" />
                            <span>{{ __('platform.create') }}</span>
                        </a>
                    @endif
                </div>

                @if(in_array('audit.read', $platformPermissions, true))
                    <div class="space-y-1">
                        <span class="px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400 block mb-1.5">{{ __('platform.nav_governance') }}</span>
                        <a href="{{ route('platform.audit') }}"
                           @if(request()->routeIs('platform.audit')) aria-current="page" @endif
                           class="flex min-h-11 items-center gap-3 px-3 rounded-lg text-sm font-medium transition-colors {{ request()->routeIs('platform.audit') ? 'bg-primary-50 text-primary font-semibold' : 'text-slate-600 hover:bg-slate-100 hover:text-slate-900' }}">
                            <x-icon name="history" class="w-5 h-5 shrink-0" />
                            <span>{{ __('platform.audit') }}</span>
                        </a>
                    </div>
                @endif

                <div class="space-y-1">
                    <span class="px-2.5 text-[11px] font-semibold uppercase tracking-wider text-slate-400 block mb-1.5">{{ __('platform.nav_future') }}</span>
                    <div class="flex min-h-11 items-center justify-between px-3 rounded-lg text-sm text-slate-400 select-none">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="chart" class="w-5 h-5 shrink-0 opacity-60" />
                            <span class="truncate">{{ __('platform.nav_system_health') }}</span>
                        </div>
                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200 shrink-0">{{ __('platform.coming_soon') }}</span>
                    </div>
                    <div class="flex min-h-11 items-center justify-between px-3 rounded-lg text-sm text-slate-400 select-none">
                        <div class="flex items-center gap-3 min-w-0">
                            <x-icon name="shield" class="w-5 h-5 shrink-0 opacity-60" />
                            <span class="truncate">{{ __('platform.nav_global_policies') }}</span>
                        </div>
                        <span class="text-[10px] font-medium px-1.5 py-0.5 rounded bg-slate-100 text-slate-500 border border-slate-200 shrink-0">{{ __('platform.coming_soon') }}</span>
                    </div>
                </div>
            </nav>

            <!-- Drawer Footer -->
            <div class="p-4 border-t border-slate-100 space-y-2 shrink-0">
                <a href="{{ route('dashboard') }}" class="flex min-h-11 items-center gap-3 px-3 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors">
                    <x-icon name="dashboard" class="w-5 h-5 shrink-0 text-slate-400" />
                    <span>{{ __('platform.tenant_portal') }}</span>
                </a>
                <a href="{{ route('profile') }}" class="flex min-h-11 items-center gap-3 px-3 rounded-lg text-sm font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors">
                    <x-icon name="settings" class="w-5 h-5 shrink-0 text-slate-400" />
                    <span>{{ __('platform.profile') }}</span>
                </a>
                @if(auth()->check())
                    <div class="pt-2 border-t border-slate-100 flex items-center gap-3 px-3 py-1">
                        <div class="w-8 h-8 rounded-full bg-slate-100 text-slate-700 font-bold text-xs grid place-items-center shrink-0 border border-slate-200">
                            {{ mb_substr(auth()->user()->name ?? 'OP', 0, 2) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-bold text-slate-800 truncate leading-tight">{{ auth()->user()->name }}</p>
                            <p class="text-xs font-medium text-slate-400 truncate leading-tight">{{ __('platform.operator_badge') }}</p>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </dialog>

    <!-- Main Outer Wrapper (Offset on desktop for fixed sidebar) -->
    <div class="lg:ps-60 flex-1 flex flex-col min-w-0">
        <!-- Compact Context Header (Top Bar) -->
        <header class="sticky top-0 z-20 bg-white/95 backdrop-blur-xs border-b border-slate-200 min-h-14 sm:min-h-16 flex items-center justify-between px-4 sm:px-6">
            <div class="flex items-center gap-3 min-w-0">
                <!-- Mobile Drawer Toggle Trigger -->
                <button type="button"
                        id="platform-drawer-open"
                        aria-controls="platform-mobile-drawer" aria-expanded="false"
                        aria-label="{{ __('platform.open_menu') }}"
                        class="lg:hidden min-h-11 min-w-11 inline-flex items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 hover:text-slate-900 transition-colors cursor-pointer">
                    <x-icon name="menu" class="w-5 h-5" />
                </button>

                <!-- Breadcrumb Navigation -->
                <nav aria-label="{{ __('platform.breadcrumb_label') }}" class="flex items-center gap-1.5 text-xs sm:text-sm font-medium text-slate-500 min-w-0 overflow-hidden">
                    <a href="{{ route('platform.index') }}" class="hover:text-primary transition-colors text-slate-600 font-semibold shrink-0">{{ __('platform.breadcrumb_platform') }}</a>
                    <div class="flex items-center gap-1.5 min-w-0 overflow-hidden truncate">
                        @yield('breadcrumb')
                    </div>
                </nav>
            </div>

            <!-- Topbar End Controls -->
            <div class="flex items-center gap-2 sm:gap-3 shrink-0">
                <!-- Language Switcher Form -->
                <form method="POST" action="{{ route('locale.switch') }}" class="inline">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button type="submit"
                            class="min-h-11 px-3 rounded-lg border border-slate-200 bg-white text-xs font-semibold text-slate-700 hover:bg-slate-50 hover:text-slate-900 flex items-center gap-1.5 transition-colors cursor-pointer"
                            title="{{ app()->getLocale() === 'ar' ? 'Switch to English' : 'التحويل إلى العربية' }}">
                        <x-icon name="globe" class="w-4 h-4 text-slate-400" />
                        <span class="font-bold">{{ app()->getLocale() === 'ar' ? 'EN' : 'عربي' }}</span>
                    </button>
                </form>

                <!-- Operator Account Pill (Desktop) -->
                @if(auth()->check())
                    <a href="{{ route('profile') }}" class="hidden sm:flex items-center gap-2 py-1 px-2.5 rounded-lg border border-slate-200 hover:bg-slate-50 text-slate-700 text-xs font-semibold min-h-11 transition-colors">
                        <div class="w-6 h-6 rounded-full bg-slate-100 text-slate-700 font-bold text-[10px] grid place-items-center shrink-0 border border-slate-200">
                            {{ mb_substr(auth()->user()->name ?? 'OP', 0, 2) }}
                        </div>
                        <span class="max-w-[120px] truncate text-slate-800 font-semibold">{{ auth()->user()->name }}</span>
                    </a>
                @endif
            </div>
        </header>

        <!-- Main Page Content -->
        <main id="platform-main" tabindex="-1" class="flex-1 p-4 sm:p-6 lg:p-8 max-w-7xl w-full mx-auto outline-none">
            @if(session('platform_success'))
                <div role="status" class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50/90 p-4 text-sm text-emerald-900 shadow-xs">
                    <x-icon name="check-circle" class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
                    <div class="font-semibold">{{ session('platform_success') }}</div>
                </div>
            @endif

            @yield('content')
        </main>

        <!-- Platform Footer -->
        <footer class="mt-auto border-t border-slate-200 bg-white py-4 px-4 sm:px-6 lg:px-8 text-xs text-slate-500">
            <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-3">
                <p>{{ __('platform.permission_help') }}</p>
                <p>{{ __('app.app_name') }} &copy; {{ date('Y') }}</p>
            </div>
        </footer>
    </div>

    <!-- Client Script for Drawer Lifecycle -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const drawer = document.getElementById('platform-mobile-drawer');
            const openBtn = document.getElementById('platform-drawer-open');
            const closeBtn = document.getElementById('platform-drawer-close');
            const drawerContent = document.getElementById('platform-drawer-content');

            if (!drawer || !openBtn || !closeBtn || !drawerContent) return;

            openBtn.addEventListener('click', function () {
                drawer.style.display = 'flex';
                drawer.showModal();
                openBtn.setAttribute('aria-expanded', 'true');
                closeBtn.focus();
            });

            function closeDrawer() {
                if (drawer.open) {
                    drawer.close();
                }
                drawer.style.display = 'none';
                openBtn.setAttribute('aria-expanded', 'false');
                if (openBtn.getClientRects().length) openBtn.focus();
                else document.getElementById('platform-main').focus();
            }

            closeBtn.addEventListener('click', closeDrawer);

            drawer.addEventListener('click', function (e) {
                if (!drawerContent.contains(e.target)) {
                    closeDrawer();
                }
            });

            drawer.addEventListener('close', function () {
                drawer.style.display = 'none';
                openBtn.setAttribute('aria-expanded', 'false');
                if (openBtn.getClientRects().length) openBtn.focus();
                else document.getElementById('platform-main').focus();
            });

            window.addEventListener('resize', function () {
                if (window.innerWidth >= 1024 && drawer.open) {
                    closeDrawer();
                }
            });
        });
    </script>

    @livewireScripts
</body>
</html>
