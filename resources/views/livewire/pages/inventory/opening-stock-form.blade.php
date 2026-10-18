<div class="space-y-6" x-data="{
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
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('inventory.overview') }}" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary text-xs text-text-muted hover:text-primary transition-colors">&larr; {{ __('inventory.inventory_overview') }}</a>
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
            <button type="button" wire:click="$set('successMessage', null)" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary text-success hover:opacity-75" wire:loading.attr="disabled" :disabled="documentBusy || saving">&times;</button>
        </div>
    @endif
    @if ($errorMessage)
        <div class="p-3.5 rounded-control bg-danger-bg border border-danger/30 text-danger text-xs font-semibold flex items-center justify-between">
            <span>{{ $errorMessage }}</span>
            <button type="button" wire:click="$set('errorMessage', null)" class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary text-danger hover:opacity-75" wire:loading.attr="disabled" :disabled="documentBusy || saving">&times;</button>
        </div>
    @endif

    <form @submit.prevent="run('save', [], true)" class="space-y-6">
        <x-card padding="p-6" class="space-y-4 max-w-3xl">
            <h2 class="text-sm font-extrabold text-text-primary border-b border-border pb-2.5">{{ __('inventory.opening_stock_details') }}</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="md:col-span-2 rounded-control border border-border bg-surface-soft p-3 space-y-2">
                    <x-camera-scanner />
                    <p class="text-xs text-text-secondary">{{ __('refinement.inventory_scan_hint') }}</p>
                    @error('barcode') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
                </div>
                <!-- Product Select -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.product') }} *</label>
                    <select wire:model.live="product_id" class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs bg-white focus:outline-none focus:border-primary @error('product_id') border-danger @enderror" :disabled="documentBusy || saving">
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
                    <select wire:model="warehouse_id" class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs bg-white focus:outline-none focus:border-primary @error('warehouse_id') border-danger @enderror" :disabled="documentBusy || saving">
                        <option value="">— {{ __('inventory.select_warehouse') }} —</option>
                        @foreach ($warehouses as $w)
                            <option value="{{ $w->id }}">{{ app()->getLocale() === 'en' ? ($w->name_en ?: $w->name_ar) : $w->name_ar }} {{ $w->is_default ? ('(' . __('inventory.default') . ')') : '' }}</option>
                        @endforeach
                    </select>
                    @error('warehouse_id') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                @include('livewire.pages.inventory.partials.form-availability')

                <!-- Quantity -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.quantity') }} *</label>
                    <div class="flex items-center gap-2" dir="ltr">
                        <button type="button" @click="run('stepInventoryQuantity', ['down'])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white text-xl font-bold disabled:opacity-50" aria-label="{{ __('refinement.decrease_quantity') }}">-</button>
                        <input type="text" inputmode="decimal" wire:model="quantity" dir="ltr" placeholder="0" aria-label="{{ __('inventory.quantity') }}" class="min-h-11 min-w-0 w-full px-3 rounded-control border border-border text-center font-mono tabular-nums"  :disabled="documentBusy || saving"/>
                        <button type="button" @click="run('stepInventoryQuantity', ['up'])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-11 rounded-control border border-border bg-white text-xl font-bold disabled:opacity-50" aria-label="{{ __('refinement.increase_quantity') }}">+</button>
                    </div>
                    @error('quantity') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Unit -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.unit') }}</label>
                    <select wire:model="unit_id" class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs bg-white focus:outline-none focus:border-primary" :disabled="documentBusy || saving">
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
                           class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('unit_cost_base') border-danger @enderror" inputmode="decimal"  :disabled="documentBusy || saving"/>
                    <span class="text-[10px] text-text-muted mt-0.5 block">{{ __('inventory.base_currency') }}: {{ $company->base_currency_code }}</span>
                    @error('unit_cost_base') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Movement Date -->
                <div>
                    <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.opening_stock_date') }} *</label>
                    <input type="date" wire:model="movement_date" dir="ltr"
                           class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('movement_date') border-danger @enderror"  :disabled="documentBusy || saving"/>
                    @error('movement_date') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                </div>

                <!-- Lot Number (if expiry tracked) -->
                @if ($selectedProduct && $selectedProduct->track_expiry)
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.lot_number') }}</label>
                        <input type="text" wire:model="lot_number" dir="ltr" placeholder="LOT-001"
                               class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('lot_number') border-danger @enderror"  :disabled="documentBusy || saving"/>
                        @error('lot_number') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>

                    <!-- Expiry Date -->
                    <div>
                        <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.expiry_date') }} *</label>
                        <input type="date" wire:model="expiry_date" dir="ltr"
                               class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs font-mono focus:outline-none focus:border-primary @error('expiry_date') border-danger @enderror"  :disabled="documentBusy || saving"/>
                        @error('expiry_date') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
                    </div>
                @endif
            </div>

            <!-- Reason -->
            <div class="pt-2 text-xs">
                <label class="block font-bold text-text-secondary mb-1">{{ __('inventory.reason') }}</label>
                <input type="text" wire:model="reason"
                       class="w-full min-h-11 px-2.5 rounded-control border border-border text-xs focus:outline-none focus:border-primary"  :disabled="documentBusy || saving"/>
                @error('reason') <span class="text-danger text-[10px]">{{ $message }}</span> @enderror
            </div>

            <p wire:loading role="status" class="text-sm font-semibold text-primary">{{ __('refinement.working') }}</p>
            <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-20 flex flex-wrap items-center justify-end gap-3 border-t border-border bg-white p-3 shadow-sm md:static md:shadow-none">
                <a href="{{ route('inventory.overview') }}"
                   class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 py-2 rounded-control border border-border text-text-secondary hover:bg-surface-soft text-xs font-semibold transition-colors">
                    {{ __('common.cancel') }}
                </a>
                <button type="submit" :disabled="saving || window.isDocumentBlocked($wire)"
                        class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-5 py-2 rounded-control bg-primary text-white hover:bg-primary-hover text-xs font-bold shadow-button transition-colors" wire:loading.attr="disabled" wire:target="save">
                    {{ __('inventory.save_opening_stock_generate_entry') }}
                </button>
            </div>
        </x-card>
    </form>
</div>
