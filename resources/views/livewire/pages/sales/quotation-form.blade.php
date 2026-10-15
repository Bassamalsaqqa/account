<div class="max-w-5xl mx-auto space-y-6 pb-28 md:pb-6"
     x-data="{
         saving: false, documentBusy: false, operationFailed: false,
         handleSaveDraft() {
             if (this.saving || window.isDocumentBusy($wire) || [...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length)) return;
             this.saving = true;
             window.enqueueDocumentOperation($wire, 'saveDraft').catch(() => { this.operationFailed = true; }).finally(() => this.saving = false);
         },
         handleAddLine() {
             if (this.saving || window.isDocumentBusy($wire) || [...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length)) return;
             window.enqueueDocumentOperation($wire, 'addLine').catch(() => { this.operationFailed = true; });
         }
     }"
     @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy"
     @keydown.window.prevent.ctrl.s="if (!$event.repeat) handleSaveDraft()"
     @keydown.window.prevent.meta.s="if (!$event.repeat) handleSaveDraft()"
     @keydown.window.prevent.alt.n="if (!$event.repeat) handleAddLine()">
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="include_product_images">{{ __('sales.include_product_images') }}</label>
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ $isEditing ? __('sales.edit_quotation') : __('sales.new_quotation') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ $isEditing ? $quotation->quotation_number : __('sales.quotation_details') }}
            </p>
        </div>

        <a href="{{ $isEditing ? route('quotations.show', $quotation->public_id) : route('quotations.index') }}"
           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    <div class="bg-white rounded-card border border-border p-4">
        @include('livewire.partials.barcode-entry', ['prefix' => 'quotation'])
    </div>

    <!-- Main Form -->
    <form @submit.prevent="handleSaveDraft()" class="bg-white rounded-card border border-border shadow-xs p-6 space-y-6">
        <!-- Document Meta Section -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pb-4 border-b border-border">
            <!-- Customer -->
            <div class="md:col-span-2">
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-xs font-bold text-text-primary">
                        {{ __('sales.customer') }} <span class="text-danger">*</span>
                    </label>
                    @if ($canManageCustomers)
                        <button type="button"
                                wire:click="openQuickCustomerModal"
                                class="text-xs text-primary hover:underline font-medium cursor-pointer">
                            + {{ __('workflow.quick_customer') }}
                        </button>
                    @endif
                </div>
                <div class="flex gap-2 mb-1.5">
                    <input type="text"
                           wire:model.live.debounce.300ms="customerSearch"
                           placeholder="{{ __('workflow.search_customers') }}"
                           class="w-full h-8 px-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                </div>
                <select wire:model.live="customer_id"
                        required
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="">{{ __('sales.select_customer') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->displayName() }} ({{ $c->default_currency_code }})</option>
                    @endforeach
                </select>
                @error('customer_id') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Issue Date -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input type="date"
                       wire:model="issue_date"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                @error('issue_date') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Expiry Date -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.valid_until') }}
                </label>
                <input type="date"
                       wire:model="expiry_date"
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                @error('expiry_date') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Currency -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.currency') }} <span class="text-danger">*</span>
                </label>
                <select wire:model.live="currency_code"
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    @foreach ($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Exchange Rate -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.exchange_rate') }} <span class="text-danger">*</span>
                </label>
                <input type="number"
                       step="0.0000000001"
                       wire:model.blur="exchange_rate"
                       wire:change="recalculate"
                       dir="ltr"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono" />
                @error('exchange_rate') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Document Locale -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.preferred_locale') }}
                </label>
                <select wire:model="document_locale"
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="ar">{{ __('sales.language_ar') }}</option>
                    <option value="en">{{ __('sales.language_en') }}</option>
                </select>
            </div>
        </div>

        <!-- Lines Section -->
        <div>
            <div class="flex flex-wrap items-center justify-between gap-3 pb-2 border-b border-border">
                <div class="flex min-w-0 flex-1 flex-wrap items-center gap-3">
                    <h2 class="text-sm font-bold text-text-primary">
                        {{ __('sales.quotation_details') }}
                    </h2>
                    <div class="w-full sm:w-64">
                        <input type="text"
                               wire:model.live.debounce.300ms="productSearch"
                               placeholder="{{ __('workflow.search_products') }}"
                               class="w-full min-w-0 h-11 px-2.5 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="mt-2 min-h-11 px-3 py-2 rounded-control border border-border text-sm font-bold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    {{ __('quick_product.create') }}
                </button>
            @endif
                    </div>
                </div>
                <button type="button"
                        @click="handleAddLine()"
                        class="min-h-11 shrink-0 px-2.5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors inline-flex items-center gap-1.5 cursor-pointer">
                    <span>+ {{ __('sales.add_line') }}</span>
                    <kbd class="px-1 py-0.5 text-[10px] font-mono rounded bg-white/20 text-white leading-none">Alt+N</kbd>
                </button>
            </div>

            <!-- Desktop Lines Table -->
            <div class="hidden md:block overflow-x-auto mt-4">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px]">
                            <th class="py-2 px-2 text-start w-1/4">{{ __('sales.product') }}</th>
                            <th class="py-2 px-2 text-start w-24">{{ __('sales.unit') }}</th>
                            <th class="py-2 px-2 text-start w-20">{{ __('sales.quantity') }}</th>
                            <th class="py-2 px-2 text-start w-24">{{ __('sales.unit_price') }}</th>
                            <th class="py-2 px-2 text-start w-32">{{ __('sales.discount') }}</th>
                            @if ($taxes->isNotEmpty())
                                <th class="py-2 px-2 text-start w-28">{{ __('sales.tax') }}</th>
                            @endif
                            <th class="py-2 px-2 text-end w-24">
                                {{ __('sales.total') }}
                                <span class="text-[10px] text-text-muted font-normal block">{{ __('workflow.preview') }}</span>
                            </th>
                            <th class="py-2 px-1 text-center w-8"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($lines as $index => $line)
                            @php
                                $selectedProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                            @endphp
                            <tr wire:key="line-{{ $index }}">
                                <td class="py-2 px-2 align-top space-y-1">
                                    <select wire:change="selectProduct({{ $index }}, $event.target.value)"
                                            class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs">
                                        <option value="">-- {{ __('sales.product') }} --</option>
                                        @foreach ($products as $p)
                                            <option value="{{ $p->id }}" @selected($line['product_id'] == $p->id)>
                                                {{ $p->displayName() }} ({{ $p->sku }})
                                            </option>
                                        @endforeach
                                    </select>
                                    <input type="text"
                                           wire:model="lines.{{ $index }}.item_description"
                                           placeholder="{{ __('sales.description') }}..."
                                           required
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs text-text-primary" />
                                </td>

                                <td class="py-2 px-2 align-top">
                                    @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                                        <select wire:change="changeUnit({{ $index }}, $event.target.value)"
                                                class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-xs">
                                            @foreach ($selectedProduct->productUnits as $pu)
                                                <option value="{{ $pu->id }}" @selected(($line['product_unit_id'] ?? null) == $pu->id)>
                                                    {{ $pu->unit?->name() ?? $pu->unit?->code ?? 'Unit' }}
                                                </option>
                                            @endforeach
                                        </select>
                                    @else
                                        <span class="text-text-muted text-[11px] block pt-1.5">-</span>
                                    @endif
                                </td>

                                <td class="py-2 px-2 align-top space-y-1">
                                    <input type="number"
                                           step="any"
                                           wire:model.blur="lines.{{ $index }}.quantity"
                                           wire:change="recalculate"
                                           required
                                           min="0.000001"
                                           dir="ltr"
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono" />
                                    @if (!empty($line['available_quantity']))
                                        <div class="text-[10px] text-text-secondary whitespace-nowrap" dir="ltr">
                                            {{ __('sales.available') }}: <span class="font-bold">{{ $line['available_quantity'] }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td class="py-2 px-2 align-top">
                                    <input type="number"
                                           step="any"
                                           wire:model.blur="lines.{{ $index }}.unit_price"
                                           wire:change="recalculate"
                                           required
                                           min="0"
                                           dir="ltr"
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono" />
<x-customer-last-price :price="$lastCustomerPrices[$line['product_id'] ?? 0] ?? null" />
                                </td>

                                <td class="py-2 px-2 align-top space-y-1">
                                    <div class="flex gap-1">
                                        <select wire:model.change="lines.{{ $index }}.discount_type"
                                                wire:change="recalculate"
                                                class="w-20 h-8 px-1 rounded-control border border-border bg-canvas text-[11px]">
                                            <option value="none">{{ __('sales.none') }}</option>
                                            <option value="fixed">{{ __('sales.fixed') }}</option>
                                            <option value="percentage">%</option>
                                        </select>
                                        @if (($line['discount_type'] ?? 'none') !== 'none')
                                            <input type="number"
                                                   step="any"
                                                   wire:model.blur="lines.{{ $index }}.discount_value"
                                                   wire:change="recalculate"
                                                   dir="ltr"
                                                   class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-xs font-mono" />
                                        @endif
                                    </div>
                                </td>

                                @if ($taxes->isNotEmpty())
                                    <td class="py-2 px-2 align-top">
                                        <select wire:model.change="lines.{{ $index }}.tax_rate_id"
                                                wire:change="recalculate"
                                                class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-xs">
                                            <option value="">{{ __('sales.none') }} (0%)</option>
                                            @foreach ($taxes as $t)
                                                <option value="{{ $t->id }}">
                                                    {{ $t->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($t->rate) }}%)
                                                </option>
                                            @endforeach
                                        </select>
                                    </td>
                                @endif

                                <td class="py-2 px-2 align-top text-end font-mono font-bold" dir="ltr">
                                    <div class="pt-1.5">
                                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line['line_total'] ?? '0', $currency_code) }}
                                    </div>
                                </td>

                                <td class="py-2 px-1 align-top text-center">
                                    @if (count($lines) > 1)
                                        <button type="button"
                                                wire:click="removeLine({{ $index }})"
                                                class="text-danger hover:opacity-80 p-1 text-sm font-bold">
                                            ✕
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
                    <div wire:key="quote-card-{{ $index }}" class="p-3 bg-canvas rounded-card border border-border space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-text-primary">#{{ $index + 1 }}</span>
                            @if (count($lines) > 1)
                                <button type="button" wire:click="removeLine({{ $index }})" class="text-danger text-xs font-bold px-2 py-1 rounded-control hover:bg-danger/10">
                                    {{ __('sales.remove_line') }}
                                </button>
                            @endif
                        </div>

                        <!-- Product & Description -->
                        <div class="space-y-1">
                            <label class="block text-[11px] font-bold text-text-secondary">{{ __('sales.product') }}</label>
                            <select wire:change="selectProduct({{ $index }}, $event.target.value)"
                                    class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs">
                                <option value="">-- {{ __('sales.product') }} --</option>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}" @selected($line['product_id'] == $p->id)>
                                        {{ $p->displayName() }} ({{ $p->sku }})
                                    </option>
                                @endforeach
                            </select>
                            <input type="text"
                                   wire:model="lines.{{ $index }}.item_description"
                                   placeholder="{{ __('sales.description') }}..."
                                   required
                                   class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs text-text-primary" />
                        </div>

                        <!-- Unit & Quantity -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] font-bold text-text-secondary">{{ __('sales.unit') }}</label>
                                @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                                    <select wire:change="changeUnit({{ $index }}, $event.target.value)"
                                            class="w-full h-8 px-1.5 rounded-control border border-border bg-white text-xs">
                                        @foreach ($selectedProduct->productUnits as $pu)
                                            <option value="{{ $pu->id }}" @selected(($line['product_unit_id'] ?? null) == $pu->id)>
                                                {{ $pu->unit?->name() ?? $pu->unit?->code ?? 'Unit' }}
                                            </option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="text-text-muted text-xs block pt-1.5">-</span>
                                @endif
                            </div>
                            <div>
                                <label for="quote-mobile-line-{{ $index }}-quantity" class="block text-[11px] font-bold text-text-secondary mb-1">{{ __('sales.quantity') }}</label>
                                @php
                                    $lineProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                                    $selectedProductUnit = $lineProduct && !empty($line['product_unit_id']) ? $lineProduct->productUnits->firstWhere('id', $line['product_unit_id']) : null;
                                    $lineAllowsFraction = $selectedProductUnit?->unit?->allows_fraction ?? false;
                                @endphp
                                <x-touch-quantity-stepper
                                    :index="$index"
                                    :allows-fraction="$lineAllowsFraction"
                                    id="quote-mobile-line-{{ $index }}-quantity"
                                    :aria-label="__('sales.quantity') . ' ' . ($index + 1)"
                                />
                                @error('lines.'.$index.'.quantity') <span id="quote-mobile-line-{{ $index }}-quantity-error" role="alert" class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                                @if (!empty($line['available_quantity']))
                                    <div class="text-[10px] text-text-secondary mt-0.5" dir="ltr">
                                        {{ __('sales.available') }}: <span class="font-bold">{{ $line['available_quantity'] }}</span>
                                    </div>
                                @endif
                            </div>
                        </div>

                        <!-- Unit Price & Discount -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="block text-[11px] font-bold text-text-secondary">{{ __('sales.unit_price') }}</label>
                                <input type="number"
                                       step="any"
                                       wire:model.blur="lines.{{ $index }}.unit_price"
                                       wire:change="recalculate"
                                       required
                                       min="0"
                                       dir="ltr"
                                       class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono" />
