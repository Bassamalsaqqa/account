<div class="space-y-6" x-data="{
        saving: false, operationFailed: false,
        run(method, args = [], terminal = false) {
            if (this.saving) return;
            if (terminal &amp;&amp; [...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0)) return;
            if (terminal) this.saving = true;
            this.operationFailed = false;
            window.enqueueDocumentOperation($wire, method, args)
                .catch(() => { this.operationFailed = true; })
                .finally(() => { if (terminal) this.saving = false; });
        }
    }">
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary text-xs text-primary">
                {{ __('purchasing.back') }}
            </a>
            <h1 class="text-2xl font-bold mt-1">
                {{ $mode === 'create' ? __('purchasing.create_purchase_return') : __('purchasing.edit_purchase_return') }}
            </h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary h-11 px-4 rounded-control border border-border text-sm font-semibold hover:bg-surface-soft">
                {{ __('purchasing.cancel') }}
            </a>
            <button type="button" @click="run('saveDraft', [], true)" :disabled="saving" wire:loading.attr="disabled" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary h-11 px-4 rounded-control bg-primary text-white text-sm font-bold disabled:opacity-50">
                {{ __('purchasing.save_draft') }}
            </button>
        </div>
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
                <label for="return_date" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.return_date') }} *
                </label>
                <input type="date" id="return_date" wire:model="return_date" min="{{ $purchaseDate }}" class="w-full h-11 px-3 rounded-control border border-border text-sm" />
                @error('return_date') <p class="text-danger text-xs mt-1">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="reason" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.return_reason') }}
                </label>
                <input type="text" id="reason" wire:model="reason" placeholder="{{ __('purchasing.return_reason') }}" class="w-full h-11 px-3 rounded-control border border-border text-sm" />
                @error('reason') <p class="text-danger text-xs mt-1">{{ $message }}</p> @enderror
            </div>
        </div>
        <div>
            <label for="notes" class="block text-xs font-semibold text-text-secondary mb-1">
                {{ __('purchasing.notes') }}
            </label>
            <textarea id="notes" wire:model="notes" rows="2" class="w-full p-3 rounded-control border border-border text-sm"></textarea>
            @error('notes') <p class="text-danger text-xs mt-1">{{ $message }}</p> @enderror
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
                        <span>{{ __('purchasing.remaining_quantity') }}: <bdi class="font-bold text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['remaining_quantity']) }}</bdi> {{ $line['unit_name'] }}</span>
                    </div>
                </div>

                <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 text-xs bg-surface-soft p-3 rounded-control">
                    <div>
                        <span class="text-text-secondary">{{ __('purchasing.original_quantity') }}</span>
                        <p class="font-semibold mt-0.5"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['purchased_quantity']) }}</bdi> {{ $line['unit_name'] }}</p>
                    </div>
                    <div>
                        <span class="text-text-secondary">{{ __('purchasing.previously_returned') }}</span>
                        <p class="font-semibold mt-0.5"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['prior_returned_quantity']) }}</bdi> {{ $line['unit_name'] }}</p>
                    </div>
                    <div>
                        <span class="text-text-secondary">{{ __('purchasing.remaining_quantity') }}</span>
                        <p class="font-semibold mt-0.5"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['remaining_quantity']) }}</bdi> {{ $line['unit_name'] }}</p>
                    </div>
                    <div class="col-span-2 sm:col-span-3">
                        <label class="text-text-secondary font-semibold">{{ __('purchasing.return_quantity') }}</label>
                        <div class="flex items-center gap-2" dir="ltr">
    <button type="button" @click="run('stepReturnQuantity', [{{ $idx }}, 'down'])" :disabled="saving" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white px-3 font-semibold disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-primary" aria-label="{{ __('refinement.decrease_quantity') }}">-</button>
    <input type="text" inputmode="decimal" wire:model="lines.{{ $idx }}.return_quantity" dir="ltr" aria-label="{{ __('refinement.return_quantity') }}" class="min-h-11 min-w-0 w-full px-2 rounded-control border border-border text-center font-mono tabular-nums" />
    <button type="button" @click="run('stepReturnQuantity', [{{ $idx }}, 'up'])" :disabled="saving" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white px-3 font-semibold disabled:opacity-50 focus-visible:ring-2 focus-visible:ring-primary" aria-label="{{ __('refinement.increase_quantity') }}">+</button>
