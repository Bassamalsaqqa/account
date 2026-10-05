<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('purchasing.vendor_payments') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('purchasing.vendor_payments') }} ({{ $payments->total() }})
            </p>
        </div>

        @if ($canCreate)
            <div class="flex items-center gap-2">
                <a href="{{ route('vendor-payments.create') }}"
                   class="inline-flex items-center justify-center gap-1.5 px-4 h-9 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('purchasing.new_vendor_payment') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Filters & Search Bar -->
    <div class="bg-white p-4 rounded-card border border-border shadow-xs flex flex-col md:flex-row gap-4 items-stretch md:items-center justify-between">
        <div class="flex-1 max-w-md relative">
            <input type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('purchasing.search_purchases') }}"
                   class="w-full h-9 pl-9 pr-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-text-muted">
                <x-icon name="search" class="w-4 h-4" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <select wire:model.live="vendorFilter" class="h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary">
                <option value="">{{ __('purchasing.select_vendor') }}</option>
                @foreach ($vendors as $v)
                    <option value="{{ $v->id }}">{{ $v->displayName() }}</option>
                @endforeach
            </select>

            <select wire:model.live="accountFilter" class="h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary">
                <option value="">{{ __('purchasing.select_money_account') }}</option>
                @foreach ($accounts as $acc)
                    <option value="{{ $acc->id }}">{{ $acc->displayName() }} ({{ $acc->currency_code }})</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Payments Table -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase tracking-wider">
                        <th class="py-3 px-4 text-start">{{ __('purchasing.payment_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.vendor') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.money_account') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.payment_method') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.payment_date') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.payment_amount') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.allocated') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.unallocated') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('purchasing.all_statuses') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('purchasing.vendor_details') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($payments as $pmt)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="py-3 px-4 font-mono font-bold" dir="ltr">
                                <a href="{{ route('vendor-payments.show', $pmt->public_id) }}" class="text-primary hover:underline">
                                    {{ $pmt->payment_number }}
                                </a>
                            </td>
                            <td class="py-3 px-4">
                                @if ($pmt->vendor || isset($pmt->vendor_snapshot['public_id']))
                                    <a href="{{ route('vendors.show', $pmt->vendor?->public_id ?? $pmt->vendor_snapshot['public_id']) }}" class="font-bold text-text-primary hover:text-primary">
                                        {{ $pmt->vendorDisplayName() }}
                                    </a>
                                @else
                                    <span class="font-bold text-text-primary">{{ $pmt->vendorDisplayName() }}</span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-text-secondary">
                                {{ $pmt->moneyAccount?->displayName() ?? '—' }}
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-surface-soft text-text-secondary">
                                    {{ __('purchasing.' . $pmt->payment_method) }}
                                </span>
                            </td>
                            <td class="py-3 px-4" dir="ltr">
                                {{ $pmt->payment_date->toDateString() }}
                            </td>
                            <td class="py-3 px-4 font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($pmt->amount, $pmt->currency_code) }} {{ $pmt->currency_code }}
                            </td>
                            <td class="py-3 px-4 text-text-secondary" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($pmt->allocated_amount, $pmt->currency_code) }}
                            </td>
                            <td class="py-3 px-4 text-text-secondary" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($pmt->unallocated_amount, $pmt->currency_code) }}
                            </td>
                            <td class="py-3 px-4">
                                @if ($pmt->is_reversed)
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-danger-bg text-danger">
                                        {{ __('purchasing.reversed') }}
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-bg text-success">
                                        {{ __('purchasing.posted') }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-end">
                                <a href="{{ route('vendor-payments.show', $pmt->public_id) }}"
                                   class="px-2 py-1 rounded-control bg-surface-soft hover:bg-primary-50 text-text-secondary hover:text-primary font-bold text-[11px] transition-colors">
                                    {{ __('purchasing.vendor_details') }}
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="10" class="py-8 text-center text-text-muted">
                                <x-icon name="credit-card" class="w-8 h-8 mx-auto mb-2 opacity-40" />
                                <p>{{ __('purchasing.no_payments_found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Mobile View -->
        <div class="md:hidden divide-y divide-border">
            @forelse ($payments as $pmt)
                <div class="p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <a href="{{ route('vendor-payments.show', $pmt->public_id) }}" class="font-mono font-bold text-primary" dir="ltr">
                                {{ $pmt->payment_number }}
                            </a>
                            <div class="font-bold text-text-primary text-xs mt-0.5">
                                {{ $pmt->vendorDisplayName() }}
                            </div>
                        </div>
                        @if ($pmt->is_reversed)
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-danger-bg text-danger">
                                {{ __('purchasing.reversed') }}
                            </span>
                        @else
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-bg text-success">
                                {{ __('purchasing.posted') }}
                            </span>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.payment_date') }}</span>
                            <span class="font-medium" dir="ltr">{{ $pmt->payment_date->toDateString() }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.payment_amount') }}</span>
                            <span class="font-bold text-text-primary" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($pmt->amount, $pmt->currency_code) }} {{ $pmt->currency_code }}
                            </span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.allocated') }}</span>
                            <span class="text-text-secondary" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($pmt->allocated_amount, $pmt->currency_code) }}
                            </span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.unallocated') }}</span>
                            <span class="text-text-secondary" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($pmt->unallocated_amount, $pmt->currency_code) }}
                            </span>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-border flex justify-end">
                        <a href="{{ route('vendor-payments.show', $pmt->public_id) }}" class="text-xs font-bold text-primary hover:underline">
                            {{ __('purchasing.vendor_details') }} &rarr;
                        </a>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center text-text-muted">
                    <p>{{ __('purchasing.no_payments_found') }}</p>
                </div>
            @endforelse
        </div>

        @if ($payments->hasPages())
            <div class="p-4 border-t border-border">
                {{ $payments->links() }}
            </div>
        @endif
    </div>
</div>
