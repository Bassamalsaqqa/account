@if ($showQuickCustomerModal)
    <x-dialog-shell
        :title="__('workflow.quick_customer')"
        title-id="quick-customer-modal-title"
        max-width="max-w-md"
        close-action="closeQuickCustomerModal"
        save-target="saveQuickCustomer">
        <div class="space-y-3">
            <p class="text-[11px] text-text-secondary">{{ __('workflow.at_least_one_name_required') }}</p>
            <div>
                <label for="quick-customer-name-ar" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.customer_name_ar') }}</label>
                <input x-ref="firstInput" id="quick-customer-name-ar" type="text" wire:model="quick_customer_name_ar" maxlength="255" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                @error('quick_customer_name_ar') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
            </div>
            <div>
                <label for="quick-customer-name-en" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.customer_name_en') }}</label>
                <input id="quick-customer-name-en" type="text" wire:model="quick_customer_name_en" maxlength="255" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                @error('quick_customer_name_en') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label for="quick-customer-phone" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.phone') }}</label>
                    <input id="quick-customer-phone" type="text" wire:model="quick_customer_phone" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                    @error('quick_customer_phone') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label for="quick-customer-code" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.code') }}</label>
                    <input id="quick-customer-code" type="text" wire:model="quick_customer_code" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving" />
                    @error('quick_customer_code') <span class="text-danger text-[11px] block mt-1" role="alert">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <x-slot:footer>
            <button type="button" @click="dismiss()" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft cursor-pointer" :disabled="isSaving" wire:loading.attr="disabled" wire:target="closeQuickCustomerModal">{{ __('workflow.cancel') }}</button>
            <button type="button" wire:click="saveQuickCustomer" wire:loading.attr="disabled" wire:target="saveQuickCustomer" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover shadow-xs cursor-pointer">
                <span wire:loading.remove wire:target="saveQuickCustomer">{{ __('workflow.save_customer') }}</span>
                <span wire:loading wire:target="saveQuickCustomer">...</span>
            </button>
        </x-slot:footer>
    </x-dialog-shell>
@endif