</div>
<button type="button" @click="run('returnFullRemaining', [{{ $idx }}])" :disabled="saving" wire:loading.attr="disabled" class="min-h-11 w-full mt-2 rounded-control border border-primary px-3 py-2 text-primary font-semibold disabled:opacity-50">{{ __('refinement.return_remaining') }}</button>
                    </div>
                </div>

                @error("lines.{$idx}.return_quantity")
                    <p class="text-danger text-xs">{{ $message }}</p>
                @enderror

                @if($line['track_expiry'] && count($line['allocations']))
                    <div class="mt-3 pt-3 border-t border-border space-y-2">
                        <h4 class="text-xs font-bold text-text-secondary">{{ __('purchasing.original_lots') }}</h4>
                        <p class="text-xs text-text-secondary">{{ __('inventory.return_lot_hint') }}</p>
                        <div class="space-y-2">
                            @foreach($line['allocations'] as $aIdx => $alloc)
                                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 items-center text-xs bg-surface p-2 rounded-control border border-border" wire:key="alloc-{{ $idx }}-{{ $aIdx }}">
                                    <div>
                                        <span class="text-text-secondary block text-[10px]">{{ __('purchasing.lot_number') }}</span>
                                        <bdi class="font-semibold">{{ $alloc['lot_number'] ?? '—' }}</bdi>
                                    </div>
                                    <div>
                                        <span class="text-text-secondary block text-[10px]">{{ __('purchasing.expiry_date') }}</span>
                                        <bdi>{{ $alloc['expiry_date'] ?? __('purchasing.unknown_expiry') }}</bdi>
                                    </div>
                                    <div>
                                        <span class="text-text-secondary block text-[10px]">{{ __('purchasing.remaining_quantity') }}</span>
                                        <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($alloc['remaining_quantity']) }}</bdi> {{ $line['unit_name'] }}
                                    </div>
                                    <div>
                                        <span class="text-text-secondary block text-[10px]">{{ __('purchasing.available_quantity') }}</span>
                                        <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($alloc['available_quantity']) }}</bdi> {{ $line['unit_name'] }}
                                    </div>
                                    <div>
                                        <label for="alloc_qty_{{ $idx }}_{{ $aIdx }}" class="text-text-secondary block text-[10px]">{{ __('purchasing.return_quantity') }}</label>
                                        <input type="text" id="alloc_qty_{{ $idx }}_{{ $aIdx }}" wire:model="lines.{{ $idx }}.allocations.{{ $aIdx }}.quantity" placeholder="0" class="w-full min-h-11 px-2 rounded-control border border-border text-xs text-end" dir="ltr" inputmode="decimal" />
                                        @error("lines.{$idx}.allocations.{$aIdx}.quantity")
                                            <p class="text-danger text-[10px]">{{ $message }}</p>
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

    <p wire:loading role="status" class="text-sm font-semibold text-primary">{{ __('refinement.working') }}</p>
            <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-20 flex flex-wrap justify-end gap-3 rounded-control border border-border bg-white p-3 shadow-sm md:static md:shadow-none">
        <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary h-11 px-4 rounded-control border border-border text-sm font-semibold hover:bg-surface-soft">
            {{ __('purchasing.cancel') }}
        </a>
        <button type="button" @click="run('saveDraft', [], true)" :disabled="saving" wire:loading.attr="disabled" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary h-11 px-4 rounded-control bg-primary text-white text-sm font-bold disabled:opacity-50">
            {{ __('purchasing.save_draft') }}
        </button>
    </div>
</div>
