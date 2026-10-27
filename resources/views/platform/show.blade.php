@extends('layouts.platform')
@section('content')
<a class="mb-4 inline-flex min-h-11 items-center underline" href="{{ route('platform.index') }}">{{ __('platform.back') }}</a>
<h2 class="break-words text-xl font-bold">{{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}</h2>
<p class="my-2 break-all font-mono text-sm" dir="ltr">{{ $company->public_id }}</p>
<dl class="my-5 grid gap-4 rounded-xl border bg-white p-5 sm:grid-cols-2">
@foreach(['status'=>'status','base_currency_code'=>'currency','default_locale'=>'locale','timezone'=>'timezone'] as $field=>$label)
    <div class="min-w-0"><dt class="text-sm text-slate-600">{{ __('platform.'.$label) }}</dt><dd class="break-words">{{ $company->$field }}</dd></div>
@endforeach
    <div><dt>{{ __('platform.ai') }}</dt><dd>{{ __('platform.'.($aiEnabled ? 'on' : 'off')) }}</dd></div>
    <div><dt>{{ __('platform.health') }}</dt><dd>@include('platform.health', ['checkedAt' => $health?->checked_at, 'domains' => $health?->domains])</dd><p class="text-sm text-slate-600">{{ __('platform.health_help') }}</p></div>
</dl>
<h3 class="text-lg font-semibold">{{ __('platform.members') }}</h3><p class="my-2 text-sm text-slate-600">{{ __('platform.member_help') }}</p>
<ul class="space-y-2">@foreach($members as $member)<li class="rounded-lg border bg-white p-4"><span class="font-semibold">{{ $member->name }}</span> — {{ __('platform.'.($member->is_owner ? 'owner' : 'member')) }} — <span dir="auto">{{ $member->role_names }}</span> — {{ $member->status }}</li>@endforeach</ul>
<div class="mt-4">{{ $members->links() }}</div>
@endsection
