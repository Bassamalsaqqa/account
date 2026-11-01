@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-semibold">{{ __('platform.denied') }}</span>
@endsection

@section('content')
<div class="max-w-2xl mx-auto my-8">
    <section class="rounded-xl border border-slate-200 bg-white p-6 sm:p-8 shadow-xs">
        <div class="w-12 h-12 rounded-xl bg-red-50 text-red-600 grid place-items-center mb-5 border border-red-100">
            <x-icon name="lock" class="w-6 h-6" />
        </div>

        <h1 class="text-xl font-bold text-slate-900">{{ __('platform.denied') }}</h1>

        <p class="mt-3 text-sm text-slate-600 leading-relaxed">
            {{ __('platform.denied_help') }}
        </p>

        <div class="mt-4 p-4 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-500 leading-relaxed">
            {{ __('platform.permission_help') }}
        </div>

        <div class="mt-6 flex flex-wrap items-center gap-3">
            <a href="{{ route('dashboard') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors">
                {{ __('platform.return_dashboard') }}
            </a>
            <a href="{{ route('profile') }}"
               class="inline-flex min-h-11 items-center justify-center rounded-lg border border-slate-200 bg-white px-5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                {{ __('platform.profile') }}
            </a>
        </div>
    </section>
</div>
@endsection
