<div data-entry-form>
<form novalidate @submit.prevent="handleSaveDraft()"
      class="space-y-5 pb-44 md:pb-6"
      x-data="{
          saving: false, documentBusy: false, operationFailed: false,
          handleSaveDraft() {
              if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBlocked($wire) || $wire.showQuickVendorModal) return;
              this.saving = true;
              window.enqueueDocumentOperation($wire, 'saveDraft').catch(() => { this.operationFailed = true; }).finally(() => { this.saving = false; });
          },
          handleAddLine() {
              if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBlocked($wire) || $wire.showQuickVendorModal) return;
              window.enqueueDocumentOperation($wire, 'addLine').catch(() => { this.operationFailed = true; });
          }
      }"
      @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy"
     @keydown.window.prevent.ctrl.s="handleSaveDraft()"
      @keydown.window.prevent.meta.s="handleSaveDraft()"
      @keydown.window.prevent.alt.n="if (!$event.repeat) handleAddLine()">
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <header class="flex flex-wrap justify-between items-center gap-3">
        <div><a href="{{ route('purchases.index') }}" class="touch-action text-sm text-primary">{{ __('purchasing.purchases') }}</a><h1 class="text-2xl font-bold mt-1">{{ __($publicId ? 'purchasing.edit_purchase' : 'purchasing.new_purchase') }}</h1></div>
        <span class="text-xs font-bold bg-surface-blue text-primary px-3 py-2 rounded-control">{{ __('purchasing.draft') }}</span>
    </header>
    <p class="p-3 bg-info-bg text-info text-sm rounded-control">{{ __('purchasing.draft_notice') }}</p>
    @include('livewire.ai.unsaved-notice')
    @if($taxes->isNotEmpty())<p class="rounded-control border border-border bg-surface-soft p-3 text-sm text-text-secondary">{{ __('refinement.tax_help') }}</p>@endif
    <x-form-attention />
    @if($duplicateWarning)<p role="status" class="p-3 bg-warning-bg text-warning text-sm rounded-control">{{ __('purchasing.duplicate_vendor_invoice') }}</p>@endif
    @if ($receivingStock)
        <p role="status" class="text-sm text-text-secondary">{{ __('quick_product.receive_help') }}</p>
    @endif
    <section class="bg-surface border border-border rounded-card p-4 space-y-4">
        <div class="entry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="lg:col-span-2">
            <div class="flex items-center justify-between mb-1.5">
                <label for="purchase-vendor" class="block text-sm font-bold">{{ __('purchasing.vendor') }}</label>

            </div>
            @php
                $selectedVendor = $vendor_id ? $vendors->firstWhere('id', $vendor_id) : null;
                $vendorLabel = $selectedVendor ? $selectedVendor->displayName() : '';
            @endphp
            <div class="party-picker flex gap-2 items-center">
                <x-searchable-combobox
                    id="purchase-vendor"
                    type="vendor"
                    context="purchasing"
                    wire-model="vendor_id"
                    :selected-id="$vendor_id"
                    :selected-label="$vendorLabel"
                    :placeholder="__('purchasing.select_vendor')"
                    :aria-label="__('purchasing.vendor')"
                    :has-error="$errors->has('vendor_id')"
                    required
                />
                @if ($canManageVendors)
                    <button type="button" wire:click="openQuickVendorModal" class="party-create-button shrink-0" aria-haspopup="dialog" wire:loading.attr="disabled" wire:target="openQuickVendorModal" :disabled="documentBusy || saving">
                        + {{ __('workflow.quick_vendor') }}
                    </button>
                @endif
            </div>
            @error('vendor_id') <span id="purchase-vendor-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>
        <div>
            <label for="purchase-purchase_date" class="block text-sm font-bold mb-1.5">{{ __('purchasing.purchase_date') }}</label>
            <input id="purchase-purchase_date" @error('purchase_date') aria-invalid="true" aria-describedby="purchase-purchase_date-error" @enderror type="date" wire:model="purchase_date" class="w-full min-w-0 h-11 px-3 border border-border rounded-control text-sm" required :disabled="documentBusy || saving"/>
            @error('purchase_date') <span id="purchase-purchase_date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>
        </div>
        <div class="border-t border-border pt-3">
            <div class="entry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 pt-3">
        <div class="sm:col-span-2">
            <label for="supplier-number" class="block text-sm font-bold mb-1.5">{{ __('purchasing.vendor_invoice_number') }}</label>
            <input id="supplier-number" @error('vendor_invoice_number') aria-invalid="true" aria-describedby="supplier-number-error" @enderror wire:model.blur="vendor_invoice_number" maxlength="128" dir="ltr" class="w-full h-11 px-3 border border-border rounded-control text-sm" :disabled="documentBusy || saving"/>
            @error('vendor_invoice_number') <span id="supplier-number-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>
        <div>
            <label for="purchase-due_date" class="block text-sm font-bold mb-1.5">{{ __('purchasing.due_date') }}</label>
            <input id="purchase-due_date" @error('due_date') aria-invalid="true" aria-describedby="purchase-due_date-error" @enderror type="date" wire:model="due_date" class="w-full min-w-0 h-11 px-3 border border-border rounded-control text-sm" :disabled="documentBusy || saving"/>
            @error('due_date') <span id="purchase-due_date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>
        <div>
            <label for="purchase-currency" class="block text-sm font-bold mb-1.5">{{ __('purchasing.currency') }}</label>
            <select id="purchase-currency" @error('currency_code') aria-invalid="true" aria-describedby="purchase-currency-error" @enderror wire:model.live="currency_code" class="w-full h-11 px-3 border border-border rounded-control text-sm" :disabled="documentBusy || saving">
                @foreach($currencies as $currency)
                    <option value="{{ $currency->currency_code }}">{{ $currency->currency_code }}</option>
                @endforeach
            </select>
            @error('currency_code') <span id="purchase-currency-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>
        @if ($currency_code !== $baseCurrencyCode)
        <div>
            <label for="purchase-rate" class="block text-sm font-bold mb-1.5">{{ __('purchasing.exchange_rate') }}</label>
            <input id="purchase-rate" @error('exchange_rate') aria-invalid="true" aria-describedby="purchase-rate-error" @enderror wire:model.blur="exchange_rate" inputmode="decimal" dir="ltr" class="w-full h-11 px-3 border border-border rounded-control text-sm font-mono" :disabled="documentBusy || saving"/>
            @error('exchange_rate') <span id="purchase-rate-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>
        @endif
        <div>
            <label for="purchase-warehouse" class="block text-sm font-bold mb-1.5">{{ __('purchasing.receiving_warehouse') }}</label>
            <select id="purchase-warehouse" @error('warehouse_id') aria-invalid="true" aria-describedby="purchase-warehouse-error" @enderror wire:model="warehouse_id" required class="w-full h-11 px-3 border border-border rounded-control text-sm" :disabled="documentBusy || saving">
                <option value="">{{ __('purchasing.select_warehouse') }}</option>
                @foreach($warehouses as $warehouse)
                    <option value="{{ $warehouse->id }}">{{ $warehouse->displayName() }}</option>
                @endforeach
            </select>
            @error('warehouse_id') <span id="purchase-warehouse-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
        </div>
        <div>
            <label for="purchase-locale" class="block text-sm font-bold mb-1.5">{{ __('purchasing.document_language') }}</label>
            <select id="purchase-locale" wire:model="document_locale" class="w-full h-11 px-3 border border-border rounded-control text-sm" :disabled="documentBusy || saving">
                @foreach($languages as $language)
                    <option value="{{ $language->locale }}">{{ __('purchasing.locale_'.$language->locale) }}</option>
                @endforeach
            </select>
        </div>
            </div>
        </div>
    </section>

    <!-- Quick Barcode Scanner & Product Search -->
    <div class="bg-surface border border-border rounded-card p-4 grid gap-4 md:grid-cols-2">
        @include('livewire.partials.barcode-entry', ['prefix' => 'purchase'])
        <div>
            <label for="purchase-product-search" class="block text-sm font-bold mb-1">{{ __('purchasing.search_products') }}</label>
            <div class="flex gap-2 items-center">
                <input id="purchase-product-search" type="search" maxlength="160" wire:model.live.debounce.300ms="productSearch" x-on:keydown.enter.prevent placeholder="{{ __('purchasing.search_products') }}" class="flex-1 min-w-0 h-11 px-3 border border-border bg-canvas rounded-control text-sm"  :disabled="documentBusy || saving"/>
                @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                    <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="shrink-0 min-h-11 px-3 py-2 rounded-control border border-border text-sm font-bold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer" wire:loading.attr="disabled" wire:target="openQuickProductModal" :disabled="documentBusy || saving">
                        + {{ __('quick_product.create') }}
                    </button>
                @endif
            </div>
            <p wire:loading wire:target="productSearch" role="status" class="mt-1 text-xs text-text-secondary">{{ __('purchasing.products_searching') }}</p>
            @if($products->isEmpty())<p role="status" class="mt-1 text-sm text-text-secondary">{{ __('purchasing.no_matching_products') }}</p>@endif
        </div>
    </div>

    <section class="space-y-3">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 class="font-bold">{{ __('purchasing.purchase_lines') }}</h2>
            <button type="button" @click="handleAddLine()" class="h-11 px-3 text-primary border border-border rounded-control text-sm font-bold inline-flex items-center gap-1.5 cursor-pointer">
                <span>{{ __('purchasing.add_line') }}</span>
                <kbd class="px-1 py-0.5 text-xs font-mono rounded border border-border bg-surface-soft text-text-secondary leading-none">Alt+N</kbd>
            </button>
        </div>
        @foreach($lines as $index => $line)
            <article wire:key="purchase-line-{{ $index }}-{{ $line['product_id'] ?? 'new' }}" class="bg-surface border border-border rounded-card p-4 space-y-3">
                <div class="flex justify-between items-center gap-2"><h3 class="text-xs font-bold">{{ __('purchasing.line') }} <bdi>{{ $index + 1 }}</bdi></h3><button type="button" wire:click="removeLine({{ $index }})" class="min-h-[44px] px-3 text-danger text-sm font-bold cursor-pointer hover:bg-danger/10 rounded-control" wire:loading.attr="disabled" wire:target="removeLine" :disabled="documentBusy || saving">{{ __('purchasing.remove_line') }}</button></div>
                <div class="grid grid-cols-2 lg:grid-cols-12 gap-3">
                    <div class="col-span-2 lg:col-span-4">
                        <label for="line-product-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.product') }}</label>
                        @php
                            $lineProd = !empty($line['product_id']) ? ($selectedProducts->get($line['product_id']) ?? $products->firstWhere('id', $line['product_id'])) : null;
                            $lineProdLabel = $lineProd ? $lineProd->displayName() . ($lineProd->sku ? ' (' . $lineProd->sku . ')' : '') : ($line['item_description'] ?? '');
                        @endphp
                        <x-searchable-combobox
                            id="line-product-{{ $index }}"
                            type="product"
                            context="purchasing"
                            :selected-id="$line['product_id'] ?? null"
                            :selected-label="$lineProdLabel"
                            on-select="selectProduct"
                            :extra-args="[$index]"
                            :placeholder="__('purchasing.select_product')"
                            :aria-label="__('purchasing.product') . ' ' . ($index + 1)"
                            :has-error="$errors->has('lines.'.$index.'.product_id')"
                        />
                        @error('lines.'.$index.'.product_id') <span id="line-product-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="lg:col-span-2">
                        <label for="line-unit-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.unit') }}</label>
                        <select id="line-unit-{{ $index }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="line-unit-{{ $index }}-error" @enderror wire:change="changeUnit({{ $index }}, $event.target.value)" class="w-full h-11 px-2 border border-border rounded-control text-sm" @disabled(empty($line['product_id'])) :disabled="documentBusy || saving">
                            <option value="">{{ __('purchasing.select_unit') }}</option>
                            @foreach($unitOptions[$index] ?? [] as $unit)
                                <option value="{{ $unit->id }}" @selected(($line['product_unit_id'] ?? null) == $unit->id)>{{ $unit->unit->name() }}</option>
                            @endforeach
                        </select>
                        @error('lines.'.$index.'.product_unit_id') <span id="line-unit-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="lg:col-span-2">
                        <label for="line-quantity-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.quantity') }}</label>
                        @php
                            $selectedUnit = collect($unitOptions[$index] ?? [])->firstWhere('id', $line['product_unit_id'] ?? null);
                            $lineAllowsFraction = $selectedUnit?->unit?->allows_fraction ?? false;
                        @endphp
                        <x-touch-quantity-stepper
                            :index="$index"
                            :allows-fraction="$lineAllowsFraction"
                            :step="\App\Support\QuantityStep::forUnit($selectedUnit?->unit)"
                            id="line-quantity-{{ $index }}"
                            :aria-label="__('purchasing.quantity') . ' ' . ($index + 1)"
                        />
                        @error('lines.'.$index.'.quantity') <span id="line-quantity-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="lg:col-span-2">
                        <label for="line-unit_cost-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.unit_cost') }}</label>
                        <input id="line-unit_cost-{{ $index }}" @error('lines.'.$index.'.unit_cost') aria-invalid="true" aria-describedby="line-unit_cost-{{ $index }}-error" @enderror wire:model.blur="lines.{{ $index }}.unit_cost" wire:change="recalculate" inputmode="decimal" dir="ltr" class="w-full min-w-0 h-11 px-2 border border-border rounded-control text-sm font-mono" :disabled="documentBusy || saving"/>
                        @error('lines.'.$index.'.unit_cost') <span id="line-unit_cost-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                        @if(isset($lastPriceHints[$index]))
                            <p class="text-xs text-text-muted mt-1 leading-tight" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
                                @php
                                    $hintPrice = (string) $lastPriceHints[$index]['unit_cost'];
                                    $hintTrimmed = strpos($hintPrice, '.') !== false ? rtrim(rtrim($hintPrice, '0'), '.') : $hintPrice;
                                    $hintParts = explode('.', $hintTrimmed);
                                    $hintDecimals = isset($hintParts[1]) ? strlen($hintParts[1]) : 0;
                                    $formattedHintPrice = $hintDecimals > 2 ? $hintTrimmed : \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($hintPrice, $lastPriceHints[$index]['currency_code']);
                                @endphp
                                {{ __('purchasing.last_purchased_hint', [
                                    'price' => $formattedHintPrice,
                                    'currency' => $lastPriceHints[$index]['currency_code'],
                                    'unit' => $lastPriceHints[$index]['unit_name'] ?: '—',
                                    'date' => $lastPriceHints[$index]['purchase_date'],
                                ]) }}
                            </p>
                        @endif
                    </div>
                    <div class="col-span-2 lg:col-span-2"><span class="block text-xs text-text-secondary mb-1">{{ __('purchasing.line_total') }} <span class="text-xs font-normal text-text-muted">({{ __('workflow.preview') }})</span></span><output class="block h-11 px-2 py-3 text-sm font-bold bg-surface-soft rounded-control"><bdi>{{ isset($line['preview_total']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['preview_total'], $currency_code) : '—' }} {{ $currency_code }}</bdi></output></div>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div>
                        <label for="line-description-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.description') }}</label>
                        <input id="line-description-{{ $index }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="line-description-{{ $index }}-error" @enderror wire:model="lines.{{ $index }}.item_description" maxlength="500" class="w-full h-11 px-2 border border-border rounded-control text-sm" :disabled="documentBusy || saving"/>
                        @error('lines.'.$index.'.item_description') <span id="line-description-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="line-discount-type-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.discount') }}</label>
                        <select id="line-discount-type-{{ $index }}" wire:model.live="lines.{{ $index }}.discount_type" class="w-full h-11 px-2 border border-border rounded-control text-sm" :disabled="documentBusy || saving">
                            @foreach(['none', 'percent', 'fixed'] as $type)
                                <option value="{{ $type }}">{{ __('purchasing.discount_'.$type) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="line-discount-value-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.discount_value') }}</label>
                        <input id="line-discount-value-{{ $index }}" @error('lines.'.$index.'.discount_value') aria-invalid="true" aria-describedby="line-discount-value-{{ $index }}-error" @enderror wire:model.blur="lines.{{ $index }}.discount_value" wire:change="recalculate" dir="ltr" inputmode="decimal" class="w-full h-11 px-2 border border-border rounded-control text-sm font-mono" :disabled="documentBusy || saving"/>
                        @error('lines.'.$index.'.discount_value') <span id="line-discount-value-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                    </div>
                    @if($taxes->isNotEmpty())
                        <div>
                            <label for="line-tax-{{ $index }}" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.tax') }}</label>
                            <select id="line-tax-{{ $index }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="line-tax-{{ $index }}-error" @enderror wire:model.live="lines.{{ $index }}.tax_rate_id" class="w-full h-11 px-2 border border-border rounded-control text-sm" :disabled="documentBusy || saving">
                                <option value="">{{ __('purchasing.no_tax') }}</option>
                                @foreach($taxes as $tax)
                                    <option value="{{ $tax->id }}">{{ $tax->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($tax->rate, 'ILS') }}%) — {{ __('refinement.tax_'.$tax->calculation) }}</option>
                                @endforeach
                            </select>
                            @error('lines.'.$index.'.tax_rate_id') <span id="line-tax-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                        </div>
                    @endif
                </div>
                @if($expiryLines[$index] ?? false)
                    <div class="border-t border-border pt-3 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <h4 class="text-xs font-bold">{{ __('purchasing.receiving_intent') }}</h4>
                                <p class="text-xs text-text-secondary mt-1">{{ __('purchasing.partial_lot_hint') }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                @if(! ($expandedLotCustomization[$index] ?? false))
                                    <button type="button" wire:click="customizeLots({{ $index }})" class="min-h-[44px] px-3 border border-border rounded-control text-primary text-sm font-medium cursor-pointer hover:bg-surface-soft" wire:loading.attr="disabled" wire:target="customizeLots" :disabled="documentBusy || saving">
                                        {{ __('purchase_settlement.customize_lots') }}
                                    </button>
                                @else
                                    <button type="button" wire:click="resetToAutoLot({{ $index }})" class="min-h-[44px] px-3 border border-border rounded-control text-text-secondary text-sm cursor-pointer hover:bg-surface-soft" wire:loading.attr="disabled" wire:target="resetToAutoLot" :disabled="documentBusy || saving">
                                        {{ __('purchase_settlement.collapse_lots') }}
                                    </button>
                                    <button type="button" wire:click="addLot({{ $index }})" class="min-h-[44px] px-3 border border-border rounded-control text-primary text-sm font-bold cursor-pointer hover:bg-surface-soft" wire:loading.attr="disabled" wire:target="addLot" :disabled="documentBusy || saving">
                                        {{ __('purchasing.add_lot') }}
                                    </button>
                                @endif
                            </div>
                        </div>

                        @if(! ($expandedLotCustomization[$index] ?? false))
                            <div class="bg-surface-soft rounded-control p-3 flex items-center justify-between gap-2 text-xs text-text-secondary">
                                <span class="flex items-center gap-2">
                                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-emerald-500 shrink-0"></span>
                                    <span>{{ __('purchase_settlement.auto_lot_notice') }} (<strong><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }}</bdi></strong> {{ $selectedUnit?->unit?->name() ?? '' }})</span>
                                </span>
                            </div>
                        @else
                            @foreach($line['lots'] ?? [] as $lotIndex => $lot)
                                <div wire:key="intent-{{ $index }}-{{ $lotIndex }}" class="grid grid-cols-2 lg:grid-cols-4 gap-3 bg-surface-soft rounded-control p-3">
                                    <div>
                                        <label for="lot-{{ $index }}-{{ $lotIndex }}-lot_number" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.lot_number') }}</label>
                                        <input id="lot-{{ $index }}-{{ $lotIndex }}-lot_number" @error('lines.'.$index.'.lots.'.$lotIndex.'.lot_number') aria-invalid="true" aria-describedby="lot-{{ $index }}-{{ $lotIndex }}-lot_number-error" @enderror type="text" wire:model="lines.{{ $index }}.lots.{{ $lotIndex }}.lot_number" dir="ltr" class="w-full min-w-0 h-11 px-2 border border-border bg-surface rounded-control text-sm font-mono" :disabled="documentBusy || saving"/>
                                        @error('lines.'.$index.'.lots.'.$lotIndex.'.lot_number') <span id="lot-{{ $index }}-{{ $lotIndex }}-lot_number-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label for="lot-{{ $index }}-{{ $lotIndex }}-expiry_date" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.expiry_date') }}</label>
                                        <input id="lot-{{ $index }}-{{ $lotIndex }}-expiry_date" @error('lines.'.$index.'.lots.'.$lotIndex.'.expiry_date') aria-invalid="true" aria-describedby="lot-{{ $index }}-{{ $lotIndex }}-expiry_date-error" @enderror type="date" wire:model="lines.{{ $index }}.lots.{{ $lotIndex }}.expiry_date" class="w-full min-w-0 h-11 px-2 border border-border bg-surface rounded-control text-sm" :disabled="documentBusy || saving"/>
                                        @error('lines.'.$index.'.lots.'.$lotIndex.'.expiry_date') <span id="lot-{{ $index }}-{{ $lotIndex }}-expiry_date-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    <div>
                                        <label for="lot-{{ $index }}-{{ $lotIndex }}-quantity" class="block text-sm text-text-secondary mb-1">{{ __('purchasing.allocated_quantity') }}</label>
                                        <input id="lot-{{ $index }}-{{ $lotIndex }}-quantity" @error('lines.'.$index.'.lots.'.$lotIndex.'.quantity') aria-invalid="true" aria-describedby="lot-{{ $index }}-{{ $lotIndex }}-quantity-error" @enderror type="text" inputmode="decimal" wire:model="lines.{{ $index }}.lots.{{ $lotIndex }}.quantity" dir="ltr" class="w-full min-w-0 h-11 px-2 border border-border bg-surface rounded-control text-sm font-mono" :disabled="documentBusy || saving"/>
                                        @error('lines.'.$index.'.lots.'.$lotIndex.'.quantity') <span id="lot-{{ $index }}-{{ $lotIndex }}-quantity-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                    </div>
                                    <button type="button" wire:click="removeLot({{ $index }}, {{ $lotIndex }})" class="self-end min-h-[44px] px-3 text-danger text-sm font-semibold cursor-pointer hover:bg-danger/10 rounded-control" wire:loading.attr="disabled" wire:target="removeLot" :disabled="documentBusy || saving">{{ __('purchasing.remove_lot') }}</button>
                                </div>
                            @endforeach
                        @endif
                    </div>
                @endif
            </article>
        @endforeach
    </section>
    <section class="bg-surface border border-border rounded-card p-4 space-y-2">
        <div class="flex items-center justify-between pb-1 border-b border-border/50">
            <span class="text-xs font-bold text-text-secondary uppercase tracking-wider">{{ __('purchasing.purchase_summary') ?? __('sales.invoice_details') }} <span class="text-xs font-normal text-text-muted">({{ __('workflow.preview') }})</span></span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @if (! $documentTotalsValid)<p role="status" class="text-sm text-warning">{{ __('camera.totals_unavailable') }}</p>@endif
            @foreach(['subtotal', 'discount', 'tax', 'total'] as $field)
                <div>
                    <span class="text-xs text-text-secondary">{{ __('purchasing.'.$field) }}</span>
                    <p class="font-bold mt-1"><bdi @if($documentTotalsValid && $field === 'total') data-document-total @endif>{{ $documentTotalsValid && isset($totals[$field]) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($totals[$field], $currency_code) : '—' }} {{ $currency_code }}</bdi></p>
                </div>
            @endforeach
        </div>
    </section>

    @if ($previewError)
        <div role="alert" class="p-3 text-sm text-danger bg-danger/10 border border-danger/20 rounded-control">
            {{ $previewError }}
        </div>
    @endif

    @include('livewire.partials.immediate-purchase-settlement')

    @if ($showPreview && ! empty($previewData['settlement']))
        @include('livewire.partials.immediate-purchase-review', ['review' => $previewData['settlement']])
    @endif

    <div><label for="purchase-notes" class="block text-sm font-bold mb-1.5">{{ __('purchasing.notes') }}</label><textarea id="purchase-notes" wire:model="notes" rows="3" class="w-full border border-border rounded-control bg-surface p-3 text-sm" :disabled="documentBusy || saving"></textarea></div>
    <footer class="flex flex-wrap gap-3">
        @can('purchasing.purchase.post')
            <button type="button" @click="if (!saving && !window.isDocumentBlocked($wire)) { saving = true; window.enqueueDocumentOperation($wire, 'saveAndConfirm').catch(() => { operationFailed = true; }).finally(() => saving = false); }" :disabled="saving || documentBusy" class="min-h-11 px-4 bg-primary text-white font-semibold text-sm rounded-control">{{ __('purchasing.post_purchase') }}</button>
        @endcan
        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="h-11 px-5 bg-primary text-white font-bold text-sm rounded-control inline-flex items-center gap-1.5 cursor-pointer">
            <span>{{ __('purchasing.save_draft') }}</span>
            <kbd class="px-1 py-0.5 text-xs font-mono rounded bg-surface/20 text-white leading-none">Ctrl+S</kbd>
        </button>
        <a href="{{ route('purchases.index') }}" class="touch-action inline-flex items-center h-11 px-4 border border-border rounded-control text-sm">{{ __('purchasing.cancel') }}</a>
    </footer>
    @php($canConfirmPurchase = auth()->user()->hasPermissionTo('purchasing.purchase.post'))
    <x-document-mobile-actions :total="$totals['total'] ?? '0'" :currency="$currency_code" :valid="$documentTotalsValid" :can-confirm="$canConfirmPurchase" :confirm-label="__('purchasing.post_purchase')" />

</form>

    @include('livewire.partials.quick-vendor-modal')
    @include('livewire.partials.quick-product-modal')
</div>
