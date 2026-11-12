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
                @if($customers->count() >= 50 || trim($customerSearch) !== '')
                <div class="flex gap-2 mb-1.5">
                    <input id="invoice-customer-search" aria-label="{{ __('workflow.search_customers') }}" type="text"
                           wire:model.live.debounce.300ms="customerSearch"
                           placeholder="{{ __('workflow.search_customers') }}"
                           class="w-full h-8 px-2.5 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                </div>
                @endif
                <div class="party-picker">
<select id="invoice-customer-id" @error('customer_id') aria-invalid="true" aria-describedby="invoice-customer-id-error" @enderror wire:model.live="customer_id"
                        required
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    <option value="">{{ __('sales.select_customer') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->displayName() }} ({{ $c->default_currency_code ?? $baseCurrencyCode }})</option>
                    @endforeach
                </select>
@if ($canManageCustomers)
                        <button type="button" wire:click="openQuickCustomerModal" class="party-create-button" aria-haspopup="dialog" wire:loading.attr="disabled" wire:target="openQuickCustomerModal" :disabled="documentBusy || saving">
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
    <div class="pb-4 border-b border-border">
        @include('livewire.partials.product-search-picker', [
            'prefix' => 'invoice',
            'context' => 'sales',
            'currencyCode' => $currency_code,
            'searchResults' => $searchResults,
        ])
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
                            <th class="py-2 px-2 text-start w-32">{{ __('sales.discount') }}</th>
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
                                    <select id="invoice-desktop-line-{{ $index }}-product-id" aria-label="{{ __('sales.product') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-product-id-error" @enderror wire:change="selectProduct({{ $index }}, $event.target.value)"
                                            class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-sm" :disabled="documentBusy || saving">
                                        <option value="">-- {{ __('sales.product') }} --</option>
                                        @foreach ($products as $p)
                                            <option value="{{ $p->id }}" @selected($line['product_id'] == $p->id)>
                                                {{ $p->displayName() }} ({{ $p->sku }})
                                            </option>
                                        @endforeach
                                    </select> @error('lines.'.$index.'.product_id') <span id="invoice-desktop-line-{{ $index }}-product-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                    <input id="invoice-desktop-line-{{ $index }}-item-description" aria-label="{{ __('sales.description') }} {{ $index + 1 }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-item-description-error" @enderror type="text"
                                           wire:model="lines.{{ $index }}.item_description"
                                           placeholder="{{ __('sales.description') }}..."
                                           required
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-sm text-text-primary"  :disabled="documentBusy || saving"/> @error('lines.'.$index.'.item_description') <span id="invoice-desktop-line-{{ $index }}-item-description-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror

</div>
</td>

                                <td class="py-2 px-2 align-top">
                                    @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                                        <select id="invoice-desktop-line-{{ $index }}-product-unit-id" aria-label="{{ __('sales.unit') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-product-unit-id-error" @enderror wire:change="changeUnit({{ $index }}, $event.target.value)"
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
                                    <input id="invoice-desktop-line-{{ $index }}-unit-price" aria-label="{{ __('sales.unit_price') }} {{ $index + 1 }}" @error('lines.'.$index.'.unit_price') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-unit-price-error" @enderror type="number" inputmode="decimal"
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

                                <td class="py-2 px-2 align-top space-y-1">
                                    <div class="flex gap-1">
                                        <select id="invoice-desktop-line-{{ $index }}-discount-type" aria-label="{{ __('sales.discount_type') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_type') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-discount-type-error" @enderror wire:model.change="lines.{{ $index }}.discount_type"
                                                wire:change="recalculate"
                                                @if (! $canChangeDiscount) disabled title="{{ __('sales.discount_permission_required') }}" @endif
                                                class="w-20 h-8 px-1 rounded-control border border-border bg-canvas text-sm {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}" :disabled="documentBusy || saving || {{ ! $canChangeDiscount ? 'true' : 'false' }}">
                                            <option value="none">{{ __('sales.none') }}</option>
                                            <option value="fixed">{{ __('sales.fixed') }}</option>
                                            <option value="percent">%</option>
                                        </select> @error('lines.'.$index.'.discount_type') <span id="invoice-desktop-line-{{ $index }}-discount-type-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                        @if (($line['discount_type'] ?? 'none') !== 'none')
                                            <input id="invoice-desktop-line-{{ $index }}-discount-value" aria-label="{{ __('sales.discount_val') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_value') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-discount-value-error" @enderror type="number" inputmode="decimal"
                                                   step="any"
                                                   wire:model.blur="lines.{{ $index }}.discount_value"
                                                   wire:change="recalculate"
                                                   dir="ltr"
                                                   @if (! $canChangeDiscount) readonly @endif
                                                   class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-sm font-mono {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}"  :disabled="documentBusy || saving"/> @error('lines.'.$index.'.discount_value') <span id="invoice-desktop-line-{{ $index }}-discount-value-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                        @endif
                                    </div>
                                </td>

                                @if ($taxes->isNotEmpty())
                                    <td class="py-2 px-2 align-top">
                                        <select id="invoice-desktop-line-{{ $index }}-tax-rate-id" aria-label="{{ __('sales.tax') }} {{ $index + 1 }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-tax-rate-id-error" @enderror wire:model.change="lines.{{ $index }}.tax_rate_id"
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
                                                wire:click="removeLine({{ $index }})"
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
                    @endphp
                    <div wire:key="inv-card-{{ $line['row_key'] }}" class="p-3 bg-canvas rounded-card border border-border space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-text-primary">#{{ $index + 1 }}</span>
                            @if (count($lines) > 1)
                                <button type="button" wire:click="removeLine({{ $index }})" class="min-h-[44px] px-3 text-danger text-sm font-bold cursor-pointer hover:bg-danger/10 rounded-control inline-flex items-center" wire:loading.attr="disabled" wire:target="removeLine" :disabled="documentBusy || saving">
                                    {{ __('sales.remove_line') }}
                                </button>
                            @endif
                        </div>

                        <!-- Product & Description -->
                        <div class="space-y-1">
                            <label for="invoice-mobile-line-{{ $index }}-product-id" class="block text-sm font-bold text-text-secondary">{{ __('sales.product') }}</label>
                            <select id="invoice-mobile-line-{{ $index }}-product-id" aria-label="{{ __('sales.product') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-product-id-error" @enderror wire:change="selectProduct({{ $index }}, $event.target.value)"
                                    class="w-full h-8 px-2 rounded-control border border-border bg-surface text-sm" :disabled="documentBusy || saving">
                                <option value="">-- {{ __('sales.product') }} --</option>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}" @selected($line['product_id'] == $p->id)>
                                        {{ $p->displayName() }} ({{ $p->sku }})
                                    </option>
                                @endforeach
                            </select> @error('lines.'.$index.'.product_id') <span id="invoice-mobile-line-{{ $index }}-product-id-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                            <input id="invoice-mobile-line-{{ $index }}-item-description" aria-label="{{ __('sales.description') }} {{ $index + 1 }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-item-description-error" @enderror type="text"
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
                                    <select id="invoice-mobile-line-{{ $index }}-product-unit-id" aria-label="{{ __('sales.unit') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-product-unit-id-error" @enderror wire:change="changeUnit({{ $index }}, $event.target.value)"
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

                        <!-- Unit Price & Discount -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-unit-price" class="block text-sm font-bold text-text-secondary">{{ __('sales.unit_price') }}</label>
                                <input id="invoice-mobile-line-{{ $index }}-unit-price" aria-label="{{ __('sales.unit_price') }} {{ $index + 1 }}" @error('lines.'.$index.'.unit_price') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-unit-price-error" @enderror type="number" inputmode="decimal"
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
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-discount-type" class="block text-sm font-bold text-text-secondary">{{ __('sales.discount') }}</label>
                                <div class="flex gap-1">
                                    <select id="invoice-mobile-line-{{ $index }}-discount-type" aria-label="{{ __('sales.discount_type') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_type') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-discount-type-error" @enderror wire:model.change="lines.{{ $index }}.discount_type"
                                            wire:change="recalculate"
                                            @if (! $canChangeDiscount) disabled @endif
                                            class="w-16 h-8 px-1 rounded-control border border-border bg-surface text-sm {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}" :disabled="documentBusy || saving || {{ ! $canChangeDiscount ? 'true' : 'false' }}">
                                        <option value="none">{{ __('sales.none') }}</option>
                                        <option value="fixed">{{ __('sales.fixed') }}</option>
                                        <option value="percent">%</option>
                                    </select> @error('lines.'.$index.'.discount_type') <span id="invoice-mobile-line-{{ $index }}-discount-type-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                    @if (($line['discount_type'] ?? 'none') !== 'none')
                                        <input id="invoice-mobile-line-{{ $index }}-discount-value" aria-label="{{ __('sales.discount_val') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_value') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-discount-value-error" @enderror type="number" inputmode="decimal"
                                               step="any"
                                               wire:model.blur="lines.{{ $index }}.discount_value"
                                               wire:change="recalculate"
                                               dir="ltr"
                                               @if (! $canChangeDiscount) readonly @endif
                                               class="w-full h-8 px-1.5 rounded-control border border-border bg-surface text-sm font-mono {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}"  :disabled="documentBusy || saving"/> @error('lines.'.$index.'.discount_value') <span id="invoice-mobile-line-{{ $index }}-discount-value-error" role="alert" class="text-danger text-xs block">{{ $message }}</span> @enderror
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($taxes->isNotEmpty())
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-tax-rate-id" class="block text-sm font-bold text-text-secondary">{{ __('sales.tax') }}</label>
                                <select id="invoice-mobile-line-{{ $index }}-tax-rate-id" aria-label="{{ __('sales.tax') }} {{ $index + 1 }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-tax-rate-id-error" @enderror wire:model.change="lines.{{ $index }}.tax_rate_id"
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
            <x-optional-fields id="invoice-optional-details" :open="$isEditing || filled($notes) || filled($terms)" :label="__('sales.optional_details')">
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
            </x-optional-fields>

            <!-- Preview Calculation Card -->
            <div class="bg-surface-soft p-4 rounded-control border border-border space-y-2 text-xs">
                @if (! $documentTotalsValid)
                    <p role="status" class="text-warning font-semibold">{{ __('camera.totals_unavailable') }}</p>
                @else
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.subtotal') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewSubtotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.discount') }}:</span>
                    <span class="font-mono text-danger font-bold" dir="ltr">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewDiscountTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
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
