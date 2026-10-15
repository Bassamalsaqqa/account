<div class="max-w-5xl mx-auto space-y-6"
     x-data="{
         saving: false,
         handleSaveDraft() {
             if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || $wire.showPreview || $wire.showQuickCustomerModal) return;
             this.saving = true;
             $wire.saveDraft().finally(() => { this.saving = false; });
         },
         handleAddLine() {
             if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || $wire.showPreview || $wire.showQuickCustomerModal) return;
             $wire.addLine();
         }
     }"
     @keydown.window.prevent.ctrl.s="handleSaveDraft()"
     @keydown.window.prevent.meta.s="handleSaveDraft()"
     @keydown.window.prevent.alt.n="if (!$event.repeat) handleAddLine()">
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

    @if ($previewError)
        <div role="alert" class="rounded-card border border-danger/30 bg-danger/10 p-3 text-xs text-danger font-medium flex items-center justify-between">
            <span>{{ $previewError }}</span>
            <button type="button" wire:click="$set('previewError', null)" class="text-danger hover:opacity-75 font-bold cursor-pointer">✕</button>
        </div>
    @endif

    <!-- Quick Barcode Scanner Bar -->
    @include('livewire.ai.unsaved-notice')

    <div class="bg-white p-4 rounded-card border border-border shadow-xs grid gap-4 md:grid-cols-2">
        @include('livewire.partials.barcode-entry', ['prefix' => 'invoice'])
        <div>
            <label for="invoice-product-search" class="block text-sm font-bold text-text-primary mb-1">{{ __('sales.search_products') }}</label>
            <input id="invoice-product-search" type="search" maxlength="160" wire:model.live.debounce.300ms="productSearch" x-on:keydown.enter.prevent
                   placeholder="{{ __('sales.search_products_hint') }}" aria-describedby="invoice-product-search-help"
                   class="w-full h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="mt-2 min-h-11 px-3 py-2 rounded-control border border-border text-sm font-bold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                    {{ __('quick_product.create') }}
                </button>
            @endif
            <p id="invoice-product-search-help" class="mt-1 text-xs text-text-secondary">{{ __('sales.product_search_help') }}</p>
            <p wire:loading wire:target="productSearch" role="status" class="mt-1 text-xs text-text-secondary">{{ __('sales.products_searching') }}</p>
            @if($productMatchCount === 0)<p role="status" class="mt-1 text-sm text-text-secondary">{{ __('sales.no_matching_products') }}</p>@endif
        </div>
    </div>

    <!-- Main Form -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-6">
        <!-- Document Meta Section -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 pb-4 border-b border-border">
            <!-- Customer -->
            <div class="md:col-span-2">
                <div class="flex items-center justify-between mb-1">
                    <label for="invoice-customer-id" class="block text-xs font-bold text-text-primary">
                        {{ __('sales.customer') }} <span class="text-danger">*</span>
                    </label>
                    @if ($canManageCustomers)
                        <button type="button" wire:click="openQuickCustomerModal" class="text-xs text-primary font-bold hover:underline cursor-pointer" aria-haspopup="dialog">
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
                <select id="invoice-customer-id" @error('customer_id') aria-invalid="true" aria-describedby="invoice-customer-id-error" @enderror wire:model.live="customer_id"
                        required
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="">{{ __('sales.select_customer') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->displayName() }} ({{ $c->default_currency_code ?? $baseCurrencyCode }})</option>
                    @endforeach
                </select>
                @error('customer_id') <span id="invoice-customer-id-error" role="alert" class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Warehouse -->
            <div class="md:col-span-2">
                <label for="invoice-warehouse-id" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.warehouse') }}
                </label>
                <select id="invoice-warehouse-id" @error('warehouse_id') aria-invalid="true" aria-describedby="invoice-warehouse-id-error" @enderror wire:model.live="warehouse_id"
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="">{{ __('sales.select_warehouse') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <span id="invoice-warehouse-id-error" role="alert" class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Issue Date -->
            <div>
                <label for="invoice-issue-date" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input id="invoice-issue-date" @error('issue_date') aria-invalid="true" aria-describedby="invoice-issue-date-error" @enderror type="date"
                       wire:model="issue_date"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                @error('issue_date') <span id="invoice-issue-date-error" role="alert" class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Due Date -->
            <div>
                <label for="invoice-due-date" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.due_date') }}
                </label>
                <input id="invoice-due-date" @error('due_date') aria-invalid="true" aria-describedby="invoice-due-date-error" @enderror type="date"
                       wire:model="due_date"
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                @error('due_date') <span id="invoice-due-date-error" role="alert" class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Currency -->
            <div>
                <label for="invoice-currency-code" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.currency') }} <span class="text-danger">*</span>
                </label>
                <select id="invoice-currency-code" wire:model.live="currency_code"
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    @foreach ($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
            </div>

            <!-- Exchange Rate -->
            <div>
                <label for="invoice-exchange-rate" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.exchange_rate') }} <span class="text-danger">*</span>
                </label>
                <input id="invoice-exchange-rate" @error('exchange_rate') aria-invalid="true" aria-describedby="invoice-exchange-rate-error" @enderror type="number"
                       step="0.0000000001"
                       wire:model.blur="exchange_rate"
                       wire:change="recalculate"
                       dir="ltr"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono" />
                @error('exchange_rate') <span id="invoice-exchange-rate-error" role="alert" class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
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
                        class="px-2.5 py-1 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors inline-flex items-center gap-1.5 cursor-pointer">
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
                            <th class="py-2 px-2 text-end w-28">{{ __('sales.total') }} <span class="text-[10px] font-normal text-text-muted">({{ __('workflow.preview') }})</span></th>
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
                                    <select id="invoice-desktop-line-{{ $index }}-product-id" aria-label="{{ __('sales.product') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-product-id-error" @enderror wire:change="selectProduct({{ $index }}, $event.target.value)"
                                            class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs">
                                        <option value="">-- {{ __('sales.product') }} --</option>
                                        @foreach ($products as $p)
                                            <option value="{{ $p->id }}" @selected($line['product_id'] == $p->id)>
                                                {{ $p->displayName() }} ({{ $p->sku }})
                                            </option>
                                        @endforeach
                                    </select> @error('lines.'.$index.'.product_id') <span id="invoice-desktop-line-{{ $index }}-product-id-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                    <input id="invoice-desktop-line-{{ $index }}-item-description" aria-label="{{ __('sales.description') }} {{ $index + 1 }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-item-description-error" @enderror type="text"
                                           wire:model="lines.{{ $index }}.item_description"
                                           placeholder="{{ __('sales.description') }}..."
                                           required
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs text-text-primary" /> @error('lines.'.$index.'.item_description') <span id="invoice-desktop-line-{{ $index }}-item-description-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                </td>

                                <td class="py-2 px-2 align-top">
                                    @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                                        <select id="invoice-desktop-line-{{ $index }}-product-unit-id" aria-label="{{ __('sales.unit') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-product-unit-id-error" @enderror wire:change="changeUnit({{ $index }}, $event.target.value)"
                                                class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-xs">
                                            @foreach ($selectedProduct->productUnits as $pu)
                                                <option value="{{ $pu->id }}" @selected(($line['product_unit_id'] ?? null) == $pu->id)>
                                                    {{ $pu->unit?->name() ?? $pu->unit?->code ?? 'Unit' }}
                                                </option>
                                            @endforeach
                                        </select> @error('lines.'.$index.'.product_unit_id') <span id="invoice-desktop-line-{{ $index }}-product-unit-id-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                    @else
                                        <span class="text-text-muted text-[11px] block pt-1.5">-</span>
                                    @endif
                                </td>

                                <td class="py-2 px-2 align-top space-y-1">
                                    <input id="invoice-desktop-line-{{ $index }}-quantity" aria-label="{{ __('sales.quantity') }} {{ $index + 1 }}" @error('lines.'.$index.'.quantity') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-quantity-error" @enderror type="number"
                                           step="any"
                                           wire:model.blur="lines.{{ $index }}.quantity"
                                           wire:change="recalculate"
                                           required
                                           min="0.000001"
                                           dir="ltr"
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono" /> @error('lines.'.$index.'.quantity') <span id="invoice-desktop-line-{{ $index }}-quantity-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                    @if (!empty($line['available_quantity']))
                                        <div class="text-[10px] text-text-secondary whitespace-nowrap" dir="ltr">
                                            {{ __('sales.available') }}: <span class="font-bold">{{ $line['available_quantity'] }}</span>
                                        </div>
                                    @endif
                                </td>

                                <td class="py-2 px-2 align-top">
                                    <input id="invoice-desktop-line-{{ $index }}-unit-price" aria-label="{{ __('sales.unit_price') }} {{ $index + 1 }}" @error('lines.'.$index.'.unit_price') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-unit-price-error" @enderror type="number"
                                           step="any"
                                           wire:model.blur="lines.{{ $index }}.unit_price"
                                           wire:change="recalculate"
                                           required
                                           min="0"
                                           dir="ltr"
                                           @if (! $canChangePrice) readonly title="{{ __('sales.price_permission_required') }}" @endif
                                           class="w-full h-8 px-2 rounded-control border border-border bg-canvas text-xs font-mono {{ ! $canChangePrice ? 'opacity-70 bg-slate-100 cursor-not-allowed' : '' }}" />
<x-customer-last-price :price="$lastCustomerPrices[$line['product_id'] ?? 0] ?? null" /> @error('lines.'.$index.'.unit_price') <span id="invoice-desktop-line-{{ $index }}-unit-price-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                </td>

                                <td class="py-2 px-2 align-top space-y-1">
                                    <div class="flex gap-1">
                                        <select id="invoice-desktop-line-{{ $index }}-discount-type" aria-label="{{ __('sales.discount_type') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_type') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-discount-type-error" @enderror wire:model.change="lines.{{ $index }}.discount_type"
                                                wire:change="recalculate"
                                                @if (! $canChangeDiscount) disabled title="{{ __('sales.discount_permission_required') }}" @endif
                                                class="w-20 h-8 px-1 rounded-control border border-border bg-canvas text-[11px] {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}">
                                            <option value="none">{{ __('sales.none') }}</option>
                                            <option value="fixed">{{ __('sales.fixed') }}</option>
                                            <option value="percent">%</option>
                                        </select> @error('lines.'.$index.'.discount_type') <span id="invoice-desktop-line-{{ $index }}-discount-type-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                        @if (($line['discount_type'] ?? 'none') !== 'none')
                                            <input id="invoice-desktop-line-{{ $index }}-discount-value" aria-label="{{ __('sales.discount_val') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_value') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-discount-value-error" @enderror type="number"
                                                   step="any"
                                                   wire:model.blur="lines.{{ $index }}.discount_value"
                                                   wire:change="recalculate"
                                                   dir="ltr"
                                                   @if (! $canChangeDiscount) readonly @endif
                                                   class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-xs font-mono {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}" /> @error('lines.'.$index.'.discount_value') <span id="invoice-desktop-line-{{ $index }}-discount-value-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                        @endif
                                    </div>
                                </td>

                                @if ($taxes->isNotEmpty())
                                    <td class="py-2 px-2 align-top">
                                        <select id="invoice-desktop-line-{{ $index }}-tax-rate-id" aria-label="{{ __('sales.tax') }} {{ $index + 1 }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="invoice-desktop-line-{{ $index }}-tax-rate-id-error" @enderror wire:model.change="lines.{{ $index }}.tax_rate_id"
                                                wire:change="recalculate"
                                                class="w-full h-8 px-1.5 rounded-control border border-border bg-canvas text-xs">
                                            <option value="">{{ __('sales.none') }} (0%)</option>
                                            @foreach ($taxes as $t)
                                                <option value="{{ $t->id }}">
                                                    {{ $t->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($t->rate) }}%)
                                                </option>
                                            @endforeach
                                        </select> @error('lines.'.$index.'.tax_rate_id') <span id="invoice-desktop-line-{{ $index }}-tax-rate-id-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
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
                            <label for="invoice-mobile-line-{{ $index }}-product-id" class="block text-[11px] font-bold text-text-secondary">{{ __('sales.product') }}</label>
                            <select id="invoice-mobile-line-{{ $index }}-product-id" aria-label="{{ __('sales.product') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-product-id-error" @enderror wire:change="selectProduct({{ $index }}, $event.target.value)"
                                    class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs">
                                <option value="">-- {{ __('sales.product') }} --</option>
                                @foreach ($products as $p)
                                    <option value="{{ $p->id }}" @selected($line['product_id'] == $p->id)>
                                        {{ $p->displayName() }} ({{ $p->sku }})
                                    </option>
                                @endforeach
                            </select> @error('lines.'.$index.'.product_id') <span id="invoice-mobile-line-{{ $index }}-product-id-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                            <input id="invoice-mobile-line-{{ $index }}-item-description" aria-label="{{ __('sales.description') }} {{ $index + 1 }}" @error('lines.'.$index.'.item_description') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-item-description-error" @enderror type="text"
                                   wire:model="lines.{{ $index }}.item_description"
                                   placeholder="{{ __('sales.description') }}..."
                                   required
                                   class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs text-text-primary" /> @error('lines.'.$index.'.item_description') <span id="invoice-mobile-line-{{ $index }}-item-description-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                        </div>

                        <!-- Unit & Quantity -->
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-product-unit-id" class="block text-[11px] font-bold text-text-secondary">{{ __('sales.unit') }}</label>
                                @if ($selectedProduct && $selectedProduct->productUnits->isNotEmpty())
                                    <select id="invoice-mobile-line-{{ $index }}-product-unit-id" aria-label="{{ __('sales.unit') }} {{ $index + 1 }}" @error('lines.'.$index.'.product_unit_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-product-unit-id-error" @enderror wire:change="changeUnit({{ $index }}, $event.target.value)"
                                            class="w-full h-8 px-1.5 rounded-control border border-border bg-white text-xs">
                                        @foreach ($selectedProduct->productUnits as $pu)
                                            <option value="{{ $pu->id }}" @selected(($line['product_unit_id'] ?? null) == $pu->id)>
                                                {{ $pu->unit?->name() ?? $pu->unit?->code ?? 'Unit' }}
                                            </option>
                                        @endforeach
                                    </select> @error('lines.'.$index.'.product_unit_id') <span id="invoice-mobile-line-{{ $index }}-product-unit-id-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                @else
                                    <span class="text-text-muted text-xs block pt-1.5">-</span>
                                @endif
                            </div>
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-quantity" class="block text-[11px] font-bold text-text-secondary">{{ __('sales.quantity') }}</label>
                                <input id="invoice-mobile-line-{{ $index }}-quantity" aria-label="{{ __('sales.quantity') }} {{ $index + 1 }}" @error('lines.'.$index.'.quantity') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-quantity-error" @enderror type="number"
                                       step="any"
                                       wire:model.blur="lines.{{ $index }}.quantity"
                                       wire:change="recalculate"
                                       required
                                       min="0.000001"
                                       dir="ltr"
                                       class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono" /> @error('lines.'.$index.'.quantity') <span id="invoice-mobile-line-{{ $index }}-quantity-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
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
                                <label for="invoice-mobile-line-{{ $index }}-unit-price" class="block text-[11px] font-bold text-text-secondary">{{ __('sales.unit_price') }}</label>
                                <input id="invoice-mobile-line-{{ $index }}-unit-price" aria-label="{{ __('sales.unit_price') }} {{ $index + 1 }}" @error('lines.'.$index.'.unit_price') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-unit-price-error" @enderror type="number"
                                       step="any"
                                       wire:model.blur="lines.{{ $index }}.unit_price"
                                       wire:change="recalculate"
                                       required
                                       min="0"
                                       dir="ltr"
                                       @if (! $canChangePrice) readonly @endif
                                       class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono {{ ! $canChangePrice ? 'opacity-70 bg-slate-100 cursor-not-allowed' : '' }}" />
<x-customer-last-price :price="$lastCustomerPrices[$line['product_id'] ?? 0] ?? null" /> @error('lines.'.$index.'.unit_price') <span id="invoice-mobile-line-{{ $index }}-unit-price-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                            </div>
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-discount-type" class="block text-[11px] font-bold text-text-secondary">{{ __('sales.discount') }}</label>
                                <div class="flex gap-1">
                                    <select id="invoice-mobile-line-{{ $index }}-discount-type" aria-label="{{ __('sales.discount_type') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_type') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-discount-type-error" @enderror wire:model.change="lines.{{ $index }}.discount_type"
                                            wire:change="recalculate"
                                            @if (! $canChangeDiscount) disabled @endif
                                            class="w-16 h-8 px-1 rounded-control border border-border bg-white text-[11px] {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}">
                                        <option value="none">{{ __('sales.none') }}</option>
                                        <option value="fixed">{{ __('sales.fixed') }}</option>
                                        <option value="percent">%</option>
                                    </select> @error('lines.'.$index.'.discount_type') <span id="invoice-mobile-line-{{ $index }}-discount-type-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                    @if (($line['discount_type'] ?? 'none') !== 'none')
                                        <input id="invoice-mobile-line-{{ $index }}-discount-value" aria-label="{{ __('sales.discount_val') }} {{ $index + 1 }}" @error('lines.'.$index.'.discount_value') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-discount-value-error" @enderror type="number"
                                               step="any"
                                               wire:model.blur="lines.{{ $index }}.discount_value"
                                               wire:change="recalculate"
                                               dir="ltr"
                                               @if (! $canChangeDiscount) readonly @endif
                                               class="w-full h-8 px-1.5 rounded-control border border-border bg-white text-xs font-mono {{ ! $canChangeDiscount ? 'opacity-70 cursor-not-allowed' : '' }}" /> @error('lines.'.$index.'.discount_value') <span id="invoice-mobile-line-{{ $index }}-discount-value-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
                                    @endif
                                </div>
                            </div>
                        </div>

                        @if ($taxes->isNotEmpty())
                            <div>
                                <label for="invoice-mobile-line-{{ $index }}-tax-rate-id" class="block text-[11px] font-bold text-text-secondary">{{ __('sales.tax') }}</label>
                                <select id="invoice-mobile-line-{{ $index }}-tax-rate-id" aria-label="{{ __('sales.tax') }} {{ $index + 1 }}" @error('lines.'.$index.'.tax_rate_id') aria-invalid="true" aria-describedby="invoice-mobile-line-{{ $index }}-tax-rate-id-error" @enderror wire:model.change="lines.{{ $index }}.tax_rate_id"
                                        wire:change="recalculate"
                                        class="w-full h-8 px-1.5 rounded-control border border-border bg-white text-xs">
                                    <option value="">{{ __('sales.none') }} (0%)</option>
                                    @foreach ($taxes as $t)
                                        <option value="{{ $t->id }}">
                                            {{ $t->displayName() }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($t->rate) }}%)
                                        </option>
                                    @endforeach
                                </select> @error('lines.'.$index.'.tax_rate_id') <span id="invoice-mobile-line-{{ $index }}-tax-rate-id-error" role="alert" class="text-danger text-[11px] block">{{ $message }}</span> @enderror
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
                    <label for="invoice-terms" class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.terms') }}
                    </label>
                    <textarea id="invoice-terms" wire:model="terms"
                              rows="3"
                              class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary outline-hidden"></textarea>
                </div>
                <div>
                    <label for="invoice-notes" class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('sales.notes') }}
                    </label>
                    <textarea id="invoice-notes" wire:model="notes"
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
                   class="px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                    {{ __('sales.cancel') }}
                </a>
                <button type="button"
                        wire:click="save(false)"
                        wire:loading.attr="disabled"
                        class="px-4 py-2 rounded-control border border-primary text-primary hover:bg-primary-50 text-xs font-bold transition-colors cursor-pointer inline-flex items-center gap-1.5">
                    <span>{{ __('sales.save_draft') }}</span>
                    <kbd class="px-1 py-0.5 text-[10px] font-mono rounded border border-primary/30 bg-primary-50/50 text-primary leading-none">Ctrl+S</kbd>
                </button>
                @if ($canPost)
                    <button id="invoice-post-button"
                            type="button"
                            wire:click="save(true)"
                            wire:loading.attr="disabled"
                            class="px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                        ⚡ {{ __('commands.review_post') }}
                    </button>
                @endif
            </div>
        @endif
    </div>

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
                        <label for="invoice-quick-customer-name-ar" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.customer_name_ar') }} <span class="text-danger">*</span></label>
                        <input x-ref="firstInput" id="invoice-quick-customer-name-ar" type="text" wire:model="quick_customer_name_ar" required maxlength="255" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                        @error('quick_customer_name_ar') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div>
                        <label for="invoice-quick-customer-name-en" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.customer_name_en') }}</label>
                        <input id="invoice-quick-customer-name-en" type="text" wire:model="quick_customer_name_en" maxlength="255" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                        @error('quick_customer_name_en') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="invoice-quick-customer-phone" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.phone') }}</label>
                            <input id="invoice-quick-customer-phone" type="text" wire:model="quick_customer_phone" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                            @error('quick_customer_phone') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                        <div>
                            <label for="invoice-quick-customer-code" class="block text-xs font-bold text-text-primary mb-1">{{ __('workflow.code') }}</label>
                            <input id="invoice-quick-customer-code" type="text" wire:model="quick_customer_code" maxlength="64" dir="ltr" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
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
