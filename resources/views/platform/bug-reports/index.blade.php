@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-semibold">{{ __('platform.bug_reports') }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-text-primary">{{ __('platform.bug_reports') }}</h1>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('platform.bug_reports_subtitle') }}</p>
        </div>
    </div>

    <form method="GET" action="{{ route('platform.bug-reports.index') }}" class="rounded-card border border-border bg-surface p-4 shadow-panel grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
        <div class="sm:col-span-2">
            <label for="br-q" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('platform.bug_report_ref') }} / {{ __('platform.bug_report_request_id') }}</label>
            <input id="br-q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="64" placeholder="{{ __('platform.bug_report_search_placeholder') }}"
                   class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
        </div>

        <div>
            <label for="br-status" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('platform.bug_report_status') }}</label>
            <select id="br-status" name="status" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
                <option value="">{{ __('platform.bug_report_filter_all') }}</option>
                @foreach($statuses as $statusOption)
                    <option value="{{ $statusOption }}" @selected(($filters['status'] ?? '') === $statusOption)>{{ __('diagnostics.statuses.'.$statusOption) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="br-category" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('platform.bug_report_category') }}</label>
            <select id="br-category" name="category" class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
                <option value="">{{ __('platform.bug_report_filter_all') }}</option>
                @foreach($categories as $categoryOption)
                    <option value="{{ $categoryOption }}" @selected(($filters['category'] ?? '') === $categoryOption)>{{ __('diagnostics.categories.'.$categoryOption) }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label for="br-release" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('platform.bug_report_release') }}</label>
            <input id="br-release" name="release" value="{{ $filters['release'] ?? '' }}" maxlength="64" placeholder="{{ __('platform.bug_report_release_placeholder') }}"
                   class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
        </div>

        <div>
            <label for="br-company" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('platform.bug_report_company') }}</label>
            <input id="br-company" name="company" value="{{ $filters['company'] ?? '' }}" maxlength="32" placeholder="{{ __('platform.bug_report_company_placeholder') }}"
                   class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
        </div>

        <div>
            <label for="br-from" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('platform.bug_report_from') }}</label>
            <input id="br-from" type="date" name="from" value="{{ $filters['from'] ?? '' }}"
                   class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
        </div>

        <div>
            <label for="br-to" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('platform.bug_report_to') }}</label>
            <input id="br-to" type="date" name="to" value="{{ $filters['to'] ?? '' }}"
                   class="min-h-11 w-full rounded-control border border-border bg-surface px-3 text-sm text-text-primary">
        </div>

        <div class="sm:col-span-2 lg:col-span-4 flex flex-wrap items-center gap-2">
            <button type="submit" class="min-h-11 inline-flex items-center justify-center rounded-control bg-primary px-5 text-sm font-semibold text-white hover:bg-primary-hover cursor-pointer">
                {{ __('platform.bug_report_apply_filters') }}
            </button>
            <a href="{{ route('platform.bug-reports.index') }}" class="min-h-11 inline-flex items-center justify-center rounded-control border border-border bg-surface px-4 text-sm font-semibold text-text-secondary hover:bg-surface-soft">
                {{ __('platform.bug_report_reset_filters') }}
            </a>
        </div>
    </form>

    <div class="hidden lg:block rounded-card border border-border bg-surface shadow-panel overflow-hidden">
        <table class="data-table w-full text-start">
            <caption class="sr-only">{{ __('platform.bug_reports') }}</caption>
            <thead class="bg-surface-soft/75 border-b border-border">
                <tr>
                    <th scope="col" class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.bug_report_ref') }}</th>
                    <th scope="col" class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.bug_report_status') }}</th>
                    <th scope="col" class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.bug_report_category') }}</th>
                    <th scope="col" class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.bug_report_route') }}</th>
                    <th scope="col" class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.bug_report_company') }}</th>
                    <th scope="col" class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.bug_report_release') }}</th>
                    <th scope="col" class="px-4 py-3 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.time') }}</th>
                    <th scope="col" class="px-4 py-3 text-end text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.actions') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($reports as $row)
                    @php
                        $badgeVariant = match ($row->status) {
                            'fixed' => 'success',
                            'triaged' => 'info',
                            'duplicate' => 'warning',
                            'closed' => 'draft',
                            default => 'alert',
                        };
                    @endphp
                    <tr class="hover:bg-surface-soft/60 transition-colors">
                        <td class="px-4 py-3 text-xs font-mono font-bold text-text-primary" dir="ltr">
                            <div>{{ $row->public_ref }}</div>
                            @if($row->failed_request_id || $row->request_id)
                                <div class="text-text-muted font-normal mt-0.5">{{ $row->failed_request_id ?: $row->request_id }}</div>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            <x-badge :variant="$badgeVariant">{{ __('diagnostics.statuses.'.$row->status) }}</x-badge>
                        </td>
                        <td class="px-4 py-3 text-sm text-text-secondary">{{ __('diagnostics.categories.'.$row->category) }}</td>
                        <td class="px-4 py-3 text-xs font-mono text-text-secondary" dir="ltr">{{ $row->route_name ?? '—' }}</td>
                        <td class="px-4 py-3 text-xs text-text-secondary">
                            @if($row->company_public_id)
                                <span class="font-semibold block">{{ app()->getLocale() === 'en' ? ($row->company_name_en ?: $row->company_name_ar) : $row->company_name_ar }}</span>
                                <span class="font-mono text-text-muted" dir="ltr">{{ $row->company_public_id }}</span>
                            @else
                                <span class="text-text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs font-mono text-text-secondary" dir="ltr">
                            {{ $row->release_version ?? '—' }}
                            @if($row->release_source_sha)
                                <span class="block text-text-muted">{{ substr($row->release_source_sha, 0, 8) }}</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-xs font-mono text-text-muted whitespace-nowrap" dir="ltr">{{ $row->created_at }} UTC</td>
                        <td class="px-4 py-3 text-end">
                            <a href="{{ route('platform.bug-reports.show', $row->public_ref) }}"
                               class="inline-flex min-h-9 items-center justify-center rounded-control border border-border bg-surface px-3.5 text-xs font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary">
                                {{ __('platform.view') }}
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-5 py-12 text-center text-sm text-text-muted">{{ __('platform.bug_reports_empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="grid gap-3.5 lg:hidden">
        @forelse($reports as $row)
            @php
                $badgeVariant = match ($row->status) {
                    'fixed' => 'success',
                    'triaged' => 'info',
                    'duplicate' => 'warning',
                    'closed' => 'draft',
                    default => 'alert',
                };
            @endphp
            <article class="rounded-card border border-border bg-surface p-4 shadow-panel space-y-2.5">
                <div class="flex items-start justify-between gap-2">
                    <span class="font-mono text-xs font-bold text-text-primary break-all" dir="ltr">{{ $row->public_ref }}</span>
                    <x-badge :variant="$badgeVariant">{{ __('diagnostics.statuses.'.$row->status) }}</x-badge>
                </div>
                <dl class="text-xs space-y-1.5">
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_category') }}</dt>
                        <dd class="text-text-primary font-semibold">{{ __('diagnostics.categories.'.$row->category) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.bug_report_route') }}</dt>
                        <dd class="font-mono text-text-secondary" dir="ltr">{{ $row->route_name ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-text-muted">{{ __('platform.time') }}</dt>
                        <dd class="font-mono text-text-muted" dir="ltr">{{ $row->created_at }} UTC</dd>
                    </div>
                </dl>
                <a href="{{ route('platform.bug-reports.show', $row->public_ref) }}"
                   class="inline-flex min-h-11 w-full items-center justify-center rounded-control border border-border bg-surface px-4 text-xs font-semibold text-text-secondary hover:bg-surface-soft">
                    {{ __('platform.view') }}
                </a>
            </article>
        @empty
            <div class="rounded-card border border-border bg-surface p-8 text-center text-sm text-text-muted shadow-panel">
                {{ __('platform.bug_reports_empty') }}
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        <x-table-pagination :paginator="$reports" :livewire="false" />
    </div>
</div>
@endsection
