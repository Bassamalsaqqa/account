@if ($showQuickVendorModal)
    <x-dialog-shell
        :title="__('workflow.quick_vendor')"
        title-id="quick-vendor-modal-title"
        max-width="max-w-md"
        close-action="closeQuickVendorModal"
        save-target="saveQuickVendor">
        <div class="space-y-3">
            <p class="text-[11px] text-text-secondary">{{ __('workflow.at_least_one_name_required') }}</p>
            <div>
                <label for="quick-vendor-name-ar" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.vendor_name_ar') }}</label>
                <input x-ref="firstInput" id="quick-vendor-name-ar" type="text" wire:model="quick_vendor_name_ar" maxlength="255" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                @error('quick_vendor_name_ar') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="quick-vendor-name-en" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.vendor_name_en') }}</label>
                <input id="quick-vendor-name-en" type="text" wire:model="quick_vendor_name_en" maxlength="255" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                @error('quick_vendor_name_en') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="quick-vendor-phone" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.phone') }}</label>
                    <input id="quick-vendor-phone" type="text" wire:model="quick_vendor_phone" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                    @error('quick_vendor_phone') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="quick-vendor-code" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.code') }}</label>
                    <input id="quick-vendor-code" type="text" wire:model="quick_vendor_code" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                    @error('quick_vendor_code') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <x-slot:footer>
            <button type="button" @click="dismiss()" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft cursor-pointer" :disabled="isSaving" wire:loading.attr="disabled" wire:target="closeQuickVendorModal">{{ __('workflow.cancel') }}</button>
            <button type="button" wire:click="saveQuickVendor" wire:loading.attr="disabled" wire:target="saveQuickVendor" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover shadow-xs cursor-pointer">
                <span wire:loading.remove wire:target="saveQuickVendor">{{ __('workflow.save_vendor') }}</span>
                <span wire:loading wire:target="saveQuickVendor">...</span>
            </button>
        </x-slot:footer>
    </x-dialog-shell>
@endif
