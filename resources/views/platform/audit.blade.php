@extends('layouts.platform')

@section('breadcrumb')
    <span class="text-slate-300">/</span>
    <span class="text-slate-900 font-semibold">{{ __('platform.audit') }}</span>
@endsection

@section('content')
<div class="space-y-6">
    <!-- Page Header -->
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-slate-900">{{ __('platform.audit') }}</h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-0.5">{{ __('platform.permission_help') }}</p>
        </div>
    </div>

    <!-- Desktop Audit Log Table -->
    <div class="hidden lg:block rounded-xl border border-slate-200 bg-white shadow-xs overflow-hidden">
        <table class="data-table w-full text-start">
            <caption class="sr-only">{{ __('platform.audit') }}</caption>
            <thead class="bg-slate-50/75 border-b border-slate-200 text-slate-600">
                <tr>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('platform.event') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('platform.actor') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('platform.target') }}</th>
                    <th scope="col" class="px-5 py-3.5 text-start text-xs font-semibold uppercase tracking-wider text-slate-500">{{ __('platform.time') }}</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($events as $event)
                    <tr class="hover:bg-slate-50/60 transition-colors">
                        <td class="px-5 py-3.5 align-top">
                            @php
                                $isDenied = str_contains($event->event_key, 'denied');
                                $isSuccess = str_contains($event->event_key, 'provisioned');
                                $isImpersonation = str_starts_with($event->event_key, 'support.') || str_starts_with($event->event_key, 'impersonation.');
                                $badgeClass = $isDenied ? 'bg-red-50 text-red-700 border border-red-200' : ($isSuccess ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : ($isImpersonation ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-slate-100 text-slate-700 border border-slate-200'));
                            @endphp
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-bold {{ $badgeClass }}" dir="ltr">
                                {{ $event->event_key }}
                            </span>
                            @include('platform.support-audit')
                        </td>
                        <td class="px-5 py-3.5 align-top text-sm text-slate-600">
                            @if($event->actor_user_id)
                                <span class="font-mono text-xs bg-slate-50 px-2 py-1 rounded border border-slate-200 text-slate-700 font-medium" dir="ltr">#{{ $event->actor_user_id }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 align-top text-sm text-slate-600">
                            @if($event->company_id)
                                <span class="font-mono text-xs bg-slate-50 px-2 py-1 rounded border border-slate-200 text-slate-700 font-medium" dir="ltr">#{{ $event->company_id }}</span>
                            @else
                                <span class="text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 align-top text-xs font-mono text-slate-500 whitespace-nowrap" dir="ltr">
                            {{ $event->created_at }} UTC
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-5 py-12 text-center text-sm text-slate-500">
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
                $badgeClass = $isDenied ? 'bg-red-50 text-red-700 border border-red-200' : ($isSuccess ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : ($isImpersonation ? 'bg-blue-50 text-blue-700 border border-blue-200' : 'bg-slate-100 text-slate-700 border border-slate-200'));
            @endphp
            <article class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs space-y-2.5">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-2">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-mono font-bold {{ $badgeClass }}" dir="ltr">
                        {{ $event->event_key }}
                    </span>
                    <time class="text-[11px] font-mono text-slate-400 shrink-0" dir="ltr">{{ $event->created_at }} UTC</time>
                </div>
                @include('platform.support-audit')
                <div class="text-xs text-slate-600 flex flex-wrap gap-x-4 gap-y-1 pt-2 border-t border-slate-100">
                    <div>
                        <span class="text-slate-400">{{ __('platform.actor') }}:</span>
                        <span class="font-mono font-medium text-slate-700" dir="ltr">{{ $event->actor_user_id ? '#'.$event->actor_user_id : '—' }}</span>
                    </div>
                    <div>
                        <span class="text-slate-400">{{ __('platform.target') }}:</span>
                        <span class="font-mono font-medium text-slate-700" dir="ltr">{{ $event->company_id ? '#'.$event->company_id : '—' }}</span>
                    </div>
                </div>
            </article>
        @empty
            <div class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500 shadow-xs">
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
