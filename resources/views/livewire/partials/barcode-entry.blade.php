<div x-data="{ scanned: false }" @barcode-scanned.window="scanned = true; $refs.scanner.focus()">
    <label for="{{ $prefix }}-barcodeSearch" class="block text-sm font-bold text-text-primary mb-1">{{ __('workflow.scan_barcode_or_sku') }}</label>
    <div class="flex gap-2">
        <input id="{{ $prefix }}-barcodeSearch" x-ref="scanner" type="text" maxlength="128" autocomplete="off" dir="ltr"
               wire:model="barcodeSearch" wire:keydown.enter.prevent="scanBarcode" @input="scanned = false"
               placeholder="{{ __('workflow.scan_barcode_hint') }}" aria-describedby="{{ $prefix }}-scan-help"
               class="min-w-0 w-full h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
        <button type="button" wire:click="scanBarcode" wire:loading.attr="disabled" wire:target="scanBarcode"
                class="shrink-0 min-h-11 px-3 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:opacity-50">
            {{ __('workflow.add_scanned_product') }}
        </button>
    </div>
    <p id="{{ $prefix }}-scan-help" class="mt-1 text-xs text-text-secondary">{{ __('workflow.scan_entry_help') }}</p>
    <p x-show="scanned" x-cloak role="status" aria-live="polite" class="mt-1 text-sm text-emerald-700">{{ __('workflow.scan_added') }}</p>
    <p wire:loading wire:target="scanBarcode" role="status" class="mt-1 text-sm text-text-secondary">{{ __('workflow.scan_loading') }}</p>
    @error('barcodeSearch') <p role="alert" class="text-danger text-sm mt-1">{{ $message }}</p> @enderror
</div>
