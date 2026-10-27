@extends('layouts.platform')

@section('content')
<div class="max-w-2xl mx-auto my-8">
    <section class="bg-white border border-border rounded-card shadow-panel p-6 sm:p-8">
        <div class="w-12 h-12 rounded-full bg-danger-bg text-danger grid place-items-center mb-5">
            <x-icon name="lock" class="w-6 h-6" />
        </div>

        <h1 class="text-xl font-extrabold text-text-primary">{{ __('platform.denied') }}</h1>

        <p class="mt-3 text-sm text-text-secondary leading-relaxed">
            {{ __('platform.denied_help') }}
        </p>

        <div class="mt-4 p-4 rounded-control bg-surface-soft border border-border text-xs text-text-muted leading-relaxed">
            {{ __('platform.permission_help') }}
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('dashboard') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors">
                {{ __('platform.return_dashboard') }}
            </a>
            <a href="{{ route('profile') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-control border border-border-strong bg-white px-5 text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('platform.profile') }}
            </a>
        </div>
    </section>
</div>
@endsection
