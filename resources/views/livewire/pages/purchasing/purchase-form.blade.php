<div>
<form @submit.prevent="handleSaveDraft()"
      class="space-y-5 pb-28 md:pb-6"
      x-data="{
          saving: false, documentBusy: false, operationFailed: false,
          handleSaveDraft() {
              if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBusy($wire) || $wire.showQuickVendorModal) return;
              this.saving = true;
              window.enqueueDocumentOperation($wire, 'saveDraft').catch(() => { this.operationFailed = true; }).finally(() => { this.saving = false; });
          },
          handleAddLine() {
              if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBusy($wire) || $wire.showQuickVendorModal) return;
              window.enqueueDocumentOperation($wire, 'addLine').catch(() => { this.operationFailed = true; });
          }
      }"
      @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy"
     @keydown.window.prevent.ctrl.s="handleSaveDraft()"
      @keydown.window.prevent.meta.s="handleSaveDraft()"
      @keydown.window.prevent.alt.n="if (!$event.repeat) handleAddLine()">
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <header class="flex flex-wrap justify-between items-center gap-3">
        <div><a href="{{ route('purchases.index') }}" class="text-xs text-primary">{{ __('purchasing.purchases') }}</a><h1 class="text-2xl font-bold mt-1">{{ __($publicId ? 'purchasing.edit_purchase' : 'purchasing.new_purchase') }}</h1></div>
        <span class="text-xs font-bold bg-surface-blue text-primary px-3 py-2 rounded-control">{{ __('purchasing.draft') }}</span>
    </header>
    <p class="p-3 bg-info-bg text-info text-sm rounded-control">{{ __('purchasing.draft_notice') }}</p>
    @include('livewire.ai.unsaved-notice')
    @if($errors->any())<div role="alert" class="p-3 bg-danger-bg text-danger text-sm rounded-control"><ul class="list-disc ps-4">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($duplicateWarning)<p role="status" class="p-3 bg-warning-bg text-warning text-sm rounded-control">{{ __('purchasing.duplicate_vendor_invoice') }}</p>@endif
    @if ($receivingStock)
        <p role="status" class="text-sm text-text-secondary">{{ __('quick_product.receive_help') }}</p>
    @endif
    <section class="bg-surface border border-border rounded-card p-4 grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="sm:col-span-2">
            <div class="flex items-center justify-between mb-1.5">
                <label for="purchase-vendor" class="block text-xs font-bold">{{ __('purchasing.vendor') }}</label>
                @if ($canManageVendors)
                    <button type="button" wire:click="openQuickVendorModal" class="text-xs text-primary font-bold hover:underline cursor-pointer" aria-haspopup="dialog">
                        + {{ __('workflow.quick_vendor') }}
                    </button>
                @endif
            </div>
            <div class="flex gap-2 mb-1.5">
                <input type="text"
                       wire:model.live.debounce.300ms="vendorSearch"
                       placeholder="{{ __('workflow.search_vendors') }}"
                       class="w-full h-8 px-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            </div>
            <select id="purchase-vendor" wire:model.live="vendor_id" required class="w-full h-11 px-3 border border-border rounded-control text-sm">
                <option value="">{{ __('purchasing.select_vendor') }}</option>
                @foreach($vendors as $vendor)
                    <option value="{{ $vendor->id }}">{{ $vendor->displayName() }}</option>
                @endforeach
            </select>
        </div>
        <div class="sm:col-span-2"><label for="supplier-number" class="block text-xs font-bold mb-1.5">{{ __('purchasing.vendor_invoice_number') }}</label><input id="supplier-number" wire:model.blur="vendor_invoice_number" maxlength="128" dir="ltr" class="w-full h-11 px-3 border border-border rounded-control text-sm" /></div>
        @foreach(['purchase_date' => 'purchase_date', 'due_date' => 'due_date'] as $field => $label)<div><label for="purchase-{{ $field }}" class="block text-xs font-bold mb-1.5">{{ __('purchasing.'.$label) }}</label><input id="purchase-{{ $field }}" type="date" wire:model="{{ $field }}" class="w-full min-w-0 h-11 px-3 border border-border rounded-control text-sm" @if($field === 'purchase_date') required @endif /></div>@endforeach
        <div><label for="purchase-currency" class="block text-xs font-bold mb-1.5">{{ __('purchasing.currency') }}</label><select id="purchase-currency" wire:model.live="currency_code" class="w-full h-11 px-3 border border-border rounded-control text-sm">@foreach($currencies as $currency)<option value="{{ $currency->currency_code }}">{{ $currency->currency_code }}</option>@endforeach</select></div>
        <div><label for="purchase-rate" class="block text-xs font-bold mb-1.5">{{ __('purchasing.exchange_rate') }}</label><input id="purchase-rate" wire:model.blur="exchange_rate" wire:change="updatedExchangeRate" inputmode="decimal" dir="ltr" class="w-full h-11 px-3 border border-border rounded-control text-sm font-mono" /></div>
        <div class="sm:col-span-2"><label for="purchase-warehouse" class="block text-xs font-bold mb-1.5">{{ __('purchasing.receiving_warehouse') }}</label><select id="purchase-warehouse" wire:model="warehouse_id" required class="w-full h-11 px-3 border border-border rounded-control text-sm"><option value="">{{ __('purchasing.select_warehouse') }}</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}">{{ $warehouse->displayName() }}</option>@endforeach</select></div>
        <div class="sm:col-span-2"><label for="purchase-locale" class="block text-xs font-bold mb-1.5">{{ __('purchasing.document_language') }}</label><select id="purchase-locale" wire:model="document_locale" class="w-full h-11 px-3 border border-border rounded-control text-sm">@foreach($languages as $language)<option value="{{ $language->locale }}">{{ __('purchasing.locale_'.$language->locale) }}</option>@endforeach</select></div>
    </section>

    <!-- Quick Barcode Scanner & Product Search -->
    <div class="bg-surface border border-border rounded-card p-4 grid gap-4 md:grid-cols-2">
        @include('livewire.partials.barcode-entry', ['prefix' => 'purchase'])
        <div>
            <label for="purchase-product-search" class="block text-sm font-bold mb-1">{{ __('purchasing.search_products') }}</label>
            <input id="purchase-product-search" type="search" maxlength="160" wire:model.live.debounce.300ms="productSearch" x-on:keydown.enter.prevent placeholder="{{ __('purchasing.search_products') }}" aria-describedby="purchase-product-search-help" class="w-full h-11 px-3 border border-border bg-canvas rounded-control text-sm" />
            @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="mt-2 min-h-11 px-3 py-2 rounded-control border border-border text-sm font-bold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    {{ __('quick_product.create') }}
                </button>
            @endif
            <p id="purchase-product-search-help" class="mt-1 text-xs text-text-secondary">{{ __('purchasing.product_search_help') }}</p>
            <p wire:loading wire:target="productSearch" role="status" class="mt-1 text-xs text-text-secondary">{{ __('purchasing.products_searching') }}</p>
            @if($products->isEmpty())<p role="status" class="mt-1 text-sm text-text-secondary">{{ __('purchasing.no_matching_products') }}</p>@endif
        </div>
    </div>

    <section class="space-y-3">
        <div class="flex flex-wrap justify-between items-center gap-3">
            <h2 class="font-bold">{{ __('purchasing.purchase_lines') }}</h2>
            <button type="button" @click="handleAddLine()" class="h-11 px-3 text-primary border border-border rounded-control text-sm font-bold inline-flex items-center gap-1.5 cursor-pointer">
                <span>{{ __('purchasing.add_line') }}</span>
                <kbd class="px-1 py-0.5 text-[10px] font-mono rounded border border-border bg-surface-soft text-text-secondary leading-none">Alt+N</kbd>
            </button>
        </div>
        @foreach($lines as $index => $line)
            <article wire:key="purchase-line-{{ $index }}-{{ $line['product_id'] ?? 'new' }}" class="bg-surface border border-border rounded-card p-4 space-y-3">
                <div class="flex justify-between items-center gap-2"><h3 class="text-xs font-bold">{{ __('purchasing.line') }} <bdi>{{ $index + 1 }}</bdi></h3><button type="button" wire:click="removeLine({{ $index }})" class="min-h-[44px] px-3 text-danger text-xs font-bold cursor-pointer hover:bg-danger/10 rounded-control">{{ __('purchasing.remove_line') }}</button></div>
                <div class="grid grid-cols-2 lg:grid-cols-12 gap-3">
                    <div class="col-span-2 lg:col-span-4"><label for="line-product-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.product') }}</label><select id="line-product-{{ $index }}" wire:change="selectProduct({{ $index }}, $event.target.value)" class="w-full h-11 px-2 border border-border rounded-control text-sm"><option value="">{{ __('purchasing.select_product') }}</option>
                        @if(!empty($line['product_id']) && !$products->contains('id', $line['product_id']))<option selected value="{{ $line['product_id'] }}">{{ $line['item_description'] }}</option>@endif
                        @foreach($products as $product)<option value="{{ $product->id }}" @selected(($line['product_id'] ?? null) == $product->id)>{{ $product->displayName() }} {{ $product->sku }}</option>@endforeach
                    </select></div>
                    <div class="lg:col-span-2"><label for="line-unit-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.unit') }}</label><select id="line-unit-{{ $index }}" wire:change="changeUnit({{ $index }}, $event.target.value)" class="w-full h-11 px-2 border border-border rounded-control text-sm" @disabled(empty($line['product_id']))><option value="">{{ __('purchasing.select_unit') }}</option>@foreach($unitOptions[$index] ?? [] as $unit)<option value="{{ $unit->id }}" @selected(($line['product_unit_id'] ?? null) == $unit->id)>{{ $unit->unit->name() }}</option>@endforeach</select></div>
                    <div class="lg:col-span-2">
                        <label for="line-quantity-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.quantity') }}</label>
                        @php
                            $selectedUnit = collect($unitOptions[$index] ?? [])->firstWhere('id', $line['product_unit_id'] ?? null);
                            $lineAllowsFraction = $selectedUnit?->unit?->allows_fraction ?? false;
                        @endphp
                        <x-touch-quantity-stepper
                            :index="$index"
                            :allows-fraction="$lineAllowsFraction"
                            id="line-quantity-{{ $index }}"
                            :aria-label="__('purchasing.quantity') . ' ' . ($index + 1)"
                        />
                        @error('lines.'.$index.'.quantity') <span id="line-quantity-{{ $index }}-error" role="alert" class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="lg:col-span-2">
                        <label for="line-unit_cost-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.unit_cost') }}</label>
                        <input id="line-unit_cost-{{ $index }}" wire:model.blur="lines.{{ $index }}.unit_cost" wire:change="recalculate" inputmode="decimal" dir="ltr" class="w-full min-w-0 h-11 px-2 border border-border rounded-control text-sm font-mono" />
                            @if(isset($lastPriceHints[$index]))
                                <p class="text-[11px] text-text-muted mt-1 leading-tight" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
                                    @php
                                        $hintPrice = (string) $lastPriceHints[$index]['unit_cost'];
                                        $hintTrimmed = strpos($hintPrice, '.') !== false ? rtrim(rtrim($hintPrice, '0'), '.') : $hintPrice;
                                        $hintParts = explode('.', $hintTrimmed);
                                        $hintDecimals = isset($hintParts[1]) ? strlen($hintParts[1]) : 0;
                                        $formattedHintPrice = $hintDecimals > 2 ? $hintTrimmed : \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($hintPrice, $lastPriceHints[$index]['currency_code']);
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
                    <div class="col-span-2 lg:col-span-2"><span class="block text-xs text-text-secondary mb-1">{{ __('purchasing.line_total') }} <span class="text-[10px] font-normal text-text-muted">({{ __('workflow.preview') }})</span></span><output class="block h-11 px-2 py-3 text-sm font-bold bg-surface-soft rounded-control"><bdi>{{ isset($line['preview_total']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['preview_total'], $currency_code) : '—' }} {{ $currency_code }}</bdi></output></div>
                </div>
                <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                    <div><label for="line-description-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.description') }}</label><input id="line-description-{{ $index }}" wire:model="lines.{{ $index }}.item_description" maxlength="500" class="w-full h-11 px-2 border border-border rounded-control text-sm" /></div>
                    <div><label for="line-discount-type-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.discount') }}</label><select id="line-discount-type-{{ $index }}" wire:model.live="lines.{{ $index }}.discount_type" class="w-full h-11 px-2 border border-border rounded-control text-sm">@foreach(['none', 'percent', 'fixed'] as $type)<option value="{{ $type }}">{{ __('purchasing.discount_'.$type) }}</option>@endforeach</select></div>
                    <div><label for="line-discount-value-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.discount_value') }}</label><input id="line-discount-value-{{ $index }}" wire:model.blur="lines.{{ $index }}.discount_value" wire:change="recalculate" dir="ltr" inputmode="decimal" class="w-full h-11 px-2 border border-border rounded-control text-sm font-mono" /></div>
                    @if($taxes->isNotEmpty())<div><label for="line-tax-{{ $index }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.tax') }}</label><select id="line-tax-{{ $index }}" wire:model.live="lines.{{ $index }}.tax_rate_id" class="w-full h-11 px-2 border border-border rounded-control text-sm"><option value="">{{ __('purchasing.no_tax') }}</option>@foreach($taxes as $tax)<option value="{{ $tax->id }}">{{ $tax->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($tax->rate, 'ILS') }}%)</option>@endforeach</select></div>@endif
                </div>
                @if($expiryLines[$index] ?? false)
                    <div class="border-t border-border pt-3 space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2"><div><h4 class="text-xs font-bold">{{ __('purchasing.receiving_intent') }}</h4><p class="text-xs text-text-secondary mt-1">{{ __('purchasing.partial_lot_hint') }}</p></div><button type="button" wire:click="addLot({{ $index }})" class="h-10 px-3 border border-border rounded-control text-primary text-xs">{{ __('purchasing.add_lot') }}</button></div>
                        @foreach($line['lots'] ?? [] as $lotIndex => $lot)
                            <div wire:key="intent-{{ $index }}-{{ $lotIndex }}" class="grid grid-cols-2 lg:grid-cols-4 gap-3 bg-surface-soft rounded-control p-3">
                                @foreach(['lot_number' => 'text', 'expiry_date' => 'date', 'quantity' => 'text'] as $field => $type)<div><label for="lot-{{ $index }}-{{ $lotIndex }}-{{ $field }}" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.'.($field === 'quantity' ? 'allocated_quantity' : $field)) }}</label><input id="lot-{{ $index }}-{{ $lotIndex }}-{{ $field }}" type="{{ $type }}" wire:model="lines.{{ $index }}.lots.{{ $lotIndex }}.{{ $field }}" dir="ltr" class="w-full min-w-0 h-11 px-2 border border-border bg-surface rounded-control text-sm" /></div>@endforeach
                                <button type="button" wire:click="removeLot({{ $index }}, {{ $lotIndex }})" class="self-end h-11 px-3 text-danger text-xs">{{ __('purchasing.remove_lot') }}</button>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        @endforeach
    </section>
    <section class="bg-surface border border-border rounded-card p-4 space-y-2">
        <div class="flex items-center justify-between pb-1 border-b border-border/50">
            <span class="text-xs font-bold text-text-secondary uppercase tracking-wider">{{ __('purchasing.purchase_summary') ?? __('sales.invoice_details') }} <span class="text-[10px] font-normal text-text-muted">({{ __('workflow.preview') }})</span></span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach(['subtotal', 'discount', 'tax', 'total'] as $field)
                <div>
                    <span class="text-xs text-text-secondary">{{ __('purchasing.'.$field) }}</span>
                    <p class="font-bold mt-1"><bdi>{{ isset($totals[$field]) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($totals[$field], $currency_code) : '—' }} {{ $currency_code }}</bdi></p>
                </div>
            @endforeach
        </div>
    </section>
    <div><label for="purchase-notes" class="block text-xs font-bold mb-1.5">{{ __('purchasing.notes') }}</label><textarea id="purchase-notes" wire:model="notes" rows="3" class="w-full border border-border rounded-control bg-surface p-3 text-sm"></textarea></div>
    <footer class="flex flex-wrap gap-3">
        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="h-11 px-5 bg-primary text-white font-bold text-sm rounded-control inline-flex items-center gap-1.5 cursor-pointer">
            <span>{{ __('purchasing.save_draft') }}</span>
            <kbd class="px-1 py-0.5 text-[10px] font-mono rounded bg-white/20 text-white leading-none">Ctrl+S</kbd>
        </button>
        <a href="{{ route('purchases.index') }}" class="inline-flex items-center h-11 px-4 border border-border rounded-control text-sm">{{ __('purchasing.cancel') }}</a>
    </footer>
    <div class="mobile-document-actions md:hidden fixed inset-x-0 z-30 bg-white border-t border-border px-3 py-2" style="bottom: var(--mobile-nav-height)">
        <div class="flex items-center justify-between gap-2 max-w-lg mx-auto">
            <div class="min-w-0"><span class="text-xs text-text-secondary block">{{ __('mobile.grand_total') }}</span><bdi class="text-sm font-bold text-primary">{{ isset($totals['total']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($totals['total'], $currency_code) : '—' }} {{ $currency_code }}</bdi></div>
            <div class="flex gap-1">
                <button type="button" @click="$dispatch('mobile-camera-open')" :disabled="documentBusy || saving" class="min-h-11 min-w-11 rounded-control bg-surface-soft px-2" aria-label="{{ __('mobile.scan') }}"><x-icon name="barcode" /></button>
                <button type="button" @click="handleAddLine()" :disabled="documentBusy || saving" class="min-h-11 min-w-11 rounded-control bg-surface-soft px-2" aria-label="{{ __('mobile.add_item') }}"><x-icon name="plus" /></button>
                <button type="button" @click="handleSaveDraft()" :disabled="documentBusy || saving" class="min-h-11 rounded-control bg-primary text-white text-xs font-bold px-3">{{ __('mobile.save_draft') }}</button>
            </div>
        </div>
    </div>
</form>

    @if ($showQuickVendorModal)
        <div x-data="{
                triggerEl: null,
                init() {
                    this.triggerEl = document.activeElement;
                    this.$nextTick(() => { this.$refs.firstInput?.focus(); });
                },
                destroy() {
                    if (this.triggerEl && typeof this.triggerEl.focus === 'function') {
                        this.triggerEl.focus();
                    }
                }
             }"
             x-trap.inert.noscroll="true"
             @keydown.escape.window.stop.prevent="$wire.closeQuickVendorModal()"
             @keydown.enter.stop.prevent="saveQuickVendor"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs"
             role="dialog"
             aria-modal="true"
             aria-labelledby="quick-vendor-modal-title">
            <div class="bg-surface border border-border rounded-card p-6 w-full max-w-md shadow-lg space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 id="quick-vendor-modal-title" class="text-base font-bold text-text-primary">{{ __('workflow.quick_vendor') }}</h3>
                    <button type="button" wire:click="closeQuickVendorModal" class="text-text-muted hover:text-text-primary text-sm font-bold cursor-pointer">✕</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label for="purchase-quick-vendor-name-ar" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.vendor_name_ar') }} <span class="text-danger">*</span></label>
                        <input x-ref="firstInput" id="purchase-quick-vendor-name-ar" type="text" wire:model="quick_vendor_name_ar" required maxlength="255" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                        @error('quick_vendor_name_ar') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="purchase-quick-vendor-name-en" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.vendor_name_en') }}</label>
                        <input id="purchase-quick-vendor-name-en" type="text" wire:model="quick_vendor_name_en" maxlength="255" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                        @error('quick_vendor_name_en') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="purchase-quick-vendor-phone" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.phone') }}</label>
                            <input id="purchase-quick-vendor-phone" type="text" wire:model="quick_vendor_phone" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                            @error('quick_vendor_phone') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="purchase-quick-vendor-code" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.code') }}</label>
                            <input id="purchase-quick-vendor-code" type="text" wire:model="quick_vendor_code" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                            @error('quick_vendor_code') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button type="button" wire:click="closeQuickVendorModal" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft cursor-pointer">{{ __('workflow.cancel') }}</button>
                    <button type="button" wire:click="saveQuickVendor" wire:loading.attr="disabled" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover shadow-xs cursor-pointer">
                        <span wire:loading.remove wire:target="saveQuickVendor">{{ __('workflow.save_vendor') }}</span>
                        <span wire:loading wire:target="saveQuickVendor">...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
    @include('livewire.partials.quick-product-modal')
</div>
