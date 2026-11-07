@if($checkedAt === null)
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-surface-soft text-text-secondary border border-border">{{ __('platform.unknown') }}</span>
@else
    @php
        $snapshotDomains = is_string($domains) ? json_decode($domains, true) : (is_array($domains) ? $domains : null);
        $requiredDomains = ['accounting','inventory','sales','money','payables','phase7'];
        $validSnapshot = is_array($snapshotDomains) && count($snapshotDomains) === 6 && array_diff($requiredDomains, array_keys($snapshotDomains)) === [] && collect($snapshotDomains)->every(fn ($value) => is_bool($value));
        $isFuture = !$validSnapshot || \Illuminate\Support\Carbon::parse($checkedAt, 'UTC')->gt(now());
        $isStale = \Illuminate\Support\Carbon::parse($checkedAt, 'UTC')->lt(now()->subDay());
        $needsAttention = in_array(false, $snapshotDomains ?? [], true);

        $statusKey = $isFuture ? 'unknown' : ($isStale ? 'stale' : ($needsAttention ? 'attention' : 'checked_healthy'));
        $badgeClass = match($statusKey) {
            'checked_healthy' => 'bg-success-bg text-success border border-success/20',
            'attention', 'stale' => 'bg-warning-bg text-warning border border-warning/20',
            default => 'bg-surface-soft text-text-secondary border border-border',
        };
    @endphp
    <div class="inline-flex flex-col items-start gap-1">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badgeClass }}">
            @if($statusKey === 'checked_healthy')
                <span class="w-1.5 h-1.5 rounded-full bg-success shrink-0" aria-hidden="true"></span>
            @elseif($statusKey === 'attention' || $statusKey === 'stale')
                <span class="w-1.5 h-1.5 rounded-full bg-warning shrink-0" aria-hidden="true"></span>
            @endif
            <span>{{ __('platform.'.$statusKey) }}</span>
        </span>
        <time class="block text-xs font-mono text-text-muted" dir="ltr">{{ $checkedAt }} UTC</time>
    </div>
@endif
