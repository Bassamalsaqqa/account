@extends('layouts.platform')
@section('content')
<a class="inline-flex min-h-11 items-center underline" href="{{ route('platform.index') }}">{{ __('platform.back') }}</a>
<h2 class="my-3 text-xl font-bold">{{ __('platform.create') }}</h2><p class="mb-5 text-slate-600">{{ __('platform.create_help') }}</p>
@if($errors->any())<div role="alert" class="mb-5 rounded-lg border border-red-300 bg-red-50 p-4"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
<form method="POST" action="{{ route('platform.companies.store') }}" class="space-y-5 rounded-xl border bg-white p-5 sm:p-6" x-data="{ review: false, saving: false, summary: {}, dirty: false }" @input="dirty = true" @change="dirty = true" @submit="saving = true" @beforeunload.window="if (dirty && !saving) { $event.preventDefault(); $event.returnValue = '' }">
    @csrf
    <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">
    <div class="grid gap-5 sm:grid-cols-2" x-show="!review">
    @foreach(['name_ar','name_en','owner_name','owner_email','password','password_confirmation'] as $field)
        <div class="min-w-0"><label class="mb-1 block font-semibold" for="{{ $field }}">{{ __('platform.'.$field) }}</label>
        <input x-ref="{{ $field }}" id="{{ $field }}" name="{{ $field }}" type="{{ str_starts_with($field, 'password') ? 'password' : ($field === 'owner_email' ? 'email' : 'text') }}" value="{{ str_starts_with($field, 'password') ? '' : old($field) }}" class="min-h-11 w-full rounded-lg border-slate-300" maxlength="255" @if($field !== 'name_en') required @endif @if(str_starts_with($field,'password')) autocomplete="new-password" minlength="8" @endif @if($field === 'owner_email') dir="ltr" @endif @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
        @error($field)<p id="{{ $field }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
    @endforeach
    @foreach(['base_currency_code'=>['ILS','USD','JOD'],'default_locale'=>['ar','en'],'timezone'=>['Asia/Hebron','Asia/Gaza','Asia/Amman']] as $field=>$options)
        <div class="min-w-0"><label class="mb-1 block font-semibold" for="{{ $field }}">{{ __('platform.'.(['base_currency_code'=>'currency','default_locale'=>'locale','timezone'=>'timezone'][$field])) }}</label><select x-ref="{{ $field }}" class="min-h-11 w-full rounded-lg border-slate-300" id="{{ $field }}" name="{{ $field }}" required>@foreach($options as $option)<option value="{{ $option }}" @selected(old($field, $options[0]) === $option)>{{ $option }}</option>@endforeach</select></div>
    @endforeach
    </div>
    <button x-show="!review" class="min-h-11 rounded-lg border px-5 font-semibold" type="button" @click="if ($el.form.reportValidity()) { summary = Object.fromEntries(['name_ar', 'name_en', 'base_currency_code', 'default_locale', 'timezone', 'owner_name', 'owner_email'].map(field => [field, $refs[field].value])); review = true; $nextTick(() => $refs.reviewHeading.focus()) }">{{ __('platform.review') }}</button>
    <section x-show="review" x-cloak>
        <h3 x-ref="reviewHeading" tabindex="-1" class="mb-4 text-lg font-semibold">{{ __('platform.review') }}</h3>
        <dl class="mb-5 grid gap-4 sm:grid-cols-2">
        @foreach(['name_ar'=>'name_ar','name_en'=>'name_en','base_currency_code'=>'currency','default_locale'=>'locale','timezone'=>'timezone','owner_name'=>'owner_name','owner_email'=>'owner_email'] as $field=>$label)
            <div class="min-w-0"><dt class="text-sm text-slate-600">{{ __('platform.'.$label) }}</dt><dd class="break-words" x-text="summary.{{ $field }}"></dd></div>
        @endforeach
        </dl>
        <div class="flex flex-wrap gap-3"><button class="min-h-11 rounded-lg border px-5" type="button" @click="review = false; $nextTick(() => $refs.name_ar.focus())" :disabled="saving">{{ __('platform.edit') }}</button><button class="min-h-11 rounded-lg bg-blue-700 px-5 font-semibold text-white" type="submit" :disabled="saving">{{ __('platform.confirm') }}</button></div>
    </section>
    <noscript><p>{{ __('platform.javascript_required') }}</p></noscript>
</form>
@endsection
