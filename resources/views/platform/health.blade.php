@if($checkedAt === null)
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-[#eef1f6] text-[#58667a]">{{ __('platform.unknown') }}</span>
@else
    @php
        $snapshotDomains = json_decode($domains, true);
        $requiredDomains = ['accounting','inventory','sales','money','payables','phase7'];
        $validSnapshot = is_array($snapshotDomains) && count($snapshotDomains) === 6 && array_diff($requiredDomains, array_keys($snapshotDomains)) === [] && collect($snapshotDomains)->every(fn ($value) => is_bool($value));
        $isFuture = !$validSnapshot || \Illuminate\Support\Carbon::parse($checkedAt, 'UTC')->gt(now());
        $isStale = \Illuminate\Support\Carbon::parse($checkedAt, 'UTC')->lt(now()->subDay());
        $needsAttention = in_array(false, $snapshotDomains ?? [], true);

        $statusKey = $isFuture ? 'unknown' : ($isStale ? 'stale' : ($needsAttention ? 'attention' : 'checked_healthy'));
        $badgeClass = match($statusKey) {
            'checked_healthy' => 'bg-success-bg text-success',
            'attention', 'stale' => 'bg-warning-bg text-warning',
            default => 'bg-[#eef1f6] text-[#58667a]',
        };
    @endphp
    <div class="inline-flex flex-col items-start gap-1">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-bold {{ $badgeClass }}">
            @if($statusKey === 'checked_healthy')
                <span class="w-1.5 h-1.5 rounded-full bg-success shrink-0" aria-hidden="true"></span>
            @elseif($statusKey === 'attention' || $statusKey === 'stale')
                <span class="w-1.5 h-1.5 rounded-full bg-warning shrink-0" aria-hidden="true"></span>
            @endif
            <span>{{ __('platform.'.$statusKey) }}</span>
        </span>
        <time class="block text-[11px] font-mono text-text-muted" dir="ltr">{{ $checkedAt }} UTC</time>
    </div>
@endif
