<section class="rounded-card border border-border bg-white p-4 sm:p-6 space-y-4" x-data="{copied:false}">
    <h2 class="text-lg font-bold text-text-primary">{{ __('sharing.manage') }}</h2>
    <p class="text-sm text-text-secondary">{{ __('sharing.issuer_notice') }}</p>
    <div class="rounded-control bg-surface-soft p-3 text-sm space-y-1">
        @if($preview)
            <div><strong>{{ __('documents.'.$preview->type) }} {{ $preview->document['number'] ?? '' }}</strong></div>
            <div><span>{{ $preview->customer['name'] }}</span></div>
            @if(isset($preview->document['grand_total']))
                <p><bdi dir="ltr" class="tabular-nums font-semibold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($preview->document['grand_total'], $preview->document['currency_code']) }} {{ $preview->document['currency_code'] }}</bdi></p>
            @endif
            @foreach($preview->statement['currencies'] ?? [] as $currency => $group)
                <p><bdi dir="ltr" class="tabular-nums font-semibold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($group['closing_balance'], $currency) }} {{ $currency }}</bdi> — {{ __('documents.closing_balance') }}</p>
            @endforeach
            <p class="text-xs text-text-secondary mt-1">{{ __('sharing.preview_scope') }}</p>
        @else
            <p role="alert" aria-live="assertive" class="text-red-700">{{ $previewError ?? __('sharing.invalid_dates') }}</p>
        @endif
    </div>
    <form wire:submit="create" class="grid gap-4 sm:grid-cols-2">
        <div>
            <label for="share-locale" class="block text-sm font-medium text-text-primary">{{ __('sharing.language') }}</label>
            <select id="share-locale" wire:model.live="locale" class="mt-1 min-h-11 w-full rounded-control border border-border px-3 py-2 bg-white text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                <option value="ar">العربية</option>
                <option value="en">English</option>
            </select>
            @error('locale')<p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>@enderror
        </div>
        <div>
            <label for="share-expiry-days" class="block text-sm font-medium text-text-primary">{{ __('sharing.expiry_days') }}</label>
            <input id="share-expiry-days" type="number" min="1" max="{{ $subjectType === \App\Models\PublicShare::SUBJECT_CUSTOMER_STATEMENT ? 30 : 365 }}" inputmode="numeric" dir="ltr" wire:model="expiryDays" class="mt-1 min-h-11 w-full rounded-control border border-border px-3 py-2 bg-white text-sm focus:border-primary focus:ring-1 focus:ring-primary">
            @error('expiryDays')<p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>@enderror
        </div>
        <div class="sm:col-span-2">
            <label for="share-password" class="block text-sm font-medium text-text-primary">{{ __('sharing.password') }}</label>
            <input id="share-password" type="password" wire:model="password" autocomplete="new-password" minlength="8" maxlength="128" class="mt-1 min-h-11 w-full rounded-control border border-border px-3 py-2 bg-white text-sm focus:border-primary focus:ring-1 focus:ring-primary">
            @error('password')<p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>@enderror
        </div>
        @if($subjectType === 'customer_statement')
            <p class="sm:col-span-2 text-xs sm:text-sm text-text-secondary">{{ __('sharing.statement_notice') }}</p>
            <div>
                <label for="share-from-date" class="block text-sm font-medium text-text-primary">{{ __('documents.from_date') }}</label>
                <input id="share-from-date" type="date" wire:model="from" class="mt-1 min-h-11 w-full rounded-control border border-border px-3 py-2 bg-white text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                @error('from')<p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="share-to-date" class="block text-sm font-medium text-text-primary">{{ __('documents.to_date') }}</label>
                <input id="share-to-date" type="date" wire:model="to" class="mt-1 min-h-11 w-full rounded-control border border-border px-3 py-2 bg-white text-sm focus:border-primary focus:ring-1 focus:ring-primary">
                @error('to')<p role="alert" aria-live="assertive" class="text-xs text-red-600 mt-1 font-medium">{{ $message }}</p>@enderror
            </div>
        @endif
        @foreach($errors->all() as $error)<p role="alert" aria-live="assertive" class="sm:col-span-2 text-xs sm:text-sm text-red-700 font-medium">{{ $error }}</p>@endforeach
        <div class="sm:col-span-2 flex items-center gap-3 pt-1">
            <button type="submit" wire:loading.attr="disabled" class="min-h-11 px-5 rounded-control bg-primary hover:bg-primary-hover font-bold text-white text-sm shadow-xs transition disabled:opacity-50">
                {{ __('sharing.create') }}
            </button>
            <span role="status" aria-live="polite" wire:loading wire:target="create" class="text-xs text-text-secondary font-medium flex items-center gap-1.5">
                <span class="inline-block w-3.5 h-3.5 border-2 border-primary/30 border-t-primary rounded-full animate-spin"></span>
                <span>{{ __('sharing.loading') }}</span>
            </span>
        </div>
    </form>
    @if($url)
        <div class="space-y-3 pt-2 border-t border-border">
            <div>
                <label for="share-link-input" class="block text-sm font-medium text-text-primary mb-1">{{ __('sharing.link') }}</label>
                <input id="share-link-input" readonly value="{{ $url }}" dir="ltr" class="min-h-11 w-full rounded-control border border-border px-3 text-xs sm:text-sm bg-slate-50 font-mono">
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" @click="navigator.clipboard.writeText(@js($url)).then(()=>copied=true)" class="min-h-11 min-w-11 rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs sm:text-sm font-medium transition">
                    {{ __('sharing.copy') }}
                </button>
                <button type="button" wire:click="showQr" aria-label="{{ __('sharing.qr_alt') }}" class="min-h-11 min-w-11 rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs sm:text-sm font-medium transition">
                    QR
                </button>
                <a href="https://wa.me/?text={{ rawurlencode($url) }}" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs sm:text-sm font-medium transition">
                    WhatsApp
                </a>
                <a href="mailto:?body={{ rawurlencode($url) }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs sm:text-sm font-medium transition">
                    {{ __('sharing.email') }}
                </a>
                <button type="button" x-show="typeof navigator.share === 'function'" @click="navigator.share({url:@js($url)}).catch(()=>{})" class="min-h-11 min-w-11 rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs sm:text-sm font-medium transition">
                    {{ __('sharing.native_share') }}
                </button>
            </div>
            <p x-show="copied" role="status" aria-live="polite" class="text-xs sm:text-sm text-emerald-700 font-medium">{{ __('sharing.copied') }}</p>
            @if($qr)
                <div class="pt-2">
                    <img src="{{ $qr }}" alt="{{ __('sharing.qr_alt') }}" width="200" height="200" class="rounded border border-border">
                </div>
            @endif
        </div>
    @endif
    <ul class="divide-y divide-border">
        @foreach($shares as $share)
            <li class="flex flex-wrap items-center justify-between gap-3 py-3 text-sm" wire:key="share-{{ $share->public_id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-text-primary">{{ $share->access_profile ? __('sharing.issued') : __('sharing.legacy') }}</span>
                    <span class="text-xs px-2 py-0.5 rounded-full {{ $share->is_active && ! $share->isExpired() ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-600 border border-slate-200' }}">
                        {{ ! $share->is_active ? __('sharing.revoked') : ($share->isExpired() ? __('sharing.expired') : (($available[$share->public_id] ?? false) ? __('sharing.active') : __('sharing.unavailable'))) }}
                    </span>
                    <span dir="ltr" class="text-xs text-text-muted font-mono">{{ $share->expires_at?->format('Y-m-d') ?? __('sharing.no_expiry') }}</span>
                </div>
                <div class="flex items-center gap-2">
                    @if($available[$share->public_id] ?? false)
                        <button wire:click="recover('{{ $share->public_id }}')" wire:loading.attr="disabled" aria-label="{{ __('sharing.recover') }} ({{ $share->public_id }})" class="min-h-11 rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs sm:text-sm font-medium transition disabled:opacity-50">
                            {{ __('sharing.recover') }}
                        </button>
                    @endif
                    @if($share->is_active)
                        <button wire:click="revoke('{{ $share->public_id }}')" wire:confirm="{{ __('sharing.revoke_confirm') }}" wire:loading.attr="disabled" aria-label="{{ __('sharing.revoke') }} ({{ $share->public_id }})" class="min-h-11 rounded-control border border-red-200 bg-red-50 hover:bg-red-100 px-3 text-xs sm:text-sm font-medium text-red-700 transition disabled:opacity-50">
                            {{ __('sharing.revoke') }}
                        </button>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</section>
