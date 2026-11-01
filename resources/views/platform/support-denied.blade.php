@extends('layouts.platform')
@section('content')
<section class="mx-auto max-w-xl rounded-card border border-border bg-surface p-5 sm:p-7" aria-labelledby="support-denied-title">
    <h1 id="support-denied-title" class="text-xl font-bold">{{ __('support.title') }}</h1>
    <p role="alert" class="mt-3 text-sm leading-relaxed text-text-secondary">{{ __('support.read_only') }}</p>
    <form class="mt-5" method="POST" action="{{ route('platform.support.end') }}">
        @csrf
        <button class="min-h-11 rounded-control bg-primary px-4 font-semibold text-white" type="submit">{{ __('support.exit') }}</button>
    </form>
</section>
@endsection
