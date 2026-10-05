<div class="space-y-6">
    <!-- Flash Messages -->
    @if (session()->has('success'))
        <div class="p-4 rounded-control bg-success-bg border border-success text-success text-xs font-bold">
            {{ session('success') }}
        </div>
    @endif

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
                            {{ __('purchasing.reversed') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">
                            {{ __('purchasing.posted') }}
                        </span>
                    @endif
                </div>
                <div class="text-xs text-text-secondary mt-1">
                    {{ __('purchasing.vendor') }}:
                    @if ($payment->vendor || isset($payment->vendor_snapshot['public_id']))
                        <a href="{{ route('vendors.show', $payment->vendor?->public_id ?? $payment->vendor_snapshot['public_id']) }}" class="font-bold text-text-primary hover:text-primary">
                            {{ $payment->vendorDisplayName() }}
                        </a>
                    @else
                        <span class="font-bold text-text-primary">{{ $payment->vendorDisplayName() }}</span>
                    @endif
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                @if ($canAllocate)
                    <button type="button" wire:click="openCreditForm" class="px-3 py-1.5 bg-primary text-white rounded-control text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                        ⚡ {{ __('purchasing.apply_advance') }}
                    </button>
                @endif
                @if ($canReverse)
                    <button type="button"
                            wire:click="$set('showReverseModal', true)"
                            class="px-3.5 py-1.5 rounded-control bg-danger-bg text-danger border border-danger/30 text-xs font-bold hover:bg-danger hover:text-white transition-colors cursor-pointer">
                        ✕ {{ __('purchasing.reverse_payment') }}
                    </button>
                @endif

                <a href="{{ route('vendor-payments.index') }}"
                   class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                    {{ __('purchasing.cancel') }}
                </a>
            </div>
        </div>

        @if ($payment->is_reversed)
            <div class="p-3 rounded-control bg-danger-bg border border-danger text-danger text-xs font-medium space-y-1">
                <div class="font-bold">{{ __('purchasing.reversed') }}</div>
                <div>{{ __('purchasing.reversal_reason') }}: {{ $payment->reversal_reason }}</div>
                <div class="text-[11px] text-text-secondary" dir="ltr">{{ $payment->reversed_at }}</div>
            </div>
        @endif

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.payment_date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $payment->payment_date->toDateString() }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.money_account') }}</span>
                <span class="font-bold text-text-primary">{{ $payment->moneyAccount?->displayName() ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.payment_method') }}</span>
                <span class="font-bold text-text-primary">{{ __('purchasing.' . $payment->payment_method) }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.payment_amount') }}</span>
                <span class="font-mono font-bold text-primary text-sm" dir="ltr">
                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->amount, $payment->currency_code) }} {{ $payment->currency_code }}
                </span>
            </div>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-3 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.exchange_rate') }}</span>
                <span class="font-mono font-semibold text-text-primary" dir="ltr">{{ $payment->exchange_rate }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.allocated') }}</span>
                <span class="font-mono font-bold text-text-primary" dir="ltr">
                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->allocated_amount, $payment->currency_code) }} {{ $payment->currency_code }}
                </span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.unallocated_advance') }}</span>
                <span class="font-mono font-bold text-info" dir="ltr">
                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->unallocated_amount, $payment->currency_code) }} {{ $payment->currency_code }}
                </span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('purchasing.vendor_invoice_number') }}</span>
                <span class="font-medium text-text-secondary" dir="ltr">{{ $payment->reference_number ?? '—' }}</span>
            </div>
        </div>
    </div>

    <!-- Apply Advance Section (Credit Form) -->
    @if ($showCreditForm)
        <form wire:submit="applyCredit" class="bg-white p-6 rounded-card border border-border shadow-xs space-y-4">
            <div class="flex items-center justify-between pb-2 border-b border-border">
                <h2 class="font-bold text-sm text-text-primary">
                    {{ __('purchasing.apply_advance') }} ({{ $payment->currency_code }})
                </h2>
                <div class="text-xs text-text-secondary">
                    {{ __('purchasing.unallocated_advance') }}:
                    <span class="font-bold text-info" dir="ltr">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->unallocated_amount, $payment->currency_code) }} {{ $payment->currency_code }}
                    </span>
                </div>
            </div>

            <div class="max-w-xs">
                <label class="block text-xs font-bold text-text-primary mb-1">
                    {{ __('purchasing.application_date') }} <span class="text-danger">*</span>
                </label>
                <input type="date" wire:model="applicationDate" class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs" required />
                @error('applicationDate') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="space-y-3 pt-2">
                <h3 class="text-xs font-bold text-text-secondary uppercase tracking-wider">
                    {{ __('purchasing.open_purchases') }}
                </h3>

                @forelse ($openPurchases as $purchase)
                    <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 p-3 rounded-control bg-canvas border border-border items-center text-xs">
                        <div class="font-mono font-bold text-primary" dir="ltr">
                            {{ $purchase['number'] }}
                            <span class="block text-[11px] text-text-muted font-normal">{{ $purchase['date'] }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.total') }}</span>
                            <span class="font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($purchase['grand_total'], $payment->currency_code) }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.outstanding_balance') }}</span>
                            <span class="font-mono font-bold text-danger" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($purchase['outstanding'], $payment->currency_code) }}</span>
                        </div>
                        <div>
                            <label class="block text-[10px] text-text-muted uppercase mb-1">{{ __('purchasing.allocated') }}</label>
                            <input type="text"
                                   inputmode="decimal"
                                   wire:model="creditAmounts.{{ $purchase['id'] }}"
                                   placeholder="0.00"
                                   class="w-full h-8 px-2 rounded-control border border-border bg-white text-xs font-mono font-bold text-end"
                                   dir="ltr" />
                            @error('creditAmounts.' . $purchase['id']) <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @empty
                    <p class="text-xs text-text-muted py-4 text-center">{{ __('purchasing.no_purchases') }}</p>
                @endforelse
            </div>

            @error('creditAmounts') <p class="text-danger text-xs font-bold">{{ $message }}</p> @enderror

            <div class="flex items-center justify-end gap-3 pt-3 border-t border-border">
                <button type="button" wire:click="$set('showCreditForm', false)" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer">
                    {{ __('purchasing.cancel') }}
                </button>
                <button type="submit" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer">
                    ⚡ {{ __('purchasing.apply_advance') }}
                </button>
            </div>
        </form>
    @endif

    <!-- Allocations Table -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('purchasing.purchase_lines') }} ({{ __('purchasing.allocated') }})
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <th class="py-2.5 px-4 text-start">{{ __('purchasing.purchase_number') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('purchasing.purchase_date') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('purchasing.allocated') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('purchasing.realized_fx') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('purchasing.all_statuses') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($payment->allocations as $alloc)
                        <tr>
                            <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                @if ($alloc->purchase)
                                    <a href="{{ route('purchases.show', $alloc->purchase->public_id) }}" class="text-primary hover:underline">
                                        {{ $alloc->purchase->purchase_number }}
                                    </a>
                                @else
                                    #{{ $alloc->purchase_id }}
                                @endif
                            </td>
                            <td class="py-2.5 px-4" dir="ltr">
                                {{ $alloc->purchase?->purchase_date ? $alloc->purchase->purchase_date->toDateString() : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($alloc->allocated_amount, $payment->currency_code) }} {{ $payment->currency_code }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                @php
                                    $fxVal = $alloc->realized_fx_gain_loss_base;
                                    $isPos = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($fxVal);
                                    $isNeg = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isNegative($fxVal);
                                @endphp
                                <span class="{{ $isPos ? 'text-danger font-bold' : ($isNeg ? 'text-success font-bold' : 'text-text-muted') }}">
                                    @if (! \App\Domain\Sales\Formatters\SalesMoneyFormatter::isZero($fxVal))
                                        {{ $isPos ? '+' . \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($fxVal) . ' (' . __('purchasing.realized_fx_loss') . ')' : \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($fxVal) . ' (' . __('purchasing.realized_fx_gain') . ')' }}
                                    @else
                                        —
                                    @endif
                                </span>
                            </td>
                            <td class="py-2.5 px-4 text-text-secondary">
                                @if ($alloc->application_event_id === null)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-surface-soft text-text-secondary">
                                        {{ __('purchasing.posted') }}
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-info-bg text-info">
                                        {{ __('purchasing.apply_advance') }} ({{ $alloc->applicationEvent?->application_date?->toDateString() }})
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="py-6 text-center text-text-muted">
                                {{ __('purchasing.unallocated_advance') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payment->amount, $payment->currency_code) }} {{ $payment->currency_code }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Reversal Modal -->
    @if ($showReverseModal)
        <div class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4">
            <div class="bg-white rounded-card max-w-md w-full p-6 space-y-4 shadow-xl">
                <h2 class="text-lg font-bold text-danger">
                    {{ __('purchasing.reverse_payment') }}
                </h2>
                <p class="text-xs text-text-secondary leading-relaxed">
                    {{ __('purchasing.reverse_confirmation') }}
                </p>

                <div>
                    <label class="block text-xs font-bold text-text-primary mb-1">
                        {{ __('purchasing.reversal_reason') }} <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           wire:model="reversalReason"
                           placeholder="{{ __('purchasing.reversal_reason') }}"
                           class="w-full h-9 px-3 rounded-control border border-border bg-canvas text-xs focus:border-danger focus:ring-1 focus:ring-danger outline-hidden" />
                    @error('reversalReason') <span class="text-danger text-[11px] mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div class="flex items-center justify-end gap-2 pt-2">
                    <button type="button"
                            wire:click="$set('showReverseModal', false)"
                            class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer">
                        {{ __('purchasing.cancel') }}
                    </button>
                    <button type="button"
                            wire:click="reversePayment"
                            class="px-4 py-1.5 rounded-control bg-danger text-white text-xs font-bold hover:bg-danger/90 transition-colors shadow-sm cursor-pointer">
                        ✕ {{ __('purchasing.reverse_payment') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
