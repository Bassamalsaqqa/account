<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-mono font-bold tracking-tight text-text-primary" dir="ltr">
                        {{ $payment->payment_number }}
                    </h1>
                    @if ($payment->is_reversed)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-danger-bg text-danger">
                            {{ __('sales.voided') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">
                            {{ __('sales.posted') }}
                        </span>
                    @endif
                </div>
                <div class="text-xs text-text-secondary mt-1">
                    {{ __('sales.customer') }}:
                    <a href="{{ route('customers.show', $payment->customer->public_id) }}" class="font-bold text-text-primary hover:text-primary">
                        {{ $payment->customer->displayName() }}
                    </a>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if($canAllocate)
                    <button type="button" wire:click="openCreditForm" class="px-3 py-2 bg-primary text-white rounded-control text-xs font-bold">{{ __('sales.allocate_customer_credit') }}</button>
                @endif
                @if ($canReverse)
                    <button type="button"
                            wire:click="$set('showReverseModal', true)"
                            class="px-3.5 py-1.5 rounded-control bg-danger-bg text-danger border border-danger/30 text-xs font-bold hover:bg-danger hover:text-white transition-colors cursor-pointer">
                        ✕ {{ __('sales.reverse') }}
                    </button>
                @endif

                <a href="{{ route('pdf.payment', $payment->public_id) }}"
                   target="_blank"
                   class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors flex items-center gap-1">
                    📄 {{ __('sales.print') }}
                </a>

                <a href="{{ route('payments.index') }}"
                   class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $payment->payment_date->toDateString() }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.money_account') }}</span>
                <span class="font-bold text-text-primary">{{ $payment->moneyAccount?->displayName() ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.payment_method') }}</span>
                <span class="font-bold text-text-primary">{{ __('sales.' . $payment->payment_method) }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.payment_amount') }}</span>
                <span class="font-mono font-bold text-success text-sm" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->amount, $payment->currency_code) }} {{ $payment->currency_code }}</span>
            </div>
        </div>
    </div>

    <!-- Allocations Table -->
    <div class="bg-white p-4 rounded-card text-sm">{{ __('sales.credit_available') }}: <span dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->unallocated_amount, $payment->currency_code) }} {{ $payment->currency_code }}</span></div>
    @if($showCreditForm)
        <form wire:submit="applyCredit" class="bg-white p-4 rounded-card space-y-4">
            <h2 class="font-bold">{{ __('sales.allocate_customer_credit') }}</h2>
            <label class="block text-sm">{{ __('sales.application_date') }}<input type="date" wire:model="applicationDate" class="block border border-border p-2 rounded-control" required></label>
            @error('applicationDate')<p class="text-danger text-xs">{{ $message }}</p>@enderror
            @foreach($openInvoices as $invoice)
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 border-b border-border pb-3">
                    <span dir="ltr">{{ $invoice['number'] }}</span>
                    <span>{{ __('sales.invoice_outstanding') }}: <span dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($invoice['outstanding'], $payment->currency_code) }} {{ $payment->currency_code }}</span></span>
                    <label>{{ __('sales.allocated') }}<input type="text" inputmode="decimal" wire:model="creditAmounts.{{ $invoice['id'] }}" class="block w-full border border-border rounded-control p-2" dir="ltr"></label>
                    @error('creditAmounts.'.$invoice['id'])<p class="text-danger text-xs">{{ $message }}</p>@enderror
                </div>
            @endforeach
            @error('creditAmounts')<p class="text-danger">{{ $message }}</p>@enderror
            <button type="submit" class="px-4 py-2 bg-primary text-white rounded-control">{{ __('sales.save') }}</button>
            <button type="button" wire:click="$set('showCreditForm', false)" class="px-4 py-2 border border-border rounded-control">{{ __('sales.cancel') }}</button>
        </form>
    @endif
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('sales.invoice_allocations') }}
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
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
                                <a href="{{ route('invoices.show', $alloc->salesInvoice->public_id) }}" class="text-primary hover:underline">
                                    {{ $alloc->salesInvoice->invoice_number }}
                                </a>
                            </td>
                            <td class="py-2.5 px-4" dir="ltr">{{ $alloc->salesInvoice->issue_date ? $alloc->salesInvoice->issue_date->toDateString() : '—' }}</td>
                            <td class="py-2.5 px-4 font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($alloc->allocated_amount, $payment->currency_code) }} {{ $payment->currency_code }}
                            </td>
                            @if($canViewFx)<td class="py-2.5 px-4 font-mono" dir="ltr">
                                @php
                                    $fxVal = $alloc->realized_fx_gain_loss_base;
                                    $isPos = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($fxVal);
                                    $isNeg = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isNegative($fxVal);
                                @endphp
                                <span class="{{ $isPos ? 'text-success font-bold' : ($isNeg ? 'text-danger font-bold' : 'text-text-muted') }}">
                                    {{ ! \App\Domain\Sales\Formatters\SalesMoneyFormatter::isZero($fxVal) ? ($isPos ? '+' : '') . \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($fxVal) : '—' }}
                                </span>
                            </td>@endif
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="py-6 text-center text-text-muted">
                                {{ __('sales.unallocated_amount') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->amount, $payment->currency_code) }} {{ $payment->currency_code }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="p-6 bg-surface-soft/40 border-t border-border flex flex-col md:flex-row justify-between gap-6 text-xs">
            <div class="space-y-3 max-w-md">
                @if ($payment->reference_number)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.reference_number') }}</span>
                        <p class="text-text-secondary text-[11px]">{{ $payment->reference_number }}</p>
                    </div>
                @endif
                @if ($payment->notes)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.notes') }}:</span>
                        <p class="text-text-secondary text-[11px]">{{ $payment->notes }}</p>
                    </div>
                @endif
                @if ($payment->reversal_reason)
                    <div class="p-2.5 bg-danger-bg border border-danger/20 rounded-control text-danger">
                        <span class="font-bold block">{{ __('sales.reverse_reason') }}:</span>
                        <p class="text-[11px] mt-0.5">{{ $payment->reversal_reason }}</p>
                    </div>
                @endif
            </div>

            <div class="w-full md:w-64 space-y-2">
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.total') }}:</span>
                    <span class="font-mono font-bold text-success" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->amount, $payment->currency_code) }} {{ $payment->currency_code }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Reverse Modal -->
    @if ($showReverseModal)
        <div class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-card border border-border shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="font-bold text-base text-danger">
                    {{ __('sales.confirm_reverse_payment') }}
                </h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.reverse_reason') }} <span class="text-danger">*</span></label>
                        <textarea wire:model="reversalReason" rows="3" required class="w-full p-2.5 rounded-control border border-border text-xs"></textarea>
                        @error('reversalReason') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <button type="button" wire:click="$set('showReverseModal', false)" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold">
                        {{ __('sales.cancel') }}
                    </button>
                    <button type="button" wire:click="reversePayment" class="px-4 py-1.5 rounded-control bg-danger text-white text-xs font-bold">
                        {{ __('sales.reverse') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
