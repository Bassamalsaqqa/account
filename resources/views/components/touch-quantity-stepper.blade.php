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
    'deferInput' => false,
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

<div data-touch-stepper
     @if($rowKey) wire:key="stepper-{{ $rowKey }}-{{ $field }}" @endif
     class="relative inline-flex flex-col items-start gap-1 touch-manipulation"
     x-data="touchQuantityStepper($wire,{index:@js($index),rowKey:@js($rowKey),quantum:@js($quantum),min:@js($minVal),max:@js($maxVal),field:@js($field)})"
     @document-operation-state.window="onOperationState($event.detail)">
    <span hidden
          x-ref="stepperMeta"
          data-line-index="{{ $index }}"
          data-row-key="{{ $rowKey ?? '' }}"
          data-quantum="{{ $quantum }}"
          data-min="{{ $minVal }}"
          data-max="{{ $maxVal ?? '' }}"></span>
    <div class="inline-flex items-stretch border border-border-control rounded-control bg-canvas shadow-xs overflow-hidden">
        <!-- Decrement Button (>=44px touch target, clear contrast) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft,selectProduct,quickAddProduct,changeUnit,scanCameraBarcode,addLine,removeLine"
                :disabled="failed || recovering || (typeof saving !== 'undefined' && saving) || @js((bool) $disabled)"
                @click="tap('down')"
                class="min-h-[44px] min-w-[44px] lg:min-h-9.5 lg:min-w-9.5 px-3 lg:px-2.5 flex items-center justify-center bg-danger-bg hover:bg-danger-bg active:bg-rose-200 text-danger border-e border-border text-lg font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
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
               @elseif ($deferInput)
                   wire:model="lines.{{ $index }}.{{ $field }}"
                   wire:change="recalculate"
               @else
                   wire:model.blur="lines.{{ $index }}.{{ $field }}"
                   wire:change="recalculate"
               @endif
               dir="ltr"
               required
               :readonly="pending > 0"
               :disabled="(typeof documentBusy !== 'undefined' && documentBusy) || failed || recovering || (typeof saving !== 'undefined' && saving) || @js((bool) $disabled)"
               @disabled($disabled)
               class="min-h-[44px] lg:min-h-9.5 w-16 text-center border-0 bg-transparent text-base lg:text-sm font-mono tabular-nums font-bold text-text-primary focus:ring-1 focus:ring-primary focus:outline-hidden disabled:opacity-60" />

        <!-- Increment Button (>=44px touch target, clear contrast) -->
        <button type="button"
                wire:loading.attr="disabled"
                wire:target="save,saveDraft,selectProduct,quickAddProduct,changeUnit,scanCameraBarcode,addLine,removeLine"
                :disabled="failed || recovering || (typeof saving !== 'undefined' && saving) || @js((bool) $disabled)"
                @click="tap('up')"
                class="min-h-[44px] min-w-[44px] lg:min-h-9.5 lg:min-w-9.5 px-3 lg:px-2.5 flex items-center justify-center bg-success-bg hover:bg-success-bg active:bg-emerald-200 text-success border-s border-border text-lg font-bold transition-colors select-none cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                aria-label="{{ __('mobile.increment') }} {{ $index + 1 }}">
            <span aria-hidden="true">+</span>
        </button>
    </div>

    <!-- Reserved single-line status slot (constant height eliminates layout shift across idle, pending, and failed states) -->
    <div data-stepper-feedback
         dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"
         role="status"
         aria-live="polite"
         aria-atomic="true"
         class="h-6 min-h-6 max-h-6 w-full max-w-[154px] flex items-center gap-1 text-xs leading-6 whitespace-nowrap overflow-hidden select-none transition-colors"
         :class="failed ? 'text-danger font-medium' : 'text-text-muted'">
        <span class="inline-block w-1.5 h-1.5 rounded-full shrink-0 transition-opacity"
              :class="failed ? 'bg-danger opacity-100' : (pending > 0 ? 'bg-primary animate-pulse opacity-100' : 'opacity-0')"
              aria-hidden="true"></span>
        <span class="truncate"
              x-text="failed ? @js(__('mobile.quantity_recovery_short')) : (pending > 0 ? @js(__('mobile.quantity_pending')) : '')"></span>
    </div>

    <!-- Out-of-flow floating recovery alert rendered only on failure so row heights remain invariant -->
    <template x-if="shouldShowRecoveryCard()">
        <div data-stepper-recovery-card
             dir="{{ app()->isLocale('ar') ? 'rtl' : 'ltr' }}"
             role="alert"
             class="fixed bottom-24 md:bottom-6 inset-x-4 sm:inset-x-auto sm:end-6 sm:w-96 sm:max-w-[calc(100vw-2rem)] z-40 rounded-card border border-danger bg-surface p-3 shadow-lg text-xs text-danger font-medium flex flex-col gap-2">
            <span>{{ __('mobile.quantity_recovery') }}</span>
            <button type="button"
                    @click.stop="resync()"
                    :disabled="recovering || pending > 0"
                    class="min-h-11 rounded-control border border-danger bg-danger-bg px-3 py-2 font-semibold text-danger hover:opacity-90 disabled:opacity-50 cursor-pointer">
                {{ __('mobile.quantity_discard') }}
            </button>
        </div>
    </template>
</div>
