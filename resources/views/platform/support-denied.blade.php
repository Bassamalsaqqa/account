@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-semibold">{{ __('support.title') }}</span>
@endsection

@section('content')
<div class="max-w-xl mx-auto my-8">
    <section class="rounded-card border border-border bg-white p-6 sm:p-8 shadow-panel" aria-labelledby="support-denied-title">
        <div class="w-12 h-12 rounded-card bg-warning-bg text-warning grid place-items-center mb-5 border border-warning/20">
            <x-icon name="shield" class="w-6 h-6" />
        </div>

        <h1 id="support-denied-title" class="text-xl font-bold text-text-primary">{{ __('support.title') }}</h1>
        <p role="alert" class="mt-3 text-sm leading-relaxed text-text-secondary">{{ __('support.read_only') }}</p>

        <form class="mt-6" method="POST" action="{{ route('platform.support.end') }}">
            @csrf
            <button class="inline-flex min-h-11 items-center justify-center rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors cursor-pointer" type="submit">
                {{ __('support.exit') }}
            </button>
        </form>
    </section>
</div>
@endsection
