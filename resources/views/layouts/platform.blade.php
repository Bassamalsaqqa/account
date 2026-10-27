<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('platform.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="bg-slate-50 text-slate-900 antialiased">
    <a href="#platform-main" class="sr-only focus:not-sr-only">{{ __('platform.title') }}</a>
    <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6">
        <header class="mb-6 flex flex-wrap items-start justify-between gap-4 border-b border-slate-200 pb-5">
            <div><p class="text-sm text-slate-600">{{ __('platform.scope') }}</p><h1 class="text-2xl font-bold">{{ __('platform.title') }}</h1></div>
            <nav class="flex flex-wrap gap-3" aria-label="{{ __('platform.title') }}">
                @if(in_array('company.read', request()->attributes->get('platform_permissions', []), true))<a class="inline-flex min-h-11 items-center rounded-lg border px-3" href="{{ route('platform.index') }}">{{ __('platform.companies') }}</a>@endif
                <a class="inline-flex min-h-11 items-center rounded-lg border px-3" href="{{ route('profile') }}">{{ __('platform.profile') }}</a>
                @if(in_array('audit.read', request()->attributes->get('platform_permissions', []), true))<a class="inline-flex min-h-11 items-center rounded-lg border px-3" href="{{ route('platform.audit') }}">{{ __('platform.audit') }}</a>@endif
                <form method="POST" action="{{ route('locale.switch') }}">@csrf<input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}"><button class="min-h-11 rounded-lg border px-3" type="submit">{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</button></form>
            </nav>
        </header>
        <main id="platform-main">
            @if(session('platform_success'))<p role="status" class="mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4">{{ session('platform_success') }}</p>@endif
            @yield('content')
        </main>
        <p class="mt-8 text-sm text-slate-600">{{ __('platform.permission_help') }}</p>
    </div>
    @livewireScripts
</body>
</html>
