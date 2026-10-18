<div class="space-y-6 max-w-3xl mx-auto" x-data="{
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
    <!-- Header -->
    <div class="flex items-center gap-3">
        <a href="{{ route('inventory.overview') }}"
           class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary p-2 rounded-control border border-border bg-white text-text-secondary hover:text-text-primary hover:bg-surface-soft transition-colors">
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
            <button type="button" wire:click="$set('successMessage', null)" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary text-success hover:opacity-75" wire:loading.attr="disabled">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button type="button" wire:click="$set('errorMessage', null)" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary text-danger hover:opacity-75" wire:loading.attr="disabled">&times;</button>
        </div>
    @endif

    <x-card padding="p-6">
        <form @submit.prevent="run('save', [], true)" class="space-y-4 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="md:col-span-2 rounded-control border border-border bg-surface-soft p-3 space-y-2">
                    <x-camera-scanner />
                    <p class="text-xs text-text-secondary">{{ __('refinement.inventory_scan_hint') }}</p>
                    @error('barcode') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <!-- Product -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.product') }} *</label>
                    <select wire:model.live="product_id" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
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
                    <select wire:model.live="warehouse_id" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
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
                    <select wire:model.live="type" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary focus:outline-none focus:border-primary">
                        <option value="{{ \App\Models\StockMovement::TYPE_ADJUSTMENT_INCREASE }}">{{ __('inventory.type_adjustment_increase') }}</option>
                        <option value="{{ \App\Models\StockMovement::TYPE_ADJUSTMENT_DECREASE }}">{{ __('inventory.type_adjustment_decrease') }}</option>
                        <option value="{{ \App\Models\StockMovement::TYPE_DAMAGE_OR_LOSS }}">{{ __('inventory.type_damage_or_loss') }}</option>
                    </select>
                    @error('type') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Movement Date -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.movement_date') }} *</label>
                    <input type="date" wire:model="movement_date" dir="ltr" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                    @error('movement_date') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                @include('livewire.pages.inventory.partials.form-availability')

                <!-- Quantity -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.quantity_delta') }} *</label>
                    <div class="flex items-center gap-2" dir="ltr">
                        <button type="button" @click="run('stepInventoryQuantity', ['down'])" :disabled="saving" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white text-xl font-bold disabled:opacity-50" aria-label="{{ __('refinement.decrease_quantity') }}">-</button>
                        <input type="text" inputmode="decimal" wire:model="quantity" dir="ltr" placeholder="0" aria-label="{{ __('inventory.quantity') }}" class="min-h-11 min-w-0 w-full px-3 rounded-control border border-border text-center font-mono tabular-nums" />
                        <button type="button" @click="run('stepInventoryQuantity', ['up'])" :disabled="saving" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white text-xl font-bold disabled:opacity-50" aria-label="{{ __('refinement.increase_quantity') }}">+</button>
                    </div>
                    @error('quantity') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Unit -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit') }}</label>
                    <select wire:model="unit_id" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs text-text-primary">
                        @foreach ($availableUnits as $u)
                            <option value="{{ $u->id }}">{{ app()->getLocale() === 'en' ? ($u->name_en ?: $u->name_ar) : $u->name_ar }}</option>
                        @endforeach
                    </select>
                </div>

                <!-- Unit Cost (Increase only, Gated by canViewCost) -->
                @if ($type === \App\Models\StockMovement::TYPE_ADJUSTMENT_INCREASE && $canViewCost)
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit_cost') }} ({{ $company->base_currency_code }})</label>
                        <input type="number" step="any" wire:model="unit_cost_base" dir="ltr" placeholder="{{ __('inventory.optional_current_average_cost') }}" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs font-mono" inputmode="decimal" />
                        <span class="text-[10px] text-text-muted">{{ __('inventory.optional_cost_hint') }}</span>
                    </div>
                @endif

                <!-- Lot Selection for Expiry Tracked -->
                @if ($selectedProduct && $selectedProduct->track_expiry)
                    @if ($type === \App\Models\StockMovement::TYPE_ADJUSTMENT_INCREASE)
                        <!-- New lot or existing -->
                        <div>
                            <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.lot_number') }}</label>
                            <input type="text" wire:model="lot_number" dir="ltr" placeholder="{{ __('inventory.lot_number_optional') }}" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                        </div>
                        <div>
                            <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.expiry_date') }}</label>
                            <input type="date" wire:model="expiry_date" dir="ltr" class="w-full min-h-11 px-2.5 rounded-control border border-border bg-white text-xs font-mono" />
                        </div>
                    @else
                        <!-- Outbound requires lot selection -->
                        <div class="md:col-span-2">
                            @include('livewire.pages.inventory.partials.lot-picker', ['lotLabel' => __('inventory.select_affected_lot')])
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

            <p wire:loading role="status" class="text-sm font-semibold text-primary">{{ __('refinement.working') }}</p>
            <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-20 flex flex-wrap items-center justify-end gap-2 border-t border-border bg-white p-3 shadow-sm md:static md:shadow-none">
                <a href="{{ route('inventory.overview') }}" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 py-2 rounded-control border border-border text-text-secondary hover:bg-surface-soft font-semibold text-xs">
                    {{ __('inventory.cancel') }}
                </a>
                <button type="submit" :disabled="saving" wire:loading.attr="disabled"
                        class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-5 py-2 rounded-control bg-primary text-white hover:bg-primary-hover font-bold text-xs shadow-button transition-colors disabled:opacity-50">
                    <span wire:loading.remove wire:target="save">{{ __('inventory.save_stock_adjustment') }}</span>
                    <span wire:loading wire:target="save">{{ __('inventory.saving') }}</span>
                </button>
            </div>
        </form>
    </x-card>
</div>
