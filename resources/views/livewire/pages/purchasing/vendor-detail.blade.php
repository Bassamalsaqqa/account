<div class="space-y-6">
    <!-- Top Action / Info Banner -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                        {{ $vendor->displayName() }}
                    </h1>
                    @if ($vendor->status === 'active')
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-success-bg text-success">
                            {{ __('purchasing.active') }}
                        </span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">
                            {{ __('purchasing.inactive') }}
                        </span>
                    @endif
                </div>

                @if ($vendor->business_name_ar || $vendor->business_name_en)
                    <p class="text-xs text-text-secondary mt-0.5">
                        {{ app()->getLocale() === 'ar' ? ($vendor->business_name_ar ?? $vendor->business_name_en) : ($vendor->business_name_en ?? $vendor->business_name_ar) }}
                    </p>
                @endif

                <div class="flex flex-wrap items-center gap-4 text-xs text-text-muted mt-2">
                    @if ($vendor->code)
                        <span class="font-mono bg-canvas px-2 py-0.5 rounded-control text-text-secondary" dir="ltr">
                            {{ $vendor->code }}
                        </span>
                    @endif
                    @if ($vendor->phone)
                        <span dir="ltr">📞 {{ $vendor->phone }}</span>
                    @endif
                    @if ($vendor->whatsapp)
                        <span dir="ltr">💬 {{ $vendor->whatsapp }}</span>
                    @endif
                    @if ($vendor->email)
                        <span dir="ltr">✉️ {{ $vendor->email }}</span>
                    @endif
                </div>
            </div>

            <!-- Header Quick Actions -->
            <div class="flex flex-wrap items-center gap-2">
                <a href="{{ route('vendors.index') }}" class="px-3 py-1.5 text-xs text-text-secondary hover:text-text-primary">
                    {{ __('purchasing.back') }}
                </a>
                @if ($canManage)
                    <a href="{{ route('vendors.edit', $vendor->public_id) }}"
                       class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                        {{ __('purchasing.edit_vendor_button') }}
                    </a>
                @endif
                @if ($vendor->status === 'active' && $canCreatePurchase)
                    <a href="{{ route('purchases.create', ['vendor_id' => $vendor->id]) }}"
                       class="px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs">
                        + {{ __('purchasing.new_purchase') }}
                    </a>
                @endif
                @if ($vendor->status === 'active' && $canCreatePayment)
                    <a href="{{ route('vendor-payments.create', ['vendor_id' => $vendor->id]) }}"
                       class="px-3 py-1.5 rounded-control bg-success text-white text-xs font-bold hover:opacity-90 transition-opacity shadow-xs">
                        + {{ __('purchasing.new_vendor_payment') }}
                    </a>
                @endif
            </div>
        </div>

        @if (session()->has('success'))
            <p role="status" class="p-3 rounded-control bg-success-bg text-success text-sm font-bold">{{ session('success') }}</p>
        @endif

        @if ($withCost && ! empty($currencyBalances))
            <!-- Financial Summary KPI Cards -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 pt-4 border-t border-border">
                @foreach ($currencyBalances as $curr => $b)
                    @php
                        $bal = \Brick\Math\BigDecimal::of($b['balance']);
                        $isPayable = $bal->isPositive();
                        $isCredit = $bal->isNegative();
                    @endphp
                    <div class="p-4 rounded-control bg-canvas border border-border col-span-1 sm:col-span-2 lg:col-span-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 border-b border-border pb-2">
                            <span class="text-xs font-bold uppercase tracking-wider text-text-primary">{{ $curr }}</span>
                            <div class="text-sm font-extrabold" dir="ltr">
                                @if ($isPayable)
                                    <span class="text-danger">{{ __('purchasing.net_payable') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($b['balance'], $curr) }} {{ $curr }}</span>
                                @elseif ($isCredit)
                                    <span class="text-success">{{ __('purchasing.net_credit') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format((string) $bal->abs(), $curr) }} {{ $curr }}</span>
                                @else
                                    <span class="text-text-muted">{{ __('purchasing.settled') }}: 0.00 {{ $curr }}</span>
                                @endif
                            </div>
                        </div>
                        <div class="grid grid-cols-3 gap-2 pt-2 text-xs">
                            <div>
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.purchases') }}</span>
                                <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($b['purchased'], $curr) }}</span>
                            </div>
                            <div>
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.purchase_returns') }}</span>
                                <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($b['returned'], $curr) }}</span>
                            </div>
                            <div>
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.vendor_payments') }}</span>
                                <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($b['paid'], $curr) }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    @if ($withCost)
        <!-- Tabs Navigation -->
        <div class="border-b border-border flex items-center gap-4 text-xs font-bold">
            <button type="button" wire:click="$set('activeTab', 'overview')" class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'overview' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('purchasing.vendor_details') }}
            </button>
            <button type="button" wire:click="$set('activeTab', 'purchases')" class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'purchases' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('purchasing.purchases') }}
            </button>
            <button type="button" wire:click="$set('activeTab', 'returns')" class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'returns' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('purchasing.purchase_returns') }}
            </button>
            <button type="button" wire:click="$set('activeTab', 'payments')" class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'payments' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                {{ __('purchasing.vendor_payments') }}
            </button>
            @if ($canStatement)
                <button type="button" wire:click="$set('activeTab', 'statement')" class="pb-3 border-b-2 cursor-pointer transition-colors {{ $activeTab === 'statement' ? 'border-primary text-primary' : 'border-transparent text-text-secondary hover:text-text-primary' }}">
                    {{ __('purchasing.statement') }} & {{ __('purchasing.aging') }}
                </button>
            @endif
        </div>
    @endif

    <!-- Tab Contents -->
    @if ($activeTab === 'overview')
        <div class="space-y-5">
            <section class="bg-white border border-border rounded-card p-4 sm:p-5">
                <dl class="grid sm:grid-cols-2 gap-5 text-sm">
                    @foreach (['name_ar' => 'vendor_name_ar', 'name_en' => 'vendor_name_en', 'business_name_ar' => 'business_name_ar', 'business_name_en' => 'business_name_en', 'code' => 'vendor_code', 'tax_number' => 'tax_number', 'phone' => 'phone', 'whatsapp' => 'whatsapp', 'email' => 'email', 'address_ar' => 'address_ar', 'address_en' => 'address_en', 'city_ar' => 'city_ar', 'city_en' => 'city_en', 'postal_code' => 'postal_code', 'country_code' => 'country_code'] as $field => $label)
                        <div class="min-w-0">
                            <dt class="text-xs text-text-secondary mb-1">{{ __('purchasing.' . $label) }}</dt>
                            <dd class="break-words whitespace-pre-line"><bdi>{{ $vendor->{$field} ?? '—' }}</bdi></dd>
                        </div>
                    @endforeach
                    <div>
                        <dt class="text-xs text-text-secondary mb-1">{{ __('purchasing.preferred_locale') }}</dt>
                        <dd>{{ $vendor->preferred_locale ? __('purchasing.language_' . $vendor->preferred_locale) : __('purchasing.company_default') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-text-secondary mb-1">{{ __('purchasing.default_currency') }}</dt>
                        <dd>{{ $vendor->default_currency_code ?? __('purchasing.company_default') }}</dd>
                    </div>
                </dl>
            </section>
            <section class="bg-white border border-border rounded-card p-4 sm:p-5">
                <h2 class="text-sm font-bold mb-3">{{ __('purchasing.notes') }}</h2>
                <p class="text-sm text-text-secondary break-words whitespace-pre-line">{{ $vendor->notes ?? __('purchasing.no_notes') }}</p>
            </section>
        </div>
    @elseif ($activeTab === 'purchases' && $withCost)
        <div class="bg-white rounded-card border border-border overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <tr>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.purchase_number') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.purchase_date') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.all_statuses') }}</th>
                            <th class="py-2.5 px-4 text-end">{{ __('purchasing.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tabPurchases as $purchase)
                            <tr>
                                <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('purchases.show', $purchase->public_id) }}" class="text-primary hover:underline">
                                        {{ $purchase->purchase_number ?? __('purchasing.draft') }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-4" dir="ltr">{{ $purchase->purchase_date->toDateString() }}</td>
                                <td class="py-2.5 px-4">{{ __('purchasing.' . $purchase->status) }}</td>
                                <td class="py-2.5 px-4 font-mono text-end font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($purchase->grand_total_currency, $purchase->currency_code) }} {{ $purchase->currency_code }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-6 text-center text-text-muted">{{ __('purchasing.no_purchases') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tabPurchases->hasPages())
                <div class="p-3 border-t border-border">{{ $tabPurchases->links() }}</div>
            @endif
        </div>
    @elseif ($activeTab === 'returns' && $withCost)
        <div class="bg-white rounded-card border border-border overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <tr>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.purchase_return_number') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.return_date') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.all_statuses') }}</th>
                            <th class="py-2.5 px-4 text-end">{{ __('purchasing.total') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tabReturns as $ret)
                            <tr>
                                <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('purchase-returns.show', $ret->public_id) }}" class="text-primary hover:underline">
                                        {{ $ret->return_number ?? __('purchasing.draft') }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-4" dir="ltr">{{ $ret->return_date->toDateString() }}</td>
                                <td class="py-2.5 px-4">{{ __('purchasing.' . $ret->status) }}</td>
                                <td class="py-2.5 px-4 font-mono text-end font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($ret->grand_total_currency, $ret->currency_code) }} {{ $ret->currency_code }}
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="p-6 text-center text-text-muted">{{ __('purchasing.no_purchase_returns') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tabReturns->hasPages())
                <div class="p-3 border-t border-border">{{ $tabReturns->links() }}</div>
            @endif
        </div>
    @elseif ($activeTab === 'payments' && $withCost)
        <div class="bg-white rounded-card border border-border overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-xs">
                    <thead class="bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <tr>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.payment_number') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.payment_date') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.money_account') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.payment_amount') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('purchasing.all_statuses') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($tabPayments as $pmt)
                            <tr>
                                <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('vendor-payments.show', $pmt->public_id) }}" class="text-primary hover:underline">
                                        {{ $pmt->payment_number }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-4" dir="ltr">{{ $pmt->payment_date->toDateString() }}</td>
                                <td class="py-2.5 px-4">{{ $pmt->moneyAccount?->displayName() ?? '—' }}</td>
                                <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($pmt->amount, $pmt->currency_code) }} {{ $pmt->currency_code }}
                                </td>
                                <td class="py-2.5 px-4">
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
                            </tr>
                        @empty
                            <tr><td colspan="5" class="p-6 text-center text-text-muted">{{ __('purchasing.no_payments_found') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($tabPayments->hasPages())
                <div class="p-3 border-t border-border">{{ $tabPayments->links() }}</div>
            @endif
        </div>
    @elseif ($activeTab === 'statement' && $canStatement && $statementData !== null)
        <div class="space-y-6">
            <!-- Date Filters -->
            <div class="bg-white p-4 rounded-card border border-border flex flex-wrap gap-4 items-center">
                <div class="flex items-center gap-2 text-xs">
                    <label for="statement-from" class="font-bold text-text-secondary">{{ __('purchasing.statement_from') }}:</label>
                    <input id="statement-from" type="date" wire:model.live="statementFrom" class="h-8 px-2 rounded-control border border-border text-xs" />
                    <label for="statement-to">{{ __('purchasing.statement_to') }}:</label>
                    <input id="statement-to" type="date" wire:model.live="statementTo" class="h-8 px-2 rounded-control border border-border text-xs" />
                </div>
            </div>

            @forelse ($statementData['currencies'] as $currKey => $s)
                <div class="bg-white rounded-card border border-border p-6 space-y-6">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <h2 class="text-lg font-bold text-text-primary">{{ __('purchasing.statement') }} ({{ $currKey }})</h2>
                        <div class="text-xs text-text-secondary">
                            {{ __('purchasing.outstanding_balance') }}:
                            <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['closing_balance'], $currKey) }} {{ $currKey }}</span>
                        </div>
                    </div>

                    <!-- Entries Table -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs">
                            <thead class="bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                                <tr>
                                    <th class="py-2 px-3 text-start">{{ __('purchasing.statement_date') }}</th>
                                    <th class="py-2 px-3 text-start">{{ __('purchasing.statement_type') }}</th>
                                    <th class="py-2 px-3 text-start">{{ __('purchasing.statement_number') }}</th>
                                    <th class="py-2 px-3 text-start">{{ __('purchasing.description') }}</th>
                                    <th class="py-2 px-3 text-end">{{ __('purchasing.statement_credit') }}</th>
                                    <th class="py-2 px-3 text-end">{{ __('purchasing.statement_debit') }}</th>
                                    <th class="py-2 px-3 text-end">{{ __('purchasing.outstanding_balance') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr class="bg-surface-soft/40 font-semibold">
                                    <td colspan="6" class="py-2 px-3">{{ __('purchasing.opening_balance') }}</td>
                                    <td class="py-2 px-3 text-end font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['opening_balance'], $currKey) }}</td>
                                </tr>
                                @foreach ($s['entries'] as $entry)
                                    <tr>
                                        <td class="py-2 px-3 font-mono" dir="ltr">{{ $entry['date'] }}</td>
                                        <td class="py-2 px-3">{{ __('purchasing.'.($entry['type'] === 'payment_reversal' ? 'vendor_payment_reversal' : $entry['type'])) }}</td>
                                        <td class="py-2 px-3 font-mono" dir="ltr">{{ $entry['number'] }}</td>
                                        <td class="py-2 px-3">{{ $entry['description'] }}</td>
                                        <td class="py-2 px-3 text-end font-mono" dir="ltr">{{ $entry['credit'] !== '0.00' ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($entry['credit'], $currKey) : '—' }}</td>
                                        <td class="py-2 px-3 text-end font-mono" dir="ltr">{{ $entry['debit'] !== '0.00' ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($entry['debit'], $currKey) : '—' }}</td>
                                        <td class="py-2 px-3 text-end font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($entry['balance'], $currKey) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="bg-surface-soft/60 font-bold border-t-2 border-border">
                                    <td colspan="4" class="py-2 px-3">{{ __('purchasing.total') }}</td>
                                    <td class="py-2 px-3 text-end font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['total_credits'], $currKey) }}</td>
                                    <td class="py-2 px-3 text-end font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['total_debits'], $currKey) }}</td>
                                    <td class="py-2 px-3 text-end font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['closing_balance'], $currKey) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- Aging Breakdown -->
                    <div class="border-t border-border pt-4">
                        <h3 class="text-xs font-bold text-text-secondary uppercase tracking-wider mb-3">
                            {{ __('purchasing.aging') }} ({{ $currKey }})
                        </h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 text-xs">
                            <div class="p-3 rounded-control bg-canvas border border-border">
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.current') }}</span>
                                <span class="font-mono font-bold text-text-primary mt-1 block" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['current'], $currKey) }}</span>
                            </div>
                            <div class="p-3 rounded-control bg-canvas border border-border">
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.days_1_30') }}</span>
                                <span class="font-mono font-bold text-text-primary mt-1 block" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['days_1_30'], $currKey) }}</span>
                            </div>
                            <div class="p-3 rounded-control bg-canvas border border-border">
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.days_31_60') }}</span>
                                <span class="font-mono font-bold text-text-primary mt-1 block" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['days_31_60'], $currKey) }}</span>
                            </div>
                            <div class="p-3 rounded-control bg-canvas border border-border">
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.days_61_90') }}</span>
                                <span class="font-mono font-bold text-text-primary mt-1 block" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['days_61_90'], $currKey) }}</span>
                            </div>
                            <div class="p-3 rounded-control bg-canvas border border-border">
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.days_90_plus') }}</span>
                                <span class="font-mono font-bold text-danger mt-1 block" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['days_90_plus'], $currKey) }}</span>
                            </div>
                            <div class="p-3 rounded-control bg-canvas border border-border">
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.unspecified') }}</span>
                                <span class="font-mono font-bold text-text-primary mt-1 block" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['unspecified'], $currKey) }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs mt-3 pt-3 border-t border-border">
                            <div>
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.gross_open_purchases') }}</span>
                                <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['gross_open_purchases'], $currKey) }}</span>
                            </div>
                            <div>
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.unapplied_credit') }}</span>
                                <span class="font-mono font-bold text-info" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['unapplied_credit_position'], $currKey) }}</span>
                            </div>
                            <div>
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.net_payable') }}</span>
                                <span class="font-mono font-bold text-danger" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['net_payable'], $currKey) }}</span>
                            </div>
                            <div>
                                <span class="text-text-muted block text-[10px] uppercase">{{ __('purchasing.net_credit') }}</span>
                                <span class="font-mono font-bold text-success" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($s['aging']['net_vendor_credit'], $currKey) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-xs text-text-muted py-6 text-center bg-white rounded-card border border-border">{{ __('purchasing.no_purchases') }}</p>
            @endforelse
        </div>
    @endif
</div>
