<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">
                {{ __('sales.customer_directory') }}
            </h1>
            <p class="text-xs text-text-secondary mt-1">
                {{ __('sales.customers') }} ({{ $customers->total() }})
            </p>
        </div>

        @if ($canManage)
            <div class="flex items-center gap-2">
                <a href="{{ route('customers.create') }}"
                   class="inline-flex items-center justify-center gap-1.5 px-4 h-9 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm">
                    <x-icon name="plus" class="w-4 h-4" />
                    <span>{{ __('sales.new_customer') }}</span>
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

        <div class="flex items-center gap-3">
            <div class="flex items-center gap-1 text-xs">
                <button type="button"
                        wire:click="$set('statusFilter', 'all')"
                        class="px-3 py-1.5 rounded-control font-semibold transition-colors {{ $statusFilter === 'all' ? 'bg-primary text-white' : 'bg-surface-soft text-text-secondary hover:text-text-primary' }}">
                    {{ __('sales.all') }}
                </button>
                <button type="button"
                        wire:click="$set('statusFilter', 'active')"
                        class="px-3 py-1.5 rounded-control font-semibold transition-colors {{ $statusFilter === 'active' ? 'bg-primary text-white' : 'bg-surface-soft text-text-secondary hover:text-text-primary' }}">
                    {{ __('sales.active') }}
                </button>
                <button type="button"
                        wire:click="$set('statusFilter', 'inactive')"
                        class="px-3 py-1.5 rounded-control font-semibold transition-colors {{ $statusFilter === 'inactive' ? 'bg-primary text-white' : 'bg-surface-soft text-text-secondary hover:text-text-primary' }}">
                    {{ __('sales.inactive') }}
                </button>
            </div>
        </div>
    </div>

    <!-- Customers Table / Cards -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="hidden md:block overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase tracking-wider">
                        <th class="py-3 px-4 text-start">{{ __('sales.code') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.customer') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.phone') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.currency') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.outstanding_balance') }}</th>
                        <th class="py-3 px-4 text-start">{{ __('sales.status') }}</th>
                        <th class="py-3 px-4 text-end">{{ __('sales.actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($customers as $customer)
                        <tr class="hover:bg-surface-soft/60 transition-colors">
                            <td class="py-3 px-4 font-mono text-[11px] text-text-muted" dir="ltr">
                                {{ $customer->code ?? '—' }}
                            </td>
                            <td class="py-3 px-4">
                                <a href="{{ route('customers.show', $customer->public_id) }}" class="font-bold text-text-primary hover:text-primary transition-colors">
                                    {{ $customer->displayName() }}
                                </a>
                                @if ($customer->business_name_ar || $customer->business_name_en)
                                    <div class="text-[11px] text-text-muted">
                                        {{ app()->getLocale() === 'ar' ? ($customer->business_name_ar ?? $customer->business_name_en) : ($customer->business_name_en ?? $customer->business_name_ar) }}
                                    </div>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-text-secondary" dir="ltr">
                                {{ $customer->phone ?? $customer->whatsapp ?? '—' }}
                            </td>
                            <td class="py-3 px-4 font-bold text-text-secondary">
                                {{ $customer->default_currency_code }}
                            </td>
                            <td class="py-3 px-4 font-bold" dir="ltr">
                                <span class="{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) ($balances[$customer->id] ?? '0')) ? 'text-danger' : 'text-text-primary' }}">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency((string) ($balances[$customer->id] ?? '0'), $customer->default_currency_code ?? $company->base_currency_code) }}
                                    @foreach ($currencyBalances[$customer->id] ?? [] as $currency => $balance)
                                        @if ($currency !== ($customer->default_currency_code ?? $company->base_currency_code))
                                            <span class="block text-xs" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($balance['outstanding'], $currency) }} <span class="text-text-muted">{{ $currency }}</span></span>
                                        @endif
                                    @endforeach
                                </span>
                            </td>
                            <td class="py-3 px-4">
                                @if ($customer->is_active)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-bg text-success">
                                        {{ __('sales.active') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                        {{ __('sales.inactive') }}
                                    </span>
                                @endif
                            </td>
                            <td class="py-3 px-4 text-end">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('customers.show', $customer->public_id) }}"
                                       class="px-2 py-1 rounded-control bg-surface-soft hover:bg-primary-50 text-text-secondary hover:text-primary font-bold text-[11px] transition-colors">
                                        {{ __('sales.customer_details') }}
                                    </a>
                                    @if ($canManage)
                                        <a href="{{ route('customers.edit', $customer->public_id) }}"
                                           class="px-2 py-1 rounded-control bg-surface-soft hover:bg-slate-200 text-text-secondary font-bold text-[11px] transition-colors">
                                            {{ __('sales.edit_customer') }}
                                        </a>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-8 text-center text-text-muted">
                                <x-icon name="users" class="w-8 h-8 mx-auto mb-2 opacity-40" />
                                <p>{{ __('sales.no_customers_found') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <!-- Mobile Card List -->
        <div class="md:hidden divide-y divide-border">
            @forelse ($customers as $customer)
                <div class="p-4 space-y-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <a href="{{ route('customers.show', $customer->public_id) }}" class="font-bold text-text-primary text-sm hover:text-primary">
                                {{ $customer->displayName() }}
                            </a>
                            @if ($customer->business_name_ar || $customer->business_name_en)
                                <div class="text-[11px] text-text-muted">
                                    {{ app()->getLocale() === 'ar' ? ($customer->business_name_ar ?? $customer->business_name_en) : ($customer->business_name_en ?? $customer->business_name_ar) }}
                                </div>
                            @endif
                        </div>
                        @if ($customer->is_active)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-success-bg text-success">
                                {{ __('sales.active') }}
                            </span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600">
                                {{ __('sales.inactive') }}
                            </span>
                        @endif
                    </div>

                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.code') }}</span>
                            <span class="font-mono text-text-secondary" dir="ltr">{{ $customer->code ?? '—' }}</span>
                        </div>
                        <div>
                            <span class="text-text-muted block text-[10px]">{{ __('sales.phone') }}</span>
                            <span class="text-text-secondary" dir="ltr">{{ $customer->phone ?? $customer->whatsapp ?? '—' }}</span>
                        </div>
                        <div class="col-span-2">
                            <span class="text-text-muted block text-[10px]">{{ __('sales.outstanding_balance') }}</span>
                            <span class="font-bold text-sm {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive((string) ($balances[$customer->id] ?? '0')) ? 'text-danger' : 'text-text-primary' }}" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency((string) ($balances[$customer->id] ?? '0'), $customer->default_currency_code ?? $company->base_currency_code) }}
                                    @foreach ($currencyBalances[$customer->id] ?? [] as $currency => $balance)
                                        @if ($currency !== ($customer->default_currency_code ?? $company->base_currency_code))
                                            <span class="block text-xs" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($balance['outstanding'], $currency) }} <span class="text-text-muted">{{ $currency }}</span></span>
                                        @endif
                                    @endforeach
                            </span>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2 border-t border-border">
                        <a href="{{ route('customers.show', $customer->public_id) }}"
                           class="px-3 py-1.5 rounded-control bg-surface-soft text-text-primary text-xs font-bold">
                            {{ __('sales.customer_details') }}
                        </a>
                        @if ($canManage)
                            <a href="{{ route('customers.edit', $customer->public_id) }}"
                               class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary text-xs font-semibold">
                                {{ __('sales.edit_customer') }}
                            </a>
                        @endif
                    </div>
                </div>
            @empty
                <div class="py-8 text-center text-text-muted">
                    <p>{{ __('sales.no_customers_found') }}</p>
                </div>
            @endforelse
        </div>

        @if ($customers->hasPages())
            <div class="p-4 border-t border-border">
                {{ $customers->links() }}
            </div>
        @endif
    </div>
</div>
