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

<div class="inline-flex flex-col items-start gap-1 touch-manipulation"
     x-data="{
         pending: 0, failed: false, recovering: false,
         tap(direction) {
             this.pending++;
             window.enqueueQuantityStep($wire, {{ $index }}, direction)
                 .catch(() => { this.failed = true; })
                 .finally(() => { this.pending--; });
         },
         async resync() {
             this.recovering = true;
             try { await window.resyncDocumentQueue($wire); this.failed = false; }
             catch { this.failed = true; }
             finally { this.recovering = false; }
         }
     }"
     @document-operation-state.window="if ($event.detail.id === $wire.$id) { if ($event.detail.blocked) failed = true; else if (!$event.detail.busy) failed = false; }">
    <div class="inline-flex items-stretch border border-border rounded-control bg-white shadow-xs overflow-hidden">
        <!-- Decrement Button (>=44px touch target) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft"
                :disabled="failed || recovering || {{ $disabled ? 'true' : 'false' }}"
                @click="tap('down')"
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
               :disabled="pending > 0 || failed || recovering || {{ $disabled ? 'true' : 'false' }}"
               @disabled($disabled)
               class="min-h-[44px] w-16 text-center border-0 bg-white text-xs font-mono font-bold text-text-primary focus:ring-1 focus:ring-primary focus:outline-hidden disabled:opacity-60" />

        <!-- Increment Button (>=44px touch target) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft"
                :disabled="failed || recovering || {{ $disabled ? 'true' : 'false' }}"
                @click="tap('up')"
                class="min-h-[44px] min-w-[44px] px-3 flex items-center justify-center bg-surface-soft hover:bg-slate-200 active:bg-slate-300 text-text-primary border-s border-border text-base font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="{{ __('mobile.increment') }} {{ $index + 1 }}">
            <span aria-hidden="true">+</span>
        </button>
    </div>

    <!-- Visible Pending / Failure State with Discard/Resync -->
    <div x-cloak x-show="pending || failed" class="text-[11px] leading-tight select-none">
        <span x-show="pending && !failed" class="text-text-muted inline-flex items-center gap-1">
            <span class="inline-block w-1.5 h-1.5 rounded-full bg-primary animate-pulse" aria-hidden="true"></span>
            <span>{{ __('mobile.quantity_pending') }}</span>
        </span>
        <div x-show="failed" role="alert" class="text-danger inline-flex items-center gap-1.5 font-medium">
            <span>{{ __('mobile.quantity_recovery') }}</span>
            <button type="button"
                    @click.stop="resync()" :disabled="recovering || pending > 0"
                    class="min-h-11 rounded-control border border-danger px-3 py-2 font-semibold disabled:opacity-50">
                {{ __('mobile.quantity_discard') }}
            </button>
        </div>
    </div>
</div>
