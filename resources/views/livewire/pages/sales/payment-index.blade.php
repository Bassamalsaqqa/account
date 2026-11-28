<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.customer_payments') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.customer_payments') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($payments->total()) }})
            </p>
        </div>

        @if ($canCreate)
            <div class="flex items-center gap-2">
                <a href="{{ route('payments.create') }}"
                   class="touch-action gap-1.5 bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('sales.new_payment') }}</span>
                </a>
            </div>
        @endif
    </div>

    <!-- Filters & Search Bar -->
    <x-refinement-filters :active-count="(int) filled($customerFilter) + (int) filled($accountFilter)">
        <x-slot:search>
            <input aria-label="{{ __('sales.search') }}" type="text"
                   wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('sales.search') }}"
                   class="min-h-11 w-full min-w-0 rounded-control border border-border bg-surface px-3 py-2 text-sm focus:border-primary focus:ring-primary" />
        </x-slot:search>

        <select aria-label="{{ __('sales.select_customer') }}" wire:model.live="customerFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary">
                <option value="">{{ __('sales.select_customer') }}</option>
                @foreach ($customers as $c)
                    <option value="{{ $c->id }}">{{ $c->displayName() }}</option>
                @endforeach
            </select>
        <select aria-label="{{ __('sales.select_money_account') }}" wire:model.live="accountFilter" class="min-h-11 h-11 px-3 rounded-control border border-border bg-canvas text-sm text-text-primary">
                <option value="">{{ __('sales.select_money_account') }}</option>
                @foreach ($accounts as $acc)
                    <option value="{{ $acc->id }}">{{ $acc->displayName() }} ({{ $acc->currency_code }})</option>
                @endforeach
            </select>
    </x-refinement-filters>

    <!-- Payments Table -->
    <div class="bg-surface rounded-card border border-border shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="data-table data-table--responsive w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-xs uppercase tracking-wider">
                        <th class="py-3 px-4 text-start">{{ __('sales.payment_number') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.customer') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.money_account') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.payment_method') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.date') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.payment_amount') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.status') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($payments as $pmt)
                        <tr wire:key="payment-row-{{ $pmt->id }}" data-row-surface x-data="{ expanded: false }" :data-expanded="expanded ? 'true' : 'false'" class="hover:bg-surface-soft/60 transition-colors">
                            <td class="td-primary py-3 px-4 font-mono font-bold">
                                <div class="flex items-center gap-2 min-w-0">
                                    <a href="{{ route('payments.show', $pmt->public_id) }}" data-row-link class="touch-action text-primary hover:underline truncate" dir="ltr">
                                        {{ $pmt->payment_number }}
                                    </a>
                                    @if ($pmt->is_reversed)
                                        <span class="lg:hidden px-2 py-0.5 rounded-full text-xs font-sans font-bold shrink-0 bg-danger-bg text-danger">
                                            {{ __('sales.voided') }}
                                        </span>
                                    @else
                                        <span class="lg:hidden px-2 py-0.5 rounded-full text-xs font-sans font-bold shrink-0 bg-success-bg text-success">
                                            {{ __('sales.posted') }}
                                        </span>
                                    @endif
                                </div>
                                <div class="lg:hidden font-sans font-normal text-xs text-text-secondary truncate mt-0.5">
                                    {{ $pmt->customer->displayName() }} &middot; <bdi dir="ltr">{{ $pmt->payment_date->toDateString() }}</bdi>
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.customer') }}">
                                <a href="{{ route('customers.show', $pmt->customer->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">
                                    {{ $pmt->customer->displayName() }}
                                </a>
                            </td>
                            <td class="td-secondary py-3 px-4 text-text-secondary" data-label="{{ __('sales.money_account') }}">
                                {{ $pmt->checkInstrument?->check_number ?? $pmt->moneyAccount?->displayName() ?? '—' }}
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.payment_method') }}">
                                <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-surface-soft text-text-secondary">
                                    {{ $pmt->payment_method === 'check' ? __('money.check') : __('sales.' . $pmt->payment_method) }}
                                </span>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.date') }}" dir="ltr">
                                {{ $pmt->payment_date->toDateString() }}
                            </td>
                            <td class="td-end py-3 px-4 font-bold">
                                <div class="inline-flex items-center gap-2">
                                    <bdi dir="ltr" class="font-mono tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($pmt->amount, $pmt->currency_code) }} {{ $pmt->currency_code }}</bdi>
                                    <x-row-details-toggle />
                                </div>
                            </td>
                            <td class="td-secondary py-3 px-4" data-label="{{ __('sales.status') }}">
                                @if ($pmt->is_reversed)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-danger-bg text-danger">
                                        {{ __('sales.voided') }}
                                    </span>
                                @else
                                    <span class="px-2 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">
                                        {{ __('sales.posted') }}
                                    </span>
                                @endif
                            </td>
                            <td class="table-actions py-3 px-4 text-end">
                                <div class="row-action-group justify-end" role="group" aria-label="{{ __('sales.actions') }}">
                                    <x-row-action data-row-link :href="route('payments.show', $pmt->public_id)" :label="__('workflow.open')" icon="eye" />
                                    @if(auth()->user()->can('sales.document.pdf') && auth()->user()->can('money.receipt.view'))
                                        <x-row-action :href="route('pdf.payment', ['publicId' => $pmt->public_id, 'locale' => app()->getLocale()])" :label="__('refinement_lists.pdf')" icon="pdf" :new-tab="true" />
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="py-8 text-center text-text-muted">
                                <x-icon name="credit-card" class="w-8 h-8 mx-auto mb-2 opacity-40" />
                                <p>{{ __('sales.no_payments_found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($payments->count() > 0 || $payments->currentPage() > 1)
            <div class="p-4 border-t border-border">
                <x-table-pagination :paginator="$payments" />
            </div>
        @endif
    </div>
</div>
