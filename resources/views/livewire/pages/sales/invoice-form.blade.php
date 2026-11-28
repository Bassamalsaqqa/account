<div data-entry-form class="max-w-5xl mx-auto space-y-6 pb-44 md:pb-6"
     x-data="{
         saving: false, documentBusy: false, operationFailed: false,
         handleSaveDraft() {
             if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBlocked($wire) || $wire.showPreview || $wire.showQuickCustomerModal) return;
             if ($wire.$el?.dataset?.rowMutationBusy === 'true') {
                 const status = $wire.$el.querySelector('[data-row-mutation-status]');
                 if (status) status.textContent = status.dataset.retryLabel;
                 return;
             }
             this.saving = true;
             window.enqueueDocumentOperation($wire, 'saveDraft').catch(() => { this.operationFailed = true; }).finally(() => { this.saving = false; });
         },
         handleAddLine() {
             if ([...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0) || this.saving || window.isDocumentBlocked($wire) || $wire.showPreview || $wire.showQuickCustomerModal) return;
             if ($wire.$el?.dataset?.rowMutationBusy === 'true') {
                 const status = $wire.$el.querySelector('[data-row-mutation-status]');
                 if (status) status.textContent = status.dataset.retryLabel;
                 return;
             }
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
           class="min-h-11 lg:min-h-9.5 inline-flex items-center px-3 rounded-control border border-border-control bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">
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
                        <button type="button" wire:click="openQuickCustomerModal" class="party-create-button shrink-0 min-h-11 lg:min-h-9.5" aria-haspopup="dialog" wire:loading.attr="disabled" wire:target="openQuickCustomerModal" :disabled="documentBusy || saving">
                            + {{ __('workflow.quick_customer') }}
                        </button>
                    @endif
                </div>
                @error('customer_id') <span id="invoice-customer-id-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror

            </div>

            <!-- Issue Date -->
            <div>
                <label for="invoice-issue-date" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input id="invoice-issue-date" @error('issue_date') aria-invalid="true" aria-describedby="invoice-issue-date-error" @enderror type="date"
                       wire:model="issue_date"
                       required
                       class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('issue_date') <span id="invoice-issue-date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

        </div>
        <div class="border-t border-border pt-3">
            <div class="entry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-3">
            <!-- Warehouse -->
            <div class="">
                <label for="invoice-warehouse-id" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.warehouse') }}
                </label>
                <select id="invoice-warehouse-id" @error('warehouse_id') aria-invalid="true" aria-describedby="invoice-warehouse-id-error" @enderror wire:model.live="warehouse_id"
                        class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    <option value="">{{ __('sales.select_warehouse') }}</option>
                    @foreach ($warehouses as $w)
                        <option value="{{ $w->id }}">{{ $w->displayName() }}</option>
                    @endforeach
                </select>
                @error('warehouse_id') <span id="invoice-warehouse-id-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Due Date -->
            <div>
                <label for="invoice-due-date" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.due_date') }}
                </label>
                <input id="invoice-due-date" @error('due_date') aria-invalid="true" aria-describedby="invoice-due-date-error" @enderror type="date"
                       wire:model="due_date"
                       class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('due_date') <span id="invoice-due-date-error" role="alert" class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Currency -->
            <div>
                <label for="invoice-currency-code" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.currency') }} <span class="text-danger">*</span>
                </label>
                <select id="invoice-currency-code" wire:model.live="currency_code"
                        class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    @foreach ($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
            </div>

            @if ($currency_code !== $baseCurrencyCode)
            <!-- Exchange Rate -->
            <div>
                <label for="invoice-exchange-rate" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.exchange_rate') }} <span class="text-danger">*</span>
                </label>
                <input id="invoice-exchange-rate" @error('exchange_rate') aria-invalid="true" aria-describedby="invoice-exchange-rate-error" @enderror type="number" inputmode="decimal"
                       step="0.0000000001"
                       wire:model.blur="exchange_rate"
                       wire:change="recalculate"
                       dir="ltr"
                       required
                       class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono tabular-nums"  :disabled="documentBusy || saving"/>
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
                <div class="flex-1 min-w-0">
                    <x-searchable-combobox
                        id="invoice-product-search"
                        type="product"
                        context="sales"
                        on-select="quickAddProduct"
                        :reset-after-select="true"
                        :has-error="$errors->has('quickProduct')"
                        :placeholder="__('sales.search_products_hint')"
                        :aria-label="__('sales.search_products')"
                    />
                </div>
                @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                    <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="shrink-0 min-h-11 lg:min-h-9.5 px-3 py-2 rounded-control border border-border-control text-sm font-semibold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer" wire:loading.attr="disabled" wire:target="openQuickProductModal" :disabled="documentBusy || saving">
                        + {{ __('quick_product.create') }}
                    </button>
                @endif
            </div>
            @error('quickProduct') <span id="invoice-product-search-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
        </div>
    </div>


        <!-- Lines Section -->
        <div>
            <div class="flex items-center justify-between pb-2 border-b border-border">
                <h2 class="text-sm font-bold text-text-primary">
                    {{ __('sales.invoice_details') }}
                </h2>
                <button type="button"
                        data-row-mutation-action
                        @click="handleAddLine()"
                        class="min-h-11 lg:min-h-9.5 px-3 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors inline-flex items-center gap-1.5 cursor-pointer">
                    <span>+ {{ __('sales.add_line') }}</span>
                    <kbd class="px-1 py-0.5 text-xs font-mono rounded bg-surface/20 text-white leading-none">Alt+N</kbd>
                </button>
            </div>

            <p data-row-mutation-status data-retry-label="{{ __('workflow.retry_after_line_update') }}" data-busy-label="{{ __('workflow.updating_lines') }}" role="status" class="min-h-5 text-xs text-text-secondary"></p>
            @include('livewire.pages.sales.partials.invoice-lines')
        </div>

        <!-- Totals & Notes Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-border">
            <div class="space-y-4">
                <div>
                    <label for="invoice-terms" class="block text-sm font-semibold text-text-primary mb-1.5">
                        {{ __('sales.terms') }}
                    </label>
                    <textarea id="invoice-terms" wire:model="terms"
                              rows="3"
                              class="w-full p-2.5 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
                </div>
                <div>
                    <label for="invoice-notes" class="block text-sm font-semibold text-text-primary mb-1.5">
                        {{ __('sales.notes') }}
                    </label>
                    <textarea id="invoice-notes" wire:model="notes"
                              rows="2"
                              class="w-full p-2.5 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
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
                                        <label for="sales-discount-type" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.discount') }}</label>
                                        <select id="sales-discount-type" wire:model.live="discount_type" wire:change="recalculate" class="w-full min-h-11 lg:min-h-9.5 px-2.5 border border-border-control bg-canvas rounded-control text-base lg:text-sm" :disabled="documentBusy || saving">
                                            <option value="none">{{ __('sales.discount_none') }}</option>
                                            <option value="percent">{{ __('sales.discount_percent') }}</option>
                                            <option value="fixed">{{ __('sales.discount_fixed') }}</option>
                                        </select>
                                    </div>
                                    @if ($discount_type !== 'none')
                                        <div>
                                            <label for="sales-discount-value" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.discount_value') }}</label>
                                            <input id="sales-discount-value" @error('discount_value') aria-invalid="true" aria-describedby="sales-discount-value-error" @enderror type="text" inputmode="decimal" dir="ltr" wire:model="discount_value" wire:change="recalculate" class="w-full min-h-11 lg:min-h-9.5 px-2.5 border border-border-control bg-canvas rounded-control text-base lg:text-sm font-mono tabular-nums" placeholder="0.00" :disabled="documentBusy || saving" />
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
            <div class="flex flex-wrap items-center justify-end gap-3 pt-4 border-t border-border">
                <a href="{{ $isEditing ? route('invoices.show', $invoice->public_id) : route('invoices.index') }}"
                   class="touch-action min-h-11 lg:min-h-9.5 inline-flex items-center justify-center px-4 rounded-control border border-border-control text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors">
                    {{ __('sales.cancel') }}
                </a>
                <button type="button"
                        data-row-mutation-action
                        @click="handleSaveDraft()"
                        wire:loading.attr="disabled"
                        class="min-h-11 lg:min-h-9.5 px-4 rounded-control border border-primary text-primary hover:bg-primary-50 text-sm font-bold transition-colors cursor-pointer inline-flex items-center justify-center gap-1.5">
                    <span>{{ __('sales.save_draft') }}</span>
                    <kbd class="px-1 py-0.5 text-xs font-mono rounded border border-primary/30 bg-primary-50/50 text-primary leading-none">Ctrl+S</kbd>
                </button>
                @if ($canPost)
                    <button id="invoice-post-button"
                            type="button"
                            data-row-mutation-action
                            @click="if (!saving && !window.isDocumentBlocked($wire)) { saving = true; window.enqueueDocumentOperation($wire, 'saveAndConfirm').catch(() => { this.operationFailed = true; }).finally(() => saving = false); }"
                            wire:loading.attr="disabled"
                            class="min-h-11 lg:min-h-9.5 inline-flex items-center justify-center px-5 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                        {{ __('commands.review_post') }}
                    </button>
                @endif
            </div>
        @endif

        <!-- Responsive Sticky Mobile Summary & Actions Toolbar -->
        @if (! $showPreview)
            <x-document-mobile-actions :total="$previewGrandTotal" :currency="$currency_code" :valid="$documentTotalsValid" :can-confirm="$canPost" :confirm-label="__('commands.review_post')" />
        @endif
    </div>

    @include('livewire.partials.quick-customer-modal')
    @include('livewire.partials.quick-product-modal')
</div>
