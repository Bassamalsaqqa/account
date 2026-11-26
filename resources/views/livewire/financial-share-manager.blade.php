@php
    $shareScope = 'share-'.preg_replace('/[^A-Za-z0-9_-]/', '', $this->getId());
    $fieldErrorKeys = ['password', 'from', 'to', 'locale', 'expiryDays'];
    $generalErrors = collect($errors->getMessages())
        ->except($fieldErrorKeys)
        ->flatten()
        ->unique()
        ->values();
@endphp
<section class="rounded-card border border-border bg-surface p-4 sm:p-5 space-y-4 shadow-xs" x-data="financialShareControls($wire)">
    <div class="flex flex-wrap items-center justify-between gap-2 pb-3 border-b border-border">
        <div class="flex items-center gap-2 min-w-0">
            <x-icon name="globe" class="w-4.5 h-4.5 text-primary shrink-0" aria-hidden="true" />
            <h2 class="text-sm font-bold text-text-primary">{{ __('sharing.manage') }}</h2>
        </div>
        @if($preview)
            <div class="min-w-0 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-text-muted">
                <span class="font-bold text-text-primary">{{ __('documents.'.$preview->type) }} {{ $preview->document['number'] ?? '' }}</span>
                <span aria-hidden="true">&bull;</span>
                <span class="text-text-secondary break-words">{{ $preview->customer['name'] }}</span>
                @if(isset($preview->document['grand_total']))
                    <span aria-hidden="true">&bull;</span>
                    <bdi dir="ltr" class="tabular-nums font-bold text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($preview->document['grand_total'], $preview->document['currency_code']) }} {{ $preview->document['currency_code'] }}</bdi>
                @endif
            </div>
        @endif
    </div>

    @if(! $preview && $previewError && ! $generalErrors->contains($previewError))
        <p id="{{ $shareScope }}-preview-error" role="alert" aria-live="assertive" class="rounded-control border border-danger/25 bg-danger-bg px-3 py-2 text-xs font-semibold text-danger">{{ $previewError }}</p>
    @endif

    @if($generalErrors->isNotEmpty())
        <div id="{{ $shareScope }}-general-errors" role="alert" aria-live="assertive" class="rounded-control border border-danger/25 bg-danger-bg px-3 py-2 text-xs font-semibold text-danger space-y-1">
            @foreach($generalErrors as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    {{-- Statement Multi-currency totals if present --}}
    @if($preview && !empty($preview->statement['currencies']))
        <div class="rounded-control bg-surface-soft p-3 text-xs space-y-1 border border-border">
            @foreach($preview->statement['currencies'] as $currency => $group)
                <p><bdi dir="ltr" class="tabular-nums font-semibold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($group['closing_balance'], $currency) }} {{ $currency }}</bdi> — {{ __('documents.closing_balance') }}</p>
            @endforeach
        </div>
    @endif

    {{-- Quick Action / Issuance Form --}}
    @if($subjectType === \App\Models\PublicShare::SUBJECT_CUSTOMER_STATEMENT)
        {{-- Statement Sharing requires password and optional dates --}}
        <form wire:submit="create" class="grid gap-3 sm:grid-cols-2 bg-surface-soft/50 p-3.5 rounded-control border border-border">
            <p class="sm:col-span-2 text-xs text-text-secondary">{{ __('sharing.statement_notice') }}</p>
            <div class="sm:col-span-2 min-w-0">
                <label for="{{ $shareScope }}-password" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sharing.password') }} <span class="text-danger">*</span>
                </label>
                <x-password-input id="{{ $shareScope }}-password"
                                  wire:model="password"
                                  autocomplete="new-password"
                                  minlength="8"
                                  maxlength="128"
                                  placeholder="••••••••"
                                  aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                                  :aria-describedby="$errors->has('password') ? $shareScope.'-password-error' : null" />
                @error('password')<p id="{{ $shareScope }}-password-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="min-w-0">
                <label for="{{ $shareScope }}-from" class="block text-sm font-bold text-text-primary mb-1">{{ __('documents.from_date') }}</label>
                <input id="{{ $shareScope }}-from"
                       type="date"
                       dir="ltr"
                       wire:model="from"
                       aria-invalid="{{ $errors->has('from') ? 'true' : 'false' }}"
                       @error('from') aria-describedby="{{ $shareScope }}-from-error" @enderror
                       class="min-h-11 lg:min-h-9.5 w-full rounded-control border border-border-control px-3 py-1.5 bg-surface text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary aria-invalid:border-danger">
                @error('from')<p id="{{ $shareScope }}-from-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="min-w-0">
                <label for="{{ $shareScope }}-to" class="block text-sm font-bold text-text-primary mb-1">{{ __('documents.to_date') }}</label>
                <input id="{{ $shareScope }}-to"
                       type="date"
                       dir="ltr"
                       wire:model="to"
                       aria-invalid="{{ $errors->has('to') ? 'true' : 'false' }}"
                       @error('to') aria-describedby="{{ $shareScope }}-to-error" @enderror
                       class="min-h-11 lg:min-h-9.5 w-full rounded-control border border-border-control px-3 py-1.5 bg-surface text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary aria-invalid:border-danger">
                @error('to')<p id="{{ $shareScope }}-to-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="min-w-0">
                <label for="{{ $shareScope }}-locale" class="block text-sm font-bold text-text-primary mb-1">{{ __('sharing.language') }}</label>
                <select id="{{ $shareScope }}-locale"
                        wire:model.live="locale"
                        aria-invalid="{{ $errors->has('locale') ? 'true' : 'false' }}"
                        @error('locale') aria-describedby="{{ $shareScope }}-locale-error" @enderror
                        class="min-h-11 lg:min-h-9.5 w-full rounded-control border border-border-control px-3 py-1.5 bg-surface text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary aria-invalid:border-danger">
                    <option value="ar">العربية</option>
                    <option value="en">English</option>
                </select>
                @error('locale')<p id="{{ $shareScope }}-locale-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="min-w-0">
                <label for="{{ $shareScope }}-expiry-days" class="block text-sm font-bold text-text-primary mb-1">{{ __('sharing.expiry_days') }}</label>
                <input id="{{ $shareScope }}-expiry-days"
                       type="number"
                       min="1"
                       max="30"
                       inputmode="numeric"
                       dir="ltr"
                       wire:model.live="expiryDays"
                       aria-invalid="{{ $errors->has('expiryDays') ? 'true' : 'false' }}"
                       @error('expiryDays') aria-describedby="{{ $shareScope }}-expiry-days-error" @enderror
                       class="min-h-11 lg:min-h-9.5 w-full rounded-control border border-border-control px-3 py-1.5 bg-surface text-sm font-mono tabular-nums text-text-primary focus:border-primary focus:ring-1 focus:ring-primary aria-invalid:border-danger">
                @error('expiryDays')<p id="{{ $shareScope }}-expiry-days-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
            </div>
            <div class="sm:col-span-2 flex flex-wrap items-center gap-3 pt-1">
                <button type="submit" wire:loading.attr="disabled" wire:target="create" class="min-h-11 lg:min-h-10 px-5 rounded-control bg-primary hover:bg-primary-hover font-bold text-white text-sm shadow-xs transition disabled:opacity-50 cursor-pointer">
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
                <div class="space-y-0.5 min-w-0">
                    <div class="text-xs font-bold text-text-primary">{{ __('sharing.create') }} {{ __('sharing.link') }}</div>
                    <div class="text-xs text-text-muted flex flex-wrap items-center gap-2">
                        <span>{{ __('sharing.expiry_days') }}: <bdi dir="ltr" class="font-mono font-bold">{{ $expiryDays }}</bdi> {{ __('dashboard.days') }}</span>
                        <span aria-hidden="true">&bull;</span>
                        <span>{{ $locale === 'en' ? 'English' : 'العربية' }}</span>
                    </div>
                </div>
                <button type="button" wire:click="create" wire:loading.attr="disabled" wire:target="create"
                        class="min-h-11 lg:min-h-10 px-4 rounded-control bg-primary hover:bg-primary-hover font-bold text-white text-sm shadow-xs transition flex items-center gap-2 cursor-pointer disabled:opacity-50">
                    <x-icon name="plus" class="w-4 h-4" aria-hidden="true" wire:loading.remove wire:target="create" />
                    <span wire:loading wire:target="create" class="inline-block w-3.5 h-3.5 border-2 border-white/30 border-t-white rounded-full animate-spin"></span>
                    <span>{{ __('sharing.create') }}</span>
                </button>
            </div>
        @endif
    @endif

    {{-- Issued Active Link Container --}}
    @if($url)
        <div class="space-y-3 p-3.5 rounded-control bg-success-bg border border-success/25">
            <div>
                <label for="{{ $shareScope }}-link" class="block text-sm font-bold text-text-primary mb-1.5">{{ __('sharing.link') }}</label>
                <div class="flex flex-col sm:flex-row gap-2">
                    <input id="{{ $shareScope }}-link" x-ref="link" readonly value="{{ $url }}" dir="ltr"
                           class="min-h-11 w-full min-w-0 sm:flex-1 rounded-control border border-border-control px-3 text-sm bg-surface font-mono select-all text-text-primary">
                    <button type="button" @click="await copy($wire.url); if (feedback === 'copy-failed') { $refs.link.focus(); $refs.link.select(); }" :disabled="busy"
                            class="min-h-11 px-4 rounded-control bg-primary text-white hover:bg-primary-hover font-bold text-sm transition cursor-pointer disabled:opacity-50 inline-flex items-center justify-center gap-1.5 shrink-0">
                        <svg class="w-4 h-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <rect x="9" y="9" width="13" height="13" rx="2" />
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1" />
                        </svg>
                        <span>{{ __('sharing.copy') }}</span>
                    </button>
                </div>
            </div>
            <div class="flex flex-wrap items-center gap-2 pt-1">
                <button type="button" wire:click="showQr" aria-label="{{ __('sharing.qr_alt') }}"
                        class="min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-surface hover:bg-surface-soft px-3 text-sm font-semibold text-text-primary transition cursor-pointer">
                    QR
                </button>
                <a href="https://wa.me/?text={{ rawurlencode($shareMessage) }}" target="_blank" rel="noopener noreferrer"
                   class="inline-flex min-h-11 lg:min-h-9.5 items-center justify-center rounded-control border border-border-control bg-surface hover:bg-surface-soft px-3 text-sm font-semibold text-text-primary transition">
                    WhatsApp
                </a>
                <a href="mailto:?body={{ rawurlencode($shareMessage) }}"
                   class="inline-flex min-h-11 lg:min-h-9.5 items-center justify-center rounded-control border border-border-control bg-surface hover:bg-surface-soft px-3 text-sm font-semibold text-text-primary transition">
                    {{ __('sharing.email') }}
                </a>
                <button type="button" x-show="typeof navigator.share === 'function'" x-cloak
                        @click="share({title:@js(__('sharing.neutral_title')), text:@js($shareText), url:$wire.url})" :disabled="busy"
                        class="min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-surface hover:bg-surface-soft px-3 text-sm font-semibold text-text-primary transition cursor-pointer">
                    {{ __('sharing.native_share') }}
                </button>
            </div>
            <p x-show="feedback === 'copied'" x-cloak role="status" aria-live="polite" class="text-xs text-success font-bold">{{ __('sharing.copied') }}</p>
            <p x-show="feedback === 'shared'" x-cloak role="status" aria-live="polite" class="text-xs text-success font-bold">{{ __('sharing.shared') }}</p>
            <p x-show="feedback === 'copy-failed'" x-cloak role="alert" class="text-xs text-danger font-bold">{{ __('sharing.copy_failed') }}</p>
            <p x-show="feedback === 'share-failed'" x-cloak role="alert" class="text-xs text-danger font-bold">{{ __('sharing.share_failed') }}</p>
            @if($qr)
                <div class="pt-2">
                    <img src="{{ $qr }}" alt="{{ __('sharing.qr_alt') }}" width="180" height="180" class="rounded-lg border border-border bg-surface p-2 max-w-full h-auto">
                </div>
            @endif
        </div>
    @endif

    {{-- Advanced Options & Sharing History (Collapsible) --}}
    <details id="{{ $shareScope }}-history"
             class="text-xs border-t border-border pt-2 text-text-secondary"
             @if($historyOpen) open @endif
             @toggle="$wire.historyOpen = $el.open; if ($el.open && !$wire.historyLoaded) { $wire.loadHistory(); }">
        <summary class="min-h-11 lg:min-h-9.5 cursor-pointer font-bold py-1.5 hover:text-text-primary select-none flex flex-wrap items-center justify-between gap-2">
            <span>{{ $subjectType === \App\Models\PublicShare::SUBJECT_CUSTOMER_STATEMENT ? __('sharing.history_list') : __('sharing.history') }}</span>
            <span class="text-xs font-normal text-text-muted">
                @if($historyLoaded)
                    (<bdi dir="ltr" class="tabular-nums">{{ $shares->count() }}</bdi>)
                @else
                    ({{ __('sharing.show_history') }})
                @endif
            </span>
        </summary>
        <div class="space-y-4 pt-3">
            @if($subjectType !== \App\Models\PublicShare::SUBJECT_CUSTOMER_STATEMENT)
                <form wire:submit="create" class="grid gap-3 sm:grid-cols-2 p-3 rounded-control bg-surface-soft border border-border">
                    <div class="min-w-0">
                        <label for="{{ $shareScope }}-locale" class="block text-sm font-bold text-text-primary mb-1">{{ __('sharing.language') }}</label>
                        <select id="{{ $shareScope }}-locale"
                                wire:model.live="locale"
                                aria-invalid="{{ $errors->has('locale') ? 'true' : 'false' }}"
                                @error('locale') aria-describedby="{{ $shareScope }}-locale-error" @enderror
                                class="min-h-11 lg:min-h-9.5 w-full rounded-control border border-border-control px-3 py-1.5 bg-surface text-sm text-text-primary aria-invalid:border-danger">
                            <option value="ar">العربية</option>
                            <option value="en">English</option>
                        </select>
                        @error('locale')<p id="{{ $shareScope }}-locale-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="min-w-0">
                        <label for="{{ $shareScope }}-expiry-days" class="block text-sm font-bold text-text-primary mb-1">{{ __('sharing.expiry_days') }}</label>
                        <input id="{{ $shareScope }}-expiry-days"
                               type="number"
                               min="1"
                               max="365"
                               inputmode="numeric"
                               dir="ltr"
                               wire:model.live="expiryDays"
                               aria-invalid="{{ $errors->has('expiryDays') ? 'true' : 'false' }}"
                               @error('expiryDays') aria-describedby="{{ $shareScope }}-expiry-days-error" @enderror
                               class="min-h-11 lg:min-h-9.5 w-full rounded-control border border-border-control px-3 py-1.5 bg-surface text-sm font-mono tabular-nums text-text-primary aria-invalid:border-danger">
                        @error('expiryDays')<p id="{{ $shareScope }}-expiry-days-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2 min-w-0">
                        <label for="{{ $shareScope }}-password" class="block text-sm font-bold text-text-primary mb-1">{{ __('sharing.password') }} ({{ __('app.optional') }})</label>
                        <x-password-input id="{{ $shareScope }}-password"
                                          wire:model="password"
                                          autocomplete="new-password"
                                          minlength="8"
                                          maxlength="128"
                                          placeholder="••••••••"
                                          aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                                          :aria-describedby="$errors->has('password') ? $shareScope.'-password-error' : null" />
                        @error('password')<p id="{{ $shareScope }}-password-error" role="alert" aria-live="assertive" class="text-xs font-semibold text-danger mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div class="sm:col-span-2 flex items-center gap-3 pt-1">
                        <button type="submit" wire:loading.attr="disabled" wire:target="create" class="min-h-11 lg:min-h-9.5 px-4 rounded-control bg-surface hover:bg-surface-soft border border-border-control font-bold text-text-primary text-sm transition disabled:opacity-50 cursor-pointer">
                            {{ __('sharing.create') }}
                        </button>
                    </div>
                </form>
            @endif

            {{-- Previous Shares List --}}
            <ul class="divide-y divide-border">
                @forelse($shares as $share)
                    <li class="flex flex-wrap items-center justify-between gap-3 py-2.5 text-xs" wire:key="share-{{ $share->public_id }}">
                        <div class="flex flex-wrap items-center gap-2 min-w-0">
                            <span class="font-bold text-text-primary">{{ $share->access_profile ? __('sharing.issued') : __('sharing.legacy') }}</span>
                            <span class="px-2 py-0.5 rounded-full text-xs font-bold {{ $share->is_active && ! $share->isExpired() ? 'bg-success-bg text-success border border-success/25' : 'bg-surface-soft text-text-secondary border border-border' }}">
                                {{ ! $share->is_active ? __('sharing.revoked') : ($share->isExpired() ? __('sharing.expired') : (($available[$share->public_id] ?? false) ? __('sharing.active') : __('sharing.unavailable'))) }}
                            </span>
                            <bdi dir="ltr" class="text-text-muted font-mono tabular-nums break-all">
                                @if($share->expires_at)
                                    {{ app(\App\Services\Sales\ShareExpiry::class)->displayInstant($share->expires_at, $companyTimezone) }}
                                @else
                                    {{ __('sharing.no_expiry') }}
                                @endif
                            </bdi>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 shrink-0">
                            @if($available[$share->public_id] ?? false)
                                <button type="button" wire:click="recover('{{ $share->public_id }}')" wire:loading.attr="disabled" wire:target="recover('{{ $share->public_id }}')" aria-label="{{ __('sharing.recover') }} ({{ $share->public_id }})" class="min-h-11 lg:min-h-9 rounded-control border border-border-control bg-surface hover:bg-surface-soft px-3 font-semibold text-text-primary transition disabled:opacity-50 cursor-pointer">
                                    {{ __('sharing.recover') }}
                                </button>
                            @endif
                            @if($share->is_active)
                                <button type="button" wire:click="revoke('{{ $share->public_id }}')" wire:confirm="{{ __('sharing.revoke_confirm') }}" wire:loading.attr="disabled" wire:target="revoke('{{ $share->public_id }}')" aria-label="{{ __('sharing.revoke') }} ({{ $share->public_id }})" class="min-h-11 lg:min-h-9 rounded-control border border-danger/25 bg-danger-bg hover:bg-danger-bg px-3 font-semibold text-danger transition disabled:opacity-50 cursor-pointer">
                                    {{ __('sharing.revoke') }}
                                </button>
                            @endif
                        </div>
                    </li>
                @empty
                    <li class="py-2 text-text-muted italic">{{ __('sharing.no_shares') }}</li>
                @endforelse
            </ul>
        </div>
    </details>
</section>
