@props([
    'index',
    'allowsFraction' => false,
    'step' => null,
    'min' => '0',
    'max' => null,
    'id' => null,
    'ariaLabel' => null,
    'disabled' => false,
    'field' => 'quantity',
])

@php
    $field = $field ?? 'quantity';
    $inputId = $id ?? 'line-' . $field . '-' . $index;
    $label = $ariaLabel ?? __('mobile.quantity') . ' ' . ($index + 1);
    $quantum = $step ?? ($allowsFraction ? '0.01' : '1');
    $minVal = $min ?? '0';
    $maxVal = $max !== null ? (string) $max : null;
@endphp

<div class="inline-flex flex-col items-start gap-1 touch-manipulation"
     x-data="{
         pending: 0, failed: false, recovering: false,
         optimisticQty: null, confirmedQty: null,
         batchEpoch: 0,
         tap(direction) {
             const input = this.$refs.qtyInput;
             this.batchEpoch = (this.batchEpoch || 0) + 1;
             const currentEpoch = this.batchEpoch;
             if (!this.optimisticQty) {
                 this.optimisticQty = input.value;
                 this.confirmedQty = input.value;
             }
             if (window.stepExactString) {
                 this.optimisticQty = window.stepExactString(
                     this.optimisticQty,
                     direction,
                     '{{ $quantum }}',
                     '{{ $minVal }}',
                     @if($maxVal !== null) '{{ $maxVal }}' @else null @endif
                 );
                 input.value = this.optimisticQty;
             }
             this.pending++;
             window.enqueueQuantityStep($wire, {{ $index }}, direction, {field: '{{ $field }}'})
                 .then(result => {
                     if (this.batchEpoch !== currentEpoch) return;
                     if (result && typeof result.quantity === 'string') {
                         this.confirmedQty = result.quantity;
                     }
                 })
                 .catch(() => {
                     if (this.batchEpoch !== currentEpoch) return;
                     this.failed = true;
                     if (this.confirmedQty !== null) {
                         input.value = this.confirmedQty;
                     }
                 })
                 .finally(() => {
                     this.pending--;
                     if (this.pending === 0 && this.batchEpoch === currentEpoch) {
                         if (!this.failed && this.confirmedQty !== null) {
                             input.value = this.confirmedQty;
                         }
                         this.optimisticQty = null;
                     }
                 });
         },
         onInputFocus() {
             if (this.pending > 0 && window.flushQuantityBatch) {
                 window.flushQuantityBatch($wire);
             }
         },
         onInputTyped() {
             this.batchEpoch = (this.batchEpoch || 0) + 1;
             this.optimisticQty = null;
             this.confirmedQty = null;
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
        <!-- Decrement Button (>=44px touch target, clear contrast) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft,selectProduct,changeUnit,scanCameraBarcode,addLine,removeLine"
                :disabled="failed || recovering || (typeof saving !== 'undefined' && saving) || {{ $disabled ? 'true' : 'false' }}"
                @click="tap('down')"
                class="min-h-[44px] min-w-[44px] px-3 flex items-center justify-center bg-rose-50 hover:bg-rose-100 active:bg-rose-200 text-rose-700 border-e border-border text-lg font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="{{ __('mobile.decrement') }} {{ $index + 1 }}">
            <span aria-hidden="true">−</span>
        </button>

        <!-- Quantity Input (stays enabled during batch steps for stable display/typing, readonly during tap batch) -->
        <input id="{{ $inputId }}"
               x-ref="qtyInput"
               @focus="onInputFocus()"
               @input="onInputTyped()"
               aria-label="{{ $label }}"
               @error('lines.'.$index.'.'.$field) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @enderror
               type="text"
               inputmode="{{ $allowsFraction ? 'decimal' : 'numeric' }}"
               wire:model.blur="lines.{{ $index }}.{{ $field }}"
               wire:change="recalculate"
               dir="ltr"
               required
               :readonly="pending > 0"
               :disabled="(typeof documentBusy !== 'undefined' && documentBusy) || failed || recovering || (typeof saving !== 'undefined' && saving) || {{ $disabled ? 'true' : 'false' }}"
               @disabled($disabled)
               class="min-h-[44px] w-16 text-center border-0 bg-white text-xs font-mono font-bold text-text-primary focus:ring-1 focus:ring-primary focus:outline-hidden disabled:opacity-60" />

        <!-- Increment Button (>=44px touch target, clear contrast) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft,selectProduct,changeUnit,scanCameraBarcode,addLine,removeLine"
                :disabled="failed || recovering || (typeof saving !== 'undefined' && saving) || {{ $disabled ? 'true' : 'false' }}"
                @click="tap('up')"
                class="min-h-[44px] min-w-[44px] px-3 flex items-center justify-center bg-emerald-50 hover:bg-emerald-100 active:bg-emerald-200 text-emerald-700 border-s border-border text-lg font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
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
