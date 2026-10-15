@if ($showQuickProductModal)
    <div x-data="{ trigger: document.activeElement, init() { this.$nextTick(() => this.$refs.firstInput.focus()); }, destroy() { this.trigger?.focus(); } }"
         x-trap.inert.noscroll="true" x-on:keydown.escape.window.stop.prevent="$wire.closeQuickProductModal()"
         x-on:keydown.enter.stop.prevent="$wire.saveQuickProduct()"
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
         role="dialog" aria-modal="true" aria-labelledby="quick-product-title" aria-describedby="quick-product-help">
        <div class="w-full max-w-lg max-h-[90dvh] overflow-y-auto rounded-card bg-surface p-6 shadow-lg space-y-4">
            <div class="flex items-center justify-between gap-3">
                <h2 id="quick-product-title" class="text-lg font-bold text-text-primary">{{ __('quick_product.create') }}</h2>
                <button type="button" wire:click="closeQuickProductModal" aria-label="{{ __('workflow.cancel') }}" class="min-h-11 min-w-11 rounded-control text-text-secondary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary"><x-icon name="close" class="h-5 w-5 mx-auto" /></button>
            </div>
            <p id="quick-product-help" class="text-sm text-text-secondary">{{ __('quick_product.help') }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach (['name_ar', 'name_en', 'sku'] as $field)
                    <div class="{{ $field === 'name_ar' ? 'sm:col-span-2' : '' }}">
                        <label for="quick-product-{{ $field }}" class="block text-sm font-bold text-text-primary mb-1">{{ __('inventory.'.$field) }}{{ $field !== 'name_en' ? ' *' : '' }}</label>
                        <input @if ($field === 'name_ar') x-ref="firstInput" @endif id="quick-product-{{ $field }}" type="text" wire:model="quick_product_{{ $field }}" maxlength="{{ $field === 'sku' ? 64 : 255 }}"
                               @if ($field !== 'name_en') required @endif @if ($field !== 'name_ar') dir="ltr" @endif
                               @error('quick_product_'.$field) aria-invalid="true" aria-describedby="quick-product-{{ $field }}-error" @enderror
                               class="w-full min-h-11 px-3 border border-border rounded-control bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary" />
                        @error('quick_product_'.$field)<p id="quick-product-{{ $field }}-error" class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                    </div>
                @endforeach
                <div>
                    <label for="quick-product-base-unit" class="block text-sm font-bold text-text-primary mb-1">{{ __('inventory.base_unit') }} *</label>
                    <select id="quick-product-base-unit" wire:model="quick_product_base_unit_id" required @error('quick_product_base_unit_id') aria-invalid="true" aria-describedby="quick-product-unit-error" @enderror class="w-full min-h-11 px-3 border border-border rounded-control bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary">
                        <option value="">{{ __('inventory.select_base_unit') }}</option>
                        @foreach ($this->quickProductUnits as $unit)
                            <option value="{{ $unit->id }}">{{ app()->getLocale() === 'en' && $unit->name_en ? $unit->name_en : $unit->name_ar }}</option>
                        @endforeach
                    </select>
                    @error('quick_product_base_unit_id')<p id="quick-product-unit-error" class="mt-1 text-sm text-danger">{{ $message }}</p>@enderror
                </div>
                <label class="sm:col-span-2 flex items-start gap-3 text-sm text-text-primary">
                    <input type="checkbox" wire:model="quick_product_track_expiry" class="mt-1 h-4 w-4 rounded border-border text-primary focus:ring-primary" />
                    <span>{{ __('inventory.track_expiry') }}</span>
                </label>
            </div>
            <div class="flex flex-wrap justify-end gap-2 border-t border-border pt-4">
                <button type="button" wire:click="closeQuickProductModal" class="min-h-11 px-4 py-2 rounded-control border border-border text-sm font-bold text-text-secondary hover:bg-surface-soft">{{ __('workflow.cancel') }}</button>
                <button type="button" wire:click="saveQuickProduct" wire:loading.attr="disabled" wire:target="saveQuickProduct" class="min-h-11 px-4 py-2 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    <span wire:loading.remove wire:target="saveQuickProduct">{{ __('quick_product.save') }}</span>
                    <span wire:loading wire:target="saveQuickProduct" role="status">{{ __('quick_product.saving') }}</span>
                </button>
            </div>
        </div>
    </div>
@endif
