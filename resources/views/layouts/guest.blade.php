<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'التاجر الصغير') }} — {{ __('app.app_subtitle') }}</title>

    <!-- Google Fonts: IBM Plex Sans Arabic -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <!-- Scripts & Styles -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-canvas text-text-primary antialiased font-arabic min-h-screen flex flex-col justify-between p-4 sm:p-6">

    <!-- Top Navigation / Language Switcher -->
    <div class="flex items-center justify-between max-w-md w-full mx-auto pt-2">
        <div class="flex items-center gap-2">
            <div class="w-8 h-8 rounded-control bg-primary text-white grid place-items-center shadow-xs">
                <x-icon name="store" class="w-4.5 h-4.5" />
            </div>
            <span class="font-extrabold text-sm text-text-primary">{{ __('app.app_name') }}</span>
        </div>

        <form method="POST" action="{{ route('locale.switch') }}" class="inline">
            @csrf
            <input type="hidden" name="locale" value="{{ app()->getLocale() === 'ar' ? 'en' : 'ar' }}">
            <button type="submit"
               class="px-2.5 py-1 rounded-control border border-border bg-white text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors flex items-center gap-1.5 cursor-pointer">
                <x-icon name="globe" class="w-3.5 h-3.5 text-text-muted" />
                <span>{{ app()->getLocale() === 'ar' ? 'English' : 'العربية' }}</span>
            </button>
        </form>
    </div>

    <!-- Centered Form Container -->
    <div class="w-full max-w-md mx-auto my-auto py-6">
        <div class="bg-white border border-border rounded-card p-6 sm:p-8 shadow-panel">
            {{ $slot }}
        </div>
    </div>

    <!-- Footer Copyright / Version -->
    <div class="text-center text-xs text-text-muted py-2 font-mono">
        {{ __('app.app_name') }} &bull; {{ __('app.version') }}
    </div>
</body>
</html>
