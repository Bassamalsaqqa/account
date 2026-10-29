@extends('layouts.platform')

@section('content')
<div class="space-y-6">
    <!-- Back to Directory -->
    <div>
        <a href="{{ route('platform.index') }}"
           class="inline-flex min-h-11 items-center gap-1.5 text-xs sm:text-sm font-semibold text-text-secondary hover:text-primary transition-colors">
            <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
            <span>{{ __('platform.back') }}</span>
        </a>
    </div>

    <!-- Company Header -->
    <div class="bg-white border border-border rounded-card shadow-panel p-6 sm:p-7">
        <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
            <div class="min-w-0">
                <h1 class="break-words text-xl sm:text-2xl font-extrabold text-text-primary">
                    {{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}
                </h1>
                <p class="mt-1 break-all font-mono text-xs text-text-muted" dir="ltr">
                    {{ $company->public_id }}
                </p>
            </div>
            <div class="shrink-0">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-bold {{ $company->status === 'active' ? 'bg-success-bg text-success' : 'bg-surface-soft text-text-muted' }}">
                    {{ $company->status }}
                </span>
            </div>
        </div>

        <div class="my-6 border-t border-border"></div>

        <!-- Details Grid -->
        <dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3 text-sm">
            @foreach(['status' => 'status', 'base_currency_code' => 'currency', 'default_locale' => 'locale', 'timezone' => 'timezone'] as $field => $label)
                <div class="min-w-0">
                    <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">
                        {{ __('platform.'.$label) }}
                    </dt>
                    <dd class="break-words font-semibold text-text-primary" @if($field === 'base_currency_code' || $field === 'timezone') dir="ltr" @endif>
                        {{ $company->$field }}
                    </dd>
                </div>
            @endforeach

            <div class="min-w-0">
                <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">
                    {{ __('platform.ai') }}
                </dt>
                <dd>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $aiEnabled ? 'bg-primary-50 text-primary' : 'bg-[#eef1f6] text-[#58667a]' }}">
                        {{ __('platform.'.($aiEnabled ? 'on' : 'off')) }}
                    </span>
                </dd>
            </div>

            <div class="min-w-0 sm:col-span-2 lg:col-span-1">
                <dt class="text-xs font-bold text-text-muted uppercase tracking-wider mb-1">
                    {{ __('platform.health') }}
                </dt>
                <dd>
                    @include('platform.health', ['checkedAt' => $health?->checked_at, 'domains' => $health?->domains])
                </dd>
                <p class="text-[11px] text-text-muted mt-1.5 leading-relaxed">
                    {{ __('platform.health_help') }}
                </p>
            </div>
        </dl>

        @php
            $platformPermissions = request()->attributes->get('platform_permissions', []);
            $canManageSubscription = in_array('subscription.manage', $platformPermissions, true);
        @endphp

        @if($canManageSubscription)
            <div class="my-6 border-t border-border"></div>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 p-4 rounded-control bg-surface-soft border border-border">
                <div class="flex items-center gap-3 min-w-0">
                    <div class="w-10 h-10 rounded-card bg-primary-50 text-primary grid place-items-center shrink-0 border border-primary/20">
                        <x-icon name="receipt" class="w-5 h-5" />
                    </div>
                    <div class="min-w-0">
                        <span class="text-xs font-bold text-text-muted uppercase tracking-wider block leading-none mb-1">{{ __('subscriptions.subscription_details') }}</span>
                        <span class="text-sm font-extrabold text-text-primary block truncate">{{ __('subscriptions.subtitle') }}</span>
                    </div>
                </div>
                <div class="shrink-0">
                    <a href="{{ route('platform.companies.subscription.show', $company->public_id) }}"
                       class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-4 text-xs sm:text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors">
                        <span>{{ __('subscriptions.manage') }}</span>
                        <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180" />
                    </a>
                </div>
            </div>
        @endif
    </div>

    <!-- Company Members -->
    <div class="space-y-3">
        <div>
            <h3 class="text-lg font-bold text-text-primary">{{ __('platform.members') }}</h3>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('platform.member_help') }}</p>
        </div>

        <div class="bg-white border border-border rounded-card shadow-panel overflow-hidden">
            <ul class="divide-y divide-border">
                @forelse($members as $member)
                    <li class="p-4 sm:p-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 hover:bg-surface-soft/40 transition-colors">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-9 h-9 rounded-full bg-slate-100 text-slate-700 font-bold text-xs grid place-items-center shrink-0 border border-slate-200">
                                <x-icon name="users" class="w-4 h-4 text-text-muted" />
                            </div>
                            <div class="min-w-0">
                                <span class="font-bold text-sm text-text-primary block truncate">{{ $member->name }}</span>
                                <span class="text-xs text-text-muted block mt-0.5" dir="auto">{{ $member->role_names ?: '—' }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $member->is_owner ? 'bg-primary-50 text-primary' : 'bg-surface-soft text-text-secondary' }}">
                                {{ __('platform.'.($member->is_owner ? 'owner' : 'member')) }}
                            </span>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $member->status === 'active' ? 'bg-success-bg text-success' : 'bg-surface-soft text-text-muted' }}">
                                {{ $member->status }}
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
            {{ $members->links('platform.pagination') }}
        </div>
    </div>
</div>
@endsection