<x-customer-last-price :price="$lastCustomerPrices[$line['product_id'] ?? 0] ?? null" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-text-secondary">{{ __('sales.discount') }}</label>
                                <div class="flex gap-1">
                                    <select wire:model.change="lines.{{ $index }}.discount_type"
                                            wire:change="recalculate"
                                            class="w-16 h-8 px-1 rounded-control border border-border bg-white text-[11px]">
                                        <option value="none">{{ __('sales.none') }}</option>
                                        <option value="fixed">{{ __('sales.fixed') }}</option>
                                        <option value="percentage">%</option>
                                    </select>
                                    @if (($line['discount_type'] ?? 'none') !== 'none')
                                        <input type="number"
                                               step="any"
                                               wire:model.blur="lines.{{ $index }}.discount_value"
                                               wire:change="recalculate"
                                               dir="ltr"
                                               class="w-full h-8 px-1.5 rounded-control border border-border bg-white text-xs font-mono" />
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($taxes->isNotEmpty())
                            <div>
                                <label class="block text-[11px] font-bold text-text-secondary">{{ __('sales.tax') }}</label>
                                <select wire:model.change="lines.{{ $index }}.tax_rate_id"
                                        wire:change="recalculate"
                                        class="w-full h-8 px-1.5 rounded-control border border-border bg-white text-xs">
                                    <option value="">{{ __('sales.none') }} (0%)</option>
                                    @foreach ($taxes as $t)
                                        <option value="{{ $t->id }}">
                                            {{ $t->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($t->rate) }}%)
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        @endif

                        <div class="flex justify-between items-center pt-2 border-t border-border text-xs">
                            <span class="text-text-muted font-bold">{{ __('sales.line_total') }}:</span>
                            <span class="font-mono font-bold text-text-primary" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line['line_total'] ?? '0', $currency_code) }}
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
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.terms') }}
                    </label>
                    <textarea wire:model="terms"
                              rows="3"
                              class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary outline-hidden"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.notes') }}
                    </label>
                    <textarea wire:model="notes"
                              rows="2"
                              class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary outline-hidden"></textarea>
                </div>
            </div>

            <!-- Preview Calculation Card -->
            <div class="bg-surface-soft p-4 rounded-control border border-border space-y-2 text-xs">
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.subtotal') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($previewSubtotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.discount') }}:</span>
                    <span class="font-mono text-danger font-bold" dir="ltr">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($previewDiscountTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.tax') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($previewTaxTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                <div class="flex justify-between py-2 border-t border-border text-sm font-extrabold text-text-primary">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span class="font-mono text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($previewGrandTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ $isEditing ? route('quotations.show', $quotation->public_id) : route('quotations.index') }}"
               class="px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer inline-flex items-center gap-1.5">
                <span>{{ __('sales.save_draft') }}</span>
                <kbd class="px-1 py-0.5 text-[10px] font-mono rounded bg-white/20 text-white leading-none">Ctrl+S</kbd>
            </button>
        </div>

        <!-- Responsive Sticky Mobile Summary & Actions Toolbar -->
        <div class="md:hidden fixed inset-x-0 z-30 bg-white/95 backdrop-blur-xs border-t border-border shadow-lg px-3 py-2 transition-transform mobile-document-actions"
             style="bottom: var(--mobile-nav-height, calc(4.5rem + env(safe-area-inset-bottom)));"
             x-data="{ busy: false }">
            <div class="max-w-[500px] mx-auto flex items-center justify-between gap-2">
                <div class="min-w-0 flex-1">
                    <span class="text-[10px] text-text-muted block leading-none truncate">{{ __('mobile.grand_total') }}</span>
                    <span class="text-xs font-mono font-extrabold text-primary block leading-tight truncate" dir="ltr">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($previewGrandTotal, $currency_code) }} {{ $currency_code }}
                    </span>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button type="button"
                            @click="$dispatch('mobile-camera-open')"
                            :disabled="busy || documentBusy || saving"
                            wire:loading.attr="disabled"
                            wire:target="scanBarcode,addLine,saveDraft,save"
                            class="min-h-[44px] min-w-[44px] px-2.5 rounded-control border border-border bg-surface-soft hover:bg-slate-200 active:bg-slate-300 text-text-primary text-xs font-bold flex items-center justify-center gap-1 cursor-pointer transition-colors"
                            title="{{ __('mobile.scan') }}">
                        <x-icon name="barcode" class="w-4 h-4 shrink-0" />
                        <span class="hidden xs:inline text-[11px]">{{ __('mobile.scan') }}</span>
                    </button>
                    <button type="button"
                            :disabled="busy || documentBusy || saving"
                            wire:loading.attr="disabled"
                            wire:target="addLine,scanBarcode,saveDraft,save"
                            @click="if (busy) return; busy = true; (window.enqueueDocumentOperation ? window.enqueueDocumentOperation($wire, 'addLine') : $wire.addLine()).catch(() => { operationFailed = true; }).finally(() => { busy = false; })"
                            class="min-h-[44px] min-w-[44px] px-2.5 rounded-control border border-primary/30 bg-primary-50 text-primary hover:bg-primary-100 active:bg-primary-200 text-xs font-bold flex items-center justify-center gap-1 cursor-pointer transition-colors"
                            title="{{ __('mobile.add_item') }}">
                        <x-icon name="plus" class="w-4 h-4 shrink-0" />
                        <span class="hidden xs:inline text-[11px]">{{ __('mobile.add_item') }}</span>
                    </button>
                    <button type="button"
                            :disabled="busy || documentBusy || saving"
                            wire:loading.attr="disabled"
                            wire:target="saveDraft,save,addLine,scanBarcode"
                            @click="if (busy) return; busy = true; (window.enqueueDocumentOperation ? window.enqueueDocumentOperation($wire, 'saveDraft') : $wire.saveDraft()).catch(() => { operationFailed = true; }).finally(() => { busy = false; })"
                            class="min-h-[44px] px-3 rounded-control bg-primary text-white hover:bg-primary-hover active:bg-primary-hover/90 text-xs font-bold flex items-center justify-center gap-1 shadow-xs cursor-pointer transition-colors"
                            title="{{ __('mobile.save_draft') }}">
                        <span wire:loading.remove wire:target="saveDraft">{{ __('mobile.save_draft') }}</span>
                        <span wire:loading wire:target="saveDraft">...</span>
                    </button>
                </div>
            </div>
        </div>
        <style>
            html.mobile-keyboard-open .mobile-document-actions,
            body.--mobile-keyboard-open .mobile-document-actions {
                display: none !important;
            }
        </style>
    </form>

    @if ($showQuickCustomerModal)
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
             @keydown.escape.window.stop.prevent="$wire.closeQuickCustomerModal()"
             @keydown.enter.stop.prevent="saveQuickCustomer"
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50 backdrop-blur-xs"
             role="dialog"
             aria-modal="true"
             aria-labelledby="quick-customer-modal-title">
            <div class="bg-surface border border-border rounded-card p-6 w-full max-w-md shadow-lg space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-border">
                    <h3 id="quick-customer-modal-title" class="text-base font-bold text-text-primary">{{ __('workflow.quick_customer') }}</h3>
                    <button type="button" wire:click="closeQuickCustomerModal" class="text-text-muted hover:text-text-primary text-sm font-bold cursor-pointer">✕</button>
                </div>
                <div class="space-y-3">
                    <div>
                        <label for="quote-quick-customer-name-ar" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.customer_name_ar') }} <span class="text-danger">*</span></label>
                        <input x-ref="firstInput" id="quote-quick-customer-name-ar" type="text" wire:model="quick_customer_name_ar" required maxlength="255" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                        @error('quick_customer_name_ar') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="quote-quick-customer-name-en" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.customer_name_en') }}</label>
                        <input id="quote-quick-customer-name-en" type="text" wire:model="quick_customer_name_en" maxlength="255" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                        @error('quick_customer_name_en') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="quote-quick-customer-phone" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.phone') }}</label>
                            <input id="quote-quick-customer-phone" type="text" wire:model="quick_customer_phone" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                            @error('quick_customer_phone') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="quote-quick-customer-code" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.code') }}</label>
                            <input id="quote-quick-customer-code" type="text" wire:model="quick_customer_code" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                            @error('quick_customer_code') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                    </div>
                </div>
                <div class="flex items-center justify-end gap-2 pt-3 border-t border-border">
                    <button type="button" wire:click="closeQuickCustomerModal" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft cursor-pointer">{{ __('workflow.cancel') }}</button>
                    <button type="button" wire:click="saveQuickCustomer" wire:loading.attr="disabled" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover shadow-xs cursor-pointer">
                        <span wire:loading.remove wire:target="saveQuickCustomer">{{ __('workflow.save_customer') }}</span>
                        <span wire:loading wire:target="saveQuickCustomer">...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif
    @include('livewire.partials.quick-product-modal')
</div>
