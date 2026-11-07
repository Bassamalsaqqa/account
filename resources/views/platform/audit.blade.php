@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-border-strong">/</span>
    <span class="text-text-primary font-semibold">{{ __('platform.audit') }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-text-primary">{{ __('platform.audit') }}</h1>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('platform.permission_help') }}</p>
        </div>
    </div>

    <!-- Desktop Audit Log Table -->
    <div class="hidden lg:block rounded-card border border-border bg-surface shadow-panel overflow-hidden">
        <table class="data-table w-full text-start">
            <caption class="sr-only">{{ __('platform.audit') }}</caption>
            <thead class="bg-surface-soft/75 border-b border-border text-text-secondary">
                <tr>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.event') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.actor') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.target') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-text-muted">{{ __('platform.time') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($events as $event)
                    <tr class="hover:bg-surface-soft/60 transition-colors">
                        <td class="px-5 py-3.5 align-top">
                            @php
                                $isDenied = str_contains($event->event_key, 'denied');
                                $isSuccess = str_contains($event->event_key, 'provisioned');
                                $isImpersonation = str_starts_with($event->event_key, 'support.') || str_starts_with($event->event_key, 'impersonation.');
                                $badgeClass = $isDenied ? 'bg-danger-bg text-danger border border-danger/20' : ($isSuccess ? 'bg-success-bg text-success border border-success/20' : ($isImpersonation ? 'bg-surface-blue text-primary border border-primary-100' : 'bg-surface-soft text-text-secondary border border-border'));
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-bold {{ $badgeClass }}" dir="ltr">
                                {{ $event->event_key }}
                            </span>
                            @include('platform.support-audit')
                        </td>
                        <td class="px-5 py-3.5 align-top text-sm text-text-secondary">
                            @if($event->actor_user_id)
                                <span class="font-mono text-xs bg-surface-soft px-2 py-1 rounded border border-border text-text-secondary font-medium" dir="ltr">#{{ $event->actor_user_id }}</span>
                            @else
                                <span class="text-text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 align-top text-sm text-text-secondary">
                            @if($event->company_id)
                                <span class="font-mono text-xs bg-surface-soft px-2 py-1 rounded border border-border text-text-secondary font-medium" dir="ltr">#{{ $event->company_id }}</span>
                            @else
                                <span class="text-text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 align-top text-xs font-mono text-text-muted whitespace-nowrap" dir="ltr">
                            {{ $event->created_at }} UTC
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-12 text-center text-sm text-text-muted">
                            {{ __('platform.audit_empty') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Mobile Audit Cards -->
    <div class="grid gap-3.5 lg:hidden">
        @forelse($events as $event)
            @php
                $isDenied = str_contains($event->event_key, 'denied');
                $isSuccess = str_contains($event->event_key, 'provisioned');
                $isImpersonation = str_starts_with($event->event_key, 'support.') || str_starts_with($event->event_key, 'impersonation.');
                $badgeClass = $isDenied ? 'bg-danger-bg text-danger border border-danger/20' : ($isSuccess ? 'bg-success-bg text-success border border-success/20' : ($isImpersonation ? 'bg-surface-blue text-primary border border-primary-100' : 'bg-surface-soft text-text-secondary border border-border'));
            @endphp
            <article class="rounded-card border border-border bg-surface p-4 shadow-panel space-y-2.5">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-bold {{ $badgeClass }}" dir="ltr">
                        {{ $event->event_key }}
                    </span>
                    <time class="text-xs font-mono text-text-muted shrink-0" dir="ltr">{{ $event->created_at }} UTC</time>
                </div>
                @include('platform.support-audit')
                <div class="text-xs text-text-secondary flex flex-wrap gap-x-4 gap-y-1 pt-2 border-t border-border/70">
                    <div>
                        <span class="text-text-muted">{{ __('platform.actor') }}:</span>
                        <span class="font-mono font-medium text-text-secondary" dir="ltr">{{ $event->actor_user_id ? '#'.$event->actor_user_id : '—' }}</span>
                    </div>
                    <div>
                        <span class="text-text-muted">{{ __('platform.target') }}:</span>
                        <span class="font-mono font-medium text-text-secondary" dir="ltr">{{ $event->company_id ? '#'.$event->company_id : '—' }}</span>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-card border border-border bg-surface p-8 text-center text-sm text-text-muted shadow-panel">
                {{ __('platform.audit_empty') }}
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        <x-table-pagination :paginator="$events" :livewire="false" />
    </div>
</div>
@endsection
