@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-semibold">{{ __('platform.companies') }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Platform Directory Summary Stat Cards -->
    @if(isset($platformSummary))
        <section aria-label="{{ __('platform.summary_heading') }}">
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
                <div class="rounded-card border border-border bg-surface p-4 sm:p-5 shadow-panel">
                    <p class="text-xs font-medium text-text-muted">{{ __('platform.summary_companies') }}</p>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-text-primary tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($platformSummary['companies'] ?? $companies->total()) }}</p>
                </div>
                <div class="rounded-card border border-border bg-surface p-4 sm:p-5 shadow-panel">
                    <p class="text-xs font-medium text-text-muted">{{ __('platform.summary_active') }}</p>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-success tabular-nums">{{ $platformSummary['active'] ?? 0 }}</p>
                </div>
                <div class="rounded-card border border-border bg-surface p-4 sm:p-5 shadow-panel">
                    <p class="text-xs font-medium text-text-muted">{{ __('platform.summary_inactive') }}</p>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-text-secondary tabular-nums">{{ $platformSummary['inactive'] ?? 0 }}</p>
                </div>
                <div class="rounded-card border border-border bg-surface p-4 sm:p-5 shadow-panel">
                    <p class="text-xs font-medium text-text-muted">{{ __('platform.summary_health_unmeasured') }}</p>
                    <p class="mt-2 text-2xl font-bold tracking-tight text-warning tabular-nums">{{ $platformSummary['health_unmeasured'] ?? 0 }}</p>
                </div>
            </div>
        </section>
    @endif

    <!-- Subscription Billing Calendar Summary (if permitted) -->
    @if($billingSummary !== null)
        <section aria-label="{{ __('subscriptions.summary_title') }}" class="rounded-card border border-border bg-surface p-4 sm:p-5 shadow-panel">
            <h2 class="text-xs font-bold uppercase tracking-wider text-text-muted mb-3">{{ __('subscriptions.summary_title') }}</h2>
            <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach($billingSummary as $state => $count)
                    <div>
                        <dt class="text-xs text-text-muted">{{ __('subscriptions.summary_'.$state) }}</dt>
                        <dd class="mt-1 text-xl font-bold tabular-nums text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($count) }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif

    <!-- Header Actions & Search -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-text-primary">{{ __('platform.companies') }}</h1>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('platform.scope') }}</p>
        </div>

        @if(in_array('company.provision', request()->attributes->get('platform_permissions', []), true))
            <div>
                <a href="{{ route('platform.companies.create') }}"
                   class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover shadow-panel transition-colors">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('platform.create') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Search Form -->
    <form method="GET" class="rounded-card border border-border bg-surface p-3 sm:p-4 shadow-panel flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
        <div class="relative flex-1 min-w-0">
            <label for="platform-search" class="sr-only">{{ __('platform.search') }}</label>
            <div class="absolute inset-y-0 start-0 ps-3.5 flex items-center pointer-events-none text-text-muted">
                <x-icon name="search" class="w-4 h-4" />
            </div>
            <input class="min-h-11 w-full rounded-control border border-border bg-surface-soft/50 ps-10 pe-3.5 text-sm text-text-primary placeholder:text-text-muted focus:bg-surface focus:border-primary focus:ring-primary/20 transition-colors"
                   id="platform-search"
                   name="q"
                   value="{{ $search }}"
                   maxlength="100"
                   placeholder="{{ __('platform.search') }}">
        </div>
        <button type="submit"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control border border-border bg-surface px-5 text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors cursor-pointer shrink-0">
            <span>{{ __('platform.find') }}</span>
        </button>
    </form>

    <!-- Desktop Companies Table -->
    <div class="hidden lg:block rounded-card border border-border bg-surface shadow-panel overflow-hidden">
        <table class="data-table w-full text-start">
            <caption class="sr-only">{{ __('platform.companies') }}</caption>
            <thead class="bg-surface-soft/75 border-b border-border text-text-secondary">
                <tr>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">
                        {{ __('platform.name') }}
                    </th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">
                        {{ __('platform.status') }}
                    </th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">
                        {{ __('platform.ai') }}
                    </th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">
                        {{ __('platform.health') }}
                    </th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">
                        {{ __('platform.currency') }}
                    </th>
                    <th scope="col" class="px-5 py-3.5 text-end text-xs font-semibold uppercase tracking-wider text-text-muted">
                        {{ __('platform.actions') }}
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($companies as $company)
                    <tr class="hover:bg-surface-soft/60 transition-colors">
                        <th scope="row" class="px-5 py-4 text-start font-semibold max-w-xs">
                            <span class="text-sm font-bold text-text-primary block break-words">
                                {{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}
                            </span>
                            <span class="block break-all font-mono text-xs font-normal text-text-muted mt-0.5" dir="ltr">
                                {{ $company->public_id }}
                            </span>
                        </th>
                        <td class="px-5 py-4 text-sm">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $company->status === 'active' ? 'bg-success-bg text-success border border-success/20' : 'bg-surface-soft text-text-secondary border border-border' }}">
                                {{ __('platform.status_'.$company->status) }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-sm">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $company->ai_enabled ? 'bg-surface-blue text-primary border border-primary-100' : 'bg-surface-soft text-text-secondary border border-border' }}">
                                {{ __('platform.'.($company->ai_enabled ? 'on' : 'off')) }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-sm">
                            @include('platform.health', ['checkedAt' => $company->checked_at, 'domains' => $company->domains])
                        </td>
                        <td class="px-5 py-4 text-sm font-mono font-semibold text-text-secondary" dir="ltr">
                            {{ $company->base_currency_code }}
                        </td>
                        <td class="table-actions px-5 py-4 text-sm text-end">
                            <a href="{{ route('platform.companies.show', $company->public_id) }}"
                               class="inline-flex min-h-9 items-center justify-center rounded-control border border-border bg-surface px-3.5 text-xs font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">
                                {{ __('platform.view') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-5 py-12 text-center text-sm text-text-muted">
                            <x-icon name="store" class="w-8 h-8 mx-auto mb-2 text-text-muted" />
                            <p>{{ __('platform.empty') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Mobile Companies Cards -->
    <div class="grid gap-3.5 sm:grid-cols-2 lg:hidden">
        @forelse($companies as $company)
            <article class="rounded-card border border-border bg-surface p-4 sm:p-5 shadow-panel flex flex-col justify-between">
                <div>
                    <h2 class="break-words text-base font-bold text-text-primary">
                        {{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}
                    </h2>
                    <p class="break-all font-mono text-xs text-text-muted mt-0.5" dir="ltr">
                        {{ $company->public_id }}
                    </p>

                    <dl class="my-4 space-y-2 text-xs">
                        <div class="flex items-center justify-between gap-2 py-1 border-b border-border/70">
                            <dt class="text-text-muted">{{ __('platform.status') }}</dt>
                            <dd>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold {{ $company->status === 'active' ? 'bg-success-bg text-success border border-success/20' : 'bg-surface-soft text-text-secondary border border-border' }}">
                                    {{ __('platform.status_'.$company->status) }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 py-1 border-b border-border/70">
                            <dt class="text-text-muted">{{ __('platform.currency') }}</dt>
                            <dd class="font-mono font-bold text-text-primary" dir="ltr">{{ $company->base_currency_code }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 py-1 border-b border-border/70">
                            <dt class="text-text-muted">{{ __('platform.ai') }}</dt>
                            <dd>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-bold {{ $company->ai_enabled ? 'bg-surface-blue text-primary border border-primary-100' : 'bg-surface-soft text-text-secondary border border-border' }}">
                                    {{ __('platform.'.($company->ai_enabled ? 'on' : 'off')) }}
                                </span>
                            </dd>
                        </div>
                        <div class="py-1">
                            <dt class="text-text-muted mb-1">{{ __('platform.health') }}</dt>
                            <dd>
                                @include('platform.health', ['checkedAt' => $company->checked_at, 'domains' => $company->domains])
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="pt-3 border-t border-border/70 mt-2">
                    <a href="{{ route('platform.companies.show', $company->public_id) }}"
                       class="inline-flex min-h-11 w-full items-center justify-center rounded-control border border-border bg-surface px-4 text-xs font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">
                        {{ __('platform.view') }}
                    </a>
                </div>
            </article>
        @empty
            <div class="sm:col-span-2 rounded-card border border-border bg-surface p-8 text-center text-sm text-text-muted shadow-panel">
                <x-icon name="store" class="w-8 h-8 mx-auto mb-2 text-text-muted" />
                <p>{{ __('platform.empty') }}</p>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        <x-table-pagination :paginator="$companies" :livewire="false" />
    </div>
</div>
@endsection
