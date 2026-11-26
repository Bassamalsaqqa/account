<div data-entry-form class="max-w-5xl mx-auto space-y-6 pb-44 md:pb-6"
     x-data="{
         saving: false, documentBusy: false, operationFailed: false,
         handleSaveDraft() {
             if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBlocked($wire) || $wire.showPreview || $wire.showQuickCustomerModal) return;
             this.saving = true;
             window.enqueueDocumentOperation($wire, 'saveDraft').catch(() => { this.operationFailed = true; }).finally(() => { this.saving = false; });
         },
         handleAddLine() {
             if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBlocked($wire) || $wire.showPreview || $wire.showQuickCustomerModal) return;
             window.enqueueDocumentOperation($wire, 'addLine').catch(() => { this.operationFailed = true; });
         }
     }"
     @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy"
     @keydown.window.prevent.ctrl.s="handleSaveDraft()"
     @keydown.window.prevent.meta.s="handleSaveDraft()"
     @keydown.window.prevent.alt.n="if (!$event.repeat) handleAddLine()">
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ $isEditing ? __('sales.edit_invoice_draft') : __('sales.new_invoice') }}
            </h1>
            @if ($isEditing)
                <p class="text-xs text-text-secondary mt-1"><bdi dir="ltr">{{ $invoice->invoice_number ?? __('sales.draft') }}</bdi></p>
            @endif
        </div>

        <a href="{{ $isEditing ? route('invoices.show', $invoice->public_id) : route('invoices.index') }}"
           class="touch-action px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    @if ($creditLimitWarning)
        <div role="status" class="rounded-card border border-warning/30 bg-warning/10 p-3 text-sm text-text-primary">
            {{ __('sales.credit_limit_warning') }}
        </div>
    @endif

    @if ($previewError)
        <div role="alert" class="rounded-card border border-danger/30 bg-danger/10 p-3 text-xs text-danger font-medium flex items-center justify-between">
            <span>{{ $previewError }}</span>
            <button type="button" wire:click="$set('previewError', null)" class="text-danger hover:opacity-75 font-bold cursor-pointer">×</button>
        </div>
    @endif

    <x-form-attention />
    <!-- Quick Barcode Scanner Bar -->
    @include('livewire.ai.unsaved-notice')

    <!-- Main Form -->
    <div class="bg-surface rounded-card border border-border shadow-xs p-4 sm:p-6 space-y-6">
        <div class="entry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <!-- Customer -->
            <div class="lg:col-span-2">
                <div class="flex items-center justify-between mb-1">
                    <label for="invoice-customer-id" class="block text-sm font-bold text-text-primary">
                        {{ __('sales.customer') }} <span class="text-danger">*</span>
                    </label>

                </div>
                @php
                    $selectedCustomer = $customer_id ? $customers->firstWhere('id', $customer_id) : null;
                    $customerLabel = $selectedCustomer ? $selectedCustomer->displayName() . ' (' . ($selectedCustomer->default_currency_code ?? $baseCurrencyCode) . ')' : '';
                @endphp
                <div class="party-picker flex gap-2 items-center">
                    <x-searchable-combobox
                        id="invoice-customer-id"
                        type="customer"
                        context="sales"
                        wire-model="customer_id"
                        :selected-id="$customer_id"
                        :selected-label="$customerLabel"
                        :placeholder="__('sales.select_customer')"
                        :aria-label="__('sales.customer')"
                        :has-error="$errors->has('customer_id')"
                        required
                    />
                    @if ($canManageCustomers)
                        <button type="button" wire:click="openQuickCustomerModal" class="party-create-button shrink-0" aria-haspopup="dialog" wire:loading.attr="disabled" wire:target="openQuickCustomerModal" :disabled="documentBusy || saving">
                            + {{ __('workflow.quick_customer') }}
                        </button>
                    @endif
                </div>
                @error('customer_id') <span id="invoice-customer-id-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror

            </div>

            <!-- Issue Date -->
            <div>
                <label for="invoice-issue-date" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input id="invoice-issue-date" @error('issue_date') aria-invalid="true" aria-describedby="invoice-issue-date-error" @enderror type="date"
                       wire:model="issue_date"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('issue_date') <span id="invoice-issue-date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

        </div>
        <div class="border-t border-border pt-3">
            <div class="entry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-3">
            <!-- Warehouse -->
            <div class="">
                <label for="invoice-warehouse-id" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.warehouse') }}
                </label>
                <select id="invoice-warehouse-id" @error('warehouse_id') aria-invalid="true" aria-describedby="invoice-warehouse-id-error" @enderror wire:model.live="warehouse_id"
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    <option value="">{{ __('sales.select_warehouse') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <span id="invoice-warehouse-id-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Due Date -->
            <div>
                <label for="invoice-due-date" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.due_date') }}
                </label>
                <input id="invoice-due-date" @error('due_date') aria-invalid="true" aria-describedby="invoice-due-date-error" @enderror type="date"
                       wire:model="due_date"
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('due_date') <span id="invoice-due-date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Currency -->
            <div>
                <label for="invoice-currency-code" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.currency') }} <span class="text-danger">*</span>
                </label>
                <select id="invoice-currency-code" wire:model.live="currency_code"
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    @foreach ($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
            </div>

            @if ($currency_code !== $baseCurrencyCode)
            <!-- Exchange Rate -->
            <div>
                <label for="invoice-exchange-rate" class="block text-sm font-bold text-text-primary mb-1">
                    {{ __('sales.exchange_rate') }} <span class="text-danger">*</span>
                </label>
                <input id="invoice-exchange-rate" @error('exchange_rate') aria-invalid="true" aria-describedby="invoice-exchange-rate-error" @enderror type="number" inputmode="decimal"
                       step="0.0000000001"
                       wire:model.blur="exchange_rate"
                       wire:change="recalculate"
                       dir="ltr"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono"  :disabled="documentBusy || saving"/>
                @error('exchange_rate') <span id="invoice-exchange-rate-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
            @endif
            </div>
        </div>
    <div class="grid gap-4 md:grid-cols-2 pb-4 border-b border-border">
        @include('livewire.partials.barcode-entry', ['prefix' => 'invoice'])
        <div>
            <label for="invoice-product-search" class="block text-sm font-bold text-text-primary mb-1">{{ __('sales.search_products') }}</label>
            <div class="flex gap-2 items-center">
                <input id="invoice-product-search" type="search" maxlength="160" wire:model.live.debounce.300ms="productSearch" x-on:keydown.enter.prevent
                       placeholder="{{ __('sales.search_products_hint') }}"
                       class="flex-1 min-w-0 h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                    <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="shrink-0 min-h-11 px-3 py-2 rounded-control border border-border text-sm font-bold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer" wire:loading.attr="disabled" wire:target="openQuickProductModal" :disabled="documentBusy || saving">
                        + {{ __('quick_product.create') }}
                    </button>
                @endif
            </div>
            <p wire:loading wire:target="productSearch" role="status" class="mt-1 text-xs text-text-secondary">{{ __('sales.products_searching') }}</p>
            @if($productMatchCount === 0)<p role="status" class="mt-1 text-sm text-text-secondary">{{ __('sales.no_matching_products') }}</p>@endif
        </div>
    </div>


        <!-- Lines Section -->
        <div>
            <div class="flex items-center justify-between pb-2 border-b border-border">
                <h2 class="text-sm font-bold text-text-primary">
                    {{ __('sales.invoice_details') }}
                </h2>
                <button type="button"
                        @click="handleAddLine()"
                        class="px-2.5 py-1 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors inline-flex items-center gap-1.5 cursor-pointer">
                    <span>+ {{ __('sales.add_line') }}</span>
                    <kbd class="px-1 py-0.5 text-xs font-mono rounded bg-surface/20 text-white leading-none">Alt+N</kbd>
                </button>
            </div>

            <!-- Desktop Lines Table -->
            <div class="hidden md:block overflow-x-auto mt-4">
                <table class="data-table w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs">
                            <th class="py-2 px-2 text-start w-1/4">{{ __('sales.product') }}</th>
                            <th class="py-2 px-2 text-start w-24">{{ __('sales.unit') }}</th>
                            <th class="py-2 px-2 text-start w-36">{{ __('sales.quantity') }}</th>
                            <th class="py-2 px-2 text-start w-24">{{ __('sales.unit_price') }}</th>
                            @if ($taxes->isNotEmpty())
                                <th class="py-2 px-2 text-start w-28">{{ __('sales.tax') }}</th>
                            @endif
                            <th class="py-2 px-2 text-end w-28">{{ __('sales.total') }} <span class="text-xs font-normal text-text-muted">({{ __('workflow.preview') }})</span></th>
                            <th class="py-2 px-1 text-center w-8"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($lines as $index => $line)
                            @php
                                $selectedProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                            @endphp
                            <tr wire:key="inv-line-{{ $line['row_key'] }}">
                                <td class="py-2 px-2 align-top space-y-1">
<div class="entry-product-fields">
                                    @php
                                        $lineProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                                        $lineProductLabel = $lineProduct ? $lineProduct->displayName() . ($lineProduct->sku ? ' (' . $lineProduct->sku . ')' : '') : '';
                                    @endphp
                                    <x-searchable-combobox
                                        id="invoice-desktop-line-{{ $index }}-product-id"
                                        type="product"
                                        context="sales"
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
                                    @error('lines.'.$index.'.product_id') <span id="invoice-desktop-line-{{ $index }}-product-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                    <input wire:key="inv-desc-{{ $line['row_key'] }}" id="invoice-desktop-line-{{ $index }}-item-description" aria-label="{{ __('sales.description') }} {{ $index + 1 }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-item-description-error" @enderror type="text"
                                           wire:model="lines.{{ $index }}.item_description"
                                           placeholder="{{ __('sales.description') }}..."
                                           required
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-sm text-text-primary"  :disabled="documentBusy || saving"/> @error('lines.'.$index.'.item_description') <span id="invoice-desktop-line-{{ $index }}-item-description-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror

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
</td>

                                <td class="py-2 px-2 align-top">
                                    @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                                        <select wire:key="inv-unit-{{ $line['row_key'] }}" id="invoice-desktop-line-{{ $index }}-product-unit-id" aria-label="{{ __('sales.unit') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-product-unit-id-error" @enderror wire:change="changeUnit('{{ $line['row_key'] }}', $event.target.value)"
                                                class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-sm" :disabled="documentBusy || saving">
                                            @foreach ($selectedProduct->productUnits as $pu)
                                                 <option value="{{ $pu->id }}" @selected(($line['product_unit_id'] ?? null) == $pu->id)>
                                                    {{ $pu->unit?->name() ?? $pu->unit?->code ?? 'Unit' }}
                                                </option>
                                            @endforeach
                                        </select> @error('lines.'.$index.'.product_unit_id') <span id="invoice-desktop-line-{{ $index }}-product-unit-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                    @else
                                        <span class="text-text-muted text-xs block pt-1.5">-</span>
                                    @endif
                                </td>

                                <td class="py-2 px-2 align-top space-y-1">
                                    @php
                                        $selectedProductUnit = $selectedProduct && !empty($line['product_unit_id']) ? $selectedProduct->productUnits->firstWhere('id', $line['product_unit_id']) : null;
                                        $lineAllowsFraction = $selectedProductUnit?->unit?->allows_fraction ?? false;
                                    @endphp
                                    <x-touch-quantity-stepper
                                        :index="$index"
                                        :row-key="$line['row_key']"
                                        :allows-fraction="$lineAllowsFraction"
                                        :step="\App\Support\QuantityStep::forUnit($selectedProductUnit?->unit)"
                                        id="invoice-desktop-line-{{ $index }}-quantity"
                                        :aria-label="__('sales.quantity') . ' ' . ($index + 1)"
                                    />
                                    @error('lines.'.$index.'.quantity') <span id="invoice-desktop-line-{{ $index }}-quantity-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                    @if (!empty($line['available_quantity']))
                                        <div class="text-xs text-text-secondary whitespace-nowrap" dir="ltr">
                                            {{ __('sales.available') }}: <span class="font-bold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($line['available_quantity']) }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td class="py-2 px-2 align-top">
                                    <input wire:key="inv-price-{{ $line['row_key'] }}" id="invoice-desktop-line-{{ $index }}-unit-price" aria-label="{{ __('sales.unit_price') }} {{ $index + 1 }}" @error('lines.'.$index.'.unit_price') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-unit-price-error" @enderror type="number" inputmode="decimal"
                                           step="any"
                                           wire:model.blur="lines.{{ $index }}.unit_price"
                                           wire:change="recalculate"
                                           required
                                           min="0"
                                           dir="ltr"
                                           @if (! $canChangePrice) readonly title="{{ __('sales.price_permission_required') }}" @endif
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-sm font-mono {{ ! $canChangePrice ? 'opacity-70 bg-slate-100 cursor-not-allowed' : '' }}"  :disabled="documentBusy || saving"/>
<x-customer-last-price :price="$lastCustomerPrices[$line['product_id'] ?? 0] ?? null" /> @error('lines.'.$index.'.unit_price') <span id="invoice-desktop-line-{{ $index }}-unit-price-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                </td>

                                @if ($taxes->isNotEmpty())
                                    <td class="py-2 px-2 align-top">
                                        <select wire:key="inv-tax-{{ $line['row_key'] }}" id="invoice-desktop-line-{{ $index }}-tax-rate-id" aria-label="{{ __('sales.tax') }} {{ $index + 1 }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-tax-rate-id-error" @enderror wire:model.change="lines.{{ $index }}.tax_rate_id"
                                                wire:change="recalculate"
                                                class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-sm" :disabled="documentBusy || saving">
                                            <option value="">{{ __('sales.none') }} (0%)</option>
                                            @foreach ($taxes as $t)
                                                <option value="{{ $t->id }}">
                                                    {{ $t->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($t->rate) }}%) — {{ __('refinement.tax_'.$t->calculation) }}
                                                </option>
                                            @endforeach
                                        </select> @error('lines.'.$index.'.tax_rate_id') <span id="invoice-desktop-line-{{ $index }}-tax-rate-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                    </td>
                                @endif

                                <td class="py-2 px-2 align-top text-end font-mono font-bold" dir="ltr">
                                    <div class="pt-1.5">
                                        {{ $documentTotalsValid ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['line_total'] ?? '0', $currency_code) : '—' }}
                                    </div>
                                </td>

                                <td class="table-actions py-2 px-1 align-top text-center">
                                    @if (count($lines) > 1)
                                        <button type="button"
                                                wire:click="removeLine('{{ $line['row_key'] }}')"
                                                title="{{ __('sales.remove_line') }}"
                                                aria-label="{{ __('sales.remove_line') }} {{ $index + 1 }}"
                                                class="min-h-10 min-w-10 inline-flex items-center justify-center rounded-control text-danger hover:bg-danger/10 hover:text-danger-hover transition-colors p-1 cursor-pointer" wire:loading.attr="disabled" wire:target="removeLine" :disabled="documentBusy || saving">
                                            <x-icon name="trash" class="w-5 h-5" />
                                        </button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <!-- Mobile Lines Cards -->
            <div class="md:hidden space-y-3 mt-4">
                @foreach ($lines as $index => $line)
                    @php
                        $selectedProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                        $hasLegacyDiscount = false;
                        if (($line['discount_type'] ?? 'none') !== 'none' && ! empty($line['discount_value'])) {
                            try {
                                $hasLegacyDiscount = \Brick\Math\BigDecimal::of((string) $line['discount_value'])->isPositive();
                            } catch (\Throwable) {
                                $hasLegacyDiscount = false;
                            }
                        }
                    @endphp
                    <div wire:key="inv-card-{{ $line['row_key'] }}" class="p-3 bg-canvas rounded-card border border-border space-y-3">
                        <div class="flex items-center justify-between">
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-bold text-text-primary">#{{ $index + 1 }}</span>
                                @if($hasLegacyDiscount)
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded bg-amber-50 text-amber-800 border border-amber-200 text-xs font-medium">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        {{ __('sales.legacy_discount_badge', ['value' => $line['discount_value'] . ($line['discount_type'] === 'percent' ? '%' : ' ' . $currency_code)]) }}
                                    </span>
                                @endif
                            </div>
                            @if (count($lines) > 1)
                                <button type="button" wire:click="removeLine('{{ $line['row_key'] }}')" class="min-h-[44px] px-3 text-danger text-sm font-bold cursor-pointer hover:bg-danger/10 rounded-control inline-flex items-center" wire:loading.attr="disabled" wire:target="removeLine" :disabled="documentBusy || saving">
                                    {{ __('sales.remove_line') }}
                                </button>
                            @endif
                        </div>

                        <!-- Product & Description -->
                        <div class="space-y-1">
                            <label for="invoice-mobile-line-{{ $index }}-product-id" class="block text-sm font-bold text-text-secondary">{{ __('sales.product') }}</label>
                            @php
                                $mobileProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                                $mobileProductLabel = $mobileProduct ? $mobileProduct->displayName() . ($mobileProduct->sku ? ' (' . $mobileProduct->sku . ')' : '') : '';
                            @endphp
                            <x-searchable-combobox
                                id="invoice-mobile-line-{{ $index }}-product-id"
                                type="product"
                                context="sales"
                                :row-key="$line['row_key']"
                                :selected-id="$line['product_id'] ?? null"
                                :selected-label="$mobileProductLabel"
                                on-select="selectProduct"
                                :extra-args="[$line['row_key']]"
                                :placeholder="__('sales.product')"
                                :aria-label="__('sales.product') . ' ' . ($index + 1)"
                                :has-error="$errors->has('lines.'.$index.'.product_id')"
                            />
                            @error('lines.'.$index.'.product_id') <span id="invoice-mobile-line-{{ $index }}-product-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                            <input wire:key="inv-mob-desc-{{ $line['row_key'] }}" id="invoice-mobile-line-{{ $index }}-item-description" aria-label="{{ __('sales.description') }} {{ $index + 1 }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-item-description-error" @enderror type="text"
                                   wire:model="lines.{{ $index }}.item_description"
                                   placeholder="{{ __('sales.description') }}..."
                                   required
                                   class="w-full h-8 px-2 rounded-control border border-border bg-surface text-sm text-text-primary"  :disabled="documentBusy || saving"/> @error('lines.'.$index.'.item_description') <span id="invoice-mobile-line-{{ $index }}-item-description-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                        </div>

                        <!-- Unit & Quantity -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-product-unit-id" class="block text-sm font-bold text-text-secondary">{{ __('sales.unit') }}</label>
                                @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                                    <select wire:key="inv-mob-unit-{{ $line['row_key'] }}" id="invoice-mobile-line-{{ $index }}-product-unit-id" aria-label="{{ __('sales.unit') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-product-unit-id-error" @enderror wire:change="changeUnit('{{ $line['row_key'] }}', $event.target.value)"
                                            class="w-full h-8 px-1.5 rounded-control border border-border bg-surface text-sm" :disabled="documentBusy || saving">
                                        @foreach ($selectedProduct->productUnits as $pu)
                                            <option value="{{ $pu->id }}" @selected(($line['product_unit_id'] ?? null) == $pu->id)>
                                                {{ $pu->unit?->name() ?? $pu->unit?->code ?? 'Unit' }}
                                            </option>
                                        @endforeach
                                    </select> @error('lines.'.$index.'.product_unit_id') <span id="invoice-mobile-line-{{ $index }}-product-unit-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                @else
                                    <span class="text-text-muted text-xs block pt-1.5">-</span>
                                @endif
                            </div>
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-quantity" class="block text-sm font-bold text-text-secondary mb-1">{{ __('sales.quantity') }}</label>
                                @php
                                    $lineProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                                    $selectedProductUnit = $lineProduct && !empty($line['product_unit_id']) ? $lineProduct->productUnits->firstWhere('id', $line['product_unit_id']) : null;
                                    $lineAllowsFraction = $selectedProductUnit?->unit?->allows_fraction ?? false;
                                @endphp
                                <x-touch-quantity-stepper
                                    :index="$index"
                                    :row-key="$line['row_key']"
                                    :allows-fraction="$lineAllowsFraction"
                                    :step="\App\Support\QuantityStep::forUnit($selectedProductUnit?->unit)"
                                    id="invoice-mobile-line-{{ $index }}-quantity"
                                    :aria-label="__('sales.quantity') . ' ' . ($index + 1)"
                                />
                                @error('lines.'.$index.'.quantity') <span id="invoice-mobile-line-{{ $index }}-quantity-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span> @enderror
                                @if (!empty($line['available_quantity']))
                                    <div class="text-xs text-text-secondary mt-0.5" dir="ltr">
                                        {{ __('sales.available') }}: <span class="font-bold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($line['available_quantity']) }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Unit Price -->
                        <div>
                            <label for="invoice-mobile-line-{{ $index }}-unit-price" class="block text-sm font-bold text-text-secondary">{{ __('sales.unit_price') }}</label>
                            <input wire:key="inv-mob-price-{{ $line['row_key'] }}" id="invoice-mobile-line-{{ $index }}-unit-price" aria-label="{{ __('sales.unit_price') }} {{ $index + 1 }}" @error('lines.'.$index.'.unit_price') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-unit-price-error" @enderror type="number" inputmode="decimal"
                                   step="any"
                                   wire:model.blur="lines.{{ $index }}.unit_price"
                                   wire:change="recalculate"
                                   required
                                   min="0"
                                   dir="ltr"
                                   @if (! $canChangePrice) readonly @endif
                                   class="w-full h-8 px-2 rounded-control border border-border bg-surface text-sm font-mono {{ ! $canChangePrice ? 'opacity-70 bg-slate-100 cursor-not-allowed' : '' }}"  :disabled="documentBusy || saving"/>
<x-customer-last-price :price="$lastCustomerPrices[$line['product_id'] ?? 0] ?? null" /> @error('lines.'.$index.'.unit_price') <span id="invoice-mobile-line-{{ $index }}-unit-price-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                        </div>

                        @if ($taxes->isNotEmpty())
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-tax-rate-id" class="block text-sm font-bold text-text-secondary">{{ __('sales.tax') }}</label>
                                <select wire:key="inv-mob-tax-{{ $line['row_key'] }}" id="invoice-mobile-line-{{ $index }}-tax-rate-id" aria-label="{{ __('sales.tax') }} {{ $index + 1 }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-tax-rate-id-error" @enderror wire:model.change="lines.{{ $index }}.tax_rate_id"
                                        wire:change="recalculate"
                                        class="w-full h-8 px-1.5 rounded-control border border-border bg-surface text-sm" :disabled="documentBusy || saving">
                                    <option value="">{{ __('sales.none') }} (0%)</option>
                                    @foreach ($taxes as $t)
                                        <option value="{{ $t->id }}">
                                            {{ $t->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($t->rate) }}%) — {{ __('refinement.tax_'.$t->calculation) }}
                                        </option>
                                    @endforeach
                                </select> @error('lines.'.$index.'.tax_rate_id') <span id="invoice-mobile-line-{{ $index }}-tax-rate-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                            </div>
                        @endif

                        <div class="flex justify-between items-center pt-2 border-t border-border text-xs">
                            <span class="text-text-muted font-bold">{{ __('sales.line_total') }}:</span>
                            <span class="font-mono font-bold text-text-primary" dir="ltr">
                                {{ $documentTotalsValid ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['line_total'] ?? '0', $currency_code) : '—' }}
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <!-- Totals & Notes Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-border">
            <div class="space-y-4">
                <div>
                    <label for="invoice-terms" class="block text-sm font-bold text-text-primary mb-1">
                        {{ __('sales.terms') }}
                    </label>
                    <textarea id="invoice-terms" wire:model="terms"
                              rows="3"
                              class="w-full p-2.5 rounded-control border border-border bg-canvas text-sm text-text-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
                </div>
                <div>
                    <label for="invoice-notes" class="block text-sm font-bold text-text-primary mb-1">
                        {{ __('sales.notes') }}
                    </label>
                    <textarea id="invoice-notes" wire:model="notes"
                              rows="2"
                              class="w-full p-2.5 rounded-control border border-border bg-canvas text-sm text-text-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
                </div>
            </div>

            <!-- Preview Calculation Card -->
            <div class="bg-surface-soft p-4 rounded-control border border-border space-y-2 text-xs">
                <!-- Global Document Discount Control -->
                @if($canChangeDiscount && $discount_scope === 'document')
                    <div class="py-2 border-b border-border/50">
                        @if (! $showDiscountControl)
                            <div class="flex items-center justify-between">
                                <span class="text-text-muted text-xs">{{ __('sales.discount') }}</span>
                                <button type="button" wire:click="toggleDiscountControl" aria-expanded="false" aria-controls="sales-document-discount-container" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer min-h-[44px] min-w-[44px] py-2">
                                    + {{ __('sales.add_discount') }}
                                </button>
                            </div>
                        @else
                            <div id="sales-document-discount-container" class="p-2.5 bg-surface rounded-control border border-border space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-text-primary">{{ __('sales.document_discount') }}</span>
                                    <button type="button" wire:click="toggleDiscountControl" aria-expanded="true" aria-controls="sales-document-discount-container" class="text-xs text-danger hover:underline cursor-pointer min-h-[44px] min-w-[44px] inline-flex items-center justify-center p-2">
                                        ✕ {{ __('sales.remove_discount') }}
                                    </button>
                                </div>
                                <div class="grid @if($discount_type !== 'none') grid-cols-2 @else grid-cols-1 @endif gap-2">
                                    <div>
                                        <label for="sales-discount-type" class="block text-[11px] text-text-secondary mb-0.5">{{ __('sales.discount') }}</label>
                                        <select id="sales-discount-type" wire:model.live="discount_type" wire:change="recalculate" class="w-full h-8 px-2 border border-border bg-canvas rounded-control text-xs" :disabled="documentBusy || saving">
                                            <option value="none">{{ __('sales.discount_none') }}</option>
                                            <option value="percent">{{ __('sales.discount_percent') }}</option>
                                            <option value="fixed">{{ __('sales.discount_fixed') }}</option>
                                        </select>
                                    </div>
                                    @if ($discount_type !== 'none')
                                        <div>
                                            <label for="sales-discount-value" class="block text-[11px] text-text-secondary mb-0.5">{{ __('sales.discount_value') }}</label>
                                            <input id="sales-discount-value" @error('discount_value') aria-invalid="true" aria-describedby="sales-discount-value-error" @enderror type="text" inputmode="decimal" dir="ltr" wire:model="discount_value" wire:change="recalculate" class="w-full h-8 px-2 border border-border bg-canvas rounded-control text-xs font-mono" placeholder="0.00" :disabled="documentBusy || saving" />
                                        </div>
                                    @endif
                                </div>
                                @error('discount_value')
                                    <span x-init="$nextTick(() => document.getElementById('sales-discount-value')?.focus())"></span>
                                    <span id="sales-discount-value-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span>
                                @enderror
                            </div>
                        @endif
                    </div>
                @endif

                @if (! $documentTotalsValid)
                    <p role="status" class="text-warning font-semibold">{{ __('camera.totals_unavailable') }}</p>
                @else
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.subtotal') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewSubtotal, $currency_code) }} {{ $currency_code }}</span>
                </div>

                @php
                    $hasDiscountAmount = false;
                    if ($documentTotalsValid && isset($previewDiscountTotal) && $previewDiscountTotal !== '') {
                        try {
                            $hasDiscountAmount = \Brick\Math\BigDecimal::of($previewDiscountTotal)->isPositive();
                        } catch (\Throwable) {
                            $hasDiscountAmount = false;
                        }
                    }
                @endphp
                @if($hasDiscountAmount)
                    <div class="flex justify-between py-1 border-b border-border/50 text-danger">
                        <span>{{ ($discount_scope ?? 'document') === 'line' ? __('sales.manual_discount') : __('sales.discount') }}:</span>
                        <span class="font-mono font-bold" dir="ltr">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewDiscountTotal, $currency_code) }} {{ $currency_code }}</span>
                    </div>
                @endif
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.tax') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewTaxTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                <div class="flex justify-between py-2 border-t border-border text-base font-extrabold text-text-primary">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span data-document-total class="font-mono text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewGrandTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                @endif
            </div>
        </div>

        @include('livewire.partials.immediate-sale-settlement')

        <!-- Phase 11 Command Preview Panel -->
        @if ($showPreview && $previewData)
            <div class="pt-4 border-t border-border">
                <x-sales-command-preview :preview="$previewData" :invoice="$invoice" :currency="$currency_code" confirm-action="confirmPost" cancel-action="cancelPreview" cancel-return-id="invoice-post-button" />
            </div>
        @endif

        <!-- Buttons -->
        @if (! $showPreview)
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                <a href="{{ $isEditing ? route('invoices.show', $invoice->public_id) : route('invoices.index') }}"
                   class="touch-action px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                    {{ __('sales.cancel') }}
                </a>
                <button type="button"
                        @click="handleSaveDraft()"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-control border border-primary text-primary hover:bg-primary-50 text-sm font-bold transition-colors cursor-pointer inline-flex items-center gap-1.5">
                    <span>{{ __('sales.save_draft') }}</span>
                    <kbd class="px-1 py-0.5 text-xs font-mono rounded border border-primary/30 bg-primary-50/50 text-primary leading-none">Ctrl+S</kbd>
                </button>
                @if ($canPost)
                    <button id="invoice-post-button"
                            type="button"
                            @click="if (!saving && !window.isDocumentBlocked($wire)) { saving = true; window.enqueueDocumentOperation($wire, 'saveAndConfirm').catch(() => { this.operationFailed = true; }).finally(() => saving = false); }"
                            wire:loading.attr="disabled"
                            class="px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                        {{ __('commands.review_post') }}
                    </button>
                @endif
            </div>
        @endif

        <!-- Responsive Sticky Mobile Summary & Actions Toolbar -->
        @if (! $showPreview)
            <x-document-mobile-actions :total="$previewGrandTotal" :currency="$currency_code" :valid="$documentTotalsValid" :can-confirm="$canPost" :confirm-label="__('commands.review_post')" />
            <style>
                html.mobile-keyboard-open .mobile-document-actions,
                body.--mobile-keyboard-open .mobile-document-actions {
                    display: none !important;
                }
            </style>
        @endif
    </div>

    @include('livewire.partials.quick-customer-modal')
    @include('livewire.partials.quick-product-modal')
</div>
