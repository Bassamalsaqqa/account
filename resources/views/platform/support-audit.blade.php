@if(str_starts_with($event->event_key, 'support.') || str_starts_with($event->event_key, 'impersonation.'))
    @php($supportAudit = json_decode($event->metadata ?? '{}', true) ?? [])
    <div class="mt-1.5 max-w-sm break-words text-xs leading-relaxed text-text-muted">
        <span class="text-text-muted">{{ __('platform.owner') }}:</span>
        <span class="font-mono font-medium text-text-secondary" dir="ltr">#{{ $supportAudit['owner_user_id'] ?? '—' }}</span>
        @if(!empty($supportAudit['reason']))
            <p class="text-text-secondary mt-0.5 italic">"{{ $supportAudit['reason'] }}"</p>
        @endif
        @if(!empty($supportAudit['exit_reason']))
            <p class="text-text-muted mt-0.5 font-mono text-xs" dir="ltr">exit: {{ $supportAudit['exit_reason'] }}</p>
        @endif
    </div>
@endif
