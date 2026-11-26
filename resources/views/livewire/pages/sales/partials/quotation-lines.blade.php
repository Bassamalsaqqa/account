<div class="document-entry-lines mt-4" style="--entry-columns: {{ $taxes->isNotEmpty() ? 'minmax(0, 2.4fr) minmax(64px, 1fr) minmax(160px, 1.5fr) minmax(80px, 1.2fr) minmax(80px, 1.2fr) minmax(64px, 1fr) 44px' : 'minmax(0, 2.4fr) minmax(64px, 1fr) minmax(160px, 1.5fr) minmax(80px, 1.2fr) minmax(64px, 1fr) 44px' }}">
@foreach ($lines as $index => $line)
    @php
        $selectedProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
    @endphp
    <article wire:key="quote-line-{{ $line['row_key'] }}" data-document-row="{{ $line['row_key'] }}" class="document-entry-row">
        <div class="document-entry-field min-w-0"><span class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.product') }}</span>
<div class="entry-product-fields">
            @php
                $lineProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                $lineProductLabel = $lineProduct ? $lineProduct->displayName() . ($lineProduct->sku ? ' (' . $lineProduct->sku . ')' : '') : '';
            @endphp
            <x-searchable-combobox
                id="quotation-line-{{ $line['row_key'] }}-product-id"
                type="product"
                context="quotation"
                :row-key="$line['row_key']"
                :selected-id="$line['product_id'] ?? null"
                :selected-label="$lineProductLabel"
                on-select="selectProduct"
                :extra-args="[$line['row_key']]"
                compact
                :placeholder="__('sales.product')"
                :aria-label="__('sales.product') . ' ' . ($index + 1)"
                :has-error="$errors->has('lines.'.$index.'.product_id')"
            />
            @error('lines.'.$index.'.product_id') <span role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
            <input wire:key="quote-description-{{ $line['row_key'] }}" id="quotation-line-{{ $line['row_key'] }}-description" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="quotation-line-{{ $line['row_key'] }}-description-error" @enderror aria-label="{{ __('sales.description') }} {{ $index + 1 }}" type="text"
                   wire:model="lines.{{ $index }}.item_description"
                   placeholder="{{ __('sales.description') }}..."
                   required
                   class="w-full h-11 lg:h-9 px-2 rounded-control border border-border bg-canvas text-sm text-text-primary"  :disabled="documentBusy || saving"/>
            @error('lines.'.$index.'.item_description') <span id="quotation-line-{{ $line['row_key'] }}-description-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror

            @php
                $hasLegacyDiscount = false;
                if (($line['discount_type'] ?? 'none') !== 'none' && ! empty($line['discount_value'])) {
                    try {
                        $hasLegacyDiscount = \Brick\Math\BigDecimal::of((string) $line['discount_value'])->isPositive();
                    } catch (\Throwable) {
                        $hasLegacyDiscount = false;
                    }
                }
            @endphp
            @if($hasLegacyDiscount)
                <div class="mt-1">
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 text-xs font-medium">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                        {{ __('sales.legacy_discount_badge', ['value' => $line['discount_value'] . ($line['discount_type'] === 'percent' ? '%' : ' ' . $currency_code)]) }}
                    </span>
                </div>
            @endif
