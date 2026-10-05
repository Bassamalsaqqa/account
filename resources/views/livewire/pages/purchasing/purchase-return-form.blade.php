<div class="space-y-6">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div>
            <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="text-xs text-primary">
                {{ __('purchasing.back') }}
            </a>
            <h1 class="text-2xl font-bold mt-1">
                {{ $mode === 'create' ? __('purchasing.create_purchase_return') : __('purchasing.edit_purchase_return') }}
            </h1>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="inline-flex h-11 items-center justify-center px-4 rounded-control border border-border text-sm font-semibold hover:bg-surface-soft">
                {{ __('purchasing.cancel') }}
            </a>
            <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" class="inline-flex h-11 items-center justify-center px-4 rounded-control bg-primary text-white text-sm font-bold disabled:opacity-50">
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

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs bg-surface-soft p-3 rounded-control">
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
                    <div>
                        <label for="line_qty_{{ $idx }}" class="text-text-secondary font-semibold">{{ __('purchasing.return_quantity') }}</label>
                        <input type="text" id="line_qty_{{ $idx }}" wire:model="lines.{{ $idx }}.return_quantity" placeholder="0" class="w-full h-8 px-2 mt-0.5 rounded-control border border-border text-xs text-end" dir="ltr" />
                    </div>
                </div>

                @error("lines.{$idx}.return_quantity")
                    <p class="text-danger text-xs">{{ $message }}</p>
                @enderror

                @if($line['track_expiry'] && count($line['allocations']))
                    <div class="mt-3 pt-3 border-t border-border space-y-2">
                        <h4 class="text-xs font-bold text-text-secondary">{{ __('purchasing.original_lots') }}</h4>
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
                                        <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($alloc['remaining_quantity']) }}</bdi>
                                    </div>
                                    <div>
                                        <span class="text-text-secondary block text-[10px]">{{ __('purchasing.available_quantity') }}</span>
                                        <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($alloc['available_quantity']) }}</bdi>
                                    </div>
                                    <div>
                                        <label for="alloc_qty_{{ $idx }}_{{ $aIdx }}" class="text-text-secondary block text-[10px]">{{ __('purchasing.return_quantity') }}</label>
                                        <input type="text" id="alloc_qty_{{ $idx }}_{{ $aIdx }}" wire:model="lines.{{ $idx }}.allocations.{{ $aIdx }}.quantity" placeholder="0" class="w-full h-8 px-2 rounded-control border border-border text-xs text-end" dir="ltr" />
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

    <div class="flex justify-end gap-3 pt-4">
        <a href="{{ $mode === 'create' ? route('purchases.show', $purchasePublicId) : route('purchase-returns.show', $returnPublicId) }}" class="inline-flex h-11 items-center justify-center px-4 rounded-control border border-border text-sm font-semibold hover:bg-surface-soft">
            {{ __('purchasing.cancel') }}
        </a>
        <button type="button" wire:click="saveDraft" wire:loading.attr="disabled" class="inline-flex h-11 items-center justify-center px-4 rounded-control bg-primary text-white text-sm font-bold disabled:opacity-50">
            {{ __('purchasing.save_draft') }}
        </button>
    </div>
</div>
