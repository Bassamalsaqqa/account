<div class="space-y-6">
    <x-page-heading :title="__('sales.sales_invoices')" icon="receipt">
        <x-slot:description><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($invoices->total()) }}</bdi></x-slot:description>
        @if ($canCreate)
            <div class="flex items-center gap-2">
                <a href="{{ route('invoices.create') }}"
                   class="touch-action gap-1.5 bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('sales.new_invoice') }}</span>
                </a>
            </div>
        @endif
    </x-page-heading>

    <!-- Filters & Search Bar -->
    <x-refinement-filters :active-count="(int) ($statusFilter !== 'all') + (int) filled($customerFilter)">
        <x-slot:search>
            <input id="invoices-search" aria-label="{{ __('sales.search') }}" type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('sales.search') }}"
                   class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>
        <x-slot:statuses>
        <button type="button" wire:click="$set('statusFilter', 'all')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'all' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'all' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('sales.all') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'draft')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'draft' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'draft' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('sales.draft') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'posted')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'posted' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'posted' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('sales.posted') }}</button>
        <button type="button" wire:click="$set('statusFilter', 'void')" wire:loading.attr="disabled" wire:target="statusFilter" aria-pressed="{{ $statusFilter === 'void' ? 'true' : 'false' }}" class="touch-action shrink-0 border text-sm font-semibold {{ $statusFilter === 'void' ? 'border-primary bg-primary text-white' : 'border-border bg-surface text-text-secondary' }}">{{ __('sales.voided') }}</button>
        </x-slot:statuses>
        <select id="invoices-statusFilter" aria-label="{{ __('sales.status') }}" wire:model.live="statusFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary">
                <option value="all">{{ __('sales.all') }}</option>
                <option value="draft">{{ __('sales.draft') }}</option>
                <option value="posted">{{ __('sales.posted') }}</option>
                <option value="void">{{ __('sales.voided') }}</option>
            </select>
        <select id="invoices-customerFilter" aria-label="{{ __('sales.customer') }}" wire:model.live="customerFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary">
                <option value="">{{ __('sales.select_customer') }}</option>
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                @endforeach
            </select>
    </x-refinement-filters>

    @if(session()->has('error'))<p role="alert" class="text-sm text-danger font-semibold bg-danger-bg p-3 rounded-control border border-danger/20">{{ session('error') }}</p>@endif
    @error('postInvoice') <p role="alert" class="text-sm text-danger font-semibold bg-danger-bg p-3 rounded-control border border-danger/20">{{ $message }}</p> @enderror
    @error('deleteDraft') <p role="alert" class="text-sm text-danger font-semibold bg-danger-bg p-3 rounded-control border border-danger/20">{{ $message }}</p> @enderror
    @if(session()->has('success'))<p role="status" class="text-sm text-success font-semibold bg-success-bg p-3 rounded-control border border-success/20">{{ session('success') }}</p>@endif
    <!-- Invoices Table -->
    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase tracking-wider">
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
                        @php
                            $paymentStatus = $summaries[$inv->id]['status'];
                            $outstanding = $summaries[$inv->id]['outstanding'];
                            $isPos = \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($outstanding);
                        @endphp
                        <tr wire:key="invoice-row-{{ $inv->id }}" data-row-surface x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60 transition-colors">
                            <td class="td-primary py-3 px-4 font-mono font-bold">
                                <div class="flex items-center gap-2 min-w-0">
                                    <a href="{{ route('invoices.show', $inv->public_id) }}" data-row-link class="touch-action text-primary hover:underline truncate" dir="ltr">
                                        {{ $inv->invoice_number ?? __('sales.draft') }}
                                    </a>
                                    <span class="lg:hidden px-2 py-0.5 rounded-full text-xs font-sans font-bold shrink-0 @if ($inv->status === 'posted') bg-success-bg text-success @elseif ($inv->status === 'void') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                                        {{ __('sales.' . $inv->status) }}
                                    </span>
                                </div>
                                <div class="lg:hidden font-sans font-normal text-xs text-text-secondary truncate mt-0.5">
                                    {{ $inv->customer->displayName() }}@if($inv->issue_date) &middot; <bdi dir="ltr">{{ $inv->issue_date->toDateString() }}</bdi>@endif
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.customer') }}">
                                <a href="{{ route('customers.show', $inv->customer->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">
                                    {{ $inv->customer->displayName() }}
                                </a>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.date') }}" dir="ltr">
                                {{ $inv->issue_date ? $inv->issue_date->toDateString() : '—' }}
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.status') }}">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold @if ($inv->status === 'posted') bg-success-bg text-success @elseif ($inv->status === 'void') bg-danger-bg text-danger @else bg-slate-100 text-slate-600 @endif">
                                    {{ __('sales.' . $inv->status) }}
                                </span>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.payment_status') }}">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold @if ($paymentStatus === 'paid') bg-success-bg text-success @elseif ($paymentStatus === 'partially_paid') bg-warning-bg text-warning @elseif ($paymentStatus === 'credit') bg-primary-50 text-primary @else bg-slate-100 text-slate-600 @endif">
                                    {{ __('sales.' . $paymentStatus) }}
                                </span>
                            </td>
                            <td class="td-end py-3 px-4 font-bold">
                                <div class="inline-flex items-center gap-2">
                                    <bdi dir="ltr" class="font-mono tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($inv->grand_total_currency ?? $inv->grand_total, $inv->currency_code) }} {{ $inv->currency_code }}</bdi>
                                    <x-row-details-toggle />
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4 font-bold" data-label="{{ __('sales.outstanding_amount') }}">
                                <bdi dir="ltr" class="font-mono tabular-nums {{ $isPos ? 'text-danger' : 'text-text-primary' }}">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($outstanding, $inv->currency_code) }} {{ $inv->currency_code }}
                                </bdi>
                            </td>
                            <td class="table-actions py-3 px-4 text-end">
                                @include('livewire.partials.invoice-row-actions')
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

        @if ($invoices->count() > 0 || $invoices->currentPage() > 1)
            <div class="p-4 border-t border-border">
                <x-table-pagination :paginator="$invoices" />
            </div>
        @endif
    </div>

    @if($payingInvoiceId)
        <x-dialog-shell
            :title="__('sales.new_payment')"
            title-id="modal-payment-title"
            max-width="max-w-2xl"
            close-action="closePaymentModal" save-target="save">
            <livewire:pages.sales.payment-form :invoice_id="$payingInvoiceId" :is-modal="true" :key="'modal-payment-'.$payingInvoiceId" />
        </x-dialog-shell>
    @endif
</div>
