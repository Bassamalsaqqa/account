<div class="space-y-6">
    <!-- Top Action / Info Banner -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                        {{ $customer->displayName() }}
                    </h1>
                    @if ($customer->is_active)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">
                            {{ __('sales.active') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">
                            {{ __('sales.inactive') }}
                        </span>
                    @endif
                </div>

                @if ($customer->business_name_ar || $customer->business_name_en)
                    <p class="text-xs text-text-secondary mt-0.5">
                        {{ app()->getLocale() === 'ar' ? ($customer->business_name_ar ?? $customer->business_name_en) : ($customer->business_name_en ?? $customer->business_name_ar) }}
                    </p>
                @endif

                <div class="flex flex-wrap items-center gap-4 text-xs text-text-muted mt-2">
                    @if ($customer->code)
                        <span class="font-mono bg-canvas px-2 py-0.5 rounded-control text-text-secondary" dir="ltr">
                            {{ $customer->code }}
                        </span>
                    @endif
                    @if ($customer->phone)
                        <span dir="ltr">📞 {{ $customer->phone }}</span>
                    @endif
                    @if ($customer->whatsapp)
                        <span dir="ltr">💬 {{ $customer->whatsapp }}</span>
                    @endif
                    @if ($customer->email)
                        <span dir="ltr">✉️ {{ $customer->email }}</span>
                    @endif
                </div>
            </div>

            <!-- Header Quick Actions -->
            <div class="flex flex-wrap items-center gap-2">
                @if ($canManage)
                    <a href="{{ route('customers.edit', $customer->public_id) }}"
                       class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                        {{ __('sales.edit_customer') }}
                    </a>
                @endif
                @if ($customer->is_active && $canInvoice)
                    <a href="{{ route('invoices.create', ['customer_id' => $customer->id]) }}"
                       class="px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs">
                        + {{ __('sales.new_invoice') }}
                    </a>
                @endif
                @if ($customer->is_active && $canPayment)
                    <a href="{{ route('payments.create', ['customer_id' => $customer->id]) }}"
                       class="px-3 py-1.5 rounded-control bg-success text-white text-xs font-bold hover:opacity-90 transition-opacity shadow-xs">
                        + {{ __('sales.new_payment') }}
                    </a>
                @endif
            </div>
        </div>

        @if ($isOverCreditLimit)
            <div class="p-3 rounded-control bg-warning-bg border border-warning text-warning text-xs font-bold flex items-center gap-2">
                <x-icon name="warning" class="w-4 h-4 shrink-0" />
                <span>{{ __('sales.credit_limit_warning') }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($customer->credit_limit, $customer->default_currency_code) }} {{ $customer->default_currency_code }})</span>
            </div>
        @endif

        <!-- Financial Summary KPI Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-4 border-t border-border">
            <div class="p-4 rounded-control bg-canvas border border-border">
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">
                    {{ __('sales.outstanding_balance') }}
                </div>
                @if (isset($currencyBalances) && count($currencyBalances) > 1)
                    <div class="mt-1 space-y-1">
                        @foreach ($currencyBalances as $cCode => $b)
                            <div class="text-sm font-extrabold {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($b['outstanding']) ? 'text-danger' : 'text-text-primary' }}" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($b['outstanding'], $cCode) }} {{ $cCode }}
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-xl font-extrabold mt-1 {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($totalOutstanding) ? 'text-danger' : 'text-text-primary' }}" dir="ltr">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($totalOutstanding, $customer->default_currency_code) }} {{ $customer->default_currency_code }}
                    </div>
                @endif
            </div>

            <div class="p-4 rounded-control bg-canvas border border-border">
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">
                    {{ __('sales.total') }} {{ __('sales.sales_invoices') }}
                </div>
                @if (isset($currencyBalances) && count($currencyBalances) > 1)
                    <div class="mt-1 space-y-1">
                        @foreach ($currencyBalances as $cCode => $b)
                            <div class="text-sm font-extrabold text-text-primary" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($b['invoiced'], $cCode) }} {{ $cCode }}
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-xl font-extrabold mt-1 text-text-primary" dir="ltr">
                        {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($totalInvoiced, $customer->default_currency_code) }} {{ $customer->default_currency_code }}
                    </div>
                @endif
            </div>

            <div class="p-4 rounded-control bg-canvas border border-border">
                <div class="text-[11px] font-bold text-text-muted uppercase tracking-wider">
                    {{ __('sales.credit_limit') }}
                </div>
                <div class="text-xl font-extrabold mt-1 text-text-secondary" dir="ltr">
                    {{ $customer->credit_limit ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($customer->credit_limit, $customer->default_currency_code) . ' ' . $customer->default_currency_code : '—' }}
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="border-b border-border flex items-center gap-2 overflow-x-auto text-xs font-bold">
        <button type="button"
                wire:click="$set('activeTab', 'overview')"
                class="pb-2.5 px-3 border-b-2 transition-colors cursor-pointer {{ $activeTab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-primary' }}">
            {{ __('sales.tab_overview') }}
        </button>
        <button type="button"
                wire:click="$set('activeTab', 'invoices')"
                class="pb-2.5 px-3 border-b-2 transition-colors cursor-pointer {{ $activeTab === 'invoices' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-primary' }}">
            {{ __('sales.tab_invoices') }}
        </button>
        <button type="button"
                wire:click="$set('activeTab', 'quotations')"
                class="pb-2.5 px-3 border-b-2 transition-colors cursor-pointer {{ $activeTab === 'quotations' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-primary' }}">
            {{ __('sales.tab_quotations') }}
        </button>
        <button type="button"
                wire:click="$set('activeTab', 'payments')"
                class="pb-2.5 px-3 border-b-2 transition-colors cursor-pointer {{ $activeTab === 'payments' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-primary' }}">
            {{ __('sales.tab_payments') }}
        </button>
        <button type="button"
                wire:click="$set('activeTab', 'returns')"
                class="pb-2.5 px-3 border-b-2 transition-colors cursor-pointer {{ $activeTab === 'returns' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-primary' }}">
            {{ __('sales.tab_returns') }}
        </button>
        @if ($canStatement)
            <button type="button"
                    wire:click="$set('activeTab', 'statement')"
                    class="pb-2.5 px-3 border-b-2 transition-colors cursor-pointer {{ $activeTab === 'statement' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-primary' }}">
                {{ __('sales.tab_statement') }}
            </button>
        @endif
        <button type="button"
                wire:click="$set('activeTab', 'notes')"
                class="pb-2.5 px-3 border-b-2 transition-colors cursor-pointer {{ $activeTab === 'notes' ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:text-text-primary' }}">
            {{ __('sales.tab_notes') }}
        </button>
    </div>

    <!-- Tab Contents -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6">
        @if ($activeTab === 'overview')
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 text-xs">
                <div class="space-y-3">
                    <h3 class="font-bold text-sm text-text-primary border-b border-border pb-1">
                        {{ __('sales.customer_details') }}
                    </h3>
                    <div class="grid grid-cols-2 gap-2">
                        <span class="text-text-muted">{{ __('sales.code') }}:</span>
                        <span class="font-mono text-text-primary" dir="ltr">{{ $customer->code ?? '—' }}</span>

                        <span class="text-text-muted">{{ __('sales.name_ar') }}:</span>
                        <span class="text-text-primary font-bold">{{ $customer->name_ar }}</span>

                        <span class="text-text-muted">{{ __('sales.name_en') }}:</span>
                        <span class="text-text-primary">{{ $customer->name_en ?? '—' }}</span>

                        <span class="text-text-muted">{{ __('sales.preferred_locale') }}:</span>
                        <span class="text-text-primary">{{ $customer->preferred_locale === 'ar' ? 'العربية' : 'English' }}</span>

                        <span class="text-text-muted">{{ __('sales.default_currency') }}:</span>
                        <span class="text-text-primary font-bold">{{ $customer->default_currency_code }}</span>
                    </div>
                </div>

                <div class="space-y-3">
                    <h3 class="font-bold text-sm text-text-primary border-b border-border pb-1">
                        {{ __('sales.address_ar') }} / {{ __('sales.phone') }}
                    </h3>
                    <div class="grid grid-cols-2 gap-2">
                        <span class="text-text-muted">{{ __('sales.phone') }}:</span>
                        <span class="text-text-primary" dir="ltr">{{ $customer->phone ?? '—' }}</span>

                        <span class="text-text-muted">{{ __('sales.whatsapp') }}:</span>
                        <span class="text-text-primary" dir="ltr">{{ $customer->whatsapp ?? '—' }}</span>

                        <span class="text-text-muted">{{ __('sales.email') }}:</span>
                        <span class="text-text-primary" dir="ltr">{{ $customer->email ?? '—' }}</span>

                        <span class="text-text-muted">{{ __('sales.address_ar') }}:</span>
                        <span class="text-text-primary">{{ $customer->address_ar ?? '—' }}</span>

                        <span class="text-text-muted">{{ __('sales.address_en') }}:</span>
                        <span class="text-text-primary">{{ $customer->address_en ?? '—' }}</span>
                    </div>
                </div>
            </div>

        @elseif ($activeTab === 'invoices')
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                            <th class="py-2.5 px-3 text-start">{{ __('sales.invoice_number') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.date') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.status') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.payment_status') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.grand_total') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.outstanding_amount') }}</th>
                            <th class="py-2.5 px-3 text-end">{{ __('sales.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tabInvoices as $inv)
                            <tr class="hover:bg-surface-soft/60">
                                <td class="py-2.5 px-3 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('invoices.show', $inv->public_id) }}" class="text-primary hover:underline">
                                        {{ $inv->invoice_number ?? __('sales.draft') }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-3" dir="ltr">{{ $inv->issue_date ? (is_string($inv->issue_date) ? $inv->issue_date : $inv->issue_date->toDateString()) : '—' }}</td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $inv->status === 'posted' ? 'bg-success-bg text-success' : ($inv->status === 'draft' ? 'bg-slate-100 text-slate-600' : 'bg-danger-bg text-danger') }}">
                                        {{ __('sales.' . $inv->status) }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-surface-soft text-text-secondary">
                                        {{ __('sales.' . $inv->derivedPaymentStatus()) }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($inv->grand_total_currency, $inv->currency_code) }} {{ $inv->currency_code }}
                                </td>
                                <td class="py-2.5 px-3 font-bold text-danger" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($inv->calculateOutstanding(), $inv->currency_code) }} {{ $inv->currency_code }}
                                </td>
                                <td class="py-2.5 px-3 text-end">
                                    <a href="{{ route('invoices.show', $inv->public_id) }}" class="text-primary hover:underline font-bold text-[11px]">
                                        {{ __('sales.invoice_details') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-6 text-center text-text-muted">
                                    {{ __('sales.no_invoices_found') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif ($activeTab === 'quotations')
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                            <th class="py-2.5 px-3 text-start">{{ __('sales.quote_number') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.date') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.valid_until') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.status') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.grand_total') }}</th>
                            <th class="py-2.5 px-3 text-end">{{ __('sales.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tabQuotations as $quote)
                            <tr class="hover:bg-surface-soft/60">
                                <td class="py-2.5 px-3 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('quotations.show', $quote->public_id) }}" class="text-primary hover:underline">
                                        {{ $quote->quotation_number }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-3" dir="ltr">{{ $quote->issue_date ? (is_string($quote->issue_date) ? $quote->issue_date : $quote->issue_date->toDateString()) : '—' }}</td>
                                <td class="py-2.5 px-3" dir="ltr">{{ $quote->expiry_date ? (is_string($quote->expiry_date) ? $quote->expiry_date : $quote->expiry_date->toDateString()) : '—' }}</td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-surface-soft text-text-secondary">
                                        {{ __('sales.status_' . $quote->status) }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($quote->grand_total_currency, $quote->currency_code) }} {{ $quote->currency_code }}
                                </td>
                                <td class="py-2.5 px-3 text-end">
                                    <a href="{{ route('quotations.show', $quote->public_id) }}" class="text-primary hover:underline font-bold text-[11px]">
                                        {{ __('sales.quotation_details') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-text-muted">
                                    {{ __('sales.no_quotations_found') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif ($activeTab === 'payments')
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                            <th class="py-2.5 px-3 text-start">{{ __('sales.payment_number') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.date') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.payment_method') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.money_account') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.payment_amount') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.status') }}</th>
                            <th class="py-2.5 px-3 text-end">{{ __('sales.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tabPayments as $pmt)
                            <tr class="hover:bg-surface-soft/60">
                                <td class="py-2.5 px-3 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('payments.show', $pmt->public_id) }}" class="text-primary hover:underline">
                                        {{ $pmt->payment_number }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-3" dir="ltr">{{ $pmt->payment_date ? (is_string($pmt->payment_date) ? $pmt->payment_date : $pmt->payment_date->toDateString()) : '—' }}</td>
                                <td class="py-2.5 px-3">{{ __('sales.' . $pmt->payment_method) }}</td>
                                <td class="py-2.5 px-3">{{ $pmt->moneyAccount?->displayName() ?? '—' }}</td>
                                <td class="py-2.5 px-3 font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($pmt->amount, $pmt->currency_code) }} {{ $pmt->currency_code }}
                                </td>
                                <td class="py-2.5 px-3">
                                    @if ($pmt->is_reversed)
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-danger-bg text-danger">
                                            {{ __('sales.voided') }}
                                        </span>
                                    @else
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-bg text-success">
                                            {{ __('sales.posted') }}
                                        </span>
                                    @endif
                                </td>
                                <td class="py-2.5 px-3 text-end">
                                    <a href="{{ route('payments.show', $pmt->public_id) }}" class="text-primary hover:underline font-bold text-[11px]">
                                        {{ __('sales.payment_details') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="py-6 text-center text-text-muted">
                                    {{ __('sales.no_payments_found') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif ($activeTab === 'returns')
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                            <th class="py-2.5 px-3 text-start">{{ __('sales.return_number') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.date') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.original_invoice') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.status') }}</th>
                            <th class="py-2.5 px-3 text-start">{{ __('sales.grand_total') }}</th>
                            <th class="py-2.5 px-3 text-end">{{ __('sales.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tabReturns as $ret)
                            <tr class="hover:bg-surface-soft/60">
                                <td class="py-2.5 px-3 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('returns.show', $ret->public_id) }}" class="text-primary hover:underline">
                                        {{ $ret->return_number ?? __('sales.draft') }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-3" dir="ltr">{{ $ret->issue_date ? (is_string($ret->issue_date) ? $ret->issue_date : $ret->issue_date->toDateString()) : '—' }}</td>
                                <td class="py-2.5 px-3 font-mono" dir="ltr">{{ $ret->salesInvoice?->invoice_number ?? '—' }}</td>
                                <td class="py-2.5 px-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $ret->status === 'posted' ? 'bg-success-bg text-success' : 'bg-slate-100 text-slate-600' }}">
                                        {{ __('sales.' . $ret->status) }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-3 font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($ret->grand_total_currency, $ret->currency_code) }} {{ $ret->currency_code }}
                                </td>
                                <td class="py-2.5 px-3 text-end">
                                    <a href="{{ route('returns.show', $ret->public_id) }}" class="text-primary hover:underline font-bold text-[11px]">
                                        {{ __('sales.return_details') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-6 text-center text-text-muted">
                                    {{ __('sales.no_returns_found') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

        @elseif ($activeTab === 'statement' && $canStatement && $statementData !== null)
            <div class="space-y-6">
                <!-- Date Filters -->
                <div class="flex flex-wrap items-center gap-4 bg-canvas p-3 rounded-control border border-border text-xs">
                    <div class="flex items-center gap-2">
                        <label class="font-bold text-text-primary">{{ __('sales.from_date') }}:</label>
                        <input type="date" wire:model.live="statementFrom" class="h-8 px-2 rounded-control border border-border bg-white text-xs" />
                    </div>
                    <div class="flex items-center gap-2">
                        <label class="font-bold text-text-primary">{{ __('sales.to_date') }}:</label>
                        <input type="date" wire:model.live="statementTo" class="h-8 px-2 rounded-control border border-border bg-white text-xs" />
                    </div>
                    <a href="{{ route('pdf.statement', ['publicId' => $customer->public_id, 'from' => $statementFrom, 'to' => $statementTo]) }}"
                       target="_blank"
                       class="ml-auto px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors">
                        📄 {{ __('sales.download_pdf') }}
                    </a>
                </div>

                <!-- Per Currency Statements -->
                @foreach (($statementData['currencies'] ?? []) as $curr => $currencyStatement)
                    <div class="space-y-4 border border-border rounded-control p-4">
                        <div class="flex items-center justify-between pb-2 border-b border-border">
                            <h4 class="font-bold text-sm text-text-primary">
                                {{ __('sales.currency') }}: {{ $curr }}
                            </h4>
                            <div class="text-xs" dir="ltr">
                                <span class="text-text-muted">{{ __('sales.closing_balance') }}:</span>
                                <span class="font-bold text-sm {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($currencyStatement['closing_balance']) ? 'text-danger' : 'text-text-primary' }}">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($currencyStatement['closing_balance'], $curr) }} {{ $curr }}
                                </span>
                            </div>
                        </div>

                        <!-- Aging Breakdown -->
                        <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-center text-xs">
                            <div class="p-2 rounded-control bg-canvas">
                                <div class="text-[10px] text-text-muted">{{ __('sales.aging_current') }}</div>
                                <div class="font-bold text-text-primary mt-0.5" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($currencyStatement['aging']['current'] ?? '0', $curr) }}</div>
                            </div>
                            <div class="p-2 rounded-control bg-canvas">
                                <div class="text-[10px] text-text-muted">{{ __('sales.aging_1_30') }}</div>
                                <div class="font-bold text-text-primary mt-0.5" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($currencyStatement['aging']['days_1_30'] ?? '0', $curr) }}</div>
                            </div>
                            <div class="p-2 rounded-control bg-canvas">
                                <div class="text-[10px] text-text-muted">{{ __('sales.aging_31_60') }}</div>
                                <div class="font-bold text-text-primary mt-0.5" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($currencyStatement['aging']['days_31_60'] ?? '0', $curr) }}</div>
                            </div>
                            <div class="p-2 rounded-control bg-canvas">
                                <div class="text-[10px] text-text-muted">{{ __('sales.aging_61_90') }}</div>
                                <div class="font-bold text-text-primary mt-0.5" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($currencyStatement['aging']['days_61_90'] ?? '0', $curr) }}</div>
                            </div>
                            <div class="p-2 rounded-control bg-canvas">
                                <div class="text-[10px] text-text-muted">{{ __('sales.aging_90_plus') }}</div>
                                <div class="font-bold text-danger mt-0.5" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($currencyStatement['aging']['days_90_plus'] ?? '0', $curr) }}</div>
                            </div>
                        </div>

                        <!-- Statement Entries Table -->
                        <div class="overflow-x-auto">
                            <table class="w-full text-start text-xs border-collapse">
                                <thead>
                                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                                        <th class="py-2 px-3 text-start">{{ __('sales.date') }}</th>
                                        <th class="py-2 px-3 text-start">{{ __('sales.actions') }}</th>
                                        <th class="py-2 px-3 text-start">{{ __('sales.debit') }}</th>
                                        <th class="py-2 px-3 text-start">{{ __('sales.credit_col') }}</th>
                                        <th class="py-2 px-3 text-start">{{ __('sales.running_balance') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border">
                                    <tr class="bg-surface-soft/40 italic">
                                        <td class="py-2 px-3" colspan="4">{{ __('sales.opening_balance') }}</td>
                                        <td class="py-2 px-3 font-bold" dir="ltr">
                                            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($currencyStatement['opening_balance'], $curr) }}
                                        </td>
                                    </tr>
                                    @foreach (($currencyStatement['entries'] ?? []) as $entry)
                                        <tr>
                                            <td class="py-2 px-3" dir="ltr">{{ $entry['date'] }}</td>
                                            <td class="py-2 px-3">
                                                <span class="font-bold font-mono">{{ $entry['number'] }}</span>
                                                <span class="text-text-muted text-[11px]">({{ $entry['type'] }})</span>
                                            </td>
                                            <td class="py-2 px-3" dir="ltr">
                                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($entry['debit']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($entry['debit'], $curr) : '—' }}
                                            </td>
                                            <td class="py-2 px-3" dir="ltr">
                                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($entry['credit']) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($entry['credit'], $curr) : '—' }}
                                            </td>
                                            <td class="py-2 px-3 font-bold" dir="ltr">
                                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($entry['balance'], $curr) }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endforeach
            </div>

        @elseif ($activeTab === 'notes')
            <div class="text-xs text-text-primary whitespace-pre-line">
                {{ $customer->notes ?: '—' }}
            </div>
        @endif
    </div>
</div>
