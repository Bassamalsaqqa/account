@if($checkedAt === null)
    <span>{{ __('platform.unknown') }}</span>
@else
    @php
        $snapshotDomains = json_decode($domains, true);
        $requiredDomains = ['accounting','inventory','sales','money','payables','phase7'];
        $validSnapshot = is_array($snapshotDomains) && count($snapshotDomains) === 6 && array_diff($requiredDomains, array_keys($snapshotDomains)) === [] && collect($snapshotDomains)->every(fn ($value) => is_bool($value));
    @endphp
    <span>{{ !$validSnapshot || \Illuminate\Support\Carbon::parse($checkedAt, 'UTC')->gt(now()) ? __('platform.unknown') : (\Illuminate\Support\Carbon::parse($checkedAt, 'UTC')->lt(now()->subDay()) ? __('platform.stale') : (in_array(false, $snapshotDomains, true) ? __('platform.attention') : __('platform.checked_healthy'))) }}</span>
    <time class="block text-xs text-slate-600" dir="ltr">{{ $checkedAt }} UTC</time>
@endif
