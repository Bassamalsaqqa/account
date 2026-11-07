@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <a href="{{ route('platform.index') }}" class="hover:text-primary transition-colors text-text-secondary font-semibold">{{ __('platform.companies') }}</a>
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-semibold truncate max-w-[200px] sm:max-w-xs">{{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Back to Directory Link -->
    <div>
        <a href="{{ route('platform.index') }}"
           class="inline-flex min-h-11 items-center gap-1.5 text-sm sm:text-sm font-semibold text-text-secondary hover:text-primary transition-colors">
            <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
            <span>{{ __('platform.back') }}</span>
        </a>
    </div>

    <!-- Company Header & Identity Card -->
    <div class="rounded-card border border-border bg-surface p-6 sm:p-7 shadow-panel">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="min-w-0">
                <h1 class="break-words text-xl sm:text-2xl font-bold tracking-tight text-text-primary">
                    {{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}
                </h1>
                <p class="mt-1 break-all font-mono text-xs text-text-muted" dir="ltr">
                    {{ $company->public_id }}
                </p>
            </div>
            <div class="shrink-0">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold {{ $company->status === 'active' ? 'bg-success-bg text-success border border-success/20' : 'bg-surface-soft text-text-secondary border border-border' }}">
                    {{ __('platform.status_'.$company->status) }}
                </span>
            </div>
        </div>

        <div class="my-6 border-t border-border/70"></div>

        <!-- Metadata Grid -->
        <dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 text-sm">
            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted mb-1">
                    {{ __('platform.status') }}
                </dt>
                <dd class="font-semibold text-text-primary">
                    {{ __('platform.status_'.$company->status) }}
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted mb-1">
                    {{ __('platform.currency') }}
                </dt>
                <dd class="font-mono font-semibold text-text-primary" dir="ltr">
                    {{ $company->base_currency_code }}
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted mb-1">
                    {{ __('platform.locale') }}
                </dt>
                <dd class="font-semibold text-text-primary">
                    {{ $company->default_locale }}
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted mb-1">
                    {{ __('platform.timezone') }}
                </dt>
                <dd class="font-mono font-semibold text-text-primary" dir="ltr">
                    {{ $company->timezone }}
                </dd>
            </div>

            <div class="min-w-0">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted mb-1">
                    {{ __('platform.ai') }}
                </dt>
                <dd>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $aiEnabled ? 'bg-surface-blue text-primary border border-primary-100' : 'bg-surface-soft text-text-secondary border border-border' }}">
                        {{ __('platform.'.($aiEnabled ? 'on' : 'off')) }}
                    </span>
                </dd>
            </div>

            <div class="min-w-0 sm:col-span-2 lg:col-span-1">
                <dt class="text-xs font-semibold uppercase tracking-wider text-text-muted mb-1">
                    {{ __('platform.health') }}
                </dt>
                <dd>
                    @include('platform.health', ['checkedAt' => $health?->checked_at, 'domains' => $health?->domains])
                </dd>
                <p class="text-xs text-text-muted mt-1.5 leading-relaxed">
                    {{ __('platform.health_help') }}
                </p>
            </div>
        </dl>

        @php
            $platformPermissions = request()->attributes->get('platform_permissions', []);
            $canManageSubscription = in_array('subscription.manage', $platformPermissions, true);
        @endphp

        <!-- Subscription Management Summary Link -->
        @if($canManageSubscription)
            <div class="my-6 border-t border-border/70"></div>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 p-4 rounded-card bg-surface-soft/75 border border-border">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-control bg-surface-blue text-primary grid place-items-center shrink-0 border border-primary-100">
                        <x-icon name="receipt" class="w-5 h-5" />
                    </div>
                    <div class="min-w-0">
                        <span class="text-xs font-semibold uppercase tracking-wider text-text-muted block leading-none mb-1">{{ __('subscriptions.subscription_details') }}</span>
                        <span class="text-sm font-bold text-text-primary block truncate">{{ __('subscriptions.subtitle') }}</span>
                    </div>
                </div>
                <div class="shrink-0">
                    <a href="{{ route('platform.companies.subscription.show', $company->public_id) }}"
                       class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-4 text-xs sm:text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors">
                        <span>{{ __('subscriptions.manage') }}</span>
                        <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
                    </a>
                </div>
            </div>
        @endif
    </div>

    <!-- Tenant Impersonation Action Card -->
    @php
        $canImpersonate = in_array('company.impersonate', $platformPermissions, true) && $company->status === 'active';
    @endphp
    <section class="rounded-card border border-border bg-surface p-6 sm:p-7 shadow-panel" aria-labelledby="impersonation-section-title">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-4 border-b border-border/70">
            <div class="flex items-center gap-2.5">
                <div class="w-8 h-8 rounded-control bg-primary-50 text-primary grid place-items-center shrink-0">
                    <x-icon name="shield" class="w-4 h-4" />
                </div>
                <h2 id="impersonation-section-title" class="text-base font-bold text-text-primary">{{ __('support.title') }}</h2>
            </div>
            <div>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-surface-blue text-primary border border-primary-100">
                    {{ __('platform.impersonation_badge') }}
                </span>
            </div>
        </div>

        <p class="mt-4 max-w-3xl text-sm leading-relaxed text-text-secondary">{{ __('support.help') }}</p>

        <!-- Company Owner Resolution State -->
        <div class="mt-4 p-4 rounded-card bg-surface-soft/75 border border-border text-xs sm:text-sm">
            <div class="flex items-center justify-between gap-3">
                <span class="text-text-muted font-medium">{{ __('platform.impersonate_target') }}:</span>
                @if($companyOwner)
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-md bg-success-bg text-success text-xs font-bold border border-success/20">
                            {{ __('platform.impersonate_owner_available') }}
                        </span>
                        <span class="font-bold text-text-primary">{{ $companyOwner->name }}</span>
                        <span class="font-mono text-xs text-text-muted" dir="ltr">(#{{ $companyOwner->id }})</span>
                    </div>
                @else
                    <span class="text-warning font-semibold">{{ __('platform.impersonate_owner_missing') }}</span>
                @endif
            </div>
        </div>

        @if($canImpersonate)
            @if($companyOwner)
                @if($errors->has('reason') || $errors->has('current_password'))
                    <div role="alert" tabindex="-1" autofocus class="mt-4 rounded-control border border-danger/20 bg-danger-bg p-3.5 text-sm text-danger">
                        {{ $errors->first('reason') ?: $errors->first('current_password') }}
                    </div>
                @endif

                <form class="mt-5 grid gap-4 sm:grid-cols-2" method="POST" action="{{ route('platform.support.start', $company->public_id) }}">
                    @csrf
                    <div class="sm:col-span-1">
                        <label class="mb-1.5 block text-sm font-bold uppercase tracking-wider text-text-secondary" for="support-reason">{{ __('support.reason') }}</label>
                        <input id="support-reason"
                               name="reason"
                               required
                               minlength="5"
                               maxlength="200"
                               value="{{ old('reason') }}"
                               aria-describedby="support-reason-help @error('reason') support-reason-error @enderror"
                               @error('reason') aria-invalid="true" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20">
                        <p id="support-reason-help" class="mt-1 text-xs leading-relaxed text-text-muted">{{ __('support.reason_help') }}</p>
                        @error('reason')<p id="support-reason-error" role="alert" class="mt-1 text-xs text-danger font-semibold">{{ $message }}</p>@enderror
                    </div>

                    <div class="sm:col-span-1">
                        <label class="mb-1.5 block text-sm font-bold uppercase tracking-wider text-text-secondary" for="support-password">{{ __('support.password') }}</label>
                        <input id="support-password"
                               name="current_password"
                               type="password"
                               required
                               autocomplete="current-password"
                               @error('current_password') aria-invalid="true" aria-describedby="support-password-error" @enderror
                               class="min-h-11 w-full rounded-control border border-border bg-surface px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20">
                        @error('current_password')<p id="support-password-error" role="alert" class="mt-1 text-xs text-danger font-semibold">{{ $message }}</p>@enderror
                    </div>

                    <div class="sm:col-span-2 pt-2">
                        <button class="min-h-11 w-full sm:w-auto inline-flex items-center justify-center gap-2 rounded-control bg-primary px-6 text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors cursor-pointer" type="submit">
                            <x-icon name="shield" class="w-4 h-4" />
                            <span>{{ __('support.start') }}</span>
                        </button>
                    </div>
                </form>
            @else
                <p class="mt-4 text-sm text-warning font-semibold">{{ __('support.owner_unavailable') }}</p>
            @endif
        @else
            <p class="mt-4 text-sm text-text-muted">{{ __('support.permission') }}</p>
        @endif
    </section>

    <!-- Company Members List -->
    <div class="space-y-3">
        <div>
            <h2 class="text-lg font-bold text-text-primary">{{ __('platform.members') }}</h2>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('platform.member_help') }}</p>
        </div>

        <div class="rounded-card border border-border bg-surface shadow-panel overflow-hidden">
            <ul class="divide-y divide-slate-100">
                @forelse($members as $member)
                    <li class="p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 hover:bg-surface-soft/60 transition-colors">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-surface-soft text-text-secondary font-bold text-xs grid place-items-center shrink-0 border border-border">
                                <x-icon name="users" class="w-4 h-4 text-text-muted" />
                            </div>
                            <div class="min-w-0">
                                <span class="font-bold text-sm text-text-primary block truncate">{{ $member->name }}</span>
                                <span class="text-xs text-text-muted block mt-0.5" dir="auto">{{ $member->role_names ?: '—' }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $member->is_owner ? 'bg-primary-50 text-primary border border-primary/20' : 'bg-surface-soft text-text-secondary border border-border' }}">
                                {{ __('platform.'.($member->is_owner ? 'owner' : 'member')) }}
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $member->status === 'active' ? 'bg-success-bg text-success border border-success/20' : 'bg-surface-soft text-text-secondary border border-border' }}">
                                {{ __('platform.status_'.$member->status) }}
                            </span>
                        </div>
                    </li>
                @empty
                    <li class="p-6 text-center text-sm text-text-muted">
                        {{ __('platform.empty') }}
                    </li>
                @endforelse
            </ul>
        </div>

        <div class="mt-4">
            <x-table-pagination :paginator="$members" :livewire="false" />
        </div>
    </div>
</div>
@endsection
