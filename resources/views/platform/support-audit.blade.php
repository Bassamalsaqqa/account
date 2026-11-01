@if(str_starts_with($event->event_key, 'support.'))
    @php($supportAudit = json_decode($event->metadata ?? '{}', true) ?? [])
    <p class="mt-2 max-w-sm break-words text-xs leading-relaxed text-text-secondary">
        {{ __('platform.owner') }}: <bdi>{{ $supportAudit['owner_user_id'] ?? '—' }}</bdi>
        @if(isset($supportAudit['reason']))<br>{{ $supportAudit['reason'] }}@endif
        @if(isset($supportAudit['exit_reason']))<br><bdi>{{ $supportAudit['exit_reason'] }}</bdi>@endif
    </p>
@endif
