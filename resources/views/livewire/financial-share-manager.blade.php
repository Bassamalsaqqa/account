<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-4" x-data="{copied:false}">
    <h2 class="text-lg font-bold">{{ __('sharing.manage') }}</h2>
    <p class="text-sm text-text-secondary">{{ __('sharing.issuer_notice') }}</p>
    <div class="rounded-control bg-surface-soft p-3 text-sm">
        @if($preview)
        <strong>{{ __('documents.'.$preview->type) }} {{ $preview->document['number'] ?? '' }}</strong>
        <p>{{ $preview->customer['name'] }}</p>
        @if(isset($preview->document['grand_total']))<p dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($preview->document['grand_total'], $preview->document['currency_code']) }} {{ $preview->document['currency_code'] }}</p>@endif
        @foreach($preview->statement['currencies'] ?? [] as $currency => $group)<p><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($group['closing_balance'], $currency) }} {{ $currency }}</bdi> — {{ __('documents.closing_balance') }}</p>@endforeach
        <p>{{ __('sharing.preview_scope') }}</p>
        @else
        <p role="alert">{{ __('sharing.invalid_dates') }}</p>
        @endif
    </div>
    <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
        <label class="text-sm">{{ __('sharing.language') }}<select wire:model.live="locale" class="mt-1 min-h-11 w-full rounded-control border border-border"><option value="ar">العربية</option><option value="en">English</option></select></label>
        <label class="text-sm">{{ __('sharing.expiry_days') }}<input type="number" min="1" max="{{ $subjectType === \App\Models\PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 30 : 365 }}" wire:model="expiryDays" class="mt-1 min-h-11 w-full rounded-control border border-border"></label>
        <label class="text-sm">{{ __('sharing.password') }}<input type="password" wire:model="password" autocomplete="new-password" minlength="8" maxlength="128" class="mt-1 min-h-11 w-full rounded-control border border-border"></label>
        @if($subjectType === 'customer_statement')
            <p class="text-sm text-text-secondary">{{ __('sharing.statement_notice') }}</p>
            <label>{{ __('documents.from_date') }}<input type="date" wire:model="from" class="min-h-11 w-full rounded-control border border-border"></label>
            <label>{{ __('documents.to_date') }}<input type="date" wire:model="to" class="min-h-11 w-full rounded-control border border-border"></label>
        @endif
        @foreach($errors->all() as $error)<p role="alert" class="text-sm text-red-700">{{ $error }}</p>@endforeach
        <button type="submit" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-primary px-4 font-bold text-white">{{ __('sharing.create') }}</button>
        <span role="status" wire:loading>{{ __('sharing.loading') }}</span>
    </form>
    @if($url)
        <div class="space-y-2">
            <label class="block text-sm">{{ __('sharing.link') }}<input readonly value="{{ $url }}" dir="ltr" class="min-h-11 w-full rounded-control border border-border px-2"></label>
            <div class="flex flex-wrap gap-2">
                <button type="button" @click="navigator.clipboard.writeText(@js($url)).then(()=>copied=true)" class="min-h-11 rounded-control border px-3">{{ __('sharing.copy') }}</button>
                <button type="button" wire:click="showQr" class="min-h-11 rounded-control border px-3">QR</button>
                <a href="https://wa.me/?text={{ rawurlencode($url) }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 items-center rounded-control border px-3">WhatsApp</a>
                <a href="mailto:?body={{ rawurlencode($url) }}" class="inline-flex min-h-11 items-center rounded-control border px-3">{{ __('sharing.email') }}</a>
                <button type="button" x-show="typeof navigator.share === 'function'" @click="navigator.share({url:@js($url)}).catch(()=>{})" class="min-h-11 rounded-control border px-3">{{ __('sharing.native_share') }}</button>
            </div>
            <p x-show="copied" role="status" class="text-sm text-emerald-700">{{ __('sharing.copied') }}</p>
            @if($qr)<img src="{{ $qr }}" alt="{{ __('sharing.qr_alt') }}" width="200" height="200">@endif
        </div>
    @endif
    <ul class="divide-y divide-border">
        @foreach($shares as $share)
            <li class="flex flex-wrap items-center gap-3 py-3 text-sm" wire:key="share-{{ $share->public_id }}">
                <span>{{ $share->access_profile ? __('sharing.issued') : __('sharing.legacy') }}</span>
                <span>{{ ! $share->is_active ? __('sharing.revoked') : ($share->isExpired() ? __('sharing.expired') : (($available[$share->public_id] ?? false) ? __('sharing.active') : __('sharing.unavailable'))) }}</span>
                <span dir="ltr">{{ $share->expires_at?->format('Y-m-d') ?? __('sharing.no_expiry') }}</span>
                @if($available[$share->public_id] ?? false)<button wire:click="recover('{{ $share->public_id }}')" class="min-h-11 rounded-control border px-3">{{ __('sharing.recover') }}</button>@endif
                @if($share->is_active)<button wire:click="revoke('{{ $share->public_id }}')" wire:confirm="{{ __('sharing.revoke_confirm') }}" class="min-h-11 rounded-control border px-3 text-red-700">{{ __('sharing.revoke') }}</button>@endif
            </li>
        @endforeach
    </ul>
</section>