</div>
</div>

        <div class="document-entry-field min-w-0"><span class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.unit') }}</span>
            @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                <select wire:key="quote-unit-{{ $line['row_key'] }}" id="quotation-line-{{ $line['row_key'] }}-unit" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="quotation-line-{{ $line['row_key'] }}-unit-error" @enderror aria-label="{{ __('sales.unit') }} {{ $index + 1 }}" wire:change="changeUnit('{{ $line['row_key'] }}', $event.target.value)"
                        class="w-full h-11 lg:h-9 px-1.5 rounded-control border border-border bg-canvas text-sm" :disabled="documentBusy || saving">
                    @foreach ($selectedProduct->productUnits as $pu)
                        <option value="{{ $pu->id }}" @selected(($line['product_unit_id'] ?? null) == $pu->id)>
                            {{ $pu->unit?->name() ?? $pu->unit?->code ?? 'Unit' }}
                        </option>
                    @endforeach
                </select>
            @error('lines.'.$index.'.product_unit_id') <span id="quotation-line-{{ $line['row_key'] }}-unit-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
            @else
                <span class="text-text-muted text-xs block pt-1.5">-</span>
            @endif
        </div>

        <div class="document-entry-field min-w-0"><span class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.quantity') }}</span>
            @php
                $selectedProductUnit = $selectedProduct && !empty($line['product_unit_id']) ? $selectedProduct->productUnits->firstWhere('id', $line['product_unit_id']) : null;
                $lineAllowsFraction = $selectedProductUnit?->unit?->allows_fraction ?? false;
            @endphp
            <x-touch-quantity-stepper
                :index="$index"
                :defer-input="true"
                :row-key="$line['row_key']"
                :allows-fraction="$lineAllowsFraction"
                :step="\App\Support\QuantityStep::forUnit($selectedProductUnit?->unit)"
                id="quote-line-{{ $line['row_key'] }}-quantity"
                :aria-label="__('sales.quantity') . ' ' . ($index + 1)"
            />
            @error('lines.'.$index.'.quantity') <span id="quote-line-{{ $line['row_key'] }}-quantity-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
            @if (!empty($line['available_quantity']))
                <div class="text-xs text-text-secondary whitespace-nowrap" dir="ltr">
                    {{ __('sales.available') }}: <span class="font-bold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($line['available_quantity']) }}</span>
                </div>
            @endif
        </div>

        <div class="document-entry-field min-w-0"><span class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.unit_price') }}</span>
            <input wire:key="quote-price-{{ $line['row_key'] }}" id="quotation-line-{{ $line['row_key'] }}-price" @error('lines.'.$index.'.unit_price') aria-invalid="true" aria-describedby="quotation-line-{{ $line['row_key'] }}-price-error" @enderror aria-label="{{ __('sales.unit_price') }} {{ $index + 1 }}" type="number" inputmode="decimal"
                   step="any"
                   wire:model="lines.{{ $index }}.unit_price"
                   wire:change="recalculate"
                   required
                   min="0"
                   dir="ltr"
                   class="w-full h-11 lg:h-9 px-2 rounded-control border border-border bg-canvas text-sm font-mono"  :disabled="documentBusy || saving"/>
            @error('lines.'.$index.'.unit_price') <span id="quotation-line-{{ $line['row_key'] }}-price-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
<x-customer-last-price :price="$lastCustomerPrices[$line['product_id'] ?? 0] ?? null" />
        </div>

        @if ($taxes->isNotEmpty())
            <div class="document-entry-field min-w-0"><span class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.tax') }}</span>
                <select wire:key="quote-tax-{{ $line['row_key'] }}" id="quotation-line-{{ $line['row_key'] }}-tax" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="quotation-line-{{ $line['row_key'] }}-tax-error" @enderror aria-label="{{ __('sales.tax') }} {{ $index + 1 }}" wire:model.change="lines.{{ $index }}.tax_rate_id"
                        wire:change="recalculate"
                        class="w-full h-11 lg:h-9 px-1.5 rounded-control border border-border bg-canvas text-sm" :disabled="documentBusy || saving">
                    <option value="">{{ __('sales.none') }} (0%)</option>
                    @foreach ($taxes as $t)
                        <option value="{{ $t->id }}">
                            {{ $t->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($t->rate) }}%) — {{ __('refinement.tax_'.$t->calculation) }}
                        </option>
                    @endforeach
                </select>
            @error('lines.'.$index.'.tax_rate_id') <span id="quotation-line-{{ $line['row_key'] }}-tax-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
            </div>
        @endif

        <div class="document-entry-field min-w-0" dir="ltr"><span class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.total') }}</span>
            <div class="pt-1.5">
                {{ $documentTotalsValid ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['line_total'] ?? '0', $currency_code) : '—' }}
            </div>
        </div>

        <div class="document-entry-field min-w-0">
            @if (count($lines) > 1)
                <button type="button"
                        wire:click="removeLine('{{ $line['row_key'] }}')"
                        title="{{ __('sales.remove_line') }}"
                        aria-label="{{ __('sales.remove_line') }} {{ $index + 1 }}"
                        class="min-h-11 min-w-11 lg:min-h-9 lg:min-w-9 inline-flex items-center justify-center rounded-control text-danger hover:bg-danger/10 hover:text-danger-hover transition-colors p-1 cursor-pointer" wire:loading.attr="disabled" wire:target="removeLine" :disabled="documentBusy || saving">
                    <x-icon name="trash" class="w-5 h-5" />
                </button>
            @endif
        </div>
    </article>
@endforeach
</div>
