@extends('layouts.platform')
@section('content')
<section class="rounded-xl border border-red-200 bg-white p-5 sm:p-6">
    <h2 class="text-xl font-semibold">{{ __('platform.denied') }}</h2>
    <p class="mt-3 max-w-prose text-slate-700">{{ __('platform.permission_help') }}</p>
    <p class="mt-3 max-w-prose text-slate-700">{{ __('platform.denied_help') }}</p>
</section>
@endsection
