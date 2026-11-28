<div class="max-w-4xl mx-auto space-y-6 pb-28 md:pb-6" x-data="{
        saving: false, documentBusy: false, operationFailed: false,
        run(method, args = [], terminal = false) {
            if (this.saving || window.isDocumentBlocked($wire)) return;
            if (terminal &amp;&amp; [...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0)) return;
            if (terminal) this.saving = true;
            this.operationFailed = false;
            window.enqueueDocumentOperation($wire, method, args)
                .catch(() => { this.operationFailed = true; })
                .finally(() => { if (terminal) this.saving = false; });
        }
    }" @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy">
    <x-draft-operation-feedback />
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <x-form-attention />
    <header class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ $mode === 'create' ? __('purchasing.create_purchase_return') : __('purchasing.edit_purchase_return') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('purchasing.return_details') }}</p>
        </div>
        <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary rounded-control border border-border-control bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">
            {{ __('purchasing.back') }}
        </a>
    </header>

    @error('save')
        <p role="alert" class="p-3 bg-danger-bg text-danger rounded-control text-sm">{{ $message }}</p>
    @enderror

    @error('lines')
        <p role="alert" class="p-3 bg-danger-bg text-danger rounded-control text-sm">{{ $message }}</p>
    @enderror

    <section class="bg-surface border border-border rounded-card p-4">
        <dl class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.purchase_number') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $purchaseNumber !== '' ? $purchaseNumber : '—' }}</bdi></dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.vendor') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $vendorName }}</bdi></dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.purchase_date') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $purchaseDate }}</bdi></dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.receiving_warehouse') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $warehouseName }}</bdi></dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.currency') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $currencyCode }}</bdi></dd>
            </div>
        </dl>
    </section>

    <section class="bg-surface border border-border rounded-card p-4 space-y-4">
        <h2 class="text-base font-bold">{{ __('purchasing.return_details') }}</h2>
        <div class="grid sm:grid-cols-2 gap-4">
            <div>
                <label for="return_date" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('purchasing.return_date') }} *
                </label>
                <input type="date" id="return_date" wire:model="return_date" min="{{ $purchaseDate }}" class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('return_date') <p role="alert" class="text-danger text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="reason" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('purchasing.return_reason') }}
                </label>
                <input type="text" id="reason" wire:model="reason" placeholder="{{ __('purchasing.return_reason') }}" class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('reason') <p role="alert" class="text-danger text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <div>
            <label for="notes" class="block text-sm font-semibold text-text-primary mb-1.5">
                {{ __('purchasing.notes') }}
            </label>
            <textarea id="notes" wire:model="notes" rows="2" class="w-full p-2.5 rounded-control border border-border-control bg-canvas text-base lg:text-sm focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
            @error('notes') <p role="alert" class="text-danger text-xs mt-1">{{ $message }}</p> @enderror
        </div>
    </section>

    <section class="space-y-4">
        <h2 class="text-base font-bold">{{ __('purchasing.purchase_lines') }}</h2>
        @foreach($lines as $idx => $line)
            <article class="bg-surface border border-border rounded-card p-4 space-y-3" wire:key="return-line-{{ $line['purchase_line_id'] }}">
                <div class="flex flex-wrap justify-between items-start gap-2">
                    <div>
                        <h3 class="font-bold text-sm">{{ $line['item_description'] }}</h3>
                        <p class="text-xs text-text-secondary"><bdi>{{ $line['product_sku'] }}</bdi></p>
                    </div>
                    <div class="text-xs text-text-secondary text-end">
                        <span>{{ __('purchasing.remaining_quantity') }}: <bdi dir="ltr" class="font-mono font-bold tabular-nums text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['remaining_quantity']) }}</bdi> {{ $line['unit_name'] }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-12 gap-3 items-start text-xs bg-surface-soft p-3 rounded-control">
                    <dl class="grid grid-cols-3 gap-2 md:col-span-6">
                        <div class="min-w-0">
                            <dt class="text-text-secondary">{{ __('purchasing.original_quantity') }}</dt>
                            <dd class="font-mono tabular-nums font-semibold mt-1 text-text-primary"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['purchased_quantity']) }}</bdi> {{ $line['unit_name'] }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-text-secondary">{{ __('purchasing.previously_returned') }}</dt>
                            <dd class="font-mono tabular-nums font-semibold mt-1 text-text-primary"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['prior_returned_quantity']) }}</bdi> {{ $line['unit_name'] }}</dd>
                        </div>
                        <div class="min-w-0">
                            <dt class="text-text-secondary">{{ __('purchasing.remaining_quantity') }}</dt>
                            <dd class="font-mono tabular-nums font-bold mt-1 text-primary"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['remaining_quantity']) }}</bdi> {{ $line['unit_name'] }}</dd>
                        </div>
                    </dl>
                    <div class="md:col-span-6 space-y-2">
                        <label for="purchase-return-quantity-{{ $idx }}" class="block text-xs font-semibold text-text-secondary">{{ __('purchasing.return_quantity') }}</label>
                        <div class="flex flex-wrap items-start gap-2">
                            <div dir="ltr" class="shrink-0">
                                <x-touch-quantity-stepper
                                    :index="$idx"
                                    field="return_quantity"
                                    :step="$line['quantity_step'] ?? '1'"
                                    :allows-fraction="($line['quantity_step'] ?? '1') !== '1'"
                                    :max="$line['remaining_quantity']"
                                    id="purchase-return-quantity-{{ $idx }}"
                                    :aria-label="__('refinement.return_quantity') . ' ' . ($idx + 1)"
                                />
                            </div>
                            <button type="button" @click="run('returnFullRemaining', [{{ $idx }}])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 w-full sm:w-auto flex-1 rounded-control border border-primary px-3 py-2 text-sm text-primary font-semibold hover:bg-primary-50 transition-colors disabled:opacity-50 cursor-pointer">{{ __('refinement.return_remaining') }}</button>
                        </div>
                    </div>
                </div>

                @error("lines.{$idx}.return_quantity")
                    <p id="purchase-return-quantity-{{ $idx }}-error" role="alert" class="text-danger text-xs mt-1">{{ $message }}</p>
                @enderror

                @if($line['track_expiry'] && count($line['allocations']))
                    <div class="mt-3 pt-3 border-t border-border space-y-2">
                        <h4 class="text-xs font-bold text-text-secondary">{{ __('purchasing.original_lots') }}</h4>
                        <p class="text-xs text-text-secondary">{{ __('inventory.return_lot_hint') }}</p>
                        <div class="space-y-2">
                            @foreach($line['allocations'] as $aIdx => $alloc)
                                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-center text-xs bg-surface p-2 rounded-control border border-border" wire:key="alloc-{{ $idx }}-{{ $aIdx }}">
                                    <div>
                                        <span class="text-text-secondary block text-xs">{{ __('purchasing.lot_number') }}</span>
                                        <bdi class="font-semibold">{{ $alloc['lot_number'] ?? '—' }}</bdi>
                                    </div>
                                    <div>
                                        <span class="text-text-secondary block text-xs">{{ __('purchasing.expiry_date') }}</span>
                                        <bdi>{{ $alloc['expiry_date'] ?? __('purchasing.unknown_expiry') }}</bdi>
                                    </div>
                                    <div>
                                        <span class="text-text-secondary block text-xs">{{ __('purchasing.remaining_quantity') }}</span>
                                        <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($alloc['remaining_quantity']) }}</bdi> {{ $line['unit_name'] }}
                                    </div>
                                    <div>
                                        <span class="text-text-secondary block text-xs">{{ __('purchasing.available_quantity') }}</span>
                                        <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($alloc['available_quantity']) }}</bdi> {{ $line['unit_name'] }}
                                    </div>
                                    <div>
                                        <label for="alloc_qty_{{ $idx }}_{{ $aIdx }}" class="text-text-secondary block text-xs font-semibold mb-1">{{ __('purchasing.return_quantity') }}</label>
                                        <input type="text" id="alloc_qty_{{ $idx }}_{{ $aIdx }}" wire:model="lines.{{ $idx }}.allocations.{{ $aIdx }}.quantity" placeholder="0" class="w-full min-h-11 lg:min-h-9.5 px-2 rounded-control border border-border-control bg-canvas text-base lg:text-sm font-mono tabular-nums text-end" dir="ltr" inputmode="decimal"  :disabled="documentBusy || saving"/>
                                        @error("lines.{$idx}.allocations.{$aIdx}.quantity")
                                            <p role="alert" class="text-danger text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif
            </article>
        @endforeach
    </section>

    @if ($purchaseId && $preReturnTotal !== null)
        <div class="rounded-card border border-border bg-surface-soft p-4 space-y-3" data-testid="return-totals-summary">
            <div class="flex items-center justify-between">
                <h3 class="text-xs font-bold uppercase tracking-wider text-text-secondary">
                    {{ __('purchasing.return_summary') }}
                </h3>
                @if ($returnCalculationError)
                    <span role="alert" aria-live="polite" class="text-xs font-semibold text-danger flex items-center gap-1" data-testid="return-totals-error">
                        {{ $returnCalculationError }}
                    </span>
                @endif
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                <div class="bg-surface rounded-control p-3 border border-border">
                    <span class="text-xs text-text-secondary block">{{ __('purchasing.pre_return_total') }}</span>
                    <div class="mt-1 font-mono text-base font-bold tabular-nums text-text-primary" dir="ltr">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($preReturnTotal, $currencyCode) }}
                        <span class="text-xs font-normal text-text-secondary">{{ $currencyCode }}</span>
                    </div>
                </div>
                <div class="bg-surface rounded-control p-3 border border-border">
                    <span class="text-xs text-text-secondary block">{{ __('purchasing.current_return_total') }}</span>
                    <div class="mt-1 font-mono text-base font-bold tabular-nums text-primary" dir="ltr">
                        @if ($returnCalculationError || $returnTotal === null)
                            <span class="text-text-muted text-sm font-normal">—</span>
                        @else
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($returnTotal, $currencyCode) }}
                            <span class="text-xs font-normal text-text-secondary">{{ $currencyCode }}</span>
                        @endif
                    </div>
                    @if (! $returnCalculationError && \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($returnTax))
                        <span class="text-xs text-text-muted block mt-0.5">
                            {{ __('purchasing.tax') }}: <span dir="ltr" class="font-mono tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($returnTax, $currencyCode) }}</span> <span class="text-text-secondary">{{ $currencyCode }}</span>
                        </span>
                    @endif
                </div>
                <div class="bg-surface rounded-control p-3 border border-border">
                    <span class="text-xs text-text-secondary block">{{ __('purchasing.post_return_remaining') }}</span>
                    <div class="mt-1 font-mono text-base font-bold tabular-nums text-text-primary" dir="ltr">
                        @if ($returnCalculationError || $postReturnTotal === null)
                            <span class="text-text-muted text-sm font-normal">—</span>
                        @else
                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($postReturnTotal, $currencyCode) }}
                            <span class="text-xs font-normal text-text-secondary">{{ $currencyCode }}</span>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    @endif

    <p wire:loading role="status" class="text-sm font-semibold text-primary">{{ __('refinement.working') }}</p>
            <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-20 flex flex-wrap items-center justify-end gap-3 rounded-control border border-border bg-surface p-3 shadow-sm md:static md:shadow-none">
        <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center gap-2 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 rounded-control border border-border-control text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors">
            {{ __('purchasing.cancel') }}
        </a>
        <button type="button" @click="run('saveDraft', [], true)" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center gap-2 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 rounded-control border border-primary text-primary hover:bg-primary-50 text-sm font-bold transition-colors disabled:opacity-50 cursor-pointer">
            {{ __('purchasing.save_draft') }}
        </button>
        <button type="button" data-mobile-confirm @click="run('saveAndConfirm', [], true)" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center rounded-control bg-primary px-5 text-sm font-bold text-white hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary transition-colors shadow-sm disabled:opacity-50 cursor-pointer">{{ __('purchasing.post_return') }}</button>
    </div>
</div>
