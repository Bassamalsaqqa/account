@extends('layouts.platform')
@section('content')
<div class="mb-5 flex flex-wrap gap-3">
    @if(in_array('company.provision', request()->attributes->get('platform_permissions', []), true))
        <a class="inline-flex min-h-11 items-center rounded-lg bg-blue-700 px-4 font-semibold text-white" href="{{ route('platform.companies.create') }}">{{ __('platform.create') }}</a>
    @endif
</div>
<form method="GET" class="mb-6 flex flex-wrap items-end gap-3">
    <div class="min-w-0 flex-1"><label class="mb-1 block text-sm font-semibold" for="platform-search">{{ __('platform.search') }}</label><input class="w-full rounded-lg border-slate-300" id="platform-search" name="q" value="{{ $search }}" maxlength="100"></div>
    <button class="min-h-11 rounded-lg border bg-white px-4">{{ __('platform.find') }}</button>
</form>
<div class="hidden overflow-x-auto rounded-xl border bg-white lg:block">
<table class="w-full text-start"><caption class="sr-only">{{ __('platform.companies') }}</caption><thead><tr class="border-b text-start">
@foreach(['name','status','ai','health','view'] as $label)<th scope="col" class="p-4 text-start text-sm">{{ __('platform.'.$label) }}</th>@endforeach
</tr></thead><tbody>
@forelse($companies as $company)
<tr class="border-b last:border-0"><th scope="row" class="max-w-xs break-words p-4 text-start font-semibold">{{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}<span class="block break-all font-mono text-xs font-normal text-slate-600" dir="ltr">{{ $company->public_id }}</span></th><td class="p-4">{{ $company->status }}</td><td class="p-4">{{ __('platform.'.($company->ai_enabled ? 'on' : 'off')) }}</td><td class="p-4">@include('platform.health', ['checkedAt'=>$company->checked_at, 'domains'=>$company->domains])</td><td class="p-4"><a class="inline-flex min-h-11 items-center rounded-lg border px-4" href="{{ route('platform.companies.show', $company->public_id) }}">{{ __('platform.view') }}</a></td></tr>
@empty
<tr><td colspan="5" class="p-5">{{ __('platform.empty') }}</td></tr>
@endforelse
</tbody></table></div>
<div class="grid gap-4 sm:grid-cols-2 lg:hidden">
@forelse($companies as $company)
    <article class="min-w-0 rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="break-words text-lg font-semibold">{{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}</h2>
        <p class="break-all font-mono text-xs text-slate-600" dir="ltr">{{ $company->public_id }}</p>
        <dl class="my-4 space-y-2 text-sm"><div><dt class="text-slate-600">{{ __('platform.status') }}</dt><dd>{{ $company->status }}</dd></div><div><dt class="text-slate-600">{{ __('platform.currency') }}</dt><dd dir="ltr">{{ $company->base_currency_code }}</dd></div><div><dt class="text-slate-600">{{ __('platform.ai') }}</dt><dd>{{ __('platform.'.($company->ai_enabled ? 'on' : 'off')) }}</dd></div><div><dt class="text-slate-600">{{ __('platform.health') }}</dt><dd>@include('platform.health', ['checkedAt' => $company->checked_at, 'domains' => $company->domains])</dd></div></dl>
        <a class="inline-flex min-h-11 items-center rounded-lg border px-4" href="{{ route('platform.companies.show', $company->public_id) }}">{{ __('platform.view') }}</a>
    </article>
@empty
    <p>{{ __('platform.empty') }}</p>
@endforelse
</div>
<div class="mt-5">{{ $companies->links() }}</div>
@endsection
