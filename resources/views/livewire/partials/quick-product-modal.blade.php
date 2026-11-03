@if ($showQuickProductModal)
    <x-dialog-shell
        :title="__('quick_product.create')"
        title-id="quick-product-title"
        max-width="max-w-lg"
        close-action="closeQuickProductModal"
        save-target="saveQuickProduct"
        aria-describedby="quick-product-help">
        <p id="quick-product-help" class="text-sm text-text-secondary">{{ __('quick_product.help') }}</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            @foreach (['name_ar', 'name_en', 'sku'] as $field)
                <div class="{{ $field === 'name_ar' ? 'sm:col-span-2' : '' }}">
                    <label for="quick-product-{{ $field }}" class="block text-sm font-bold text-text-primary mb-1">{{ __('inventory.'.$field) }}{{ $field === 'sku' ? ' *' : '' }}</label>
                    <input @if ($field === 'name_ar') x-ref="firstInput" @endif id="quick-product-{{ $field }}" type="text" wire:model="quick_product_{{ $field }}" maxlength="{{ $field === 'sku' ? 64 : 255 }}"
                           @if ($field === 'sku') required @endif @if ($field !== 'name_ar') dir="ltr" @endif
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

        <x-slot:footer>
            <button type="button" @click="dismiss()" class="min-h-11 px-4 py-2 rounded-control border border-border text-sm font-bold text-text-secondary hover:bg-surface-soft cursor-pointer" :disabled="isSaving" wire:loading.attr="disabled" wire:target="closeQuickProductModal">{{ __('workflow.cancel') }}</button>
            <button type="button" wire:click="saveQuickProduct" wire:loading.attr="disabled" wire:target="saveQuickProduct" class="min-h-11 px-4 py-2 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover disabled:opacity-60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer">
                <span wire:loading.remove wire:target="saveQuickProduct">{{ __('quick_product.save') }}</span>
                <span wire:loading wire:target="saveQuickProduct" role="status">{{ __('quick_product.saving') }}</span>
            </button>
        </x-slot:footer>
    </x-dialog-shell>
@endif
