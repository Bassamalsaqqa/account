@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-semibold">{{ __('platform.denied') }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto my-8">
    <section class="rounded-card border border-border bg-surface p-6 sm:p-8 shadow-panel">
        <div class="w-12 h-12 rounded-card bg-danger-bg text-danger grid place-items-center mb-5 border border-danger/20">
            <x-icon name="lock" class="w-6 h-6" />
        </div>

        <h1 class="text-xl font-bold text-text-primary">{{ __('platform.denied') }}</h1>

        <p class="mt-3 text-sm text-text-secondary leading-relaxed">
            {{ __('platform.denied_help') }}
        </p>

        <div class="mt-4 p-4 rounded-control bg-surface-soft border border-border text-xs text-text-muted leading-relaxed">
            {{ __('platform.permission_help') }}
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('dashboard') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors">
                {{ __('platform.return_dashboard') }}
            </a>
            <a href="{{ route('profile') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-surface px-5 text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('platform.profile') }}
            </a>
        </div>
    </section>
</div>
@endsection
