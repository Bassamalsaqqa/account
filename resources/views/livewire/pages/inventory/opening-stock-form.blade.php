<div class="space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('inventory.overview') }}" class="text-xs text-text-muted hover:text-primary transition-colors">&larr; {{ __('inventory.inventory_overview') }}</a>
            </div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2.5">
                <x-icon name="plus" class="w-6 h-6 text-primary" />
                <span>{{ __('inventory.register_opening_stock') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-1">{{ __('inventory.opening_stock_description') }}</p>
        </div>
    </div>

    <!-- Messages -->
    @if ($successMessage)
        <div class="p-3.5 rounded-control bg-success-bg border border-success/30 text-success text-xs font-semibold flex items-center justify-between">
            <span>{{ $successMessage }}</span>
            <button type="button" wire:click="$set('successMessage', null)" class="text-success hover:opacity-75">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button type="button" wire:click="$set('errorMessage', null)" class="text-danger hover:opacity-75">&times;</button>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <x-card padding="p-6" class="space-y-4 max-w-3xl">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-2.5">{{ __('inventory.opening_stock_details') }}</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <!-- Product Select -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.product') }} *</label>
                    <select wire:model.live="product_id" class="w-full h-9 px-2.5 rounded-control border border-border text-xs bg-white focus:outline-none focus:border-primary @error('product_id') border-danger @enderror">
                        <option value="">— {{ __('inventory.select_product') }} —</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}">{{ app()->getLocale() === 'en' ? ($p->name_en ?: $p->name_ar) : $p->name_ar }} {{ $p->sku ? "({$p->sku})" : '' }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Warehouse Select -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.warehouse') }} *</label>
                    <select wire:model="warehouse_id" class="w-full h-9 px-2.5 rounded-control border border-border text-xs bg-white focus:outline-none focus:border-primary @error('warehouse_id') border-danger @enderror">
                        <option value="">— {{ __('inventory.select_warehouse') }} —</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }} {{ $w->is_default ? ('(' . __('inventory.default') . ')') : '' }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Quantity -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.quantity') }} *</label>
                    <input type="text" wire:model="quantity" dir="ltr" placeholder="0.000000"
                           class="w-full h-9 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('quantity') border-danger @enderror" />
                    @error('quantity') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Unit -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit') }}</label>
                    <select wire:model="unit_id" class="w-full h-9 px-2.5 rounded-control border border-border text-xs bg-white focus:outline-none focus:border-primary">
                        @if ($selectedProduct)
                            @foreach ($availableUnits as $u)
                                <option value="{{ $u->id }}">{{ app()->getLocale() === 'en' ? ($u->name_en ?: $u->name_ar) : $u->name_ar }} ({{ $u->code }})</option>
                            @endforeach
                        @else
                            <option value="">— {{ __('inventory.select_product_first') }} —</option>
                        @endif
                    </select>
                    @error('unit_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Unit Cost -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_cost_base_currency') }} *</label>
                    <input type="text" wire:model="unit_cost_base" dir="ltr" placeholder="0.000000"
                           class="w-full h-9 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('unit_cost_base') border-danger @enderror" />
                    <span class="text-[10px] text-text-muted mt-0.5 block">{{ __('inventory.base_currency') }}: {{ $company->base_currency_code }}</span>
                    @error('unit_cost_base') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Movement Date -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.opening_stock_date') }} *</label>
                    <input type="date" wire:model="movement_date" dir="ltr"
                           class="w-full h-9 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('movement_date') border-danger @enderror" />
                    @error('movement_date') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Lot Number (if expiry tracked) -->
                @if ($selectedProduct && $selectedProduct->track_expiry)
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.lot_number') }}</label>
                        <input type="text" wire:model="lot_number" dir="ltr" placeholder="LOT-001"
                               class="w-full h-9 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('lot_number') border-danger @enderror" />
                        @error('lot_number') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <!-- Expiry Date -->
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.expiry_date') }} *</label>
                        <input type="date" wire:model="expiry_date" dir="ltr"
                               class="w-full h-9 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('expiry_date') border-danger @enderror" />
                        @error('expiry_date') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            <!-- Reason -->
            <div class="pt-2 text-xs">
                <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.reason') }}</label>
                <input type="text" wire:model="reason"
                       class="w-full h-9 px-2.5 rounded-control border border-border text-xs focus:outline-none focus:border-primary" />
                @error('reason') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                <a href="{{ route('inventory.overview') }}"
                   class="px-4 py-2 rounded-control border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold transition-colors">
                    {{ __('common.cancel') }}
                </a>
                <button type="submit"
                        class="px-5 py-2 rounded-control bg-primary text-white hover:bg-primary-hover text-xs font-bold shadow-button transition-colors">
                    {{ __('inventory.save_opening_stock_generate_entry') }}
                </button>
            </div>
        </x-card>
    </form>
</div>
