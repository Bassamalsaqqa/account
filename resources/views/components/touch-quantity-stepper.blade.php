@props([
    'index',
    'allowsFraction' => false,
    'id' => null,
    'ariaLabel' => null,
    'disabled' => false,
])

@php
    $inputId = $id ?? 'line-qty-' . $index;
    $label = $ariaLabel ?? __('mobile.quantity') . ' ' . ($index + 1);
@endphp

<div class="inline-flex items-stretch border border-border rounded-control bg-white shadow-xs overflow-hidden touch-manipulation"
     x-data="{ busy: false, failed: false }">
    <span x-cloak x-show="failed" role="alert" class="sr-only">{{ __('camera.network_error') }}</span>
    <!-- Decrement Button (>=44px touch target) -->
    <button type="button"
            wire:loading.attr="disabled"
            wire:target="decrementQuantity,incrementQuantity,stepQuantity,save,saveDraft,addLine,scanBarcode"
            :disabled="busy || {{ $disabled ? 'true' : 'false' }}"
            @click="if (busy) return; busy = true; (window.enqueueDocumentOperation ? window.enqueueDocumentOperation($wire, 'decrementQuantity', [{{ $index }}]) : $wire.decrementQuantity({{ $index }})).catch(() => { failed = true; }).finally(() => { busy = false; })"
            class="min-h-[44px] min-w-[44px] px-3 flex items-center justify-center bg-surface-soft hover:bg-slate-200 active:bg-slate-300 text-text-primary border-e border-border text-base font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label="{{ __('mobile.decrement') }} {{ $index + 1 }}">
        <span aria-hidden="true">−</span>
    </button>

    <!-- Quantity Input -->
    <input id="{{ $inputId }}"
           aria-label="{{ $label }}"
           @error('lines.'.$index.'.quantity') aria-invalid="true" aria-describedby="{{ $inputId }}-error" @enderror
           type="text"
           inputmode="{{ $allowsFraction ? 'decimal' : 'numeric' }}"
           wire:model.blur="lines.{{ $index }}.quantity"
           wire:change="recalculate"
           dir="ltr"
           required
           @disabled($disabled)
           class="min-h-[44px] w-16 text-center border-0 bg-white text-xs font-mono font-bold text-text-primary focus:ring-1 focus:ring-primary focus:outline-hidden disabled:opacity-60" />

    <!-- Increment Button (>=44px touch target) -->
    <button type="button"
            wire:loading.attr="disabled"
            wire:target="decrementQuantity,incrementQuantity,stepQuantity,save,saveDraft,addLine,scanBarcode"
            :disabled="busy || {{ $disabled ? 'true' : 'false' }}"
            @click="if (busy) return; busy = true; (window.enqueueDocumentOperation ? window.enqueueDocumentOperation($wire, 'incrementQuantity', [{{ $index }}]) : $wire.incrementQuantity({{ $index }})).catch(() => { failed = true; }).finally(() => { busy = false; })"
            class="min-h-[44px] min-w-[44px] px-3 flex items-center justify-center bg-surface-soft hover:bg-slate-200 active:bg-slate-300 text-text-primary border-s border-border text-base font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
            aria-label="{{ __('mobile.increment') }} {{ $index + 1 }}">
        <span aria-hidden="true">+</span>
    </button>
</div>
