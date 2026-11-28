<div data-entry-form class="max-w-5xl mx-auto space-y-6 pb-28 md:pb-6"
     x-data="{
         saving: false, documentBusy: false, operationFailed: false,
         handleSaveDraft() {
             if (this.saving || window.isDocumentBlocked($wire) || [...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length)) return;
             if ($wire.$el?.dataset?.rowMutationBusy === 'true') {
                 const status = $wire.$el.querySelector('[data-row-mutation-status]');
                 if (status) status.textContent = status.dataset.retryLabel;
                 return;
             }
             this.saving = true;
             window.enqueueDocumentOperation($wire, 'saveDraft').catch(() => { this.operationFailed = true; }).finally(() => this.saving = false);
         },
         handleAddLine() {
             if (this.saving || window.isDocumentBlocked($wire) || [...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length)) return;
             if ($wire.$el?.dataset?.rowMutationBusy === 'true') {
                 const status = $wire.$el.querySelector('[data-row-mutation-status]');
                 if (status) status.textContent = status.dataset.retryLabel;
                 return;
             }
             window.enqueueDocumentOperation($wire, 'addLine').catch(() => { this.operationFailed = true; });
         }
     }"
     @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy"
     @keydown.window.prevent.ctrl.s="if (!$event.repeat) handleSaveDraft()"
     @keydown.window.prevent.meta.s="if (!$event.repeat) handleSaveDraft()"
     @keydown.window.prevent.alt.n="if (!$event.repeat) handleAddLine()">
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <x-form-attention />
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
           class="min-h-11 lg:min-h-9.5 inline-flex items-center px-3 rounded-control border border-border-control bg-surface text-sm font-semibold text-text-secondary hover:bg-surface-soft hover:text-text-primary transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    <!-- Main Form -->
    <form @submit.prevent="handleSaveDraft()" class="bg-surface rounded-card border border-border shadow-xs p-4 sm:p-6 space-y-6">
        <!-- Document Meta Section -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Customer -->
            <div class="md:col-span-2">
                <div class="flex items-center justify-between mb-1.5">
                    <label for="quotation-customer-id" class="block text-sm font-semibold text-text-primary">
                        {{ __('sales.customer') }} <span class="text-danger">*</span>
                    </label>

                </div>
                @php
                    $selectedCustomer = $customer_id ? $customers->firstWhere('id', $customer_id) : null;
                    $customerLabel = $selectedCustomer ? $selectedCustomer->displayName() . ($selectedCustomer->default_currency_code ? ' (' . $selectedCustomer->default_currency_code . ')' : '') : '';
                @endphp
                <div class="party-picker flex gap-2 items-center">
                    <x-searchable-combobox
                        id="quotation-customer-id"
                        type="customer"
                        context="quotation"
                        wire-model="customer_id"
                        :selected-id="$customer_id"
                        :selected-label="$customerLabel"
                        :placeholder="__('sales.select_customer')"
                        :aria-label="__('sales.customer')"
                        :has-error="$errors->has('customer_id')"
                        required
                    />
                    @if ($canManageCustomers)
                        <button type="button"
                                wire:click="openQuickCustomerModal"
                                class="party-create-button shrink-0 min-h-11 lg:min-h-9.5" wire:loading.attr="disabled" wire:target="openQuickCustomerModal" :disabled="documentBusy || saving">
                            + {{ __('workflow.quick_customer') }}
                        </button>
                    @endif
                </div>
                @error('customer_id') <span class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror

            </div>

            <!-- Issue Date -->
            <div>
                <label for="quotation-issue-date" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input id="quotation-issue-date"
                       type="date"
                       wire:model="issue_date"
                       required
                       class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('issue_date') <span class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
        </div>

        <div class="border-t border-border pt-3">
            <div class="entry-grid grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-3">
            <!-- Expiry Date -->
            <div>
                <label for="quotation-expiry-date" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.valid_until') }}
                </label>
                <input id="quotation-expiry-date"
                       type="date"
                       wire:model="expiry_date"
                       class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('expiry_date') <span class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Currency -->
            <div>
                <label for="quotation-currency-code" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.currency') }} <span class="text-danger">*</span>
                </label>
                <select id="quotation-currency-code" wire:model.live="currency_code"
                        class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    @foreach ($currencies as $curr)
                        <option value="{{ $curr->currency_code }}">{{ $curr->currency_code }}</option>
                    @endforeach
                </select>
            </div>

            @if ($currency_code !== $baseCurrencyCode)
            <!-- Exchange Rate -->
            <div>
                <label for="quotation-exchange-rate" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.exchange_rate') }} <span class="text-danger">*</span>
                </label>
                <input id="quotation-exchange-rate"
                       type="number" inputmode="decimal"
                       step="0.0000000001"
                       wire:model.blur="exchange_rate"
                       wire:change="recalculate"
                       dir="ltr"
                       required
                       class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono tabular-nums"  :disabled="documentBusy || saving"/>
                @error('exchange_rate') <span class="text-danger text-xs mt-1 block">{{ $message }}</span> @enderror
            </div>
            @endif

            <!-- Document Locale -->
            <div>
                <label for="quotation-document-locale" class="block text-sm font-semibold text-text-primary mb-1.5">
                    {{ __('sales.preferred_locale') }}
                </label>
                <select id="quotation-document-locale" wire:model="document_locale"
                        class="w-full min-h-11 lg:min-h-9.5 px-3 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    <option value="ar">{{ __('sales.language_ar') }}</option>
                    <option value="en">{{ __('sales.language_en') }}</option>
                </select>
            </div>

            <!-- Include Product Images Toggle -->
            <div class="flex items-end pb-1">
                <label class="inline-flex min-h-11 lg:min-h-9.5 items-center gap-2 text-sm font-semibold text-text-primary cursor-pointer select-none">
                    <input type="checkbox" wire:model="include_product_images" :disabled="documentBusy || saving" class="h-4 w-4 rounded border-border-control text-primary focus:ring-primary">
                    <span>{{ __('sales.include_product_images') }}</span>
                </label>
            </div>
            </div>
        </div>

    <div class="grid gap-4 md:grid-cols-2 pb-4 border-b border-border">
        <div>
            @include('livewire.partials.barcode-entry', ['prefix' => 'quotation'])
        </div>
        <div>
            <label for="quotation-product-search" class="block text-sm font-semibold text-text-primary mb-1.5">
                {{ __('workflow.search_products') }}
            </label>
            <div class="flex items-center gap-2">
                <div class="flex-1 min-w-0">
                    <x-searchable-combobox
                        id="quotation-product-search"
                        type="product"
                        context="quotation"
                        on-select="quickAddProduct"
                        :reset-after-select="true"
                        :has-error="$errors->has('quickProduct')"
                        :placeholder="__('workflow.search_products')"
                        :aria-label="__('workflow.search_products')"
                    />
                </div>
                @if (auth()->user()->hasPermissionTo('inventory.product.manage'))
                    <button type="button" wire:click="openQuickProductModal" aria-haspopup="dialog" class="shrink-0 min-h-11 lg:min-h-9.5 px-3 py-2 rounded-control border border-border-control text-sm font-semibold text-primary hover:bg-surface-soft focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary cursor-pointer" wire:loading.attr="disabled" wire:target="openQuickProductModal" :disabled="documentBusy || saving">
                        + {{ __('quick_product.create') }}
                    </button>
                @endif
            </div>
            @error('quickProduct') <span id="quotation-product-search-error" role="alert" class="mt-1 block text-xs text-danger">{{ $message }}</span> @enderror
        </div>
    </div>

        <!-- Lines Section -->
        <div>
            <div class="flex items-center justify-between pb-2 border-b border-border">
                <h2 class="text-sm font-bold text-text-primary">
                    {{ __('sales.quotation_details') }}
                </h2>
                <button type="button"
                        data-row-mutation-action
                        @click="handleAddLine()"
                        class="min-h-11 lg:min-h-9.5 shrink-0 px-3 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors inline-flex items-center gap-1.5 cursor-pointer">
                    <span>+ {{ __('sales.add_line') }}</span>
                    <kbd class="px-1 py-0.5 text-xs font-mono rounded bg-surface/20 text-white leading-none">Alt+N</kbd>
                </button>
            </div>

            <p data-row-mutation-status data-retry-label="{{ __('workflow.retry_after_line_update') }}" data-busy-label="{{ __('workflow.updating_lines') }}" role="status" class="min-h-5 text-xs text-text-secondary"></p>
            @include('livewire.pages.sales.partials.quotation-lines')
        </div>

        <!-- Totals & Notes Section -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-4 border-t border-border">
            <div class="space-y-4">
                <div>
                    <label for="quotation-terms" class="block text-sm font-semibold text-text-primary mb-1.5">
                        {{ __('sales.terms') }}
                    </label>
                    <textarea id="quotation-terms" wire:model="terms"
                              rows="3"
                              class="w-full p-2.5 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
                </div>
                <div>
                    <label for="quotation-notes" class="block text-sm font-semibold text-text-primary mb-1.5">
                        {{ __('sales.notes') }}
                    </label>
                    <textarea id="quotation-notes" wire:model="notes"
                              rows="2"
                              class="w-full p-2.5 rounded-control border border-border-control bg-canvas text-base lg:text-sm text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
                </div>
            </div>

            <!-- Preview Calculation Card -->
            <div class="bg-surface-soft p-4 rounded-control border border-border space-y-2 text-xs">
                <!-- Global Document Discount Control -->
                @if(($discount_scope ?? 'document') === 'document')
                    <div class="py-2 border-b border-border/50">
                        @if (! $showDiscountControl)
                            <div class="flex items-center justify-between">
                                <span class="text-text-muted text-xs">{{ __('sales.discount') }}</span>
                                <button type="button" wire:click="toggleDiscountControl" aria-expanded="false" aria-controls="quote-document-discount-container" class="inline-flex items-center gap-1 text-xs font-semibold text-primary hover:underline cursor-pointer min-h-[44px] min-w-[44px] py-2">
                                    + {{ __('sales.add_discount') }}
                                </button>
                            </div>
                        @else
                            <div id="quote-document-discount-container" class="p-2.5 bg-surface rounded-control border border-border space-y-2">
                                <div class="flex items-center justify-between">
                                    <span class="text-xs font-bold text-text-primary">{{ __('sales.document_discount') }}</span>
                                    <button type="button" wire:click="toggleDiscountControl" aria-expanded="true" aria-controls="quote-document-discount-container" class="text-xs text-danger hover:underline cursor-pointer min-h-[44px] min-w-[44px] inline-flex items-center justify-center p-2">
                                        ✕ {{ __('sales.remove_discount') }}
                                    </button>
                                </div>
                                <div class="grid @if($discount_type !== 'none') grid-cols-2 @else grid-cols-1 @endif gap-2">
                                    <div>
                                        <label for="quote-discount-type" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.discount') }}</label>
                                        <select id="quote-discount-type" wire:model.live="discount_type" wire:change="recalculate" class="w-full min-h-11 lg:min-h-9.5 px-2.5 border border-border-control bg-canvas rounded-control text-base lg:text-sm" :disabled="documentBusy || saving">
                                            <option value="none">{{ __('sales.discount_none') }}</option>
                                            <option value="percent">{{ __('sales.discount_percent') }}</option>
                                            <option value="fixed">{{ __('sales.discount_fixed') }}</option>
                                        </select>
                                    </div>
                                    @if ($discount_type !== 'none')
                                        <div>
                                            <label for="quote-discount-value" class="block text-xs font-semibold text-text-secondary mb-1">{{ __('sales.discount_value') }}</label>
                                            <input id="quote-discount-value" @error('discount_value') aria-invalid="true" aria-describedby="quote-discount-value-error" @enderror type="text" inputmode="decimal" dir="ltr" wire:model="discount_value" wire:change="recalculate" class="w-full min-h-11 lg:min-h-9.5 px-2.5 border border-border-control bg-canvas rounded-control text-base lg:text-sm font-mono tabular-nums" placeholder="0.00" :disabled="documentBusy || saving" />
                                        </div>
                                    @endif
                                </div>
                                @error('discount_value')
                                    <span x-init="$nextTick(() => document.getElementById('quote-discount-value')?.focus())"></span>
                                    <span id="quote-discount-value-error" role="alert" class="text-danger text-xs block mt-1">{{ $message }}</span>
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
                <div class="flex justify-between py-2 border-t border-border text-sm font-extrabold text-text-primary">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span data-document-total class="font-mono text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($previewGrandTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                @endif
            </div>
        </div>

        <!-- Buttons -->
        <div class="flex flex-wrap items-center justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ $isEditing ? route('quotations.show', $quotation->public_id) : route('quotations.index') }}"
               class="touch-action min-h-11 lg:min-h-9.5 inline-flex items-center justify-center px-4 rounded-control border border-border-control text-sm font-semibold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="min-h-11 lg:min-h-9.5 px-5 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer inline-flex items-center justify-center gap-1.5">
                <span>{{ __('sales.save_draft') }}</span>
                <kbd class="px-1 py-0.5 text-xs font-mono rounded bg-surface/20 text-white leading-none">Ctrl+S</kbd>
            </button>
        </div>

        <x-document-mobile-actions :total="$previewGrandTotal" :currency="$currency_code" :valid="$documentTotalsValid" />
    </form>

    @include('livewire.partials.quick-customer-modal')
    @include('livewire.partials.quick-product-modal')
</div>
