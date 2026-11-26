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
    'previewInput' => false,
    'rowKey' => null,
])

@php
    $field = (string) ($field ?? 'quantity');
    $index = (int) $index;
    $rowKey = is_string($rowKey) && $rowKey !== '' ? $rowKey : null;
    $inputId = $id ?? 'line-' . $field . '-' . $index;
    $label = $ariaLabel ?? __('mobile.quantity') . ' ' . ($index + 1);
    $quantum = (string) ($step ?? ($allowsFraction ? '0.01' : '1'));
    $minVal = (string) ($min ?? '0');
    $maxVal = $max !== null ? (string) $max : null;
@endphp

<div @if($rowKey) wire:key="stepper-{{ $rowKey }}-{{ $field }}" @endif
     class="inline-flex flex-col items-start gap-1 touch-manipulation"
     x-data="touchQuantityStepper($wire,{index:@js($index),rowKey:@js($rowKey),quantum:@js($quantum),min:@js($minVal),max:@js($maxVal),field:@js($field)})"
     @document-operation-state.window="onOperationState($event.detail)">
    <span hidden x-ref="stepperMeta" data-line-index="{{ $index }}" data-row-key="{{ $rowKey ?? '' }}"></span>
    <div class="inline-flex items-stretch border border-border rounded-control bg-surface shadow-xs overflow-hidden">
        <!-- Decrement Button (>=44px touch target, clear contrast) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft,selectProduct,changeUnit,scanCameraBarcode,addLine,removeLine"
                :disabled="failed || recovering || (typeof saving !== 'undefined' && saving) || @js((bool) $disabled)"
                @click="tap('down')"
                class="min-h-[44px] min-w-[44px] px-3 flex items-center justify-center bg-danger-bg hover:bg-danger-bg active:bg-rose-200 text-danger border-e border-border text-lg font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="{{ __('mobile.decrement') }} {{ $index + 1 }}">
            <span aria-hidden="true">−</span>
        </button>

        <!-- Quantity Input (stays enabled during batch steps for stable display/typing, readonly during tap batch) -->
        <input id="{{ $inputId }}"
               @if($rowKey) wire:key="stepper-input-{{ $rowKey }}-{{ $field }}" @endif
               x-ref="qtyInput"
               @focus="onInputFocus()"
               @input="onInputTyped()"
               aria-label="{{ $label }}"
               @error('lines.'.$index.'.'.$field) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @enderror
               type="text"
               inputmode="{{ $allowsFraction ? 'decimal' : 'numeric' }}"
               @if ($previewInput)
                   wire:model="lines.{{ $index }}.{{ $field }}"
                   data-purchase-preview-input
               @else
                   wire:model.blur="lines.{{ $index }}.{{ $field }}"
                   wire:change="recalculate"
               @endif
               dir="ltr"
               required
               :readonly="pending > 0"
               :disabled="(typeof documentBusy !== 'undefined' && documentBusy) || failed || recovering || (typeof saving !== 'undefined' && saving) || @js((bool) $disabled)"
               @disabled($disabled)
               class="min-h-[44px] w-16 text-center border-0 bg-surface text-xs font-mono font-bold text-text-primary focus:ring-1 focus:ring-primary focus:outline-hidden disabled:opacity-60" />

        <!-- Increment Button (>=44px touch target, clear contrast) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft,selectProduct,changeUnit,scanCameraBarcode,addLine,removeLine"
                :disabled="failed || recovering || (typeof saving !== 'undefined' && saving) || @js((bool) $disabled)"
                @click="tap('up')"
                class="min-h-[44px] min-w-[44px] px-3 flex items-center justify-center bg-success-bg hover:bg-success-bg active:bg-emerald-200 text-success border-s border-border text-lg font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="{{ __('mobile.increment') }} {{ $index + 1 }}">
            <span aria-hidden="true">+</span>
        </button>
    </div>

    <!-- Visible Pending / Failure State with Discard/Resync -->
    <div x-cloak x-show="pending || failed" class="text-xs leading-tight select-none">
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
