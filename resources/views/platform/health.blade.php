@if($checkedAt === null)
    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-slate-100 text-slate-600 border border-slate-200">{{ __('platform.unknown') }}</span>
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
            'checked_healthy' => 'bg-emerald-50 text-emerald-700 border border-emerald-200',
            'attention', 'stale' => 'bg-amber-50 text-amber-800 border border-amber-200',
            default => 'bg-slate-100 text-slate-600 border border-slate-200',
        };
    @endphp
    <div class="inline-flex flex-col items-start gap-1">
        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-semibold {{ $badgeClass }}">
            @if($statusKey === 'checked_healthy')
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" aria-hidden="true"></span>
            @elseif($statusKey === 'attention' || $statusKey === 'stale')
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500 shrink-0" aria-hidden="true"></span>
            @endif
            <span>{{ __('platform.'.$statusKey) }}</span>
        </span>
        <time class="block text-[11px] font-mono text-slate-400" dir="ltr">{{ $checkedAt }} UTC</time>
    </div>
@endif
