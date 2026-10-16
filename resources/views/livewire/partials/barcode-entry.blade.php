<div x-data="barcodeEntry($wire)" data-network-error="{{ __('camera.network_error') }}" @barcode-scanned.window="focusInput()">
    <label for="{{ $prefix }}-barcodeSearch" class="block text-sm font-bold text-text-primary mb-1">{{ __('workflow.scan_barcode_or_sku') }}</label>
    <div class="flex gap-2">
        <input id="{{ $prefix }}-barcodeSearch" x-ref="scanner" type="text" maxlength="128" autocomplete="off" dir="ltr"
               x-model="entry" @keydown.enter.prevent="if (!$event.repeat) submit()" @input="scanned = false"
               placeholder="{{ __('workflow.scan_barcode_hint') }}" aria-describedby="{{ $prefix }}-scan-help"
               class="min-w-0 w-full h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
        <button type="button" @click="submit()" :disabled="pending > 0"
                class="shrink-0 min-h-11 px-3 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:opacity-50">
            {{ __('workflow.add_scanned_product') }}
        </button>
    </div>
    <div class="mt-2"><x-camera-scanner /></div>
    <p id="{{ $prefix }}-scan-help" class="mt-1 text-xs text-text-secondary">{{ __('workflow.scan_entry_help') }}</p>
    <p x-show="scanned" x-cloak role="status" aria-live="polite" class="mt-1 text-sm text-emerald-700">{{ __('workflow.scan_added') }}</p>
    <p x-show="pending > 0" x-cloak role="status" class="mt-1 text-sm text-text-secondary">{{ __('workflow.scan_loading') }}</p>
    <p x-show="feedback" x-text="feedback" role="alert" class="mt-1 text-danger text-sm"></p>
    @error('barcodeSearch') <p role="alert" class="text-danger text-sm mt-1">{{ $message }}</p> @enderror
</div>
