<div class="max-w-5xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ $isEditing ? __('sales.edit_invoice_draft') : __('sales.new_invoice') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ $isEditing ? ($invoice->invoice_number ?? __('sales.draft')) : __('sales.invoice_details') }}
            </p>
        </div>

        <a href="{{ $isEditing ? route('invoices.show', $invoice->public_id) : route('invoices.index') }}"
           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    @if ($creditLimitWarning)
        <div role="status" class="rounded-card border border-warning/30 bg-warning/10 p-3 text-sm text-text-primary">
            {{ __('sales.credit_limit_warning') }}
        </div>
    @endif

    <!-- Quick Barcode Scanner Bar -->
    <div class="bg-white p-4 rounded-card border border-border shadow-xs">
        <label class="block text-xs font-bold text-text-primary mb-1">
            ⚡ {{ __('sales.scan_or_search_product') }}
        </label>
        <div class="relative max-w-md">
            <input type="text"
                   wire:model="barcodeSearch"
                   wire:keydown.enter.prevent="scanBarcode"
                   placeholder="{{ __('sales.barcode') }} / SKU + Enter..."
                   class="w-full h-9 pl-9 pr-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-text-muted">
                <x-icon name="barcode" class="w-4 h-4" />
            </div>
        </div>
    </div>

    <!-- Main Form -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-6">
        <!-- Document Meta Section -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pb-4 border-b border-border">
            <!-- Customer -->
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.customer') }} <span class="text-danger">*</span>
                </label>
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

            <!-- Warehouse -->
            <div class="md:col-span-2">
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.warehouse') }}
                </label>
                <select wire:model.live="warehouse_id"
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="">{{ __('sales.select_warehouse') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
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

            <!-- Due Date -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.due_date') }} <span class="text-danger">*</span>
                </label>
                <input type="date"
                       wire:model="due_date"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                @error('due_date') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
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
        </div>

        <!-- Lines Section -->
        <div>
            <div class="flex items-center justify-between pb-2 border-b border-border">
                <h2 class="text-sm font-bold text-text-primary">
                    {{ __('sales.invoice_details') }}
                </h2>
                <button type="button"
                        wire:click="addLine"
                        class="px-2.5 py-1 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors">
                    + {{ __('sales.add_line') }}
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
                            <th class="py-2 px-2 text-end w-24">{{ __('sales.total') }}</th>
                            <th class="py-2 px-1 text-center w-8"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($lines as $index => $line)
                            @php
                                $selectedProduct = !empty($line['product_id']) ? $products->firstWhere('id', $line['product_id']) : null;
                            @endphp
                            <tr wire:key="inv-line-{{ $index }}">
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
                                           @if (! $canChangePrice) readonly title="Permission required to change price" @endif
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono {{ ! $canChangePrice ? 'opacity-70 bg-slate-100 cursor-not-allowed' : '' }}" />
                                </td>

                                <td class="py-2 px-2 align-top space-y-1">
                                    <div class="flex gap-1">
                                        <select wire:model.change="lines.{{ $index }}.discount_type"
                                                wire:change="recalculate"
                                                @if (! $canChangeDiscount) disabled title="Permission required to change discount" @endif
                                                class="w-20 h-8 px-1 rounded-control border border-border bg-canvas text-[11px] {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}">
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
                                                   @if (! $canChangeDiscount) readonly @endif
                                                   class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-xs font-mono {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}" />
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
                    <div wire:key="inv-card-{{ $index }}" class="p-3 bg-canvas rounded-card border border-border space-y-3">
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
                                <label class="block text-[11px] font-bold text-text-secondary">{{ __('sales.quantity') }}</label>
                                <input type="number"
                                       step="any"
                                       wire:model.blur="lines.{{ $index }}.quantity"
                                       wire:change="recalculate"
                                       required
                                       min="0.000001"
                                       dir="ltr"
                                       class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono" />
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
                                       @if (! $canChangePrice) readonly @endif
                                       class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono {{ ! $canChangePrice ? 'opacity-70 bg-slate-100 cursor-not-allowed' : '' }}" />
                            </div>
                            <div>
                                <label class="block text-[11px] font-bold text-text-secondary">{{ __('sales.discount') }}</label>
                                <div class="flex gap-1">
                                    <select wire:model.change="lines.{{ $index }}.discount_type"
                                            wire:change="recalculate"
                                            @if (! $canChangeDiscount) disabled @endif
                                            class="w-16 h-8 px-1 rounded-control border border-border bg-white text-[11px] {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}">
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
                                               @if (! $canChangeDiscount) readonly @endif
                                               class="w-full h-8 px-1.5 rounded-control border border-border bg-white text-xs font-mono {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}" />
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
                <div class="flex justify-between py-2 border-t border-border text-base font-extrabold text-text-primary">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span class="font-mono text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($previewGrandTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ $isEditing ? route('invoices.show', $invoice->public_id) : route('invoices.index') }}"
               class="px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="button"
                    wire:click="save(false)"
                    class="px-4 py-2 rounded-control border border-primary text-primary hover:bg-primary-50 text-xs font-bold transition-colors cursor-pointer">
                {{ __('sales.save_draft') }}
            </button>
            @if ($canPost)
                <button type="button"
                        wire:click="save(true)"
                        class="px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                    ⚡ {{ __('sales.post') }}
                </button>
            @endif
        </div>
    </div>
</div>
