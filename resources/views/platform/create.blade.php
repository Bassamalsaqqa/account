@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <a href="{{ route('platform.index') }}" class="hover:text-primary transition-colors text-text-secondary font-semibold">{{ __('platform.companies') }}</a>
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-semibold">{{ __('platform.create') }}</span>
@endsection

@section('content')
<div class="max-w-4xl mx-auto space-y-6">
    <!-- Back Navigation -->
    <div>
        <a href="{{ route('platform.index') }}"
           class="inline-flex min-h-11 items-center gap-1.5 text-xs sm:text-sm font-semibold text-text-secondary hover:text-primary transition-colors">
            <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
            <span>{{ __('platform.back') }}</span>
        </a>
    </div>

    <!-- Page Header & Instructions -->
    <div>
        <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-text-primary">{{ __('platform.create') }}</h1>
        <p class="text-xs sm:text-sm text-text-muted mt-1 leading-relaxed">{{ __('platform.create_help') }}</p>
    </div>

    <!-- Validation Error Summary -->
    @if($errors->any())
        <div role="alert" tabindex="-1" autofocus class="rounded-card border border-danger/20 bg-danger-bg p-4 sm:p-5 text-sm text-danger shadow-panel">
            <div class="flex items-start gap-3">
                <x-icon name="shield" class="w-5 h-5 text-danger shrink-0 mt-0.5" />
                <div class="space-y-1.5">
                    <p class="font-bold">
                        {{ app()->getLocale() === 'ar' ? 'تعذر إكمال العملية، يرجى تصحيح الأخطاء أدناه:' : 'There was a problem with your submission:' }}
                    </p>
                    <ul class="list-disc list-inside space-y-0.5 text-xs">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    @endif

    <!-- Form Card -->
    <form method="POST"
          action="{{ route('platform.companies.store') }}"
          class="rounded-card border border-border bg-white p-6 sm:p-8 space-y-6 shadow-panel"
          x-data="{ review: false, saving: false, summary: {}, dirty: false }"
          @input="dirty = true"
          @change="dirty = true"
          @submit="saving = true"
          @beforeunload.window="if (dirty && !saving) { $event.preventDefault(); $event.returnValue = '' }">
        @csrf
        <input type="hidden" name="request_key" value="{{ old('request_key', $requestKey) }}">

        <!-- Editable Form Inputs -->
        <div class="space-y-6" x-show="!review">
            <!-- Business Information Group -->
            <div>
                <h2 class="text-xs font-bold uppercase tracking-wider text-text-muted mb-4 pb-2 border-b border-border/70 flex items-center gap-2">
                    <x-icon name="store" class="w-4 h-4 text-primary" />
                    <span>{{ __('platform.name') }}</span>
                </h2>

                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach(['name_ar', 'name_en'] as $field)
                        <div class="min-w-0">
                            <label class="block font-bold text-xs uppercase tracking-wider text-text-secondary mb-1.5" for="{{ $field }}">
                                {{ __('platform.'.$field) }}
                            </label>
                            <input x-ref="{{ $field }}"
                                   id="{{ $field }}"
                                   name="{{ $field }}"
                                   type="text"
                                   value="{{ old($field) }}"
                                   class="min-h-11 w-full rounded-control border border-border bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                                   maxlength="255"
                                   @if($field !== 'name_en') required @endif
                                   @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                            @error($field)
                                <p id="{{ $field }}-error" class="mt-1.5 text-xs font-semibold text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach

                    @foreach(['base_currency_code' => ['ILS', 'USD', 'JOD'], 'default_locale' => ['ar', 'en'], 'timezone' => ['Asia/Hebron', 'Asia/Gaza', 'Asia/Amman']] as $field => $options)
                        <div class="min-w-0">
                            <label class="block font-bold text-xs uppercase tracking-wider text-text-secondary mb-1.5" for="{{ $field }}">
                                {{ __('platform.'.(['base_currency_code' => 'currency', 'default_locale' => 'locale', 'timezone' => 'timezone'][$field])) }}
                            </label>
                            <select x-ref="{{ $field }}"
                                    class="min-h-11 w-full rounded-control border border-border bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                                    id="{{ $field }}"
                                    name="{{ $field }}"
                                    required>
                                @foreach($options as $option)
                                    <option value="{{ $option }}" @selected(old($field, $options[0]) === $option)>{{ $option }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Initial Owner Account Information Group -->
            <div class="pt-2">
                <h2 class="text-xs font-bold uppercase tracking-wider text-text-muted mb-4 pb-2 border-b border-border/70 flex items-center gap-2">
                    <x-icon name="users" class="w-4 h-4 text-primary" />
                    <span>{{ __('platform.owner') }}</span>
                </h2>

                <div class="grid gap-5 sm:grid-cols-2">
                    @foreach(['owner_name', 'owner_email', 'password', 'password_confirmation'] as $field)
                        <div class="min-w-0">
                            <label class="block font-bold text-xs uppercase tracking-wider text-text-secondary mb-1.5" for="{{ $field }}">
                                {{ __('platform.'.$field) }}
                            </label>
                            <input x-ref="{{ $field }}"
                                   id="{{ $field }}"
                                   name="{{ $field }}"
                                   type="{{ str_starts_with($field, 'password') ? 'password' : ($field === 'owner_email' ? 'email' : 'text') }}"
                                   value="{{ str_starts_with($field, 'password') ? '' : old($field) }}"
                                   class="min-h-11 w-full rounded-control border border-border bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                                   maxlength="255"
                                   required
                                   @if(str_starts_with($field, 'password')) autocomplete="new-password" minlength="8" @endif
                                   @if($field === 'owner_email') dir="ltr" @endif
                                   @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror>
                            @error($field)
                                <p id="{{ $field }}-error" class="mt-1.5 text-xs font-semibold text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="pt-4 border-t border-border/70 flex items-center justify-end">
                <button type="button"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-6 text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors cursor-pointer"
                        @click="if ($el.form.reportValidity()) { summary = Object.fromEntries(['name_ar', 'name_en', 'base_currency_code', 'default_locale', 'timezone', 'owner_name', 'owner_email'].map(field => [field, $refs[field].value])); review = true; $nextTick(() => requestAnimationFrame(() => $refs.reviewHeading.focus())) }">
                    <span>{{ __('platform.review') }}</span>
                    <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
                </button>
            </div>
        </div>

        <!-- Read-Only Review Confirmation Step -->
        <section x-show="review" x-cloak class="space-y-6">
            <div>
                <h2 x-ref="reviewHeading" tabindex="-1" class="text-lg font-bold text-text-primary focus:outline-none">
                    {{ __('platform.review') }}
                </h2>
                <p class="text-xs text-text-muted mt-0.5">
                    {{ __('platform.create_help') }}
                </p>
            </div>

            <div class="rounded-card border border-border bg-surface-soft/75 p-5 sm:p-6">
                <dl class="grid gap-5 sm:grid-cols-2 text-sm">
                    @foreach(['name_ar' => 'name_ar', 'name_en' => 'name_en', 'base_currency_code' => 'currency', 'default_locale' => 'locale', 'timezone' => 'timezone', 'owner_name' => 'owner_name', 'owner_email' => 'owner_email'] as $field => $label)
                        <div class="min-w-0">
                            <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted mb-1">
                                {{ __('platform.'.$label) }}
                            </dt>
                            <dd class="font-semibold text-text-primary break-words" x-text="summary.{{ $field }}" @if($field === 'owner_email' || $field === 'base_currency_code' || $field === 'timezone') dir="ltr" @endif></dd>
                        </div>
                    @endforeach
                </dl>
            </div>

            <div class="flex flex-wrap items-center justify-end gap-3 pt-2">
                <button type="button"
                        class="inline-flex min-h-11 items-center justify-center rounded-control border border-border bg-white px-5 text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer"
                        @click="review = false; $nextTick(() => requestAnimationFrame(() => $refs.name_ar.focus()))"
                        :disabled="saving">
                    {{ __('platform.edit') }}
                </button>
                <button type="submit"
                        class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-6 text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors cursor-pointer disabled:opacity-50"
                        :disabled="saving">
                    <x-icon name="check" class="w-4 h-4" />
                    <span>{{ __('platform.confirm') }}</span>
                </button>
            </div>
        </section>

        <noscript>
            <p class="mt-4 text-sm text-warning font-semibold">{{ __('platform.javascript_required') }}</p>
        </noscript>
    </form>
</div>
@endsection
