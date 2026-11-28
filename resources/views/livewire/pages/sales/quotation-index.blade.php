<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.quotations') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.quotations') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($quotations->total()) }})
            </p>
        </div>

        @if ($canCreate)
            <div class="flex items-center gap-2">
                <a href="{{ route('quotations.create') }}"
                   class="touch-action gap-1.5 bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('sales.new_quotation') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Filters & Search Bar -->
    <x-refinement-filters :active-count="(int) ($statusFilter !== 'all') + (int) filled($customerFilter)">
        <x-slot:search>
            <input aria-label="{{ __('sales.search') }}" type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('sales.search') }}"
                   class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>

        <select aria-label="{{ __('sales.all') }}" wire:model.live="statusFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary">
                <option value="all">{{ __('sales.all') }}</option>
                <option value="draft">{{ __('sales.draft') }}</option>
                <option value="sent">{{ __('sales.status_sent') }}</option>
                <option value="accepted">{{ __('sales.status_accepted') }}</option>
                <option value="rejected">{{ __('sales.status_rejected') }}</option>
                <option value="expired">{{ __('sales.status_expired') }}</option>
                <option value="converted">{{ __('sales.status_converted') }}</option>
            </select>
        <select aria-label="{{ __('sales.select_customer') }}" wire:model.live="customerFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary">
                <option value="">{{ __('sales.select_customer') }}</option>
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                @endforeach
            </select>
    </x-refinement-filters>

    <!-- Quotations Table -->
    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase tracking-wider">
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
                        <tr wire:key="quote-row-{{ $quote->id }}" data-row-surface x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60 transition-colors">
                            <td class="td-primary py-3 px-4 font-mono font-bold">
                                <div class="flex items-center gap-2 min-w-0">
                                    <a href="{{ route('quotations.show', $quote->public_id) }}" data-row-link class="touch-action text-primary hover:underline truncate" dir="ltr">
                                        {{ $quote->quotation_number }}
                                    </a>
                                    <span class="lg:hidden px-2 py-0.5 rounded-full text-xs font-sans font-bold shrink-0 @if ($quote->status === 'accepted' || $quote->status === 'converted') bg-success-bg text-success @elseif ($quote->status === 'sent') bg-primary-50 text-primary @elseif ($quote->status === 'rejected') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                                        {{ __('sales.status_' . $quote->status) }}
                                    </span>
                                </div>
                                <div class="lg:hidden font-sans font-normal text-xs text-text-secondary truncate mt-0.5">
                                    {{ $quote->customer->displayName() }} &middot; <bdi dir="ltr">{{ $quote->quotation_date->toDateString() }}</bdi>
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.customer') }}">
                                <a href="{{ route('customers.show', $quote->customer->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">
                                    {{ $quote->customer->displayName() }}
                                </a>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.date') }}" dir="ltr">
                                {{ $quote->quotation_date->toDateString() }}
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.valid_until') }}" dir="ltr">
                                {{ $quote->expiry_date ? $quote->expiry_date->toDateString() : '—' }}
                            </td>
                            <td class="td-end py-3 px-4 font-bold">
                                <div class="inline-flex items-center gap-2">
                                    <bdi dir="ltr" class="font-mono tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($quote->grand_total, $quote->currency_code) }} {{ $quote->currency_code }}</bdi>
                                    <x-row-details-toggle />
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.status') }}">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold @if ($quote->status === 'accepted' || $quote->status === 'converted') bg-success-bg text-success @elseif ($quote->status === 'sent') bg-primary-50 text-primary @elseif ($quote->status === 'rejected') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                                    {{ __('sales.status_' . $quote->status) }}
                                </span>
                            </td>
                            <td class="table-actions py-3 px-4 text-end">
                                @include('livewire.partials.quotation-row-actions')
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

        @if ($quotations->count() > 0 || $quotations->currentPage() > 1)
            <div class="p-4 border-t border-border">
                <x-table-pagination :paginator="$quotations" />
            </div>
        @endif
    </div>
</div>
