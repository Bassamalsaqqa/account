@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-semibold">{{ __('support.title') }}</span>
@endsection

@section('content')
<div class="max-w-xl mx-auto my-8">
    <section class="rounded-xl border border-slate-200 bg-white p-6 sm:p-8 shadow-xs" aria-labelledby="support-denied-title">
        <div class="w-12 h-12 rounded-xl bg-amber-50 text-amber-700 grid place-items-center mb-5 border border-amber-200">
            <x-icon name="shield" class="w-6 h-6" />
        </div>

        <h1 id="support-denied-title" class="text-xl font-bold text-slate-900">{{ __('support.title') }}</h1>
        <p role="alert" class="mt-3 text-sm leading-relaxed text-slate-600">{{ __('support.read_only') }}</p>

        <form class="mt-6" method="POST" action="{{ route('platform.support.end') }}">
            @csrf
            <button class="inline-flex min-h-11 items-center justify-center rounded-lg bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer" type="submit">
                {{ __('support.exit') }}
            </button>
        </form>
    </section>
</div>
@endsection
