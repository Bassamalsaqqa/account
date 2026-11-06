@php
    $sourceAllocation = $sourceInvoiceId !== null ? collect($allocations)->firstWhere('sales_invoice_id', $sourceInvoiceId) : null;
    $simplePayment = $sourceAllocation !== null && ($sourceAllocation['document_currency_code'] ?? null) === $currency_code;
@endphp
<div class="max-w-4xl mx-auto space-y-6" x-data="{
        saving: false, documentBusy: false, operationFailed: false,
        run(method, args = [], terminal = false) {
            if (this.saving || window.isDocumentBlocked($wire)) return;
            if (terminal &amp;&amp; [...this.$el.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0)) return;
            if (terminal) this.saving = true;
            this.operationFailed = false;
            window.enqueueDocumentOperation($wire, method, args)
                .catch(() => { this.operationFailed = true; })
                .finally(() => { if (terminal) this.saving = false; });
        }
    }" @document-operation-state.window="if ($event.detail.id === $wire.$id) documentBusy = $event.detail.busy">
    <x-draft-operation-feedback />
    <p x-cloak x-show="operationFailed" role="alert" class="text-sm text-danger">{{ __('camera.network_error') }}</p>
    <x-form-attention />
    <!-- Header -->
    @if(! $isModal)
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.new_payment') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.payment_details') }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($sourceInvoicePublicId && auth()->user()?->hasPermissionTo('sales.invoice.view'))
                <a href="{{ route('invoices.show', $sourceInvoicePublicId) }}"
                   class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary py-1.5 rounded-control border border-border bg-white text-text-secondary hover:text-text-primary hover:bg-surface-soft text-xs font-bold transition-colors">
                    <x-icon name="arrow-right" class="w-4 h-4 rtl:rotate-0 ltr:rotate-180 text-primary" />
                    <span>{{ __('release_b1_workflow.back_to_invoice') }}: {{ $sourceInvoiceNumber }}</span>
                </a>
            @endif
            <a href="{{ route('payments.index') }}"
               class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                {{ __('sales.back') }}
            </a>
        </div>
    </div>
    @endif

    <!-- Main Form -->
    <form novalidate @submit.prevent="run('save', [], true)" class="{{ $isModal ? 'space-y-5' : 'bg-white rounded-card border border-border shadow-xs p-6 space-y-6' }}">

        <!-- Payment Details Header -->
        <div class="grid grid-cols-1 {{ $sourceInvoiceId !== null ? 'md:grid-cols-2' : 'md:grid-cols-3' }} gap-4 pb-4 border-b border-border">
            <!-- Customer -->
            <div @if($sourceInvoiceId !== null) hidden @endif>
                <label for="sales-payment-{{ $this->getId() }}-customer_id" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.customer') }} <span class="text-danger">*</span>
                </label>
                <select id="sales-payment-{{ $this->getId() }}-customer_id" wire:model.live="customer_id"
                        required
                        class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    <option value="">{{ __('sales.select_customer') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                    @endforeach
                </select>
                @error('customer_id') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Money Account -->
            <div>
                <label for="sales-payment-{{ $this->getId() }}-money_account_id" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.money_account') }} <span class="text-danger">*</span>
                </label>
                <select id="sales-payment-{{ $this->getId() }}-money_account_id" wire:model.live="money_account_id"
                        required
                        class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    <option value="">{{ __('sales.select_money_account') }}</option>
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->displayName() }} ({{ $acc->currency_code }})</option>
                    @endforeach
                </select>
                @error('money_account_id') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Payment Method -->
            <div @if($sourceInvoiceId !== null) hidden @endif>
                <label for="sales-payment-{{ $this->getId() }}-payment_method" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.payment_method') }} <span class="text-danger">*</span>
                </label>
                <select id="sales-payment-{{ $this->getId() }}-payment_method" wire:model="payment_method"
                        required
                        class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" :disabled="documentBusy || saving">
                    <option value="cash">{{ __('sales.cash') }}</option>
                    <option value="bank_transfer">{{ __('sales.bank_transfer') }}</option>
                </select>
                @error('payment_method') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Payment Date -->
            <div>
                <label for="sales-payment-{{ $this->getId() }}-payment_date" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input id="sales-payment-{{ $this->getId() }}-payment_date" type="date"
                       wire:model="payment_date"
                       required
                       class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
                @error('payment_date') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label for="sales-payment-{{ $this->getId() }}-document_locale" class="block text-xs font-bold text-text-primary mb-1">{{ __('sales.document_language') }}</label>
                <select id="sales-payment-{{ $this->getId() }}-document_locale" wire:model="document_locale" class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs" :disabled="documentBusy || saving">
                    @foreach ($company->languages()->where('enabled', true)->get() as $language)
                        <option value="{{ $language->locale }}">{{ __('sales.language_'.$language->locale) }}</option>
                    @endforeach
                </select>
                @error('document_locale') <span class="text-danger text-[11px]">{{ $message }}</span> @enderror
            </div>

            <!-- Amount -->
            <div @class(['md:col-span-2' => $sourceInvoiceId !== null])>
                <label for="sales-payment-{{ $this->getId() }}-amount" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.payment_amount') }} ({{ $currency_code }}) <span class="text-danger">*</span>
                </label>
                <div class="relative flex items-center" dir="ltr">
                    <input id="sales-payment-{{ $this->getId() }}-amount" type="number"
                           step="any"
                           wire:model.blur="amount"
                           wire:change="recalculateAllocations"
                           required
                           min="{{ $currency_code === 'JOD' ? '0.001' : '0.01' }}"
                           dir="ltr"
                           class="w-full min-h-11 px-3 pe-28 rounded-control border border-border bg-canvas text-xs font-mono font-bold text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden text-start" inputmode="decimal"  :disabled="documentBusy || saving"/>
                    <button type="button" @click="run('useFullBalance')" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="absolute end-1 top-1 bottom-1 px-3 inline-flex items-center justify-center rounded-control bg-surface-soft hover:bg-primary-50 text-xs font-bold text-primary border border-border disabled:opacity-50 transition-colors">{{ __('refinement.full_balance') }}</button>
                </div>
                @error('amount') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Exchange Rate -->
            @if ($currency_code !== $company->base_currency_code)
            <div>
                <label for="sales-payment-{{ $this->getId() }}-exchange_rate" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.exchange_rate') }} <span class="text-danger">*</span>
                </label>
                <input id="sales-payment-{{ $this->getId() }}-exchange_rate" type="number"
                       step="0.0000000001"
                       wire:model.blur="exchange_rate"
                       wire:change="recalculateAllocations"
                       dir="ltr"
                       required
                       class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono text-start" inputmode="decimal"  :disabled="documentBusy || saving"/>
                @error('exchange_rate') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>
            @endif

            <!-- Reference Number -->
            <div class="md:col-span-3">
                <label for="sales-payment-{{ $this->getId() }}-reference_number" class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.payment_reference') }}
                </label>
                <input id="sales-payment-{{ $this->getId() }}-reference_number" type="text"
                       wire:model="reference_number"
                       placeholder="{{ __('sales.payment_reference_placeholder') }}"
                       class="w-full min-h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden"  :disabled="documentBusy || saving"/>
            </div>
        </div>

        @if ($sourceAllocation !== null)
            <x-payment-source-summary :allocation="$sourceAllocation" :number="$sourceInvoiceNumber" :currency="$sourceAllocation['document_currency_code']" :unallocated="$unallocatedAmount" :payment-currency="$currency_code" />
        @endif
        @if (! $simplePayment)
        <!-- Invoice Allocations Section -->
        <div>
            <div class="flex items-center justify-between pb-2 border-b border-border">
                <h2 class="text-sm font-bold text-text-primary">
                    {{ __('sales.invoice_allocations') }} ({{ $currency_code }})
                </h2>
                @if (! empty($allocations))
                    <button type="button"
                            wire:click="autoAllocate"
                            class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-2.5 py-1 rounded-control bg-surface-soft hover:bg-primary-50 text-text-secondary hover:text-primary text-xs font-bold transition-colors" wire:loading.attr="disabled" :disabled="documentBusy || saving">
                        {{ __('sales.auto_allocate') }}
                    </button>
                @endif
            </div>

            @error('allocations') <span class="text-danger text-xs font-bold mt-2 block">{{ $message }}</span> @enderror

            @if (empty($allocations))
                <div class="py-8 text-center text-text-muted text-xs">
                    {{ $customer_id ? __('sales.no_open_invoices', ['currency' => $currency_code]) : __('sales.select_receipt_customer') }}
                </div>
            @else
                <div class="space-y-3 mt-4">
                    @foreach ($allocations as $idx => $alloc)
                    <section class="rounded-control border border-border p-3 space-y-2" wire:key="allocation-{{ $alloc['sales_invoice_id'] }}">
                        <div class="flex flex-wrap justify-between gap-2"><span dir="ltr" class="font-mono text-sm">{{ $alloc['invoice_number'] }}</span><span dir="ltr" class="text-sm">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($alloc['outstanding']) }} {{ $alloc['document_currency_code'] }}</span></div>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <label for="sales-payment-{{ $this->getId() }}-allocations-{{ $idx }}-allocated_amount" class="block text-sm">{{ __('money.document_amount') }} ({{ $alloc['document_currency_code'] }})<input id="sales-payment-{{ $this->getId() }}-allocations-{{ $idx }}-allocated_amount" type="text" inputmode="decimal" wire:model.blur="allocations.{{ $idx }}.allocated_amount" wire:change="recalculateAllocations" dir="ltr" class="w-full min-h-11 px-3 rounded-control border border-border font-mono tabular-nums"  :disabled="documentBusy || saving"/></label>
                            @error('allocations.'.$idx.'.allocated_amount') <p class="text-danger text-xs">{{ $message }}</p> @enderror
                            @if($alloc['document_currency_code'] !== $currency_code)
                            <label for="sales-payment-{{ $this->getId() }}-allocations-{{ $idx }}-payment_currency_amount" class="block text-sm">{{ __('money.payment_consumed') }} ({{ $currency_code }})<input id="sales-payment-{{ $this->getId() }}-allocations-{{ $idx }}-payment_currency_amount" type="text" inputmode="decimal" wire:model.blur="allocations.{{ $idx }}.payment_currency_amount" wire:change="recalculateAllocations" dir="ltr" class="w-full min-h-11 px-3 rounded-control border border-border font-mono tabular-nums"  :disabled="documentBusy || saving"/></label>
                            @error('allocations.'.$idx.'.payment_currency_amount') <p class="text-danger text-xs">{{ $message }}</p> @enderror
                            @endif
                        </div>
                        @if ($alloc['document_currency_code'] === $currency_code)
                            <button type="button" @click="run('payRemaining', [{{ $idx }}])" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 inline-flex items-center justify-center rounded-control border border-primary px-3 py-2 text-sm font-semibold text-primary disabled:opacity-50">{{ __('refinement.pay_remaining') }}</button>
                        @endif
                        <p class="text-xs text-text-muted">{{ __('money.fx_preview') }}: <span dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($alloc['preview_fx'] ?? null, $company->base_currency_code) }} {{ $company->base_currency_code }}</span></p>
                    </section>
                    @endforeach
                </div>
            @endif

            <!-- Allocation Summary -->
            <div class="mt-4 p-4 rounded-control bg-surface-soft border border-border flex flex-col sm:flex-row items-center justify-between gap-4 text-xs">
                <div>
                    <span class="text-text-muted">{{ __('sales.allocated_total') }}:</span>
                    <span class="font-mono font-bold text-primary mr-1 ml-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($allocatedTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                <div>
                    <span class="text-text-muted">{{ __('sales.unallocated_amount') }}:</span>
                    <span class="font-mono font-bold mr-1 ml-1 {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($unallocatedAmount) ? 'text-success' : 'text-text-primary' }}" dir="ltr">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($unallocatedAmount, $currency_code) }} {{ $currency_code }}
                    </span>
                </div>
            </div>
        </div>

        @endif
        <div>
            <label for="sales-payment-{{ $this->getId() }}-notes" class="block text-xs font-bold text-text-primary mb-1">
                {{ __('sales.notes') }}
            </label>
            <textarea id="sales-payment-{{ $this->getId() }}-notes" wire:model="notes" rows="2" class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary outline-hidden" :disabled="documentBusy || saving"></textarea>
        </div>

        <!-- Buttons -->
        <p wire:loading role="status" class="text-sm font-semibold text-primary">{{ __('refinement.working') }}</p>
            <div class="sticky bottom-[calc(5rem+env(safe-area-inset-bottom))] z-20 flex flex-wrap items-center justify-end gap-3 border-t border-border bg-white p-3 shadow-sm md:static md:shadow-none">
            @if ($isModal)
                <button type="button" @click="$dispatch('dialog-close')" class="min-h-11 rounded-control border border-border px-3 text-sm">{{ __('workflow.close') }}</button>
            @else
            <a href="{{ route('payments.index') }}"
               class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            @endif
            <button type="submit" :disabled="saving || window.isDocumentBlocked($wire)"
                    class="min-h-11 inline-flex items-center justify-center gap-2 px-3 active:bg-surface-soft focus-visible:ring-2 focus-visible:ring-primary px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer" wire:loading.attr="disabled" wire:target="save">
                {{ __('sales.confirm') }}
            </button>
        </div>
    </form>
</div>
