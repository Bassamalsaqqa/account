<div class="space-y-6 max-w-3xl mx-auto">
    <!-- Header -->
    <div class="flex items-center gap-3">
        <a href="{{ route('inventory.overview') }}"
           class="p-2 rounded-control border border-border bg-white text-text-secondary hover:text-text-primary hover:bg-surface-soft transition-colors">
            <x-icon name="arrow-right" class="w-4 h-4 rtl:rotate-0 ltr:rotate-180" />
        </a>
        <div>
            <h1 class="text-xl font-extrabold text-text-primary flex items-center gap-2">
                <x-icon name="plus" class="w-5 h-5 text-primary" />
                <span>{{ __('inventory.adjust_stock') }}</span>
            </h1>
            <p class="text-xs text-text-secondary mt-0.5">{{ __('inventory.adjustment_subtitle') }}</p>
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

                <!-- Warehouse -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.warehouse') }} *</label>
                    <select wire:model.live="warehouse_id" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
                        <option value="">{{ __('inventory.select_warehouse') }}</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }} {{ $w->is_default ? ('(' . __('inventory.default') . ')') : '' }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Adjustment Type -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.movement_type') }} *</label>
                    <select wire:model.live="type" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
                        <option value="{{ \App\Models\StockMovement::TYPE_ADJUSTMENT_INCREASE }}">{{ __('inventory.type_adjustment_increase') }}</option>
                        <option value="{{ \App\Models\StockMovement::TYPE_ADJUSTMENT_DECREASE }}">{{ __('inventory.type_adjustment_decrease') }}</option>
                        <option value="{{ \App\Models\StockMovement::TYPE_DAMAGE_OR_LOSS }}">{{ __('inventory.type_damage_or_loss') }}</option>
                    </select>
                    @error('type') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
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

                <!-- Unit Cost (Increase only, Gated by canViewCost) -->
                @if ($type === \App\Models\StockMovement::TYPE_ADJUSTMENT_INCREASE && $canViewCost)
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_cost') }} ({{ $company->base_currency_code }})</label>
                        <input type="number" step="any" wire:model="unit_cost_base" dir="ltr" placeholder="{{ __('inventory.optional_current_average_cost') }}" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                        <span class="text-[10px] text-text-muted">{{ __('inventory.optional_cost_hint') }}</span>
                    </div>
                @endif

                <!-- Lot Selection for Expiry Tracked -->
                @if ($selectedProduct && $selectedProduct->track_expiry)
                    @if ($type === \App\Models\StockMovement::TYPE_ADJUSTMENT_INCREASE)
                        <!-- New lot or existing -->
                        <div>
                            <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.lot_number') }}</label>
                            <input type="text" wire:model="lot_number" dir="ltr" placeholder="{{ __('inventory.lot_number_optional') }}" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                        </div>
                        <div>
                            <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.expiry_date') }}</label>
                            <input type="date" wire:model="expiry_date" dir="ltr" class="w-full h-9 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                        </div>
                    @else
                        <!-- Outbound requires lot selection -->
                        <div class="md:col-span-2">
                            <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.select_affected_lot') }} *</label>
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
                @endif

                <!-- Reason (Mandatory) -->
                <div class="md:col-span-2">
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.reason') }} *</label>
                    <textarea wire:model="reason" rows="2" placeholder="{{ __('inventory.adjustment_reason_placeholder') }}" class="w-full p-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary @error('reason') border-danger @enderror"></textarea>
                    @error('reason') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex items-center justify-end gap-2 pt-4 border-t border-border">
                <a href="{{ route('inventory.overview') }}" class="px-4 py-2 rounded-control border border-border text-text-secondary hover:bg-surface-soft font-semibold text-xs">
                    {{ __('inventory.cancel') }}
                </a>
                <button type="submit" wire:loading.attr="disabled"
                        class="px-5 py-2 rounded-control bg-primary text-white hover:bg-primary-hover font-bold text-xs shadow-button transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">{{ __('inventory.save_stock_adjustment') }}</span>
                    <span wire:loading wire:target="save">{{ __('inventory.saving') }}</span>
                </button>
            </div>
        </form>
    </x-card>
</div>
