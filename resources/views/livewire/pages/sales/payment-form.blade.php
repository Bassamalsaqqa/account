<div class="max-w-4xl mx-auto space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.new_payment') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.payment_details') }}
            </p>
        </div>

        <a href="{{ route('payments.index') }}"
           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
            {{ __('sales.back') }}
        </a>
    </div>

    <!-- Main Form -->
    <form wire:submit="save" class="bg-white rounded-card border border-border shadow-xs p-6 space-y-6">
        <!-- Payment Details Header -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 pb-4 border-b border-border">
            <!-- Customer -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.customer') }} <span class="text-danger">*</span>
                </label>
                <select wire:model.live="customer_id"
                        required
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="">{{ __('sales.select_customer') }}</option>
                    @foreach ($customers as $c)
                        <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                    @endforeach
                </select>
                @error('customer_id') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Money Account -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.money_account') }} <span class="text-danger">*</span>
                </label>
                <select wire:model.live="money_account_id"
                        required
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="">{{ __('sales.select_money_account') }}</option>
                    @foreach ($accounts as $acc)
                        <option value="{{ $acc->id }}">{{ $acc->displayName() }} ({{ $acc->currency_code }})</option>
                    @endforeach
                </select>
                @error('money_account_id') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Payment Method -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.payment_method') }} <span class="text-danger">*</span>
                </label>
                <select wire:model="payment_method"
                        required
                        class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden">
                    <option value="cash">{{ __('sales.cash') }}</option>
                    <option value="bank_transfer">{{ __('sales.bank_transfer') }}</option>
                </select>
                @error('payment_method') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Payment Date -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.date') }} <span class="text-danger">*</span>
                </label>
                <input type="date"
                       wire:model="payment_date"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
                @error('payment_date') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">{{ __('sales.document_language') }}</label>
                <select wire:model="document_locale" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs">
                    @foreach ($company->languages()->where('enabled', true)->get() as $language)
                        <option value="{{ $language->locale }}">{{ __('sales.language_'.$language->locale) }}</option>
                    @endforeach
                </select>
                @error('document_locale') <span class="text-danger text-[11px]">{{ $message }}</span> @enderror
            </div>

            <!-- Amount -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.payment_amount') }} ({{ $currency_code }}) <span class="text-danger">*</span>
                </label>
                <input type="number"
                       step="any"
                       wire:model.blur="amount"
                       wire:change="recalculateAllocations"
                       required
                       min="{{ $currency_code === 'JOD' ? '0.001' : '0.01' }}"
                       dir="ltr"
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs font-mono font-bold text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden text-start" />
                @error('amount') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Exchange Rate -->
            <div>
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.exchange_rate') }} <span class="text-danger">*</span>
                </label>
                <input type="number"
                       step="0.0000000001"
                       wire:model.blur="exchange_rate"
                       wire:change="recalculateAllocations"
                       dir="ltr"
                       required
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden font-mono text-start" />
                @error('exchange_rate') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <!-- Reference Number -->
            <div class="md:col-span-3">
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('sales.payment_reference') }}
                </label>
                <input type="text"
                       wire:model="reference_number"
                       placeholder="{{ __('sales.payment_reference_placeholder') }}"
                       class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            </div>
        </div>

        <!-- Invoice Allocations Section -->
        <div>
            <div class="flex items-center justify-between pb-2 border-b border-border">
                <h2 class="text-sm font-bold text-text-primary">
                    {{ __('sales.invoice_allocations') }} ({{ $currency_code }})
                </h2>
                @if (! empty($allocations))
                    <button type="button"
                            wire:click="autoAllocate"
                            class="px-2.5 py-1 rounded-control bg-surface-soft hover:bg-primary-50 text-text-secondary hover:text-primary text-xs font-bold transition-colors">
                        ⚡ {{ __('sales.auto_allocate') }}
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
                        <div class="flex flex-wrap justify-between gap-2"><span dir="ltr" class="font-mono text-sm">{{ $alloc['invoice_number'] }}</span><span dir="ltr" class="text-sm">{{ $alloc['outstanding'] }} {{ $alloc['document_currency_code'] }}</span></div>
                        <div class="grid sm:grid-cols-2 gap-3">
                            <label class="block text-sm">{{ __('money.document_amount') }} ({{ $alloc['document_currency_code'] }})<input type="text" inputmode="decimal" wire:model.blur="allocations.{{ $idx }}.allocated_amount" wire:change="recalculateAllocations" dir="ltr" class="w-full rounded-control border-border" /></label>
                            @error('allocations.'.$idx.'.allocated_amount') <p class="text-danger text-xs">{{ $message }}</p> @enderror
                            @if($alloc['document_currency_code'] !== $currency_code)
                            <label class="block text-sm">{{ __('money.payment_consumed') }} ({{ $currency_code }})<input type="text" inputmode="decimal" wire:model.blur="allocations.{{ $idx }}.payment_currency_amount" wire:change="recalculateAllocations" dir="ltr" class="w-full rounded-control border-border" /></label>
                            @error('allocations.'.$idx.'.payment_currency_amount') <p class="text-danger text-xs">{{ $message }}</p> @enderror
                            @endif
                        </div>
                        <p class="text-xs text-text-muted">{{ __('money.fx_preview') }}: <span dir="ltr">{{ $alloc['preview_fx'] ?? '—' }} {{ $company->base_currency_code }}</span></p>
                    </section>
                    @endforeach
                </div>
            @endif

            <!-- Allocation Summary -->
            <div class="mt-4 p-4 rounded-control bg-surface-soft border border-border flex flex-col sm:flex-row items-center justify-between gap-4 text-xs">
                <div>
                    <span class="text-text-muted">{{ __('sales.allocated_total') }}:</span>
                    <span class="font-mono font-bold text-primary mr-1 ml-1" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($allocatedTotal, $currency_code) }} {{ $currency_code }}</span>
                </div>
                <div>
                    <span class="text-text-muted">{{ __('sales.unallocated_amount') }}:</span>
                    <span class="font-mono font-bold mr-1 ml-1 {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($unallocatedAmount) ? 'text-success' : 'text-text-primary' }}" dir="ltr">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($unallocatedAmount, $currency_code) }} {{ $currency_code }}
                    </span>
                </div>
            </div>
        </div>

        <div>
            <label class="block text-xs font-bold text-text-primary mb-1">
                {{ __('sales.notes') }}
            </label>
            <textarea wire:model="notes" rows="2" class="w-full p-2.5 rounded-control border border-border bg-canvas text-xs text-text-primary outline-hidden"></textarea>
        </div>

        <!-- Buttons -->
        <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
            <a href="{{ route('payments.index') }}"
               class="px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                {{ __('sales.cancel') }}
            </a>
            <button type="submit"
                    class="px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                ⚡ {{ __('sales.confirm') }}
            </button>
        </div>
    </form>
</div>
