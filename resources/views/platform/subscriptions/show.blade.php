@extends('layouts.platform')

@section('content')
@php
    $submittedOperation = old('form_company_public_id') === $company->public_id ? old('form_operation') : null;
    $oldFor = static function (string $operation, string $key, mixed $fallback = null) use ($submittedOperation): mixed {
        $value = $submittedOperation === $operation ? old($key, $fallback) : $fallback;
        return is_scalar($value) || $value === null ? $value : $fallback;
    };
@endphp
<div class="space-y-6">
    <!-- Back Navigation -->
    <div>
        <a href="{{ route('platform.companies.show', $company->public_id) }}"
           class="inline-flex min-h-11 items-center gap-1.5 text-xs sm:text-sm font-semibold text-text-secondary hover:text-primary transition-colors">
            <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
            <span>{{ __('subscriptions.back_to_company') }}</span>
        </a>
    </div>

    <!-- Page Header & Company Context -->
    <div class="bg-white border border-border rounded-card shadow-panel p-5 sm:p-7">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="min-w-0">
                <span class="text-[11px] font-bold text-text-muted uppercase tracking-wider block mb-1">
                    {{ __('subscriptions.title') }}
                </span>
                <h1 class="break-words text-xl sm:text-2xl font-extrabold text-text-primary">
                    {{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}
                </h1>
                <p class="mt-1 break-all font-mono text-xs text-text-muted" dir="ltr">
                    {{ $company->public_id }}
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2 shrink-0">
                @if($subscription === null)
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold bg-surface-soft text-text-muted">
                        {{ __('subscriptions.status_unmanaged') }}
                    </span>
                @else
                    @php
                        $state = $timeline['state'] ?? 'active';
                        $stateClasses = match($state) {
                            'active' => 'bg-success-bg text-success border border-emerald-200',
                            'approaching_renewal' => 'bg-amber-50 text-amber-800 border border-amber-200',
                            'expired_in_grace' => 'bg-orange-50 text-orange-800 border border-orange-200',
                            'read_only' => 'bg-danger-bg text-danger border border-danger/20',
                            default => 'bg-surface-soft text-text-muted',
                        };
                        $stateLabel = match($state) {
                            'active' => __('subscriptions.status_active'),
                            'approaching_renewal' => __('subscriptions.status_approaching_renewal', ['days' => $timeline['days_until_renewal'] ?? 0]),
                            'expired_in_grace' => __('subscriptions.status_expired_in_grace', ['date' => $timeline['read_only_from']]),
                            'read_only' => __('subscriptions.status_read_only'),
                            default => $state,
                        };
                    @endphp
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold {{ $stateClasses }}">
                        {{ $stateLabel }}
                    </span>

                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold {{ $effectiveEnforcement ? 'bg-danger-bg text-danger border border-danger/20' : 'bg-slate-100 text-slate-700 border border-slate-200' }}">
                        {{ $effectiveEnforcement ? __('subscriptions.enforcement_active') : __('subscriptions.enforcement_preview') }}
                    </span>
                @endif
            </div>
        </div>

        @if($timeline['reminder_due'] ?? false)
            <div role="status" class="mt-4 flex items-center gap-2.5 rounded-control border border-amber-300 bg-amber-50 p-3 text-xs sm:text-sm font-bold text-amber-900">
                <x-icon name="bell" class="w-4 h-4 text-amber-700 shrink-0" />
                <span>{{ __('subscriptions.reminder_due_alert', ['days' => $timeline['days_until_renewal'] ?? 0]) }}</span>
            </div>
        @endif
    </div>

    <!-- Validation Error Summary -->
    @if($errors->any() && $submittedOperation !== null)
        <div role="alert" tabindex="-1" autofocus class="rounded-card border border-danger/30 bg-danger-bg p-4 sm:p-5 text-sm text-danger shadow-xs">
            <div class="flex items-start gap-3">
                <x-icon name="shield" class="w-5 h-5 text-danger shrink-0 mt-0.5" />
                <div class="space-y-1.5">
                    <p class="font-bold">
                        {{ app()->getLocale() === 'ar' ? 'تعذر إكمال العملية، يرجى مراجعة الأخطاء أدناه:' : 'There was a problem with your submission:' }}
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

    <!-- Active Subscription Overview (if configured) -->
    @if($subscription !== null)
        <div class="bg-white border border-border rounded-card shadow-panel p-5 sm:p-6 space-y-4">
            <h2 class="text-xs font-bold text-text-muted uppercase tracking-wider pb-2 border-b border-border flex items-center gap-2">
                <x-icon name="receipt" class="w-4 h-4 text-primary" />
                <span>{{ __('subscriptions.commercial_terms') }}</span>
            </h2>

            <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 text-sm">
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.plan') }}</dt>
                    <dd class="font-extrabold text-text-primary">{{ $subscription->plan }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.fee') }}</dt>
                    <dd class="font-mono font-bold text-text-primary text-base" dir="ltr">
                        {{ $subscription->fee }} <span class="text-xs font-normal text-text-muted">{{ $subscription->currency }}</span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.starts_on') }}</dt>
                    <dd class="font-mono text-text-primary" dir="ltr">{{ $subscription->starts_on }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.renews_on') }}</dt>
                    <dd class="font-mono font-bold text-primary text-base" dir="ltr">{{ $subscription->renews_on }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.grace_days') }}</dt>
                    <dd class="text-text-primary">{{ $subscription->grace_days }} {{ app()->getLocale() === 'ar' ? 'أيام' : 'days' }}</dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.reminder_days') }}</dt>
                    <dd class="font-mono text-text-primary" dir="ltr">
                        {{ is_array(json_decode($subscription->reminder_days, true)) ? implode(', ', json_decode($subscription->reminder_days, true)) : $subscription->reminder_days }}
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.enforcement_enabled') }}</dt>
                    <dd>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold {{ $subscription->enforcement_enabled ? 'bg-primary-50 text-primary' : 'bg-surface-soft text-text-muted' }}">
                            {{ $subscription->enforcement_enabled ? __('platform.on') : __('platform.off') }}
                        </span>
                    </dd>
                </div>
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">{{ __('subscriptions.revision') }}</dt>
                    <dd class="font-mono text-text-muted text-xs" dir="ltr">#{{ $subscription->revision }}</dd>
                </div>
            </dl>

            @if($subscription->notes)
                <div class="pt-3 border-t border-border/60">
                    <span class="text-xs font-bold text-text-muted uppercase tracking-wider block mb-1">{{ __('subscriptions.notes') }}</span>
                    <p class="text-xs sm:text-sm text-text-secondary whitespace-pre-wrap leading-relaxed">{{ $subscription->notes }}</p>
                </div>
            @endif
        </div>
    @endif

    <!-- Operational Action Cards -->
    <div class="grid items-start gap-6 {{ $subscription !== null ? 'lg:grid-cols-2' : '' }}">
        <!-- Form 1: Configure / Save Terms -->
        <div class="bg-white border border-border rounded-card shadow-panel p-5 sm:p-6"
             x-data="{ review: false, saving: false, summary: {} }"
             x-init="$watch('review', shown => $nextTick(() => (shown ? $refs.reviewPanel : $refs.plan).focus()))"
             @submit="if (!review || saving) { $event.preventDefault(); } else { saving = true; }">
            <h2 class="text-sm font-bold text-text-primary uppercase tracking-wider mb-1 flex items-center gap-2">
                <x-icon name="settings" class="w-4 h-4 text-primary" />
                <span>{{ $subscription === null ? __('subscriptions.configure_title') : __('subscriptions.edit_terms') }}</span>
            </h2>
            <p class="text-xs text-text-muted mb-4 leading-relaxed">
                {{ $subscription === null ? __('subscriptions.unmanaged_desc') : __('subscriptions.save_terms') }}
            </p>

            <form method="POST" action="{{ route('platform.companies.subscription.update', [$company->public_id, 'configure']) }}" class="space-y-4">
                @csrf
                <input type="hidden" name="request_key" value="{{ $oldFor('configure', 'request_key', $requestKey) }}">
                <input type="hidden" name="expected_revision" value="{{ $oldFor('configure', 'expected_revision', $subscription->revision ?? 0) }}">

                <div :hidden="review" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="min-w-0 sm:col-span-2">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_plan">
                                {{ __('subscriptions.plan') }}
                            </label>
                            @if($errors->has('plan') && $submittedOperation === 'configure')
                                <p id="term_plan-error" class="mb-1 text-sm text-danger">{{ $errors->first('plan') }}</p>
                            @endif
                            <input x-ref="plan"
                                   id="term_plan"
                                   @if($errors->has('plan') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_plan-error" @endif
                                   name="plan"
                                   type="text"
                                   value="{{ $oldFor('configure', 'plan', $subscription->plan ?? '') }}"
                                   class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                                   maxlength="100"
                                   required>
                        </div>

                        <div class="min-w-0">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_fee">
                                {{ __('subscriptions.fee') }}
                            </label>
                            @if($errors->has('fee') && $submittedOperation === 'configure')
                                <p id="term_fee-error" class="mb-1 text-sm text-danger">{{ $errors->first('fee') }}</p>
                            @endif
                            <input x-ref="fee"
                                   id="term_fee"
                                   inputmode="decimal"
                                   @if($errors->has('fee') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_fee-error" @endif
                                   name="fee"
                                   type="text"
                                   dir="ltr"
                                   value="{{ $oldFor('configure', 'fee', $subscription->fee ?? '') }}"
                                   pattern="^\d{1,14}(\.\d{1,6})?$"
                                   class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm font-mono text-text-primary focus:border-primary focus:ring-primary/20"
                                   required>
                        </div>

                        <div class="min-w-0">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_currency">
                                {{ __('subscriptions.currency') }}
                            </label>
                            @if($errors->has('currency') && $submittedOperation === 'configure')
                                <p id="term_currency-error" class="mb-1 text-sm text-danger">{{ $errors->first('currency') }}</p>
                            @endif
                            <select x-ref="currency"
                                    id="term_currency"
                                   @if($errors->has('currency') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_currency-error" @endif
                                    name="currency"
                                    class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                                    required>
                                @foreach(['ILS', 'USD', 'JOD'] as $cur)
                                    <option value="{{ $cur }}" @selected($oldFor('configure', 'currency', $subscription->currency ?? $company->base_currency_code) === $cur)>
                                        {{ $cur }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="min-w-0">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_starts_on">
                                {{ __('subscriptions.starts_on') }}
                            </label>
                            @if($errors->has('starts_on') && $submittedOperation === 'configure')
                                <p id="term_starts_on-error" class="mb-1 text-sm text-danger">{{ $errors->first('starts_on') }}</p>
                            @endif
                            @if($subscription !== null)
                                <input type="hidden" name="starts_on" value="{{ $subscription->starts_on }}">
                                <div class="min-h-11 w-full rounded-control border border-border bg-surface-soft px-3.5 flex items-center font-mono text-sm text-text-muted" dir="ltr">
                                    {{ $subscription->starts_on }}
                                </div>
                            @else
                                <input x-ref="starts_on"
                                       id="term_starts_on"
                                   @if($errors->has('starts_on') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_starts_on-error" @endif
                                       name="starts_on"
                                       type="date"
                                       dir="ltr"
                                       value="{{ $oldFor('configure', 'starts_on', now()->setTimezone($company->timezone)->toDateString()) }}"
                                       class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm font-mono text-text-primary focus:border-primary focus:ring-primary/20"
                                       required>
                            @endif
                        </div>

                        <div class="min-w-0">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_renews_on">
                                {{ __('subscriptions.renews_on') }}
                            </label>
                            @if($errors->has('renews_on') && $submittedOperation === 'configure')
                                <p id="term_renews_on-error" class="mb-1 text-sm text-danger">{{ $errors->first('renews_on') }}</p>
                            @endif
                            @if($subscription !== null)
                                <input type="hidden" name="renews_on" value="{{ $subscription->renews_on }}">
                                <div class="min-h-11 w-full rounded-control border border-border bg-surface-soft px-3.5 flex items-center font-mono text-sm text-text-muted" dir="ltr">
                                    {{ $subscription->renews_on }}
                                </div>
                            @else
                                <input x-ref="renews_on"
                                       id="term_renews_on"
                                   @if($errors->has('renews_on') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_renews_on-error" @endif
                                       name="renews_on"
                                       type="date"
                                       dir="ltr"
                                       value="{{ $oldFor('configure', 'renews_on', now()->setTimezone($company->timezone)->addMonth()->toDateString()) }}"
                                       class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm font-mono text-text-primary focus:border-primary focus:ring-primary/20"
                                       required>
                            @endif
                        </div>

                        <div class="min-w-0">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_grace_days">
                                {{ __('subscriptions.grace_days') }}
                            </label>
                            @if($errors->has('grace_days') && $submittedOperation === 'configure')
                                <p id="term_grace_days-error" class="mb-1 text-sm text-danger">{{ $errors->first('grace_days') }}</p>
                            @endif
                            <input x-ref="grace_days"
                                   id="term_grace_days"
                                   @if($errors->has('grace_days') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_grace_days-error" @endif
                                   name="grace_days"
                                   type="number"
                                   min="0"
                                   max="365"
                                   value="{{ $oldFor('configure', 'grace_days', $subscription->grace_days ?? config('subscriptions.grace_days', 7)) }}"
                                   class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                                   required>
                        </div>

                        <div class="min-w-0">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_reminder_days">
                                {{ __('subscriptions.reminder_days') }}
                            </label>
                            @if($errors->hasAny(['reminder_days', 'reminder_days.*']) && $submittedOperation === 'configure')
                                <p id="term_reminder_days-error" class="mb-1 text-sm text-danger">{{ ($errors->first('reminder_days') ?: $errors->first('reminder_days.*')) }}</p>
                            @endif
                            <input x-ref="reminder_days"
                                   id="term_reminder_days"
                                   @if($errors->hasAny(['reminder_days', 'reminder_days.*']) && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_reminder_days-error" @endif
                                   name="reminder_days"
                                   type="text"
                                   dir="ltr"
                                   value="{{ $oldFor('configure', 'reminder_days', is_array($reminderDays) ? implode(', ', $reminderDays) : '14, 7, 1') }}"
                                   placeholder="14, 7, 1"
                                   class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm font-mono text-text-primary focus:border-primary focus:ring-primary/20"
                                   required>
                            <p class="text-[11px] text-text-muted mt-1">{{ __('subscriptions.reminder_days_help') }}</p>
                        </div>

                        <div class="min-w-0 sm:col-span-2">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_enforcement">
                                {{ __('subscriptions.enforcement_enabled') }}
                            </label>
                            @if($errors->has('enforcement_enabled') && $submittedOperation === 'configure')
                                <p id="term_enforcement-error" class="mb-1 text-sm text-danger">{{ $errors->first('enforcement_enabled') }}</p>
                            @endif
                            <select x-ref="enforcement_enabled"
                                    id="term_enforcement"
                                   @if($errors->has('enforcement_enabled') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_enforcement-error" @endif
                                    name="enforcement_enabled"
                                    class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20">
                                <option value="0" @selected((string) $oldFor('configure', 'enforcement_enabled', $subscription->enforcement_enabled ?? 0) === '0')>
                                    {{ __('platform.off') }} — {{ __('subscriptions.enforcement_preview') }}
                                </option>
                                <option value="1" @selected((string) $oldFor('configure', 'enforcement_enabled', $subscription->enforcement_enabled ?? 0) === '1')>
                                    {{ __('platform.on') }} — {{ __('subscriptions.enforcement_active') }}
                                </option>
                            </select>
                            <p class="text-[11px] text-text-muted mt-1 leading-relaxed">{{ __('subscriptions.enforcement_enabled_help') }}</p>
                        </div>

                        <div class="min-w-0 sm:col-span-2">
                            <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="term_notes">
                                {{ __('subscriptions.notes') }}
                            </label>
                            @if($errors->has('notes') && $submittedOperation === 'configure')
                                <p id="term_notes-error" class="mb-1 text-sm text-danger">{{ $errors->first('notes') }}</p>
                            @endif
                            <textarea x-ref="notes"
                                      id="term_notes"
                                   @if($errors->has('notes') && $submittedOperation === 'configure') aria-invalid="true" aria-describedby="term_notes-error" @endif
                                      name="notes"
                                      rows="2"
                                      maxlength="2000"
                                      class="w-full rounded-control border border-border-strong bg-white p-3 text-sm text-text-primary focus:border-primary focus:ring-primary/20">{{ $oldFor('configure', 'notes', $subscription->notes ?? '') }}</textarea>
                        </div>
                    </div>

                    <div class="pt-3 border-t border-border flex justify-end">
                        <button type="button"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer"
                                @keydown.enter.prevent
                                @keyup.enter.prevent="$el.click()"
                                @click="if ($el.form.reportValidity()) {
                                    summary = {
                                        plan: $refs.plan.value,
                                        fee: $refs.fee.value,
                                        currency: $refs.currency.value,
                                        grace_days: $refs.grace_days.value,
                                        reminder_days: $refs.reminder_days.value,
                                        enforcement: $refs.enforcement_enabled.selectedOptions[0].textContent,
                                        starts_on: $el.form.elements.starts_on.value,
                                        renews_on: $el.form.elements.renews_on.value
                                    };
                                    review = true;
                                }">
                            <span>{{ __('subscriptions.review_title') }}</span>
                            <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
                        </button>
                    </div>
                </div>

                <!-- Review Step -->
                <div :hidden="!review" hidden x-cloak x-ref="reviewPanel" tabindex="-1" role="group" aria-label="{{ __('subscriptions.review_title') }}" class="space-y-4">
                    <div class="bg-surface-soft border border-border rounded-control p-4 text-xs space-y-2">
                        <p class="font-bold text-sm text-text-primary">{{ __('subscriptions.review_title') }}</p>
                        <div class="grid grid-cols-2 gap-2 text-xs">
                            <div><span class="text-text-muted">{{ __('subscriptions.plan') }}:</span> <strong x-text="summary.plan"></strong></div>
                            <div><span class="text-text-muted">{{ __('subscriptions.fee') }}:</span> <strong dir="ltr" x-text="summary.fee + ' ' + summary.currency"></strong></div>
                            <div><span class="text-text-muted">{{ __('subscriptions.grace_days') }}:</span> <strong x-text="summary.grace_days"></strong></div>
                            <div><span class="text-text-muted">{{ __('subscriptions.reminder_days') }}:</span> <strong dir="ltr" x-text="summary.reminder_days"></strong></div>
                            <div><span class="text-text-muted">{{ __('subscriptions.starts_on') }}:</span> <strong dir="ltr" x-text="summary.starts_on"></strong></div>
                            <div><span class="text-text-muted">{{ __('subscriptions.renews_on') }}:</span> <strong dir="ltr" x-text="summary.renews_on"></strong></div>
                            <div><span class="text-text-muted">{{ __('subscriptions.enforcement_enabled') }}:</span> <strong x-text="summary.enforcement"></strong></div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2">
                        <button type="button"
                                class="inline-flex min-h-11 items-center justify-center rounded-control border border-border-strong bg-white px-4 text-xs font-semibold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer"
                                @click="review = false"
                                    @keydown.enter.prevent
                                    @keyup.enter.prevent="$el.click()"
                                :disabled="saving">
                            {{ __('subscriptions.review_edit') }}
                        </button>
                        <button type="submit"
                                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                                :disabled="saving">
                            <x-icon name="check" class="w-4 h-4" />
                            <span>{{ __('subscriptions.save_terms') }}</span>
                        </button>
                    </div>
                </div>
            </form>
        </div>

        @if($subscription !== null)
            <!-- Form 2: Renew Subscription Period -->
            <div class="bg-white border border-border rounded-card shadow-panel p-5 sm:p-6"
                 x-data="{ review: false, saving: false, newDate: @js($oldFor('renew', 'renews_on', now()->parse($subscription->renews_on)->addMonthNoOverflow()->toDateString())) }"
                 x-init="$watch('review', shown => $nextTick(() => (shown ? $refs.reviewPanel : $refs.newDateInput).focus()))"
                 @submit="if (!review || saving) { $event.preventDefault(); } else { saving = true; }">
                <h2 class="text-sm font-bold text-text-primary uppercase tracking-wider mb-1 flex items-center gap-2">
                    <x-icon name="history" class="w-4 h-4 text-primary" />
                    <span>{{ __('subscriptions.renew_title') }}</span>
                </h2>
                <p class="text-xs text-text-muted mb-4 leading-relaxed">
                    {{ __('subscriptions.renew_desc') }}
                </p>

                <form method="POST" action="{{ route('platform.companies.subscription.update', [$company->public_id, 'renew']) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ $oldFor('renew', 'request_key', $requestKey) }}">
                    <input type="hidden" name="expected_revision" value="{{ $oldFor('renew', 'expected_revision', $subscription->revision) }}">

                    <div :hidden="review" class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="min-w-0">
                                <label class="block font-bold text-xs text-text-muted uppercase tracking-wider mb-1.5">
                                    {{ __('subscriptions.renews_on') }} ({{ app()->getLocale() === 'ar' ? 'الحالي' : 'Current' }})
                                </label>
                                <div class="min-h-11 w-full rounded-control border border-border bg-surface-soft px-3.5 flex items-center font-mono text-sm text-text-secondary" dir="ltr">
                                    {{ $subscription->renews_on }}
                                </div>
                            </div>

                            <div class="min-w-0">
                                <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="renew_new_date">
                                    {{ __('subscriptions.new_renews_on') }}
                                </label>
                            @if($errors->has('renews_on') && $submittedOperation === 'renew')
                                <p id="renew_new_date-error" class="mb-1 text-sm text-danger">{{ $errors->first('renews_on') }}</p>
                            @endif
                                <input x-ref="newDateInput"
                                       id="renew_new_date"
                                   @if($errors->has('renews_on') && $submittedOperation === 'renew') aria-invalid="true" aria-describedby="renew_new_date-error" @endif
                                       name="renews_on"
                                       type="date"
                                       dir="ltr"
                                       x-model="newDate"
                                       min="{{ now()->parse($subscription->renews_on)->addDay()->toDateString() }}"
                                       class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm font-mono text-text-primary focus:border-primary focus:ring-primary/20"
                                       required>
                            </div>

                            <div class="min-w-0 sm:col-span-2">
                                <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="renew_notes">
                                    {{ __('subscriptions.notes') }}
                                </label>
                                <textarea id="renew_notes"
                                          name="notes"
                                          rows="2"
                                          maxlength="2000"
                                          placeholder="{{ app()->getLocale() === 'ar' ? 'ملاحظة التجديد (اختياري)...' : 'Renewal notes (optional)...' }}"
                                          class="w-full rounded-control border border-border-strong bg-white p-3 text-sm text-text-primary focus:border-primary focus:ring-primary/20">{{ $oldFor('renew', 'notes') }}</textarea>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="button"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer"
                                    @keydown.enter.prevent
                                @keyup.enter.prevent="$el.click()"
                                @click="if ($el.form.reportValidity()) { review = true; }">
                                <span>{{ __('subscriptions.review_title') }}</span>
                                <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
                            </button>
                        </div>
                    </div>

                    <!-- Review Step -->
                    <div :hidden="!review" hidden x-cloak x-ref="reviewPanel" tabindex="-1" role="group" aria-label="{{ __('subscriptions.review_title') }}" class="space-y-4">
                        <div class="bg-surface-soft border border-border rounded-control p-4 text-xs space-y-2">
                            <p class="font-bold text-sm text-text-primary">{{ __('subscriptions.renew_title') }}</p>
                            <p class="text-text-secondary leading-relaxed">
                                {{ app()->getLocale() === 'ar'
                                    ? 'سيتم تمديد تاريخ التجديد إلى '
                                    : 'The renewal date will be extended to ' }}
                                <strong dir="ltr" x-text="newDate"></strong>.
                                {{ app()->getLocale() === 'ar'
                                    ? ' سيُنشأ رسم جديد غير قابل للتعديل بمبلغ '
                                    : ' A new immutable charge will be created for ' }}
                                <strong dir="ltr">{{ $subscription->fee }} {{ $subscription->currency }}</strong>.
                            </p>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button type="button"
                                    class="inline-flex min-h-11 items-center justify-center rounded-control border border-border-strong bg-white px-4 text-xs font-semibold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer"
                                    @click="review = false"
                                    @keydown.enter.prevent
                                    @keyup.enter.prevent="$el.click()"
                                    :disabled="saving">
                                {{ __('subscriptions.review_edit') }}
                            </button>
                            <button type="submit"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                                    :disabled="saving">
                                <x-icon name="check" class="w-4 h-4" />
                                <span>{{ __('subscriptions.renew_button') }}</span>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        @endif
    </div>

    <!-- Form 3: Record Payment Receipt (if configured and has outstanding charges) -->
    @if($subscription !== null)
        <div class="bg-white border border-border rounded-card shadow-panel p-5 sm:p-6"
             x-data="{
                review: false,
                saving: false,
                selectedChargeId: @js((string) $oldFor('payment', 'charge_id', $outstandingCharges[0]->id ?? '')),
                charges: {
                    @foreach($charges as $c)
                        '{{ $c->id }}': {
                            amount: '{{ $c->amount }}',
                            currency: '{{ $c->currency }}',
                            outstanding: '{{ $c->outstanding }}',
                            starts_on: '{{ $c->starts_on }}',
                            ends_before: '{{ $c->ends_before }}'
                        },
                    @endforeach
                },
                amount: @js($oldFor('payment', 'amount', $outstandingCharges[0]->outstanding ?? '')),
                currency: @js($oldFor('payment', 'currency', $outstandingCharges[0]->currency ?? $subscription->currency)),
                updateCharge() {
                    const c = this.charges[this.selectedChargeId];
                    if (c) {
                        this.currency = c.currency;
                        this.amount = c.outstanding;
                    }
                }
             }"
             x-init="$watch('review', shown => $nextTick(() => (shown ? $refs.reviewPanel : $refs.charge).focus()))"
             @submit="if (!review || saving) { $event.preventDefault(); } else { saving = true; }">
            <h2 class="text-sm font-bold text-text-primary uppercase tracking-wider mb-1 flex items-center gap-2">
                <x-icon name="money" class="w-4 h-4 text-primary" />
                <span>{{ __('subscriptions.payment_title') }}</span>
            </h2>
            <p class="text-xs text-text-muted mb-4 leading-relaxed">
                {{ __('subscriptions.payment_desc') }}
            </p>
            @if($charges->hasPages())
                <p class="mb-4 text-xs leading-relaxed text-text-muted">{{ __('subscriptions.charges_page_help') }}</p>
            @endif

            @if(count($outstandingCharges) === 0)
                <div class="rounded-control bg-surface-soft border border-border p-4 text-xs sm:text-sm text-text-muted flex items-center gap-2">
                    <x-icon name="check-circle" class="w-4 h-4 text-success" />
                    <span>{{ __('subscriptions.no_outstanding_charges') }}</span>
                </div>
            @else
                <form method="POST" action="{{ route('platform.companies.subscription.update', [$company->public_id, 'payment']) }}" class="space-y-4">
                    @csrf
                    <input type="hidden" name="request_key" value="{{ $oldFor('payment', 'request_key', $requestKey) }}">
                    <input type="hidden" name="expected_revision" value="{{ $oldFor('payment', 'expected_revision', $subscription->revision) }}">
                    <input type="hidden" name="currency" :value="currency">

                    <div :hidden="review" class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="min-w-0 sm:col-span-2">
                                <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="pay_charge_id">
                                    {{ __('subscriptions.select_charge') }}
                                </label>
                            @if($errors->has('charge_id') && $submittedOperation === 'payment')
                                <p id="pay_charge_id-error" class="mb-1 text-sm text-danger">{{ $errors->first('charge_id') }}</p>
                            @endif
                                <select x-ref="charge" id="pay_charge_id"
                                   @if($errors->has('charge_id') && $submittedOperation === 'payment') aria-invalid="true" aria-describedby="pay_charge_id-error" @endif
                                        name="charge_id"
                                        x-model="selectedChargeId"
                                        @change="updateCharge()"
                                        class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                                        required>
                                    @foreach($outstandingCharges as $oc)
                                        <option value="{{ $oc->id }}">
                                            #{{ $oc->id }} ({{ $oc->starts_on }} → {{ $oc->ends_before }}) — {{ __('subscriptions.outstanding_amount') }}: {{ $oc->outstanding }} {{ $oc->currency }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="min-w-0">
                                <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="pay_amount">
                                    {{ __('subscriptions.payment_amount') }} (<span x-text="currency"></span>)
                                </label>
                            @if($errors->has('amount') && $submittedOperation === 'payment')
                                <p id="pay_amount-error" class="mb-1 text-sm text-danger">{{ $errors->first('amount') }}</p>
                            @endif
                                <input id="pay_amount"
                                       inputmode="decimal"
                                   @if($errors->has('amount') && $submittedOperation === 'payment') aria-invalid="true" aria-describedby="pay_amount-error" @endif
                                       name="amount"
                                       type="text"
                                       dir="ltr"
                                       x-model="amount"
                                       pattern="^\d{1,14}(\.\d{1,6})?$"
                                       class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm font-mono text-text-primary focus:border-primary focus:ring-primary/20"
                                       required>
                            </div>

                            <div class="min-w-0">
                                <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="pay_received_on">
                                    {{ __('subscriptions.received_on') }}
                                </label>
                            @if($errors->has('received_on') && $submittedOperation === 'payment')
                                <p id="pay_received_on-error" class="mb-1 text-sm text-danger">{{ $errors->first('received_on') }}</p>
                            @endif
                                <input id="pay_received_on"
                                   @if($errors->has('received_on') && $submittedOperation === 'payment') aria-invalid="true" aria-describedby="pay_received_on-error" @endif
                                       name="received_on"
                                       type="date"
                                       dir="ltr"
                                       max="{{ now()->setTimezone($company->timezone)->toDateString() }}"
                                       value="{{ $oldFor('payment', 'received_on', now()->setTimezone($company->timezone)->toDateString()) }}"
                                       class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm font-mono text-text-primary focus:border-primary focus:ring-primary/20"
                                       required>
                            </div>

                            <div class="min-w-0 sm:col-span-2">
                                <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="pay_ref">
                                    {{ __('subscriptions.reference') }}
                                </label>
                            @if($errors->has('reference') && $submittedOperation === 'payment')
                                <p id="pay_ref-error" class="mb-1 text-sm text-danger">{{ $errors->first('reference') }}</p>
                            @endif
                                <input id="pay_ref"
                                   @if($errors->has('reference') && $submittedOperation === 'payment') aria-invalid="true" aria-describedby="pay_ref-error" @endif
                                       name="reference"
                                       type="text"
                                       maxlength="255"
                                       placeholder="{{ app()->getLocale() === 'ar' ? 'رقم الإيصال أو الحوالة البنكية...' : 'Bank reference, receipt #...' }}"
                                       value="{{ $oldFor('payment', 'reference') }}"
                                       class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20">
                            </div>

                            <div class="min-w-0 sm:col-span-2">
                                <label class="block font-bold text-xs text-text-secondary uppercase tracking-wider mb-1.5" for="pay_notes">
                                    {{ __('subscriptions.notes') }}
                                </label>
                                <textarea id="pay_notes"
                                          name="notes"
                                          rows="1"
                                          maxlength="2000"
                                          placeholder="{{ app()->getLocale() === 'ar' ? 'ملاحظة إضافية (اختياري)...' : 'Internal receipt note (optional)...' }}"
                                          class="w-full rounded-control border border-border-strong bg-white p-3 text-sm text-text-primary focus:border-primary focus:ring-primary/20">{{ $oldFor('payment', 'notes') }}</textarea>
                            </div>
                        </div>

                        <div class="pt-3 border-t border-border flex justify-end">
                            <button type="button"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer"
                                    @keydown.enter.prevent
                                @keyup.enter.prevent="$el.click()"
                                @click="if ($el.form.reportValidity()) { review = true; }">
                                <span>{{ __('subscriptions.review_title') }}</span>
                                <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
                            </button>
                        </div>
                    </div>

                    <!-- Review Step -->
                    <div :hidden="!review" hidden x-cloak x-ref="reviewPanel" tabindex="-1" role="group" aria-label="{{ __('subscriptions.review_title') }}" class="space-y-4">
                        <div class="bg-surface-soft border border-border rounded-control p-4 text-xs space-y-2">
                            <p class="font-bold text-sm text-text-primary">{{ __('subscriptions.payment_title') }}</p>
                            <p class="text-text-secondary leading-relaxed">
                                {{ app()->getLocale() === 'ar'
                                    ? 'تسجيل دفعة مستلمة بمبلغ '
                                    : 'Recording a received payment of ' }}
                                <strong dir="ltr" x-text="amount + ' ' + currency"></strong>
                                {{ app()->getLocale() === 'ar'
                                    ? ' للرسم رقم '
                                    : ' for Charge #' }}
                                <strong dir="ltr" x-text="selectedChargeId"></strong>.
                                <br>
                                <span class="text-text-muted">{{ __('subscriptions.payment_desc') }}</span>
                            </p>
                        </div>

                        <div class="flex items-center justify-end gap-3 pt-2">
                            <button type="button"
                                    class="inline-flex min-h-11 items-center justify-center rounded-control border border-border-strong bg-white px-4 text-xs font-semibold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer"
                                    @click="review = false"
                                    @keydown.enter.prevent
                                    @keyup.enter.prevent="$el.click()"
                                    :disabled="saving">
                                {{ __('subscriptions.review_edit') }}
                            </button>
                            <button type="submit"
                                    class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors cursor-pointer disabled:opacity-50"
                                    :disabled="saving">
                                <x-icon name="check" class="w-4 h-4" />
                                <span>{{ __('subscriptions.record_payment_button') }}</span>
                            </button>
                        </div>
                    </div>
                </form>
            @endif
        </div>
    @endif

    <!-- Immutable Charges & Receipts History -->
    <div class="space-y-4">
        <div>
            <h2 class="text-base sm:text-lg font-bold text-text-primary">{{ __('subscriptions.charges_heading') }}</h2>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('subscriptions.charges_desc') }}</p>
        </div>

        <div class="bg-white border border-border rounded-card shadow-panel overflow-hidden">
            @if($charges->isEmpty())
                <div class="p-8 text-center text-sm text-text-muted">
                    <x-icon name="receipt" class="w-8 h-8 mx-auto mb-2 text-text-muted/60" />
                    <p>{{ __('subscriptions.no_charges') }}</p>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-start border-collapse text-xs sm:text-sm">
                        <thead class="bg-surface-soft border-b border-border">
                            <tr>
                                <th scope="col" class="px-4 py-3 text-start font-bold text-text-muted uppercase tracking-wider">
                                    {{ __('subscriptions.charge_id') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-start font-bold text-text-muted uppercase tracking-wider">
                                    {{ __('subscriptions.plan') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-start font-bold text-text-muted uppercase tracking-wider">
                                    {{ __('subscriptions.period') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-start font-bold text-text-muted uppercase tracking-wider">
                                    {{ __('subscriptions.charge_amount') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-start font-bold text-text-muted uppercase tracking-wider">
                                    {{ __('subscriptions.paid_amount') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-start font-bold text-text-muted uppercase tracking-wider">
                                    {{ __('subscriptions.outstanding_amount') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-start font-bold text-text-muted uppercase tracking-wider">
                                    {{ __('platform.status') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            @foreach($charges as $charge)
                                <tr class="hover:bg-surface-soft/30 transition-colors">
                                    <th scope="row" class="px-4 py-3.5 font-mono font-bold text-text-primary text-start" dir="ltr">
                                        #{{ $charge->id }}
                                    </th>
                                    <td class="px-4 py-3.5 font-semibold text-text-primary">
                                        {{ $charge->plan }}
                                    </td>
                                    <td class="px-4 py-3.5 font-mono text-text-secondary text-xs" dir="ltr">
                                        {{ $charge->starts_on }} → {{ $charge->ends_before }}
                                    </td>
                                    <td class="px-4 py-3.5 font-mono font-bold text-text-primary" dir="ltr">
                                        {{ $charge->amount }} <span class="text-xs text-text-muted">{{ $charge->currency }}</span>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono text-success font-semibold" dir="ltr">
                                        {{ $charge->paid }} <span class="text-xs text-text-muted">{{ $charge->currency }}</span>
                                    </td>
                                    <td class="px-4 py-3.5 font-mono font-bold {{ $charge->is_settled ? 'text-text-muted' : 'text-danger' }}" dir="ltr">
                                        {{ $charge->outstanding }} <span class="text-xs text-text-muted">{{ $charge->currency }}</span>
                                    </td>
                                    <td class="px-4 py-3.5">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold {{ $charge->is_settled ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                            {{ $charge->is_settled ? __('subscriptions.settled') : __('subscriptions.has_outstanding') }}
                                        </span>
                                    </td>
                                </tr>

                                @php $chargePayments = $paymentsByCharge->get($charge->id, collect()); @endphp
                                @if($chargePayments->isNotEmpty())
                                    <tr class="bg-surface-soft/40">
                                        <td colspan="7" class="px-6 py-3">
                                            <div class="space-y-1.5">
                                                <span class="text-[11px] font-bold text-text-muted uppercase tracking-wider block">
                                                    {{ __('subscriptions.payments_heading') }} (#{{ $charge->id }})
                                                </span>
                                                <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                                                    @foreach($chargePayments as $payment)
                                                        <div class="bg-white border border-border/80 rounded-control p-2.5 text-xs shadow-2xs">
                                                            <div class="flex items-center justify-between gap-2">
                                                                <span class="font-mono font-bold text-text-primary" dir="ltr">
                                                                    {{ $payment->amount }} {{ $payment->currency }}
                                                                </span>
                                                                <span class="font-mono text-text-muted text-[11px]" dir="ltr">
                                                                    {{ $payment->received_on }}
                                                                </span>
                                                            </div>
                                                            @if($payment->reference)
                                                                <p class="text-[11px] text-text-secondary mt-1 truncate" dir="auto">
                                                                    <span class="text-text-muted">{{ __('subscriptions.reference') }}:</span> {{ $payment->reference }}
                                                                </p>
                                                            @endif
                                                            @if($payment->notes)
                                                                <p class="text-[11px] text-text-muted mt-0.5 truncate" dir="auto">
                                                                    {{ $payment->notes }}
                                                                </p>
                                                            @endif
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="p-4 border-t border-border">
                    {{ $charges->withQueryString()->links('platform.pagination') }}
                    {{ $payments->links('platform.pagination') }}
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
