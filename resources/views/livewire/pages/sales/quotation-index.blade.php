<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.quotations') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.quotations') }} ({{ $quotations->total() }})
            </p>
        </div>

        @if ($canCreate)
            <div class="flex items-center gap-2">
                <a href="{{ route('quotations.create') }}"
                   class="inline-flex items-center justify-center gap-1.5 px-4 h-9 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('sales.new_quotation') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Filters & Search Bar -->
    <div class="bg-white p-4 rounded-card border border-border shadow-xs flex flex-col md:flex-row gap-4 items-stretch md:items-center justify-between">
        <div class="flex-1 max-w-md relative">
            <input type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('sales.search') }}"
                   class="w-full h-9 pl-9 pr-3 rounded-control border border-border bg-canvas text-xs text-text-primary focus:border-primary focus:ring-1 focus:ring-primary outline-hidden" />
            <div class="absolute inset-y-0 left-0 pl-2.5 flex items-center pointer-events-none text-text-muted">
                <x-icon name="search" class="w-4 h-4" />
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <select wire:model.live="statusFilter" class="h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary">
                <option value="all">{{ __('sales.all') }}</option>
                <option value="draft">{{ __('sales.draft') }}</option>
                <option value="sent">{{ __('sales.status_sent') }}</option>
                <option value="accepted">{{ __('sales.status_accepted') }}</option>
                <option value="rejected">{{ __('sales.status_rejected') }}</option>
                <option value="expired">{{ __('sales.status_expired') }}</option>
                <option value="converted">{{ __('sales.status_converted') }}</option>
            </select>

            <select wire:model.live="customerFilter" class="h-9 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary">
                <option value="">{{ __('sales.select_customer') }}</option>
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Quotations Table / Cards -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase tracking-wider">
                        <th class="py-3 px-4 text-start">{{ __('sales.quote_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.customer') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.date') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.valid_until') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.grand_total') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.status') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($quotations as $quote)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="py-3 px-4 font-mono font-bold" dir="ltr">
                                <a href="{{ route('quotations.show', $quote->public_id) }}" class="text-primary hover:underline">
                                    {{ $quote->quotation_number }}
                                </a>
                            </td>
                            <td class="py-3 px-4">
                                <a href="{{ route('customers.show', $quote->customer->public_id) }}" class="font-bold text-text-primary hover:text-primary">
                                    {{ $quote->customer->displayName() }}
                                </a>
                            </td>
                            <td class="py-3 px-4" dir="ltr">
                                {{ $quote->quotation_date->toDateString() }}
                            </td>
                            <td class="py-3 px-4" dir="ltr">
                                {{ $quote->expiry_date ? $quote->expiry_date->toDateString() : '—' }}
                            </td>
                            <td class="py-3 px-4 font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($quote->grand_total, $quote->currency_code) }} {{ $quote->currency_code }}
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold
                                    @if ($quote->status === 'accepted' || $quote->status === 'converted') bg-success-bg text-success
                                    @elseif ($quote->status === 'sent') bg-primary-50 text-primary
                                    @elseif ($quote->status === 'rejected') bg-danger-bg text-danger
                                    @else bg-slate-100 text-slate-600 @endif">
                                    {{ __('sales.status_' . $quote->status) }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-end">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('quotations.show', $quote->public_id) }}"
                                       class="px-2 py-1 rounded-control bg-surface-soft hover:bg-primary-50 text-text-secondary hover:text-primary font-bold text-[11px] transition-colors">
                                        {{ __('sales.quotation_details') }}
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-text-muted">
                                <x-icon name="quote" class="w-8 h-8 mx-auto mb-2 opacity-40" />
                                <p>{{ __('sales.no_quotations_found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Mobile View -->
        <div class="md:hidden divide-y divide-border">
            @forelse ($quotations as $quote)
                <div class="p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <a href="{{ route('quotations.show', $quote->public_id) }}" class="font-mono font-bold text-primary" dir="ltr">
                                {{ $quote->quotation_number }}
                            </a>
                            <div class="font-bold text-text-primary text-xs mt-0.5">
                                {{ $quote->customer->displayName() }}
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold
                            @if ($quote->status === 'accepted' || $quote->status === 'converted') bg-success-bg text-success
                            @elseif ($quote->status === 'sent') bg-primary-50 text-primary
                            @elseif ($quote->status === 'rejected') bg-danger-bg text-danger
                            @else bg-slate-100 text-slate-600 @endif">
                            {{ __('sales.status_' . $quote->status) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.date') }}</span>
                            <span dir="ltr">{{ $quote->quotation_date->toDateString() }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.grand_total') }}</span>
                            <span class="font-bold text-text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($quote->grand_total, $quote->currency_code) }} {{ $quote->currency_code }}</span>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-border flex justify-end">
                        <a href="{{ route('quotations.show', $quote->public_id) }}"
                           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-primary text-xs font-bold">
                            {{ __('sales.quotation_details') }}
                        </a>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-text-muted">
                    <p>{{ __('sales.no_quotations_found') }}</p>
                </div>
            @endforelse
        </div>

        @if ($quotations->hasPages())
            <div class="p-4 border-t border-border">
                {{ $quotations->links() }}
            </div>
        @endif
    </div>
</div>
