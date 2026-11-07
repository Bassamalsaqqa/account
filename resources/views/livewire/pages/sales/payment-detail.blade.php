<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-surface rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-mono font-bold tracking-tight text-text-primary" dir="ltr">
                        {{ $payment->payment_number }}
                    </h1>
                    @if ($payment->is_reversed)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-bold bg-danger-bg text-danger">
                            {{ __('sales.voided') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-bold bg-success-bg text-success">
                            {{ __('sales.posted') }}
                        </span>
                    @endif
                </div>
                <div class="text-sm text-text-secondary mt-1">
                    {{ __('sales.customer') }}:
                    <a href="{{ route('customers.show', $payment->customer->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">
                        {{ $payment->customer->displayName() }}
                    </a>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($canAllocate)
                    <button type="button" wire:click="openCreditForm" class="px-3 py-2 bg-primary text-white rounded-control text-sm font-bold" wire:loading.attr="disabled" wire:target="openCreditForm">{{ __('sales.allocate_customer_credit') }}</button>
                @endif
                @if ($canReverse)
                    <button type="button"
                            wire:click="openReversalReview"
                            class="px-3.5 py-1.5 rounded-control bg-danger-bg text-danger border border-danger/30 text-sm font-bold hover:bg-danger hover:text-white transition-colors cursor-pointer">
                        ✕ {{ __('sales.reverse') }}
                    </button>
                @endif

                @if ($singleAllocatedInvoice)
                    <a href="{{ route('invoices.show', $singleAllocatedInvoice->public_id) }}"
                       class="touch-action inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control border border-border text-sm font-bold text-text-secondary hover:text-text-primary hover:bg-surface-soft transition-colors focus-visible:ring-2 focus-visible:ring-primary">
                        <x-icon name="arrow-right" class="w-3.5 h-3.5 rtl:rotate-0 ltr:rotate-180 text-primary" />
                        <span>{{ __('release_b1_workflow.back_to_invoice') }}: {{ $singleAllocatedInvoice->invoice_number }}</span>
                    </a>
                @endif

                <x-document-actions route-name="pdf.payment" :parameters="['publicId' => $payment->public_id]" :permissions="['sales.document.pdf', 'money.receipt.view']" />

                <a href="{{ route('payments.index') }}"
                   class="touch-action px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-sm font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        <!-- Metadata Row -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-sm">
            <div>
                <span class="text-text-muted block text-sm">{{ __('sales.date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $payment->payment_date->toDateString() }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-sm">{{ __('sales.money_account') }}</span>
                <span class="font-bold text-text-primary">{{ $payment->check_id !== null ? __('money.check_number').' #'.$payment->checkInstrument?->check_number : ($payment->moneyAccount?->displayName() ?? '—') }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-sm">{{ __('sales.payment_method') }}</span>
                <span class="font-bold text-text-primary">{{ $payment->payment_method === 'check' ? __('fin1.check') : __('sales.' . $payment->payment_method) }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-sm">{{ __('sales.currency') }}</span>
                <span class="font-bold text-text-primary">{{ $payment->currency_code }}</span>
            </div>
        </div>

        <!-- Shared Summary Cards -->
        @php
            $unallocated = $unallocatedAmount ?? $payment->unallocated_amount;
            $allocatedAmount = bcsub((string) $payment->amount, (string) $unallocated, 4);
            $receiptCards = [
                [
                    'label' => __('sales.received_amount'),
                    'value' => $payment->amount,
                    'currency' => $payment->currency_code,
                    'variant' => 'success',
                ],
                [
                    'label' => __('sales.allocated_amount'),
                    'value' => $allocatedAmount,
                    'currency' => $payment->currency_code,
                    'variant' => 'primary',
                ],
                [
                    'label' => __('sales.credit_available'),
                    'value' => $unallocated,
                    'currency' => $payment->currency_code,
                    'variant' => \Brick\Math\BigDecimal::of((string) $unallocated)->isPositive() ? 'default' : 'muted',
                ],
                [
                    'label' => __('sales.date'),
                    'raw_value' => $payment->payment_date->toDateString(),
                    'is_date' => true,
                    'variant' => 'default',
                ],
            ];
        @endphp
        <div class="pt-4 border-t border-border">
            <x-document-summary-cards :cards="$receiptCards" :currency="$payment->currency_code" />
        </div>
    </div>
    @if($showCreditForm)
        <form wire:submit="applyCredit" class="bg-surface p-4 rounded-card space-y-4">
            <h2 class="font-bold">{{ __('sales.allocate_customer_credit') }}</h2>
            <label class="block text-sm">{{ __('sales.application_date') }}<input type="date" wire:model="applicationDate" class="block border border-border p-2 rounded-control" required></label>
            @error('applicationDate')<p class="text-danger text-sm">{{ $message }}</p>@enderror
            @foreach($openInvoices as $invoice)
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-b border-border pb-3">
                    <span dir="ltr">{{ $invoice['number'] }}</span>
                    <span>{{ __('sales.invoice_outstanding') }}: <span dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($invoice['outstanding'], $invoice['currency']) }} {{ $invoice['currency'] }}</span></span>
                    <label>{{ __('sales.allocated') }}<input type="text" inputmode="decimal" wire:model="creditAmounts.{{ $invoice['id'] }}" class="block w-full border border-border rounded-control p-2" dir="ltr"></label>
                            <span class="text-sm" dir="ltr">{{ $invoice['currency'] }}</span>
                            @if($invoice['currency'] !== $payment->currency_code)
                            <label class="block text-sm">{{ __('money.payment_consumed') }} ({{ $payment->currency_code }})<input type="text" inputmode="decimal" wire:model="creditPaymentAmounts.{{ $invoice['id'] }}" class="w-full border-border rounded-control p-2" dir="ltr" /></label>
                            @endif
                    @error('creditAmounts.'.$invoice['id'])<p class="text-danger text-sm">{{ $message }}</p>@enderror
                    @error('creditPaymentAmounts.'.$invoice['id'])<p class="text-danger text-sm">{{ $message }}</p>@enderror
                </div>
            @endforeach
            @error('creditAmounts')<p class="text-danger">{{ $message }}</p>@enderror
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-control">{{ __('sales.save') }}</button>
            <button type="button" wire:click="$set('showCreditForm', false)" class="px-4 py-2 border border-border rounded-control">{{ __('sales.cancel') }}</button>
        </form>
    @endif
    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('sales.invoice_allocations') }}
        </div>
        <div class="overflow-x-auto">
            <table class="data-table w-full text-start text-sm border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-sm uppercase">
                        <th class="py-2.5 px-4 text-start">{{ __('sales.invoice_number') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.date') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.allocated') }}</th>
                        @if($canViewFx)<th class="py-2.5 px-4 text-start">{{ __('sales.realized_fx') }}</th>@endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($payment->allocations as $alloc)
                        <tr>
                            <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                @if (auth()->user()?->hasPermissionTo('sales.invoice.view'))
                                    <a href="{{ route('invoices.show', $alloc->salesInvoice->public_id) }}" class="touch-action text-primary hover:underline">
                                        {{ $alloc->salesInvoice->invoice_number }}
                                    </a>
                                @else
                                    <span>{{ $alloc->salesInvoice->invoice_number }}</span>
                                @endif
                            </td>
                            <td class="py-2.5 px-4" dir="ltr">{{ $alloc->salesInvoice->issue_date ? $alloc->salesInvoice->issue_date->toDateString() : '—' }}</td>
                            <td class="py-2.5 px-4 font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($alloc->allocated_amount, $alloc->salesInvoice->currency_code) }} {{ $alloc->salesInvoice->currency_code }}<span class="block text-sm text-text-muted">{{ __('money.payment_consumed') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($alloc->payment_currency_amount) }} {{ $payment->currency_code }}</span>
                            </td>
                            @if($canViewFx)<td class="py-2.5 px-4 font-mono" dir="ltr">
                                @php
                                    $fxVal = $alloc->realized_fx_gain_loss_base;
                                    $isPos = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($fxVal);
                                    $isNeg = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isNegative($fxVal);
                                @endphp
                                <span class="{{ $isPos ? 'text-success font-bold' : ($isNeg ? 'text-danger font-bold' : 'text-text-muted') }}">
                                    {{ ! \App\Domain\Sales\Formatters\SalesMoneyFormatter::isZero($fxVal) ? ($isPos ? '+' : '') . \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($fxVal) : '—' }}
                                </span>
                            </td>@endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-text-muted">
                                {{ __('sales.unallocated_amount') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($payment->amount, $payment->currency_code) }} {{ $payment->currency_code }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-6 bg-surface-soft/40 border-t border-border flex flex-col md:flex-row justify-between gap-6 text-sm">
            <div class="space-y-3 max-w-md">
                @if ($payment->reference_number)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.reference_number') }}</span>
                        <p class="text-text-secondary text-sm">{{ $payment->reference_number }}</p>
                    </div>
                @endif
                @if ($payment->notes)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.notes') }}:</span>
                        <p class="text-text-secondary text-sm">{{ $payment->notes }}</p>
                    </div>
                @endif
                @if ($payment->reversal_reason)
                    <div class="p-2.5 bg-danger-bg border border-danger/20 rounded-control text-danger">
                        <span class="font-bold block">{{ __('sales.reverse_reason') }}:</span>
                        <p class="text-sm mt-0.5">{{ $payment->reversal_reason }}</p>
                    </div>
                @endif
            </div>

            <div class="w-full md:w-64 space-y-2">
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.total') }}:</span>
                    <span class="font-mono font-bold text-success" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($payment->amount, $payment->currency_code) }} {{ $payment->currency_code }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Reverse Modal -->


    @if(! $payment->is_reversed && collect(['sales.document.share', 'money.receipt.view', 'money.receipt.share'])->every(fn ($permission) => auth()->user()->can($permission)))
        <livewire:financial-share-manager subject-type="customer_payment" :subject-id="$payment->id" :key="'financial-share-'.$payment->public_id" />
    @endif
    <x-standalone-reversal-review />
</div>
