<div class="space-y-6 max-w-3xl mx-auto">
    <!-- Header -->
    <div class="flex items-center gap-3">
        <a href="{{ route('inventory.overview') }}"
           class="p-2 rounded-control border border-border bg-white text-text-secondary hover:text-text-primary hover:bg-surface-soft transition-colors">
            <x-icon name="arrow-right" class="w-4 h-4 rtl:rotate-0 ltr:rotate-180" />
        </a>
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2">
                <x-icon name="truck" class="w-5 h-5 text-primary" />
                <span>{{ __('inventory.transfer_stock') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-0.5">{{ __('inventory.transfer_subtitle') }}</p>
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

    <x-card padding="p-6">
        <form wire:submit.prevent="save" class="space-y-4 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <!-- Source Warehouse -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.source_warehouse') }} *</label>
                    <select wire:model.live="source_warehouse_id" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
                        <option value="">{{ __('inventory.select_source_warehouse') }}</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }}</option>
                        @endforeach
                    </select>
                    @error('source_warehouse_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Destination Warehouse -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.destination_warehouse') }} *</label>
                    <select wire:model.live="destination_warehouse_id" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
                        <option value="">{{ __('inventory.select_destination_warehouse') }}</option>
                        @foreach ($warehouses as $w)
                            @if ($w->id !== (int) $source_warehouse_id)
                                <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }}</option>
                            @endif
                        @endforeach
                    </select>
                    @error('destination_warehouse_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Product -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.product') }} *</label>
                    <select wire:model.live="product_id" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
                        <option value="">{{ __('inventory.select_product') }}</option>
                        @foreach ($products as $p)
                            <option value="{{ $p->id }}">{{ app()->getLocale() === 'en' ? ($p->name_en ?: $p->name_ar) : $p->name_ar }} {{ $p->sku ? "({$p->sku})" : '' }}</option>
                        @endforeach
                    </select>
                    @error('product_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Movement Date -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.movement_date') }} *</label>
                    <input type="date" wire:model="movement_date" dir="ltr" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                    @error('movement_date') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Quantity -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.quantity_delta') }} *</label>
                    <input type="number" step="any" wire:model="quantity" dir="ltr" placeholder="0" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                    @error('quantity') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Unit -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit') }}</label>
                    <select wire:model="unit_id" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary">
                        @foreach ($availableUnits as $u)
                            <option value="{{ $u->id }}">{{ app()->getLocale() === 'en' ? ($u->name_en ?: $u->name_ar) : $u->name_ar }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Lot Selection for Expiry Tracked -->
                @if ($selectedProduct && $selectedProduct->track_expiry)
                    <div class="md:col-span-2">
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.select_lot_to_transfer') }} *</label>
                        <select wire:model="lot_id" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary">
                            <option value="">{{ __('inventory.select_lot') }}</option>
                            @foreach ($availableLots as $lb)
                                <option value="{{ $lb->lot_id }}">
                                    {{ $lb->lot?->lot_number ?? __('inventory.lot_prefix') . $lb->lot_id }} — {{ __('inventory.expiry_date') }}: {{ $lb->lot?->expiry_date ?? '—' }} ({{ __('inventory.current_stock') }}: {{ $lb->quantity_base }})
                                </option>
                            @endforeach
                        </select>
                        @error('lot_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                @endif

                <!-- Reason (Optional) -->
                <div class="md:col-span-2">
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.reason') }}</label>
                    <textarea wire:model="reason" rows="2" placeholder="{{ __('inventory.transfer_reason_notes') }}" class="w-full p-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary"></textarea>
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <a href="{{ route('inventory.overview') }}" class="px-4 py-2 rounded-control border border-border text-text-secondary hover:bg-surface-soft font-semibold text-xs">
                    {{ __('inventory.cancel') }}
                </a>
                <button type="submit" wire:loading.attr="disabled"
                        class="px-5 py-2 rounded-control bg-primary text-white hover:bg-primary-hover font-bold text-xs shadow-button transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">{{ __('inventory.execute_transfer') }}</span>
                    <span wire:loading wire:target="save">{{ __('inventory.saving') }}</span>
                </button>
            </div>
        </form>
    </x-card>
</div>
