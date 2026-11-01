@if(str_starts_with($event->event_key, 'support.') || str_starts_with($event->event_key, 'impersonation.'))
    @php($supportAudit = json_decode($event->metadata ?? '{}', true) ?? [])
    <div class="mt-1.5 max-w-sm break-words text-xs leading-relaxed text-slate-500">
        <span class="text-slate-400">{{ __('platform.owner') }}:</span>
        <span class="font-mono font-medium text-slate-700" dir="ltr">#{{ $supportAudit['owner_user_id'] ?? '—' }}</span>
        @if(!empty($supportAudit['reason']))
            <p class="text-slate-600 mt-0.5 italic">"{{ $supportAudit['reason'] }}"</p>
        @endif
        @if(!empty($supportAudit['exit_reason']))
            <p class="text-slate-400 mt-0.5 font-mono text-[11px]" dir="ltr">exit: {{ $supportAudit['exit_reason'] }}</p>
        @endif
    </div>
@endif
