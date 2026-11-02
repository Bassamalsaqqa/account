<div x-data="barcodeEntry($wire)" data-network-error="{{ __('camera.network_error') }}" @barcode-scanned.window="focusInput()">
    <label for="{{ $prefix }}-barcodeSearch" class="block text-sm font-bold text-text-primary mb-1">{{ __('workflow.scan_barcode_or_sku') }}</label>
    <div class="flex gap-2 items-start">
        <div class="flex min-w-0 flex-1 items-center rounded-control border border-border bg-canvas focus-within:border-primary focus-within:ring-1 focus-within:ring-primary">
        <input id="{{ $prefix }}-barcodeSearch" x-ref="scanner" type="text" maxlength="128" autocomplete="off" dir="ltr"
               x-model="entry" @keydown.enter.prevent="if (!$event.repeat) submit()" @input="scanned = false"
               placeholder="{{ __('workflow.scan_barcode_hint') }}"
               class="min-w-0 w-full h-11 px-3 rounded-control border-0 bg-transparent text-sm text-text-primary focus:ring-0 outline-hidden" />
            <x-camera-scanner :compact="true" button-class="min-h-11 min-w-11 inline-flex items-center justify-center rounded-control text-primary hover:bg-surface-soft focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary" />
        </div>
        <button type="button" @click="submit()" :disabled="pending > 0"
                class="shrink-0 min-h-11 px-3 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 disabled:opacity-50">
            {{ __('workflow.add_scanned_product') }}
        </button>
    </div>
    <p x-show="scanned" x-cloak role="status" aria-live="polite" class="mt-1 text-sm text-emerald-700">{{ __('workflow.scan_added') }}</p>
    <p x-show="pending > 0" x-cloak role="status" class="mt-1 text-sm text-text-secondary">{{ __('workflow.scan_loading') }}</p>
    <p x-show="feedback" x-text="feedback" role="alert" class="mt-1 text-danger text-sm"></p>
    @error('barcodeSearch') <p role="alert" class="text-danger text-sm mt-1">{{ $message }}</p> @enderror
</div>
