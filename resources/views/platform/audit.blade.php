@extends('layouts.platform')

@section('content')
<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-extrabold text-text-primary">{{ __('platform.audit') }}</h1>
            <p class="text-xs sm:text-sm text-text-muted mt-0.5">{{ __('platform.permission_help') }}</p>
        </div>
    </div>

    <!-- Desktop Audit Log Table -->
    <div class="hidden lg:block bg-white border border-border rounded-card shadow-panel overflow-hidden">
        <table class="w-full text-start">
            <caption class="sr-only">{{ __('platform.audit') }}</caption>
            <thead class="bg-surface-soft border-b border-border">
                <tr>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('platform.event') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('platform.actor') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('platform.target') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-bold text-text-muted uppercase tracking-wider">{{ __('platform.time') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                @forelse($events as $event)
                    <tr class="hover:bg-surface-soft/40 transition-colors">
                        <td class="px-5 py-3.5">
                            @php
                                $isDenied = str_contains($event->event_key, 'denied');
                                $isSuccess = str_contains($event->event_key, 'provisioned');
                                $badgeClass = $isDenied ? 'bg-danger-bg text-danger' : ($isSuccess ? 'bg-success-bg text-success' : 'bg-primary-50 text-primary');
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-bold {{ $badgeClass }}" dir="ltr">
                                {{ $event->event_key }}
                            </span>
                        </td>
                        <td class="px-5 py-3.5 text-sm text-text-secondary">
                            @if($event->actor_user_id)
                                <span class="font-mono text-xs bg-surface-soft px-2 py-1 rounded border border-border" dir="ltr">#{{ $event->actor_user_id }}</span>
                            @else
                                <span class="text-text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-sm text-text-secondary">
                            @if($event->company_id)
                                <span class="font-mono text-xs bg-surface-soft px-2 py-1 rounded border border-border" dir="ltr">#{{ $event->company_id }}</span>
                            @else
                                <span class="text-text-muted">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-xs font-mono text-text-muted" dir="ltr">
                            {{ $event->created_at }} UTC
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-8 text-center text-sm text-text-muted">
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
                $badgeClass = $isDenied ? 'bg-danger-bg text-danger' : ($isSuccess ? 'bg-success-bg text-success' : 'bg-primary-50 text-primary');
            @endphp
            <article class="bg-white border border-border rounded-card shadow-panel p-4 space-y-2.5">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-bold {{ $badgeClass }}" dir="ltr">
                        {{ $event->event_key }}
                    </span>
                    <time class="text-[11px] font-mono text-text-muted shrink-0" dir="ltr">{{ $event->created_at }} UTC</time>
                </div>
                <div class="text-xs text-text-secondary flex flex-wrap gap-x-4 gap-y-1 pt-1 border-t border-border/60">
                    <div>
                        <span class="text-text-muted">{{ __('platform.actor') }}:</span>
                        <span class="font-mono font-semibold" dir="ltr">{{ $event->actor_user_id ? '#'.$event->actor_user_id : '—' }}</span>
                    </div>
                    <div>
                        <span class="text-text-muted">{{ __('platform.target') }}:</span>
                        <span class="font-mono font-semibold" dir="ltr">{{ $event->company_id ? '#'.$event->company_id : '—' }}</span>
                    </div>
                </div>
            </article>
        @empty
            <div class="bg-white border border-border rounded-card shadow-panel p-6 text-center text-sm text-text-muted">
                {{ __('platform.audit_empty') }}
            </div>
        @endforelse
    </div>

    <!-- Pagination -->
    <div class="mt-6">
        {{ $events->links('platform.pagination') }}
    </div>
</div>
@endsection
