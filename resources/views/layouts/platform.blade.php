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
<body class="business-app bg-canvas text-text-primary antialiased font-arabic selection:bg-primary-100 selection:text-primary min-h-screen flex flex-col">
    <a href="#platform-main" class="app-skip-link">{{ __('platform.title') }}</a>

    <!-- Platform Top Bar -->
    <header class="sticky top-0 z-30 bg-white/95 backdrop-blur-md border-b border-border shadow-xs">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3 lg:py-0 lg:min-h-16 flex flex-col lg:flex-row lg:items-center items-stretch justify-between gap-2 sm:gap-4">
            <!-- Brand / Control Plane Identity -->
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-10 h-10 rounded-card bg-primary text-white grid place-items-center shadow-[0_4px_12px_rgba(37,95,214,.18)] shrink-0">
                    <x-icon name="shield" class="w-5 h-5" />
                </div>
                <div class="min-w-0">
                    <span class="text-[11px] font-bold text-text-muted uppercase tracking-wider block truncate leading-none mb-1">{{ __('platform.scope') }}</span>
                    <a href="{{ route('platform.index') }}" class="min-h-11 inline-flex items-center text-base font-extrabold text-text-primary hover:text-primary leading-tight">{{ __('platform.title') }}</a>
                </div>
            </div>

            <!-- Platform Navigation & Actions -->
            <div class="flex items-center justify-between sm:justify-end gap-1 sm:gap-3 shrink-0">
                <nav class="flex items-center gap-0 sm:gap-1.5" aria-label="{{ __('platform.title') }}">
                    @php $platformPermissions = request()->attributes->get('platform_permissions', []); @endphp

                    @if(in_array('company.read', $platformPermissions, true))
                        <a href="{{ route('platform.index') }}"
                           @if(request()->routeIs('platform.index', 'platform.companies.*')) aria-current="page" @endif
                           class="inline-flex min-h-11 items-center gap-1 sm:gap-2 px-2 sm:px-3 rounded-control text-xs sm:text-sm font-semibold transition-colors
                                  {{ request()->routeIs('platform.index') || request()->routeIs('platform.companies.*') ? 'bg-primary-50 text-primary font-bold shadow-[inset_0_-2px_0_#255fd6]' : 'text-text-secondary hover:bg-surface-soft hover:text-text-primary' }}">
                            <x-icon name="store" class="w-4 h-4 shrink-0" />
                            <span>{{ __('platform.companies') }}</span>
                        </a>
                    @endif

                    @if(in_array('audit.read', $platformPermissions, true))
                        <a href="{{ route('platform.audit') }}"
                           @if(request()->routeIs('platform.audit')) aria-current="page" @endif
                           class="inline-flex min-h-11 items-center gap-1 sm:gap-2 px-2 sm:px-3 rounded-control text-xs sm:text-sm font-semibold transition-colors
                                  {{ request()->routeIs('platform.audit') ? 'bg-primary-50 text-primary font-bold shadow-[inset_0_-2px_0_#255fd6]' : 'text-text-secondary hover:bg-surface-soft hover:text-text-primary' }}">
                            <x-icon name="history" class="hidden sm:block w-4 h-4 shrink-0" />
                            <span>{{ __('platform.audit') }}</span>
                        </a>
                    @endif

                    <a href="{{ route('dashboard') }}" aria-label="{{ __('platform.dashboard') }}" title="{{ __('platform.dashboard') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control text-text-secondary hover:bg-surface-soft hover:text-primary">
                        <x-icon name="dashboard" class="w-4 h-4" />
                    </a>
                    <a href="{{ route('profile') }}"
                       class="inline-flex min-h-11 min-w-11 items-center justify-center gap-1 sm:gap-2 px-2 sm:px-3 rounded-control text-xs sm:text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors"
                       aria-label="{{ __('platform.profile') }}">
                        <x-icon name="settings" class="w-4 h-4 shrink-0" />
                        <span class="hidden md:inline">{{ __('platform.profile') }}</span>
                    </a>
                </nav>

                <div class="h-6 w-px bg-border hidden sm:block"></div>

                <!-- Language Switcher -->
                <form method="POST" action="{{ route('locale.switch') }}" class="inline">
                    @csrf
                    <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
                    <button type="submit"
                       class="min-h-11 px-2.5 rounded-control border border-border bg-white text-xs font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary flex items-center gap-1.5 transition-colors cursor-pointer"
                       title="{{ app()->getLocale() === 'ar' ? 'Switch to English' : 'التحويل إلى العربية' }}">
                        <x-icon name="globe" class="w-4 h-4 text-text-muted" />
                        <span class="font-bold">{{ app()->getLocale() === 'ar' ? 'EN' : 'عربي' }}</span>
                    </button>
                </form>

                <!-- User Profile Pill -->
                @if(auth()->check())
                    <a href="{{ route('profile') }}" aria-label="{{ __('platform.profile') }}" class="hidden sm:flex items-center gap-2 py-1 px-2 sm:px-2.5 rounded-control hover:bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-semibold min-h-11 transition-colors">
                        <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 font-extrabold text-[11px] grid place-items-center shrink-0 border border-slate-300">
                            {{ mb_substr(auth()->user()->name ?? 'OP', 0, 2) }}
                        </div>
                        <span class="hidden lg:inline max-w-[120px] truncate text-text-primary font-bold">{{ auth()->user()->name }}</span>
                    </a>
                @endif
            </div>
        </div>
    </header>

    <!-- Main Content Area -->
    <main id="platform-main" tabindex="-1" class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 lg:p-8">
        @if(session('platform_success'))
            <div role="status" class="mb-6 flex items-start gap-3 rounded-card border border-emerald-200 bg-emerald-50/90 p-4 text-sm text-emerald-900 shadow-xs">
                <x-icon name="check-circle" class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" />
                <div class="font-semibold">{{ session('platform_success') }}</div>
            </div>
        @endif

        @yield('content')
    </main>

    <!-- Platform Footer -->
    <footer class="mt-auto border-t border-border bg-white py-4 px-4 sm:px-6 lg:px-8">
        <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-3 text-xs text-text-muted">
            <p>{{ __('platform.permission_help') }}</p>
            <p>{{ __('app.app_name') }} &copy; {{ date('Y') }}</p>
        </div>
    </footer>

    @livewireScripts
</body>
</html>
