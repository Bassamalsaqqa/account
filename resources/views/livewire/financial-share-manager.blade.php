<section class="rounded-card border border-border bg-white p-4 sm:p-5 space-y-4 shadow-xs" x-data="financialShareControls($wire)">
    <div class="flex items-center justify-between pb-3 border-b border-border">
        <div class="flex items-center gap-2">
            <x-icon name="globe" class="w-4.5 h-4.5 text-primary shrink-0" />
            <h2 class="text-sm font-bold text-text-primary">{{ __('sharing.manage') }}</h2>
        </div>
        @if($preview)
            <div class="text-xs text-text-muted flex items-center gap-2">
                <span class="font-bold text-text-primary">{{ __('documents.'.$preview->type) }} {{ $preview->document['number'] ?? '' }}</span>
                <span>&bull;</span>
                <span class="text-text-secondary">{{ $preview->customer['name'] }}</span>
                @if(isset($preview->document['grand_total']))
                    <span>&bull;</span>
                    <bdi dir="ltr" class="tabular-nums font-bold text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($preview->document['grand_total'], $preview->document['currency_code']) }} {{ $preview->document['currency_code'] }}</bdi>
                @endif
            </div>
        @endif
    </div>

    @if(! $preview && $previewError)
        <p role="alert" aria-live="assertive" class="text-xs text-danger">{{ $previewError }}</p>
    @endif

    {{-- Statement Multi-currency totals if present --}}
    @if($preview && !empty($preview->statement['currencies']))
        <div class="rounded-control bg-surface-soft p-3 text-xs space-y-1 border border-border/50">
            @foreach($preview->statement['currencies'] as $currency => $group)
                <p><bdi dir="ltr" class="tabular-nums font-semibold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($group['closing_balance'], $currency) }} {{ $currency }}</bdi> — {{ __('documents.closing_balance') }}</p>
            @endforeach
        </div>
    @endif

    {{-- Quick Action / Issuance Form --}}
    @if($subjectType === \App\Models\PublicShare::SUBJECT_CUSTOMER_STATEMENT)
        {{-- Statement Sharing requires password and optional dates --}}
        <form wire:submit="create" class="grid gap-3 sm:grid-cols-2 bg-surface-soft/40 p-3.5 rounded-control border border-border/60">
            <div class="sm:col-span-2">
                <label for="share-password" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sharing.password') }} <span class="text-danger">*</span>
                </label>
                <input id="share-password" type="password" wire:model="password" autocomplete="new-password" minlength="8" maxlength="128" placeholder="••••••••" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs focus:border-primary focus:ring-1 focus:ring-primary">
                @error('password')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="share-from-date" class="block text-xs font-bold text-text-primary mb-1">{{ __('documents.from_date') }}</label>
                <input id="share-from-date" type="date" wire:model="from" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs focus:border-primary focus:ring-1 focus:ring-primary">
                @error('from')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="share-to-date" class="block text-xs font-bold text-text-primary mb-1">{{ __('documents.to_date') }}</label>
                <input id="share-to-date" type="date" wire:model="to" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs focus:border-primary focus:ring-1 focus:ring-primary">
                @error('to')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="share-locale" class="block text-xs font-bold text-text-primary mb-1">{{ __('sharing.language') }}</label>
                <select id="share-locale" wire:model.live="locale" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs focus:border-primary focus:ring-1 focus:ring-primary">
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
                @error('locale')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="share-expiry-days" class="block text-xs font-bold text-text-primary mb-1">{{ __('sharing.expiry_days') }}</label>
                <input id="share-expiry-days" type="number" min="1" max="30" inputmode="numeric" dir="ltr" wire:model.live="expiryDays" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs font-mono focus:border-primary focus:ring-1 focus:ring-primary">
                @error('expiryDays')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            @foreach($errors->all() as $error)<p role="alert" aria-live="assertive" class="sm:col-span-2 text-xs text-danger">{{ $error }}</p>@endforeach
            <div class="sm:col-span-2 flex items-center gap-3 pt-1">
                <button type="submit" wire:loading.attr="disabled" class="min-h-10 px-5 rounded-control bg-primary hover:bg-primary-hover font-bold text-white text-xs shadow-xs transition disabled:opacity-50 cursor-pointer">
                    {{ __('sharing.create') }}
                </button>
                <span role="status" aria-live="polite" wire:loading wire:target="create" class="text-xs text-text-secondary font-medium flex items-center gap-1.5">
                    <span class="inline-block w-3.5 h-3.5 border-2 border-primary/30 border-t-primary rounded-full animate-spin"></span>
                    <span>{{ __('sharing.loading') }}</span>
                </span>
            </div>
        </form>
    @else
        {{-- Standard Documents: One-click Quick Action --}}
        @if(! $url)
            <div class="flex flex-wrap items-center justify-between gap-3 p-3.5 rounded-control bg-surface-soft border border-border">
                <div class="space-y-0.5">
                    <div class="text-xs font-bold text-text-primary">{{ __('sharing.create') }} {{ __('sharing.link') }}</div>
                    <div class="text-[11px] text-text-muted flex items-center gap-2">
                        <span>{{ __('sharing.expiry_days') }}: <bdi dir="ltr" class="font-mono font-bold">{{ $expiryDays }}</bdi> {{ __('dashboard.days') }}</span>
                        <span>&bull;</span>
                        <span>{{ $locale === 'en' ? 'English' : 'العربية' }}</span>
                    </div>
                </div>
                <button type="button" wire:click="create" wire:loading.attr="disabled"
                        class="min-h-10 px-4 rounded-control bg-primary hover:bg-primary-hover font-bold text-white text-xs shadow-xs transition flex items-center gap-2 cursor-pointer disabled:opacity-50">
                    <x-icon name="plus" class="w-4 h-4" wire:loading.remove wire:target="create" />
                    <span wire:loading wire:target="create" class="inline-block w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                    <span>{{ __('sharing.create') }}</span>
                </button>
            </div>
        @endif
    @endif

    {{-- Issued Active Link Container --}}
    @if($url)
        <div class="space-y-3 p-3.5 rounded-control bg-emerald-50/50 border border-emerald-200/60">
            <div>
                <label for="share-link-input" class="block text-xs font-bold text-text-primary mb-1.5">{{ __('sharing.link') }}</label>
                <div class="flex gap-2">
                    <input id="share-link-input" x-ref="link" readonly value="{{ $url }}" dir="ltr"
                           class="min-h-11 flex-1 rounded-control border border-border px-3 text-xs sm:text-sm bg-white font-mono select-all">
                    <button type="button" @click="await copy($wire.url); if (feedback === 'copy-failed') { $refs.link.focus(); $refs.link.select(); }" :disabled="busy"
                            class="min-h-11 px-4 rounded-control bg-primary text-white hover:bg-primary-hover font-bold text-xs transition cursor-pointer disabled:opacity-50 flex items-center gap-1.5 shrink-0">
                        <x-icon name="copy" class="w-4 h-4" />
                        <span>{{ __('sharing.copy') }}</span>
                    </button>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 pt-1">
                <button type="button" wire:click="showQr" aria-label="{{ __('sharing.qr_alt') }}"
                        class="min-h-9 rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs font-semibold text-text-secondary transition cursor-pointer">
                    QR
                </button>
                <a href="https://wa.me/?text={{ rawurlencode($shareMessage) }}" target="_blank" rel="noopener noreferrer"
                   class="inline-flex min-h-9 items-center justify-center rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs font-semibold text-text-secondary transition">
                    WhatsApp
                </a>
                <a href="mailto:?body={{ rawurlencode($shareMessage) }}"
                   class="inline-flex min-h-9 items-center justify-center rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs font-semibold text-text-secondary transition">
                    {{ __('sharing.email') }}
                </a>
                <button type="button" x-show="typeof navigator.share === 'function'" x-cloak
                        @click="share({title:@js(__('sharing.neutral_title')), text:@js($shareText), url:$wire.url})" :disabled="busy"
                        class="min-h-9 rounded-control border border-border bg-white hover:bg-slate-50 px-3 text-xs font-semibold text-text-secondary transition cursor-pointer">
                    {{ __('sharing.native_share') }}
                </button>
            </div>
            <p x-show="feedback === 'copied'" x-cloak role="status" aria-live="polite" class="text-xs text-emerald-700 font-bold">{{ __('sharing.copied') }}</p>
            <p x-show="feedback === 'shared'" x-cloak role="status" aria-live="polite" class="text-xs text-emerald-700 font-bold">{{ __('sharing.shared') }}</p>
            <p x-show="feedback === 'copy-failed'" x-cloak role="alert" class="text-xs text-danger font-bold">{{ __('sharing.copy_failed') }}</p>
            <p x-show="feedback === 'share-failed'" x-cloak role="alert" class="text-xs text-danger font-bold">{{ __('sharing.share_failed') }}</p>
            @if($qr)
                <div class="pt-2">
                    <img src="{{ $qr }}" alt="{{ __('sharing.qr_alt') }}" width="180" height="180" class="rounded-lg border border-border bg-white p-2">
                </div>
            @endif
        </div>
    @endif

    {{-- Advanced Options & Sharing History (Collapsible) --}}
    <details class="text-xs border-t border-border pt-2 text-text-secondary" @toggle="if ($el.open && !$wire.historyLoaded) { $wire.loadHistory(); }">
        <summary wire:click="loadHistory" class="cursor-pointer font-bold py-1 hover:text-text-primary select-none flex items-center justify-between">
            <span>{{ __('settings.title') }} &bull; {{ __('sharing.history') }}</span>
            <span class="text-[11px] font-normal text-text-muted">
                @if($historyLoaded)
                    ({{ $shares->count() }} {{ __('sharing.issued') }})
                @else
                    ({{ __('sharing.show_history') }})
                @endif
            </span>
        </summary>
        <div class="space-y-4 pt-3">
            @if($subjectType !== \App\Models\PublicShare::SUBJECT_CUSTOMER_STATEMENT)
                <form wire:submit="create" class="grid gap-3 sm:grid-cols-2 p-3 rounded-control bg-surface-soft border border-border">
                    <div>
                        <label for="share-locale" class="block text-xs font-bold text-text-primary mb-1">{{ __('sharing.language') }}</label>
                        <select id="share-locale" wire:model.live="locale" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs">
                            <option value="ar">العربية</option>
                            <option value="en">English</option>
                        </select>
                        @error('locale')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="share-expiry-days" class="block text-xs font-bold text-text-primary mb-1">{{ __('sharing.expiry_days') }}</label>
                        <input id="share-expiry-days" type="number" min="1" max="365" inputmode="numeric" dir="ltr" wire:model.live="expiryDays" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs font-mono">
                        @error('expiryDays')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="share-password" class="block text-xs font-bold text-text-primary mb-1">{{ __('sharing.password') }} ({{ __('app.optional') }})</label>
                        <input id="share-password" type="password" wire:model="password" autocomplete="new-password" minlength="8" maxlength="128" placeholder="••••••••" class="min-h-10 w-full rounded-control border border-border px-3 py-1.5 bg-white text-xs">
                        @error('password')<p role="alert" aria-live="assertive" class="text-xs text-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    @foreach($errors->all() as $error)<p role="alert" aria-live="assertive" class="sm:col-span-2 text-xs text-danger">{{ $error }}</p>@endforeach
                    <div class="sm:col-span-2 flex items-center gap-3 pt-1">
                        <button type="submit" wire:loading.attr="disabled" class="min-h-9 px-4 rounded-control bg-white hover:bg-slate-50 border border-border font-bold text-text-primary text-xs transition disabled:opacity-50 cursor-pointer">
                            {{ __('sharing.create') }}
                        </button>
                    </div>
                </form>
            @endif

            {{-- Previous Shares List --}}
            <ul class="divide-y divide-border">
                @php
                    $companyTz = app(\App\Support\Tenancy\CompanyContext::class)->company()->timezone;
                @endphp
                @forelse($shares as $share)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-xs" wire:key="share-{{ $share->public_id }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-text-primary">{{ $share->access_profile ? __('sharing.issued') : __('sharing.legacy') }}</span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $share->is_active && ! $share->isExpired() ? 'bg-success-bg text-success border border-success/20' : 'bg-slate-100 text-slate-600 border border-slate-200' }}">
                                {{ ! $share->is_active ? __('sharing.revoked') : ($share->isExpired() ? __('sharing.expired') : (($available[$share->public_id] ?? false) ? __('sharing.active') : __('sharing.unavailable'))) }}
                            </span>
                            <span dir="ltr" class="text-text-muted font-mono">
                                @if($share->expires_at)
                                    {{ app(\App\Services\Sales\ShareExpiry::class)->displayInstant($share->expires_at, $companyTz) }}
                                @else
                                    {{ __('sharing.no_expiry') }}
                                @endif
                            </span>
                        </div>
                        <div class="flex items-center gap-2">
                            @if($available[$share->public_id] ?? false)
                                <button wire:click="recover('{{ $share->public_id }}')" wire:loading.attr="disabled" aria-label="{{ __('sharing.recover') }} ({{ $share->public_id }})" class="min-h-8 rounded-control border border-border bg-white hover:bg-slate-50 px-2.5 font-semibold text-text-secondary transition disabled:opacity-50 cursor-pointer">
                                    {{ __('sharing.recover') }}
                                </button>
                            @endif
                            @if($share->is_active)
                                <button wire:click="revoke('{{ $share->public_id }}')" wire:confirm="{{ __('sharing.revoke_confirm') }}" wire:loading.attr="disabled" aria-label="{{ __('sharing.revoke') }} ({{ $share->public_id }})" class="min-h-8 rounded-control border border-red-200 bg-red-50 hover:bg-red-100 px-2.5 font-semibold text-danger transition disabled:opacity-50 cursor-pointer">
                                    {{ __('sharing.revoke') }}
                                </button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="py-2 text-text-muted italic">{{ __('sharing.no_shares') ?? 'لا توجد روابط سابقة' }}</li>
                @endforelse
            </ul>
        </div>
    </details>
</section>
