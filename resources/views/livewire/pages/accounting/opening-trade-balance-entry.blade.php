<div class="max-w-2xl mx-auto px-4 py-4 sm:px-6 space-y-4">
    {{-- Header --}}
    <div class="flex items-center justify-between border-b border-border pb-3">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-text-primary">
                {{ __('accounting.opening_trade_balances_title') }}
            </h1>
            <p class="text-xs text-text-secondary mt-0.5">
                {{ $side === 'customer' ? __('accounting.opening_trade_customer_subtitle') : __('accounting.opening_trade_vendor_subtitle') }}
            </p>
        </div>

        {{-- Side Selector Pill --}}
        <div class="inline-flex rounded-control p-1 bg-surface-soft border border-border"
             role="tablist"
             aria-label="{{ __('accounting.opening_trade_balances_title') }}"
             @keydown="window.handleTablistKeydown?.($event, $el)">
            <button
                type="button"
                role="tab"
                id="tab-opening-customer"
                aria-controls="panel-opening"
                aria-selected="{{ $side === 'customer' ? 'true' : 'false' }}"
                tabindex="{{ $side === 'customer' ? '0' : '-1' }}"
                wire:click="$set('side', 'customer')"
                class="min-h-11 lg:min-h-9 px-3 py-1 text-xs font-bold rounded-control transition-colors cursor-pointer {{ $side === 'customer' ? 'bg-surface text-text-primary shadow-xs' : 'text-text-muted hover:text-text-primary' }}"
            >
                {{ __('accounting.customer_side') }}
            </button>
            <button
                type="button"
                role="tab"
                id="tab-opening-vendor"
                aria-controls="panel-opening"
                aria-selected="{{ $side === 'vendor' ? 'true' : 'false' }}"
                tabindex="{{ $side === 'vendor' ? '0' : '-1' }}"
                wire:click="$set('side', 'vendor')"
                class="min-h-11 lg:min-h-9 px-3 py-1 text-xs font-bold rounded-control transition-colors cursor-pointer {{ $side === 'vendor' ? 'bg-surface text-text-primary shadow-xs' : 'text-text-muted hover:text-text-primary' }}"
            >
                {{ __('accounting.vendor_side') }}
            </button>
        </div>
    </div>

    <div role="tabpanel"
         id="panel-opening"
         aria-labelledby="tab-opening-{{ $side }}"
         tabindex="0"
         class="space-y-4">
    {{-- Overlap Rejection Alert (No explanation banner; concise localized error) --}}
    @if ($glOverlapMessage)
        <div class="p-3 bg-danger-bg/20 border border-danger/30 rounded-control text-sm text-danger flex items-start gap-2.5" role="alert">
            <x-icon name="alert-triangle" class="w-5 h-5 text-danger shrink-0 mt-0.5" />
            <div class="flex-1">
                <span class="sr-only">GL_OVERLAP_REJECTED</span>
                <p class="font-bold">{{ $glOverlapMessage }}</p>
            </div>
        </div>
    @endif

    {{-- Success & Error Messages --}}
    @if ($successMessage)
        <div class="p-3 bg-emerald-50 dark:bg-emerald-950/40 border border-emerald-200 dark:border-emerald-800 rounded-control text-sm text-emerald-800 dark:text-emerald-200 flex items-center gap-2" role="status">
            <x-icon name="check-circle" class="w-4 h-4 text-emerald-600 dark:text-emerald-400 shrink-0" />
            <span class="font-semibold">{{ $successMessage }}</span>
        </div>
    @endif

    @if ($errorMessage)
        <div class="p-3 bg-danger-bg/20 border border-danger/30 rounded-control text-sm text-danger font-semibold" role="alert">
            {{ $errorMessage }}
        </div>
    @endif

    @if ($errors->any())
        <div role="alert" tabindex="-1" data-form-attention class="text-sm text-danger">
            @foreach ($errors->all() as $message)
                <p>{{ $message }}</p>
            @endforeach
        </div>
    @endif

    {{-- Main Form Card --}}
    <form x-data="{ saving: false }" @submit.prevent="if (!saving) { saving = true; if (window.isDocumentBlocked && window.isDocumentBlocked($wire) && window.resyncDocumentQueue) { window.resyncDocumentQueue($wire, true).catch(() => {}); } const op = window.enqueueDocumentOperation ? window.enqueueDocumentOperation($wire, 'save') : $wire.save(); Promise.resolve(op).catch(() => {}).finally(() => { saving = false; }); }" class="bg-surface border border-border rounded-card p-4 sm:p-5 space-y-4 shadow-xs">
        {{-- Party Selection --}}
        <div wire:key="opening-party-{{ $side }}">
            <label for="party_combobox" class="block text-xs font-bold text-text-primary mb-1">
                {{ $side === 'customer' ? __('accounting.customer') : __('accounting.vendor') }} <span class="text-danger">*</span>
            </label>
            <x-searchable-combobox
                id="party_combobox"
                :type="$side === 'customer' ? 'customer' : 'vendor'"
                context="management"
                wireModel="party_id"
                :selectedId="$party_id"
                :selectedLabel="$selectedPartyLabel"
                :placeholder="$side === 'customer' ? __('accounting.search_customer_placeholder') : __('accounting.search_vendor_placeholder')"
                :ariaLabel="$side === 'customer' ? __('accounting.customer') : __('accounting.vendor')"
                required
            />
            <x-input-error :messages="$errors->get('party_id')" class="mt-1" />
        </div>

        {{-- Amount & Currency Row --}}
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            {{-- Amount Input with LTR isolation --}}
            <div class="sm:col-span-2">
                <label for="opening-amount" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('accounting.balance_amount') }} <span class="text-danger">*</span>
                </label>
                <div class="relative rounded-control shadow-xs" dir="ltr">
                    <input
                        type="text"
                        inputmode="decimal"
                        id="opening-amount" aria-invalid="{{ $errors->has('amount') ? 'true' : 'false' }}"
                        wire:model.live.debounce.300ms="amount"
                        placeholder="0.00"
                        class="block w-full min-h-11 rounded-control border border-border-strong bg-surface pl-3 pr-16 py-2 text-base sm:text-sm font-semibold tabular-nums text-text-primary focus:border-primary focus:ring-1 focus:ring-primary text-left"
                        dir="ltr"
                        required
                    />
                    <div class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-3">
                        <span class="text-xs font-bold text-text-muted">{{ $currency_code }}</span>
                    </div>
                </div>
                <x-input-error :messages="$errors->get('amount')" class="mt-1" />
            </div>

            {{-- Currency Selector --}}
            <div>
                <label for="opening-currency" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('accounting.currency') }} <span class="text-danger">*</span>
                </label>
                <select
                    id="opening-currency" aria-invalid="{{ $errors->has('currency_code') ? 'true' : 'false' }}"
                    wire:model.live="currency_code"
                    class="block w-full min-h-11 rounded-control border border-border-strong bg-surface text-base sm:text-sm py-2 px-3 text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"
                >
                    @foreach ($currencies as $curr)
                        <option value="{{ $curr }}">{{ $curr }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('currency_code')" class="mt-1" />
            </div>
        </div>

        {{-- Foreign Currency FX & Converted Base Preview --}}
        @if (! $isBase)
            <div class="p-3 bg-surface-soft border border-border rounded-control space-y-2">
                <div class="flex items-center justify-between gap-2">
                    <label for="opening-exchange-rate" class="text-xs font-bold text-text-secondary">
                        {{ __('accounting.exchange_rate_hint', ['currency' => $company->base_currency_code]) }}
                    </label>
                    @if ($carryingBasePreview)
                        <span class="text-xs font-bold tabular-nums text-text-primary" dir="ltr">
                            ≈ {{ $carryingBasePreview }} {{ $company->base_currency_code }}
                        </span>
                    @endif
                </div>
                <div class="w-full sm:w-48" dir="ltr">
                    <input
                        type="text"
                        inputmode="decimal"
                        id="opening-exchange-rate" aria-invalid="{{ $errors->has('exchange_rate') ? 'true' : 'false' }}"
                        wire:model.live.debounce.300ms="exchange_rate"
                        placeholder="1.0000000000"
                        class="block w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-strong bg-surface px-2.5 py-1.5 text-base sm:text-xs font-mono tabular-nums text-text-primary focus:border-primary focus:ring-1 focus:ring-primary text-left"
                    />
                </div>
                <x-input-error :messages="$errors->get('exchange_rate')" class="mt-0.5" />
            </div>
        @endif

        {{-- Collapsed Details Accordion --}}
        <details class="group border border-border rounded-control text-xs" {{ $original_reference || $due_date || $notes ? 'open' : '' }}>
            <summary class="cursor-pointer select-none px-3 py-2.5 font-bold text-text-secondary hover:text-text-primary flex items-center justify-between">
                <span>{{ __('accounting.additional_details') }}</span>
                <x-icon name="chevron-down" class="w-4 h-4 text-text-muted group-open:rotate-180 transition-transform" />
            </summary>
            <div class="p-3 pt-0 border-t border-border grid grid-cols-1 sm:grid-cols-3 gap-3 mt-2">
                <div>
                    <label for="opening-date" class="block mb-1 font-bold text-text-secondary">{{ __('accounting.opening_date') }}</label>
                    <input
                        type="date"
                        id="opening-date" aria-invalid="{{ $errors->has('opening_date') ? 'true' : 'false' }}"
                        wire:model="opening_date"
                        class="block w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-strong bg-surface text-base sm:text-xs py-1.5 px-2.5 text-text-primary focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                    <x-input-error :messages="$errors->get('opening_date')" class="mt-0.5" />
                </div>
                <div>
                    <label for="opening-reference" class="block mb-1 font-bold text-text-secondary">{{ __('accounting.original_reference') }}</label>
                    <input
                        type="text"
                        id="opening-reference" aria-invalid="{{ $errors->has('original_reference') ? 'true' : 'false' }}"
                        wire:model="original_reference"
                        placeholder="{{ __('accounting.original_reference_placeholder') }}"
                        class="block w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-strong bg-surface text-base sm:text-xs py-1.5 px-2.5 text-text-primary focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                    <x-input-error :messages="$errors->get('original_reference')" class="mt-0.5" />
                </div>
                <div>
                    <label for="opening-due-date" class="block mb-1 font-bold text-text-secondary">{{ __('accounting.due_date') }}</label>
                    <input
                        type="date"
                        id="opening-due-date" aria-invalid="{{ $errors->has('due_date') ? 'true' : 'false' }}"
                        wire:model="due_date"
                        class="block w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-strong bg-surface text-base sm:text-xs py-1.5 px-2.5 text-text-primary focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                    <x-input-error :messages="$errors->get('due_date')" class="mt-0.5" />
                </div>
                <div class="sm:col-span-3">
                    <label for="opening-notes" class="block mb-1 font-bold text-text-secondary">{{ __('accounting.notes') }}</label>
                    <input
                        type="text"
                        id="opening-notes" aria-invalid="{{ $errors->has('notes') ? 'true' : 'false' }}"
                        wire:model="notes"
                        placeholder="{{ __('accounting.notes_placeholder') }}"
                        class="block w-full min-h-11 lg:min-h-9.5 rounded-control border border-border-strong bg-surface text-base sm:text-xs py-1.5 px-2.5 text-text-primary focus:border-primary focus:ring-1 focus:ring-primary"
                    />
                    <x-input-error :messages="$errors->get('notes')" class="mt-0.5" />
                </div>
            </div>
        </details>

        @foreach ($additional_lines as $index => $line)
            <div class="flex items-end gap-2" wire:key="historical-line-{{ $index }}">
                <div class="flex-1">
                    <x-input-label :for="'historical-amount-'.$index" :value="__('accounting.balance_amount')" />
                    <x-text-input :id="'historical-amount-'.$index" aria-invalid="{{ $errors->has('additional_lines.'.$index.'.amount') ? 'true' : 'false' }}" wire:model.live.debounce.300ms="additional_lines.{{ $index }}.amount" inputmode="decimal" dir="ltr" class="w-full tabular-nums text-base sm:text-sm" />
                    <x-input-error :messages="$errors->get('additional_lines.'.$index.'.amount')" />
                </div>
                <div class="py-2.5 flex items-center gap-1.5 shrink-0" dir="ltr">
                    <span class="text-sm font-bold text-text-muted px-1">{{ $currency_code }}</span>
                    @if (! $isBase && ! empty($exchange_rate) && ! empty($line['amount']))
                        @php
                            $lineBasePreview = null;
                            try {
                                $lAmt = \Brick\Math\BigDecimal::of($line['amount']);
                                $lRate = \Brick\Math\BigDecimal::of($exchange_rate);
                                if ($lAmt->isPositive() && $lRate->isPositive()) {
                                    $lineBasePreview = (string) $lAmt->multipliedBy($lRate)->toScale(6, \Brick\Math\RoundingMode::HALF_UP)->toScale(2, \Brick\Math\RoundingMode::HALF_UP);
                                }
                            } catch (\Throwable) {}
                        @endphp
                        @if ($lineBasePreview)
                            <span class="text-xs font-mono tabular-nums text-text-secondary">
                                ≈ {{ $lineBasePreview }} {{ $company->base_currency_code }}
                            </span>
                        @endif
                    @endif
                </div>
                <x-secondary-button type="button" wire:click="removeAdditionalLine({{ $index }})" class="min-h-11">{{ __('accounting.remove_line') }}</x-secondary-button>
            </div>
        @endforeach
        <button type="button" wire:click="addAdditionalLine" class="min-h-11 text-sm text-primary underline underline-offset-4 focus-visible:outline-2 focus-visible:outline-primary">{{ __('accounting.add_another_line') }}</button>

        <div class="flex items-center justify-end pt-2">
            <x-primary-button
                x-bind:disabled="saving"
                wire:loading.attr="disabled"
                :disabled="$glOverlapMessage !== null"
                class="min-h-11 px-5"
            >
                <span wire:loading.remove>{{ __('accounting.confirm_opening_balance') }}</span>
                <span wire:loading class="flex items-center gap-1.5">
                    <x-icon name="loader" class="animate-spin w-4 h-4" />
                    <span>{{ __('accounting.posting') }}</span>
                </span>
            </x-primary-button>
        </div>
    </form>
    </div>
</div>
