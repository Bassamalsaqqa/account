@extends('layouts.platform')

@section('content')
<div class="space-y-6">
    @if($billingSummary !== null)
        <section aria-label="{{ __('subscriptions.summary_title') }}" class="rounded-card border border-border bg-surface p-4 sm:p-5">
            <h2 class="font-semibold text-text-primary">{{ __('subscriptions.summary_title') }}</h2>
            <dl class="mt-4 grid grid-cols-2 gap-4 lg:grid-cols-4">
                @foreach($billingSummary as $state => $count)
                    <div>
                        <dt class="text-sm text-text-secondary">{{ __('subscriptions.summary_'.$state) }}</dt>
                        <dd class="mt-1 text-xl font-semibold tabular-nums text-text-primary">{{ $count }}</dd>
                    </div>
                @endforeach
            </dl>
        </section>
    @endif
    <!-- Header Actions & Search -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-extrabold text-text-primary">{{ __('platform.companies') }}</h1>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('platform.scope') }}</p>
        </div>

        @if(in_array('company.provision', request()->attributes->get('platform_permissions', []), true))
            <div>
                <a href="{{ route('platform.companies.create') }}"
                   class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control bg-primary px-4 text-sm font-semibold text-white hover:bg-primary-hover shadow-xs transition-colors">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('platform.create') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Search Form -->
    <form method="GET" class="bg-white border border-border rounded-card shadow-panel p-4 flex flex-col sm:flex-row items-stretch sm:items-end gap-3">
        <div class="min-w-0 flex-1">
            <label class="mb-1.5 block text-xs font-bold text-text-secondary uppercase tracking-wider" for="platform-search">
                {{ __('platform.search') }}
            </label>
            <div class="relative">
                <input class="min-h-11 w-full rounded-control border border-border-strong bg-white px-3.5 text-sm text-text-primary focus:border-primary focus:ring-primary/20"
                       id="platform-search"
                       name="q"
                       value="{{ $search }}"
                       maxlength="100"
                       placeholder="{{ __('platform.search') }}">
            </div>
        </div>
        <button type="submit"
                class="inline-flex min-h-11 items-center justify-center gap-2 rounded-control border border-border-strong bg-white px-5 text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors cursor-pointer shrink-0">
            <x-icon name="search" class="w-4 h-4 text-text-muted" />
            <span>{{ __('platform.find') }}</span>
        </button>
    </form>

    <!-- Desktop Companies Table -->
    <div class="hidden lg:block bg-white border border-border rounded-card shadow-panel overflow-hidden">
        <table class="w-full text-start">
            <caption class="sr-only">{{ __('platform.companies') }}</caption>
            <thead class="bg-surface-soft border-b border-border">
                <tr>
                    @foreach(['name', 'status', 'ai', 'health', 'view'] as $label)
                        <th scope="col" class="px-5 py-3.5 text-start text-xs font-bold text-text-muted uppercase tracking-wider">
                            {{ __('platform.'.$label) }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($companies as $company)
                    <tr class="hover:bg-surface-soft/40 transition-colors">
                        <th scope="row" class="px-5 py-4 text-start font-semibold max-w-xs">
                            <span class="text-sm font-extrabold text-text-primary block break-words">
                                {{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}
                            </span>
                            <span class="block break-all font-mono text-xs font-normal text-text-muted mt-0.5" dir="ltr">
                                {{ $company->public_id }}
                            </span>
                        </th>
                        <td class="px-5 py-4 text-sm">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $company->status === 'active' ? 'bg-success-bg text-success' : 'bg-surface-soft text-text-muted' }}">
                                {{ $company->status }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-sm">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold {{ $company->ai_enabled ? 'bg-primary-50 text-primary' : 'bg-[#eef1f6] text-[#58667a]' }}">
                                {{ __('platform.'.($company->ai_enabled ? 'on' : 'off')) }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-sm">
                            @include('platform.health', ['checkedAt' => $company->checked_at, 'domains' => $company->domains])
                        </td>
                        <td class="px-5 py-4 text-sm">
                            <a href="{{ route('platform.companies.show', $company->public_id) }}"
                               class="inline-flex min-h-11 items-center justify-center rounded-control border border-border-strong bg-white px-3.5 text-xs font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">
                                {{ __('platform.view') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-text-muted">
                            <x-icon name="store" class="w-8 h-8 mx-auto mb-2 text-text-muted/60" />
                            <p>{{ __('platform.empty') }}</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Mobile Companies Cards -->
    <div class="grid gap-4 sm:grid-cols-2 lg:hidden">
        @forelse($companies as $company)
            <article class="bg-white border border-border rounded-card shadow-panel p-5 flex flex-col justify-between">
                <div>
                    <h3 class="break-words text-base font-bold text-text-primary">
                        {{ app()->getLocale() === 'en' ? ($company->name_en ?: $company->name_ar) : $company->name_ar }}
                    </h3>
                    <p class="break-all font-mono text-xs text-text-muted mt-0.5" dir="ltr">
                        {{ $company->public_id }}
                    </p>

                    <dl class="my-4 space-y-2.5 text-xs">
                        <div class="flex items-center justify-between gap-2 py-1 border-b border-border/50">
                            <dt class="text-text-muted">{{ __('platform.status') }}</dt>
                            <dd>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold {{ $company->status === 'active' ? 'bg-success-bg text-success' : 'bg-surface-soft text-text-muted' }}">
                                    {{ $company->status }}
                                </span>
                            </dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 py-1 border-b border-border/50">
                            <dt class="text-text-muted">{{ __('platform.currency') }}</dt>
                            <dd class="font-mono font-bold text-text-primary" dir="ltr">{{ $company->base_currency_code }}</dd>
                        </div>
                        <div class="flex items-center justify-between gap-2 py-1 border-b border-border/50">
                            <dt class="text-text-muted">{{ __('platform.ai') }}</dt>
                            <dd>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold {{ $company->ai_enabled ? 'bg-primary-50 text-primary' : 'bg-[#eef1f6] text-[#58667a]' }}">
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

                <div class="pt-3 border-t border-border mt-2">
                    <a href="{{ route('platform.companies.show', $company->public_id) }}"
                       class="inline-flex min-h-11 w-full items-center justify-center rounded-control border border-border-strong bg-white px-4 text-xs font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">
                        {{ __('platform.view') }}
                    </a>
                </div>
            </article>
        @empty
            <div class="sm:col-span-2 bg-white border border-border rounded-card shadow-panel p-8 text-center text-sm text-text-muted">
                <x-icon name="store" class="w-8 h-8 mx-auto mb-2 text-text-muted/60" />
                <p>{{ __('platform.empty') }}</p>
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $companies->links('platform.pagination') }}
    </div>
</div>
@endsection
