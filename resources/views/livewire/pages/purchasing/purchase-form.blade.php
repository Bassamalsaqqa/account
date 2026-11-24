<div data-entry-form>
<form novalidate @submit.prevent="handleSaveDraft()"
      class="space-y-4 pb-44 md:pb-6"
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

    <!-- Header / Breadcrumbs -->
    <header class="flex flex-wrap justify-between items-center gap-3">
        <div>
            <a href="{{ route('purchases.index') }}" class="touch-action text-sm text-primary font-medium hover:underline">{{ __('purchasing.purchases') }}</a>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight mt-0.5">{{ __($publicId ? 'purchasing.edit_purchase' : 'purchasing.new_purchase') }}</h1>
        </div>
        <span class="text-xs font-bold bg-surface-blue text-primary px-3 py-1.5 rounded-control">{{ __('purchasing.draft') }}</span>
    </header>

    <p class="p-3 bg-info-bg text-info text-xs sm:text-sm rounded-control">{{ __('purchasing.draft_notice') }}</p>
    @include('livewire.ai.unsaved-notice')
    <x-form-attention />
    @if($duplicateWarning)<p role="status" class="p-3 bg-warning-bg text-warning text-sm rounded-control">{{ __('purchasing.duplicate_vendor_invoice') }}</p>@endif
    @if ($receivingStock)
        <p role="status" class="text-sm text-text-secondary">{{ __('quick_product.receive_help') }}</p>
    @endif

    <!-- 1. Context Ribbon & Core Primary Inputs -->
    <section class="bg-surface border border-border rounded-card p-3 sm:p-4 space-y-3">
        <!-- Compact Context Ribbon Strip -->
        <div class="flex flex-wrap items-center justify-between gap-2.5 border-b border-border/60 pb-3">
            <div class="flex flex-wrap items-center gap-2 sm:gap-3 text-xs">
                <div class="flex items-center gap-1.5 bg-surface-soft px-2.5 py-1 rounded-control border border-border font-mono text-text-secondary">
                    <span class="font-sans font-medium text-text-muted">{{ __('purchasing.purchase_date') }}:</span>
                    <span class="font-semibold text-text-primary">{{ $purchase_date }}</span>
                </div>
                <div class="flex items-center gap-1.5 bg-surface-soft px-2.5 py-1 rounded-control border border-border font-mono text-text-secondary">
                    <span class="font-sans font-medium text-text-muted">{{ __('purchasing.currency') }}:</span>
                    <span class="font-semibold text-text-primary">{{ $currency_code }}</span>
                </div>
                @php
                    $resolvedWh = $warehouse_id ? $warehouses->firstWhere('id', $warehouse_id) : null;
                @endphp
                <div class="flex items-center gap-1.5 bg-surface-soft px-2.5 py-1 rounded-control border border-border text-xs text-text-secondary">
                    <span class="w-1.5 h-1.5 rounded-full {{ $resolvedWh ? 'bg-emerald-500' : 'bg-amber-500' }}"></span>
                    <span class="text-text-muted">{{ __('purchasing.resolved_warehouse') }}:</span>
                    <span class="font-semibold text-text-primary">{{ $resolvedWh ? $resolvedWh->displayName() : __('purchasing.select_warehouse') }}</span>
                </div>
            </div>
            <button type="button" wire:click="toggleMoreDetails" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-surface-soft hover:bg-surface-soft/80 text-text-primary rounded-control font-medium text-xs border border-border transition-colors cursor-pointer">
                <x-icon name="tune" class="w-3.5 h-3.5 text-text-secondary" />
                <span>{{ $showMoreDetails ? __('purchasing.hide_more_details') : __('purchasing.more_details') }}</span>
                <x-icon name="chevron-down" class="w-3.5 h-3.5 text-text-secondary transition-transform duration-200 {{ $showMoreDetails ? 'rotate-180' : '' }}" />
            </button>
        </div>

        <!-- Primary Core Inputs Row -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 items-end">
            <!-- Supplier Combobox (Span 6) -->
            <div class="sm:col-span-2 lg:col-span-6 space-y-1">
                <div class="flex items-center justify-between mb-1">
                    <label for="purchase-vendor" class="block text-xs font-bold text-text-primary">
                        {{ __('purchasing.vendor') }} <span class="text-danger">*</span>
                    </label>
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
                        <button type="button" wire:click="openQuickVendorModal" class="party-create-button shrink-0 min-h-11 px-3 py-2 bg-surface-blue hover:bg-surface-blue/80 text-primary border border-primary/20 rounded-control font-medium text-xs flex items-center gap-1 transition-colors cursor-pointer" aria-haspopup="dialog" wire:loading.attr="disabled" wire:target="openQuickVendorModal" :disabled="documentBusy || saving">
                            + {{ __('workflow.quick_vendor') }}
                        </button>
                    @endif
                </div>
                @error('vendor_id') <span id="purchase-vendor-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Supplier Bill # (Span 3) -->
            <div class="lg:col-span-3 space-y-1">
                <label for="supplier-number" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('purchasing.vendor_invoice_number') }}
                </label>
                <input id="supplier-number" @error('vendor_invoice_number') aria-invalid="true" aria-describedby="supplier-number-error" @enderror wire:model.blur="vendor_invoice_number" maxlength="128" dir="ltr" class="w-full h-11 px-3 border border-border bg-surface rounded-control text-xs font-mono" placeholder="PUR-..." :disabled="documentBusy || saving"/>
                @error('vendor_invoice_number') <span id="supplier-number-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Due Date (Span 3) -->
            <div class="lg:col-span-3 space-y-1">
                <label for="purchase-due_date" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('purchasing.due_date') }}
                </label>
                <input id="purchase-due_date" @error('due_date') aria-invalid="true" aria-describedby="purchase-due_date-error" @enderror type="date" wire:model="due_date" class="w-full min-w-0 h-11 px-3 border border-border bg-surface rounded-control text-xs" :disabled="documentBusy || saving"/>
                @error('due_date') <span id="purchase-due_date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Collapsible More Details Drawer -->
        @php
            $hasDrawerErrors = $errors->hasAny(['warehouse_id', 'purchase_date', 'currency_code', 'exchange_rate', 'document_locale', 'notes']);
        @endphp
        <div x-cloak x-show="{{ $showMoreDetails || $hasDrawerErrors ? 'true' : 'false' }}" class="pt-3 border-t border-border grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 bg-surface-soft p-3 rounded-control" id="extendedDetailsDrawer">
            <div>
                <label for="purchase-warehouse" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.receiving_warehouse') }}
                </label>
                <select id="purchase-warehouse" @error('warehouse_id') aria-invalid="true" aria-describedby="purchase-warehouse-error" @enderror wire:model="warehouse_id" required class="w-full h-10 px-2.5 border border-border bg-surface rounded-control text-xs" :disabled="documentBusy || saving">
                    <option value="">{{ __('purchasing.select_warehouse') }}</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->id }}">{{ $warehouse->displayName() }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <span id="purchase-warehouse-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="purchase-purchase_date" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.purchase_date') }}
                </label>
                <input id="purchase-purchase_date" @error('purchase_date') aria-invalid="true" aria-describedby="purchase-purchase_date-error" @enderror type="date" wire:model="purchase_date" class="w-full min-w-0 h-10 px-2.5 border border-border bg-surface rounded-control text-xs" required :disabled="documentBusy || saving"/>
                @error('purchase_date') <span id="purchase-purchase_date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="purchase-currency" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.currency') }}
                </label>
                <select id="purchase-currency" @error('currency_code') aria-invalid="true" aria-describedby="purchase-currency-error" @enderror wire:model.live="currency_code" class="w-full h-10 px-2.5 border border-border bg-surface rounded-control text-xs" :disabled="documentBusy || saving">
                    @foreach($currencies as $currency)
                        <option value="{{ $currency->currency_code }}">{{ $currency->currency_code }}</option>
                    @endforeach
                </select>
                @error('currency_code') <span id="purchase-currency-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            @if ($currency_code !== $baseCurrencyCode)
            <div>
                <label for="purchase-rate" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.exchange_rate') }}
                </label>
                <input id="purchase-rate" @error('exchange_rate') aria-invalid="true" aria-describedby="purchase-rate-error" @enderror wire:model.blur="exchange_rate" inputmode="decimal" dir="ltr" class="w-full h-10 px-2.5 border border-border bg-surface rounded-control text-xs font-mono" :disabled="documentBusy || saving"/>
                @error('exchange_rate') <span id="purchase-rate-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
            @endif

            <div>
                <label for="purchase-locale" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.document_language') }}
                </label>
                <select id="purchase-locale" wire:model="document_locale" class="w-full h-10 px-2.5 border border-border bg-surface rounded-control text-xs" :disabled="documentBusy || saving">
                    @foreach($languages as $language)
                        <option value="{{ $language->locale }}">{{ __('purchasing.locale_'.$language->locale) }}</option>
                    @endforeach
                </select>
            </div>

            <div class="{{ $currency_code !== $baseCurrencyCode ? 'sm:col-span-2 lg:col-span-1' : 'sm:col-span-2 lg:col-span-2' }}">
                <label for="purchase-notes" class="block text-xs font-semibold text-text-secondary mb-1">
                    {{ __('purchasing.notes') }}
                </label>
                <textarea id="purchase-notes" wire:model="notes" rows="1" class="w-full h-10 border border-border bg-surface rounded-control p-2 text-xs" placeholder="{{ __('purchasing.notes') }}" :disabled="documentBusy || saving"></textarea>
            </div>
        </div>
    </section>

    <!-- Quick Barcode Scanner & Product Search -->
    <div class="bg-surface border border-border rounded-card p-3 sm:p-4 grid gap-3 md:grid-cols-2 items-center">
        @include('livewire.partials.barcode-entry', ['prefix' => 'purchase'])
        <div>
            <label for="purchase-product-search" class="block text-xs font-bold text-text-secondary mb-1">{{ __('purchasing.search_products') }}</label>
            <div class="flex gap-2 items-center">
                <input id="purchase-product-search" type="search" maxlength="160" wire:model.live.debounce.300ms="productSearch" x-on:keydown.enter.prevent placeholder="{{ __('purchasing.search_products') }}" class="flex-1 min-w-0 h-10 px-3 border border-border bg-canvas rounded-control text-xs" :disabled="documentBusy || saving"/>
                @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                    <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="shrink-0 min-h-10 px-3 py-1.5 rounded-control border border-border text-xs font-bold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer" wire:loading.attr="disabled" wire:target="openQuickProductModal" :disabled="documentBusy || saving">
                        + {{ __('quick_product.create') }}
                    </button>
                @endif
            </div>
            <p wire:loading wire:target="productSearch" role="status" class="mt-1 text-xs text-text-secondary">{{ __('purchasing.products_searching') }}</p>
            @if($products->isEmpty())<p role="status" class="mt-1 text-sm text-text-secondary">{{ __('purchasing.no_matching_products') }}</p>@endif
        </div>
    </div>

    <!-- 2. Invoice Lines Section -->
    <section class="space-y-3">
        <!-- Table Action Bar -->
        <div class="px-4 py-2.5 bg-surface border border-border rounded-card flex items-center justify-between">
            <div class="flex items-center gap-2">
                <h2 class="font-bold text-sm text-text-primary">{{ __('purchasing.purchase_lines') }}</h2>
                <span class="text-xs text-text-muted">·</span>
                <span class="text-xs font-medium text-text-secondary">{{ count($lines) }} {{ __('purchasing.lines') ?? 'Items' }}</span>
            </div>
            <button type="button" @click="handleAddLine()" class="h-9 px-3 text-primary border border-border bg-surface hover:bg-surface-soft rounded-control text-xs font-bold inline-flex items-center gap-1.5 cursor-pointer shadow-2xs transition-colors">
                <span>+ {{ __('purchasing.add_line') }}</span>
                <kbd class="px-1 py-0.5 text-[10px] font-mono rounded border border-border bg-surface-soft text-text-secondary leading-none">Alt+N</kbd>
            </button>
        </div>

        @foreach($lines as $index => $line)
            @php
                $lineProd = !empty($line['product_id']) ? ($selectedProducts->get($line['product_id']) ?? $products->firstWhere('id', $line['product_id'])) : null;
                $lineProdLabel = $lineProd ? $lineProd->displayName() . ($lineProd->sku ? ' (' . $lineProd->sku . ')' : '') : ($line['item_description'] ?? '');
                $selectedUnit = collect($unitOptions[$index] ?? [])->firstWhere('id', $line['product_unit_id'] ?? null);
                $lineAllowsFraction = $selectedUnit?->unit?->allows_fraction ?? false;
                $hasLegacyDiscount = ($line['discount_type'] ?? 'none') !== 'none' && !empty($line['discount_value']) && (float)$line['discount_value'] > 0;
                $hasRowOptionErrors = $errors->hasAny(["lines.{$index}.item_description", "lines.{$index}.tax_rate_id", "lines.{$index}.lots.*"]);
                $isRowOptionsOpen = ($expandedRowOptions[$index] ?? false) || $hasRowOptionErrors;
            @endphp
            <article wire:key="purchase-line-{{ $index }}-{{ $line['product_id'] ?? 'new' }}" class="bg-surface border border-border rounded-card p-3 sm:p-4 space-y-3">
                <div class="flex justify-between items-center gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-mono text-text-muted font-bold">#<bdi>{{ $index + 1 }}</bdi></span>
                        @if($hasLegacyDiscount)
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 text-xs font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                {{ __('purchasing.legacy_discount_badge', ['value' => $line['discount_value'] . ($line['discount_type'] === 'percent' ? '%' : ' ' . $currency_code)]) }}
                            </span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="toggleRowOptions({{ $index }})" class="px-2.5 py-1 text-xs text-text-secondary hover:text-text-primary border border-border rounded-control hover:bg-surface-soft transition-colors cursor-pointer">
                            {{ __('purchasing.row_options') }}
                        </button>
                        <button type="button" wire:click="removeLine({{ $index }})" class="min-h-[36px] px-2.5 text-danger text-xs font-bold cursor-pointer hover:bg-danger/10 rounded-control transition-colors" wire:loading.attr="disabled" wire:target="removeLine" :disabled="documentBusy || saving">
                            {{ __('purchasing.remove_line') }}
                        </button>
                    </div>
                </div>

                <!-- Main Line Inputs -->
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 items-start">
                    <div class="sm:col-span-2 lg:col-span-5">
                        <label for="line-product-{{ $index }}" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('purchasing.product') }} <span class="text-danger">*</span></label>
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
                        <label for="line-unit-{{ $index }}" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('purchasing.unit') }}</label>
                        <select id="line-unit-{{ $index }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="line-unit-{{ $index }}-error" @enderror wire:change="changeUnit({{ $index }}, $event.target.value)" class="w-full h-11 px-2 border border-border rounded-control text-xs" @disabled(empty($line['product_id'])) :disabled="documentBusy || saving">
                            <option value="">{{ __('purchasing.select_unit') }}</option>
                            @foreach($unitOptions[$index] ?? [] as $unit)
                                <option value="{{ $unit->id }}" @selected(($line['product_unit_id'] ?? null) == $unit->id)>{{ $unit->unit->name() }}</option>
                            @endforeach
                        </select>
                        @error('lines.'.$index.'.product_unit_id') <span id="line-unit-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                    </div>

                    <div class="lg:col-span-2">
                        <label for="line-quantity-{{ $index }}" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('purchasing.quantity') }} <span class="text-danger">*</span></label>
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
                        <label for="line-unit_cost-{{ $index }}" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('purchasing.unit_cost') }} <span class="text-danger">*</span></label>
                        <input id="line-unit_cost-{{ $index }}" @error('lines.'.$index.'.unit_cost') aria-invalid="true" aria-describedby="line-unit_cost-{{ $index }}-error" @enderror wire:model.blur="lines.{{ $index }}.unit_cost" wire:change="recalculate" inputmode="decimal" dir="ltr" class="w-full min-w-0 h-11 px-2 border border-border bg-surface rounded-control text-xs font-mono text-end" placeholder="0.00" :disabled="documentBusy || saving"/>
                        @error('lines.'.$index.'.unit_cost') <span id="line-unit_cost-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                        @if(isset($lastPriceHints[$index]))
                            <p class="text-[11px] text-text-muted mt-1 leading-tight" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
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

                    <div class="sm:col-span-2 lg:col-span-1">
                        <span class="block text-xs font-semibold text-text-secondary mb-1">{{ __('purchasing.line_total') }}</span>
                        <output class="block h-11 px-2 py-3 text-xs font-bold font-mono bg-surface-soft rounded-control text-end tabular-nums"><bdi>{{ isset($line['preview_total']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['preview_total'], $currency_code) : '—' }}</bdi></output>
                    </div>
                </div>

                <!-- Collapsible Row Options (Description, Tax, Expiry) -->
                <div x-cloak x-show="{{ $isRowOptionsOpen ? 'true' : 'false' }}" class="border-t border-border/60 pt-3 space-y-3 bg-surface-soft p-3 rounded-control">
                    <div class="grid sm:grid-cols-2 gap-3">
                        <div>
                            <label for="line-description-{{ $index }}" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('purchasing.description') }}</label>
                            <input id="line-description-{{ $index }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="line-description-{{ $index }}-error" @enderror wire:model="lines.{{ $index }}.item_description" maxlength="500" class="w-full h-10 px-2.5 border border-border bg-surface rounded-control text-xs" :disabled="documentBusy || saving"/>
                            @error('lines.'.$index.'.item_description') <span id="line-description-{{ $index }}-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                        </div>
                        @if($taxes->isNotEmpty())
                            <div>
                                <label for="line-tax-{{ $index }}" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('purchasing.tax') }}</label>
                                <select id="line-tax-{{ $index }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="line-tax-{{ $index }}-error" @enderror wire:model.live="lines.{{ $index }}.tax_rate_id" class="w-full h-10 px-2.5 border border-border bg-surface rounded-control text-xs" :disabled="documentBusy || saving">
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
                                    <p class="text-xs text-text-secondary mt-0.5">{{ __('purchasing.partial_lot_hint') }}</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if(! ($expandedLotCustomization[$index] ?? false))
                                        <button type="button" wire:click="customizeLots({{ $index }})" class="min-h-[36px] px-3 border border-border bg-surface rounded-control text-primary text-xs font-medium cursor-pointer hover:bg-surface-soft" wire:loading.attr="disabled" wire:target="customizeLots" :disabled="documentBusy || saving">
                                            {{ __('purchase_settlement.customize_lots') }}
                                        </button>
                                    @else
                                        <button type="button" wire:click="resetToAutoLot({{ $index }})" class="min-h-[36px] px-3 border border-border bg-surface rounded-control text-text-secondary text-xs cursor-pointer hover:bg-surface-soft" wire:loading.attr="disabled" wire:target="resetToAutoLot" :disabled="documentBusy || saving">
                                            {{ __('purchase_settlement.collapse_lots') }}
                                        </button>
                                        <button type="button" wire:click="addLot({{ $index }})" class="min-h-[36px] px-3 border border-border bg-surface rounded-control text-primary text-xs font-bold cursor-pointer hover:bg-surface-soft" wire:loading.attr="disabled" wire:target="addLot" :disabled="documentBusy || saving">
                                            {{ __('purchasing.add_lot') }}
                                        </button>
                                    @endif
                                </div>
                            </div>

                            @if(! ($expandedLotCustomization[$index] ?? false))
                                <div class="bg-surface rounded-control p-2.5 flex items-center justify-between gap-2 text-xs text-text-secondary border border-border/50">
                                    <span class="flex items-center gap-2">
                                        <span class="inline-block w-2 h-2 rounded-full bg-emerald-500 shrink-0"></span>
                                        <span>{{ __('purchase_settlement.auto_lot_notice') }} (<strong><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }}</bdi></strong> {{ $selectedUnit?->unit?->name() ?? '' }})</span>
                                    </span>
                                </div>
                            @else
                                @foreach($line['lots'] ?? [] as $lotIndex => $lot)
                                    <div wire:key="intent-{{ $index }}-{{ $lotIndex }}" class="grid grid-cols-2 lg:grid-cols-4 gap-3 bg-surface rounded-control p-2.5 border border-border/50">
                                        <div>
                                            <label for="lot-{{ $index }}-{{ $lotIndex }}-lot_number" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.lot_number') }}</label>
                                            <input id="lot-{{ $index }}-{{ $lotIndex }}-lot_number" @error('lines.'.$index.'.lots.'.$lotIndex.'.lot_number') aria-invalid="true" aria-describedby="lot-{{ $index }}-{{ $lotIndex }}-lot_number-error" @enderror type="text" wire:model="lines.{{ $index }}.lots.{{ $lotIndex }}.lot_number" dir="ltr" class="w-full min-w-0 h-9 px-2 border border-border bg-canvas rounded-control text-xs font-mono" :disabled="documentBusy || saving"/>
                                            @error('lines.'.$index.'.lots.'.$lotIndex.'.lot_number') <span id="lot-{{ $index }}-{{ $lotIndex }}-lot_number-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <label for="lot-{{ $index }}-{{ $lotIndex }}-expiry_date" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.expiry_date') }}</label>
                                            <input id="lot-{{ $index }}-{{ $lotIndex }}-expiry_date" @error('lines.'.$index.'.lots.'.$lotIndex.'.expiry_date') aria-invalid="true" aria-describedby="lot-{{ $index }}-{{ $lotIndex }}-expiry_date-error" @enderror type="date" wire:model="lines.{{ $index }}.lots.{{ $lotIndex }}.expiry_date" class="w-full min-w-0 h-9 px-2 border border-border bg-canvas rounded-control text-xs" :disabled="documentBusy || saving"/>
                                            @error('lines.'.$index.'.lots.'.$lotIndex.'.expiry_date') <span id="lot-{{ $index }}-{{ $lotIndex }}-expiry_date-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                        </div>
                                        <div>
                                            <label for="lot-{{ $index }}-{{ $lotIndex }}-quantity" class="block text-xs text-text-secondary mb-1">{{ __('purchasing.allocated_quantity') }}</label>
                                            <input id="lot-{{ $index }}-{{ $lotIndex }}-quantity" @error('lines.'.$index.'.lots.'.$lotIndex.'.quantity') aria-invalid="true" aria-describedby="lot-{{ $index }}-{{ $lotIndex }}-quantity-error" @enderror type="text" inputmode="decimal" wire:model="lines.{{ $index }}.lots.{{ $lotIndex }}.quantity" dir="ltr" class="w-full min-w-0 h-9 px-2 border border-border bg-canvas rounded-control text-xs font-mono" :disabled="documentBusy || saving"/>
                                            @error('lines.'.$index.'.lots.'.$lotIndex.'.quantity') <span id="lot-{{ $index }}-{{ $lotIndex }}-quantity-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                        </div>
                                        <button type="button" wire:click="removeLot({{ $index }}, {{ $lotIndex }})" class="self-end min-h-[36px] px-3 text-danger text-xs font-semibold cursor-pointer hover:bg-danger/10 rounded-control" wire:loading.attr="disabled" wire:target="removeLot" :disabled="documentBusy || saving">{{ __('purchasing.remove_lot') }}</button>
                                    </div>
                                @endforeach
                            @endif
                        </div>
                    @endif
                </div>
            </article>
        @endforeach
    </section>

    <!-- 3. Bottom Grid: Settlement & Arithmetic Summary -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-4 items-start">
        <!-- LEFT COLUMN: Payment & Settlement Method -->
        <div class="lg:col-span-7 space-y-4">
            @include('livewire.partials.immediate-purchase-settlement')

            @if ($showPreview && ! empty($previewData['settlement']))
                @include('livewire.partials.immediate-purchase-review', ['review' => $previewData['settlement']])
            @endif
        </div>

        <!-- RIGHT COLUMN: Summary Card & Global Document Discount -->
        <div class="lg:col-span-5 space-y-4">
            <section class="bg-surface border border-border rounded-card p-4 space-y-3">
                <div class="flex items-center justify-between pb-2 border-b border-border/60">
                    <span class="text-xs font-bold text-text-primary uppercase tracking-wider">{{ __('purchasing.purchase_summary') ?? __('sales.invoice_details') }}</span>
                    <span class="text-[11px] text-text-muted font-mono">{{ $currency_code }}</span>
                </div>

                <div class="space-y-2 text-xs divide-y divide-border/40">
                    <!-- Subtotal -->
                    <div class="flex items-center justify-between pt-1">
                        <span class="text-text-secondary font-medium">{{ __('purchasing.subtotal') }}</span>
                        <span class="font-mono font-semibold text-text-primary tabular-nums">
                            {{ $documentTotalsValid && isset($totals['subtotal']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($totals['subtotal'], $currency_code) : '—' }} {{ $currency_code }}
                        </span>
                    </div>

                    <!-- Global Invoice Discount -->
                    <div class="pt-2">
                        @if (! $showDiscountControl)
                            <div class="flex items-center justify-between">
                                <span class="text-text-secondary">{{ __('purchasing.discount') }}</span>
                                <button type="button" wire:click="toggleDiscountControl" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer">
                                    + {{ __('purchasing.add_discount') }}
                                </button>
                            </div>
                        @else
                            <div class="p-2.5 bg-surface-soft rounded-control border border-border space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-text-primary">{{ __('purchasing.document_discount') }}</span>
                                    <button type="button" wire:click="toggleDiscountControl" class="text-xs text-danger hover:underline cursor-pointer">
                                        ✕ {{ __('purchasing.remove_discount') }}
                                    </button>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label for="purchase-discount-type" class="block text-[11px] text-text-secondary mb-0.5">{{ __('purchasing.discount') }}</label>
                                        <select id="purchase-discount-type" wire:model.live="discount_type" class="w-full h-8 px-2 border border-border bg-surface rounded-control text-xs" :disabled="documentBusy || saving">
                                            <option value="none">{{ __('purchasing.discount_none') }}</option>
                                            <option value="percent">{{ __('purchasing.discount_percent') }}</option>
                                            <option value="fixed">{{ __('purchasing.discount_fixed') }}</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label for="purchase-discount-value" class="block text-[11px] text-text-secondary mb-0.5">{{ __('purchasing.discount_value') }}</label>
                                        <input id="purchase-discount-value" @error('discount_value') aria-invalid="true" aria-describedby="purchase-discount-value-error" @enderror type="text" inputmode="decimal" dir="ltr" wire:model.blur="discount_value" wire:change="recalculate" class="w-full h-8 px-2 border border-border bg-surface rounded-control text-xs font-mono" :disabled="documentBusy || saving" />
                                    </div>
                                </div>
                                @error('discount_value') <span id="purchase-discount-value-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                            </div>
                        @endif
                    </div>

                    <!-- Tax (if applicable) -->
                    @if($taxes->isNotEmpty() || ($documentTotalsValid && isset($totals['tax']) && (float)$totals['tax'] > 0))
                    <div class="flex items-center justify-between pt-2">
                        <span class="text-text-secondary">{{ __('purchasing.tax') }}</span>
                        <span class="font-mono font-semibold text-text-secondary tabular-nums">
                            {{ $documentTotalsValid && isset($totals['tax']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($totals['tax'], $currency_code) : '—' }} {{ $currency_code }}
                        </span>
                    </div>
                    @endif

                    <!-- Grand Total -->
                    <div class="flex items-baseline justify-between pt-3 border-t-2 border-border">
                        <span class="text-sm font-bold text-text-primary uppercase tracking-tight">{{ __('purchasing.total') }}</span>
                        <div class="text-xl sm:text-2xl font-bold font-mono text-text-primary tracking-tight tabular-nums">
                            <bdi @if($documentTotalsValid) data-document-total @endif>{{ $documentTotalsValid && isset($totals['total']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($totals['total'], $currency_code) : '—' }}</bdi>
                            <span class="text-sm font-normal text-text-muted">{{ $currency_code }}</span>
                        </div>
                    </div>
                </div>

                @if (! $documentTotalsValid)
                    <div class="flex items-center gap-2 p-2 rounded bg-warning-bg border border-warning/20 text-warning text-xs mt-2" role="status">
                        <x-icon name="alert-triangle" class="w-4 h-4 shrink-0 text-warning" />
                        <span>{{ __('camera.totals_unavailable') }}</span>
                    </div>
                @endif
            </section>
        </div>
    </div>

    @if ($previewError)
        <div role="alert" class="p-3 text-sm text-danger bg-danger/10 border border-danger/20 rounded-control">
            {{ $previewError }}
        </div>
    @endif

    <!-- Footer Actions -->
    <footer class="flex flex-wrap items-center gap-3 pt-2">
        @can('purchasing.purchase.post')
            <button type="button" @click="if (!saving && !window.isDocumentBlocked($wire)) { saving = true; window.enqueueDocumentOperation($wire, 'saveAndConfirm').catch(() => { operationFailed = true; }).finally(() => saving = false); }" :disabled="saving || documentBusy" class="min-h-11 px-5 bg-primary text-white font-bold text-sm rounded-control hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                {{ __('purchasing.post_purchase') }}
            </button>
        @endcan
        <button type="submit" wire:loading.attr="disabled" wire:target="save" class="h-11 px-5 bg-surface border border-border text-text-primary hover:bg-surface-soft font-bold text-sm rounded-control inline-flex items-center gap-1.5 cursor-pointer transition-colors">
            <span>{{ __('purchasing.save_draft') }}</span>
            <kbd class="px-1 py-0.5 text-xs font-mono rounded bg-surface-soft border border-border text-text-secondary leading-none">Ctrl+S</kbd>
        </button>
        <a href="{{ route('purchases.index') }}" class="touch-action inline-flex items-center h-11 px-4 border border-border text-text-secondary hover:text-text-primary rounded-control text-sm transition-colors">{{ __('purchasing.cancel') }}</a>
    </footer>

    @php($canConfirmPurchase = auth()->user()->hasPermissionTo('purchasing.purchase.post'))
    <x-document-mobile-actions :total="$totals['total'] ?? '0'" :currency="$currency_code" :valid="$documentTotalsValid" :can-confirm="$canConfirmPurchase" :confirm-label="__('purchasing.post_purchase')" />

</form>

    @include('livewire.partials.quick-vendor-modal')
    @include('livewire.partials.quick-product-modal')
</div>
