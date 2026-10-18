<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.sales_invoices') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.sales_invoices') }} ({{ $invoices->total() }})
            </p>
        </div>

        @if ($canCreate)
            <div class="flex items-center gap-2">
                <a href="{{ route('invoices.create') }}"
                   class="touch-action gap-1.5 bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('sales.new_invoice') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Filters & Search Bar -->
    <x-refinement-filters :active-count="(int) ($statusFilter !== 'all') + (int) filled($customerFilter)">
        <x-slot:search>
            <input id="invoices-search" aria-label="{{ __('sales.search') }}" type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('sales.search') }}"
                   class="min-h-11 w-full min-w-0 rounded-control border border-border bg-white px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('statusFilter', 'all')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'all' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('sales.all') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'draft')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'draft' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'draft' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('sales.draft') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'posted')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'posted' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'posted' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('sales.posted') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'void')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'void' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'void' ? 'border-primary bg-primary text-white' : 'border-border bg-white text-text-secondary' }}">{{ __('sales.voided') }}</button>
        </x-slot:statuses>
        <select id="invoices-statusFilter" aria-label="{{ __('sales.status') }}" wire:model.live="statusFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary">
                <option value="all">{{ __('sales.all') }}</option>
                <option value="draft">{{ __('sales.draft') }}</option>
                <option value="posted">{{ __('sales.posted') }}</option>
                <option value="void">{{ __('sales.voided') }}</option>
            </select>
        <select id="invoices-customerFilter" aria-label="{{ __('sales.customer') }}" wire:model.live="customerFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-xs text-text-primary">
                <option value="">{{ __('sales.select_customer') }}</option>
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                @endforeach
            </select>
    </x-refinement-filters>

    <!-- Invoices Table / Cards -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase tracking-wider">
                        <th class="py-3 px-4 text-start">{{ __('sales.invoice_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.customer') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.date') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.status') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.payment_status') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.grand_total') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.outstanding_amount') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($invoices as $inv)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="py-3 px-4 font-mono font-bold" dir="ltr">
                                <a href="{{ route('invoices.show', $inv->public_id) }}" class="touch-action text-primary hover:underline">
                                    {{ $inv->invoice_number ?? __('sales.draft') }}
                                </a>
                            </td>
                            <td class="py-3 px-4">
                                <a href="{{ route('customers.show', $inv->customer->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">
                                    {{ $inv->customer->displayName() }}
                                </a>
                            </td>
                            <td class="py-3 px-4" dir="ltr">
                                {{ $inv->issue_date ? $inv->issue_date->toDateString() : '—' }}
                            </td>
                            <td class="py-3 px-4">
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold @if ($inv->status === 'posted') bg-success-bg text-success @elseif ($inv->status === 'void') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                                    {{ __('sales.' . $inv->status) }}
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                @php
                                    $paymentStatus = $inv->derivedPaymentStatus();
                                @endphp
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold @if ($paymentStatus === 'paid') bg-success-bg text-success @elseif ($paymentStatus === 'partially_paid') bg-warning-bg text-warning @elseif ($paymentStatus === 'credit') bg-primary-50 text-primary @else bg-slate-100 text-slate-600 @endif">
                                    {{ __('sales.' . $paymentStatus) }}
                                </span>
                            </td>
                            <td class="py-3 px-4 font-bold" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($inv->grand_total_currency ?? $inv->grand_total, $inv->currency_code) }} {{ $inv->currency_code }}
                            </td>
                            <td class="py-3 px-4 font-bold" dir="ltr">
                                @php
                                    $outstanding = $inv->calculateOutstanding();
                                    $isPos = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($outstanding);
                                @endphp
                                <span class="{{ $isPos ? 'text-danger' : 'text-text-primary' }}">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($outstanding, $inv->currency_code) }} {{ $inv->currency_code }}
                                </span>
                            </td>
                            <td class="py-3 px-4 text-end">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('invoices.show', $inv->public_id) }}"
                                       class="touch-action bg-surface-soft hover:bg-primary-50 text-text-secondary hover:text-primary font-bold text-[11px] transition-colors">
                                        {{ __('sales.invoice_details') }}
                                    </a>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center text-text-muted">
                                <x-icon name="receipt" class="w-8 h-8 mx-auto mb-2 opacity-40" />
                                <p>{{ __('sales.no_invoices_found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Mobile View -->
        <div class="md:hidden divide-y divide-border">
            @forelse ($invoices as $inv)
                <div class="p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <a href="{{ route('invoices.show', $inv->public_id) }}" class="touch-action font-mono font-bold text-primary" dir="ltr">
                                {{ $inv->invoice_number ?? __('sales.draft') }}
                            </a>
                            <div class="font-bold text-text-primary text-xs mt-0.5">
                                {{ $inv->customer->displayName() }}
                            </div>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold @if ($inv->status === 'posted') bg-success-bg text-success @elseif ($inv->status === 'void') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                            {{ __('sales.' . $inv->status) }}
                        </span>
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.date') }}</span>
                            <span dir="ltr">{{ $inv->issue_date ? $inv->issue_date->toDateString() : '—' }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.payment_status') }}</span>
                            <span class="font-bold">{{ __('sales.' . $inv->derivedPaymentStatus()) }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.grand_total') }}</span>
                            <span class="font-bold text-text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($inv->grand_total_currency ?? $inv->grand_total, $inv->currency_code) }} {{ $inv->currency_code }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.outstanding_amount') }}</span>
                            <span class="font-bold text-danger" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($inv->calculateOutstanding(), $inv->currency_code) }} {{ $inv->currency_code }}</span>
                        </div>
                    </div>

                    <div class="pt-2 border-t border-border flex flex-wrap justify-end gap-2">
                        @can('sales.document.pdf')
                            <a href="{{ route('pdf.invoice', ['publicId' => $inv->public_id, 'locale' => app()->getLocale()]) }}" target="_blank" rel="noopener noreferrer" class="touch-action bg-white text-primary text-sm font-semibold"><x-icon name="receipt" class="h-4 w-4" />{{ __('refinement_lists.pdf') }}</a>
                        @endcan
                        @if($inv->isDraft() && auth()->user()->can('sales.invoice.edit_draft'))
                            <a href="{{ route('invoices.edit', $inv->public_id) }}" class="touch-action bg-primary-50 text-primary text-xs font-bold">{{ __('mobile.edit_draft') }}</a>
                        @elseif($inv->status === 'posted' && \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($inv->calculateOutstanding()) && auth()->user()->can('money.receipt.create'))
                            <a href="{{ route('payments.create', ['invoice_id' => $inv->id]) }}" class="touch-action bg-primary-50 text-primary text-xs font-bold">{{ __('mobile.record_payment') }}</a>
                        @endif
                        <a href="{{ route('invoices.show', $inv->public_id) }}"
                           class="touch-action bg-surface-soft text-text-primary text-xs font-bold">
                            {{ __('sales.invoice_details') }}
                        </a>
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-text-muted">
                    <p>{{ __('sales.no_invoices_found') }}</p>
                </div>
            @endforelse
        </div>

        @if ($invoices->hasPages())
            <div class="p-4 border-t border-border">
                {{ $invoices->links() }}
            </div>
        @endif
    </div>
</div>
