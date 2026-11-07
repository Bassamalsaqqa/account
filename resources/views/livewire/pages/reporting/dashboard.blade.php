<div class="merchant-home space-y-6" wire:loading.class="opacity-70" wire:target="choosePeriod,applyPeriod">
    <!-- Merchant Workspace Header -->
    <header class="flex flex-wrap items-center justify-between gap-4 pb-1">
        <div class="space-y-1">
            <h1 class="text-2xl font-bold tracking-tight text-text-primary">{{ __('dashboard.home') }}</h1>
            <div class="flex items-center gap-2 text-xs text-text-muted">
                <span class="inline-flex items-center gap-1 font-semibold text-text-secondary">
                    <x-icon name="calendar" class="w-3.5 h-3.5 text-primary" />
                    <bdi dir="ltr">{{ $data['today'] }}</bdi>
                </span>
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
            @can('purchasing.purchase.create')
                <a href="{{ route('purchases.create') }}" class="home-action home-action-secondary">
                    <x-icon name="plus" class="w-4 h-4 shrink-0 text-text-muted" />
                    <span class="whitespace-nowrap">{{ __('dashboard.new_purchase') }}</span>
                </a>
            @endcan
            @can('sales.invoice.create')
                <a href="{{ route('invoices.create') }}" class="home-action home-action-primary">
                    <x-icon name="plus" class="w-4 h-4 shrink-0" />
                    <span class="whitespace-nowrap">{{ __('dashboard.new_sale') }}</span>
                </a>
            @endcan
        </div>
    </header>

    <!-- Period Filter & Custom Dates -->
    <section aria-label="{{ __('dashboard.quick_periods') }}" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3 p-3 rounded-2xl bg-surface border border-border shadow-2xs">
            <div class="inline-flex flex-wrap gap-1 rounded-control bg-surface-soft p-1 border border-border/40" role="group" aria-label="{{ __('dashboard.quick_periods') }}">
                @foreach (['today', 'this_week', 'this_month', 'last_month'] as $choice)
                    <button type="button" wire:click="choosePeriod('{{ $choice }}')" wire:loading.attr="disabled" aria-pressed="{{ $preset === $choice ? 'true' : 'false' }}"
                        class="min-h-9 rounded-control px-3.5 text-sm sm:text-sm font-bold transition-colors cursor-pointer {{ $preset === $choice ? 'bg-surface text-primary shadow-xs border border-border/50' : 'text-text-secondary hover:text-text-primary hover:bg-surface/50' }}">
                        {{ __('dashboard.'.$choice) }}
                    </button>
                @endforeach
            </div>
            <form wire:submit="applyPeriod" class="flex flex-wrap items-end gap-2.5" aria-label="{{ __('dashboard.custom_dates') }}">
                <div class="flex flex-col">
                    <label for="home-from" class="mb-1 text-sm font-bold text-text-secondary leading-tight">{{ __('dashboard.from_date') }}</label>
                    <input id="home-from" type="date" wire:model="from" class="h-10 min-h-[40px]  rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm font-mono box-border leading-normal focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr">
                </div>
                <div class="flex flex-col">
                    <label for="home-to" class="mb-1 text-sm font-bold text-text-secondary leading-tight">{{ __('dashboard.to_date') }}</label>
                    <input id="home-to" type="date" wire:model="to" class="h-10 min-h-[40px]  rounded-control border border-border bg-surface px-3 py-0 text-sm sm:text-sm font-mono box-border leading-normal focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr">
                </div>
                <div class="flex flex-col justify-end">
                    <button type="submit" wire:loading.attr="disabled" class="h-10 min-h-[40px]  rounded-control bg-primary hover:bg-primary-hover px-4 text-sm sm:text-sm font-bold text-white shadow-xs transition inline-flex items-center justify-center cursor-pointer">
                        {{ __('dashboard.apply') }}
                    </button>
                </div>
            </form>
        </div>
        @error('preset') <p role="alert" class="text-sm text-danger font-medium">{{ $message }}</p> @enderror

        <!-- Compact Financial Metrics: Selected Period Activity & Current Balances in One Shared Grid -->
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3.5">
            {{-- Activity Cards (Selected Period) --}}
            @foreach ($data['activity'] as $key => $metric)
                <a href="{{ $metric['url'] }}"
                   class="home-total {{ $key === 'sales.summary' ? 'home-total-sales' : 'home-total-purchases' }} group focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2"
                   aria-label="{{ __('dashboard.'.($key === 'sales.summary' ? 'sales_total' : 'purchases_total')) }}: {{ $metric['display'] }} {{ $metric['currency'] }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="space-y-0.5 min-w-0">
                            <h2 class="home-total-label truncate">{{ __('dashboard.'.($key === 'sales.summary' ? 'sales_total' : 'purchases_total')) }}</h2>
                        </div>
                        <div class="w-8 h-8 rounded-lg grid place-items-center shrink-0 shadow-2xs {{ $key === 'sales.summary' ? 'bg-success-bg text-success border border-success/20' : 'bg-info-bg text-info border border-info/20' }}">
                            <x-icon :name="$key === 'sales.summary' ? 'receipt' : 'cart'" class="w-4 h-4" />
                        </div>
                    </div>
                    <p class="home-total-value">
                        <bdi dir="ltr">{{ $metric['display'] }} <span class="home-currency">{{ $metric['currency'] }}</span></bdi>
                    </p>
                </a>
            @endforeach

            {{-- Current Balances (As of today) --}}
            @foreach ($data['accounts'] as $account)
                <a href="{{ $data['accounts_url'] }}" class="home-wallet home-wallet-{{ $account['type'] }} focus-visible:ring-2 focus-visible:ring-primary">
                    <div class="flex items-center justify-between gap-2">
                        <div class="space-y-0.5 min-w-0">
                            <span class="home-wallet-label truncate">{{ $account['name'] }}</span>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="home-wallet-type">{{ __('dashboard.account_'.$account['type']) }}</span>
                                <span class="text-xs font-medium text-text-muted">{{ __('dashboard.as_of') }} {{ $data['today'] }}</span>
                            </div>
                        </div>
                        <div class="w-8 h-8 rounded-lg grid place-items-center shrink-0 shadow-2xs {{ $account['type'] === 'cash' ? 'bg-warning-bg text-warning border border-warning/20' : 'bg-info-bg text-info border border-info/20' }}">
                            <x-icon :name="$account['type'] === 'cash' ? 'money' : 'bank'" class="w-4 h-4" />
                        </div>
                    </div>
                    <div class="home-wallet-value">
                        <bdi dir="ltr">{{ $account['display'] ?? __('money.unavailable') }} <span class="home-currency text-xs text-text-muted">{{ $account['currency'] }}</span></bdi>
                    </div>
                </a>
            @endforeach

            @if ($data['checks'] !== null)
                <a href="{{ $data['checks']['url'] }}" class="home-wallet home-wallet-checks focus-visible:ring-2 focus-visible:ring-primary">
                    <div class="flex items-center justify-between gap-2">
                        <div class="space-y-0.5 min-w-0">
                            @if (!empty($data['checks']['url']))
                                <span class="home-wallet-label text-info font-bold truncate block">{{ __('dashboard.checks_held') }}</span>
                            @else
                                <span class="home-wallet-label text-info font-bold truncate block">{{ __('dashboard.checks_held') }}</span>
                            @endif
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="home-wallet-type">{{ __('dashboard.account_check') }}</span>
                                <span class="text-xs font-medium text-text-muted">{{ __('dashboard.as_of') }} {{ $data['today'] }}</span>
                            </div>
                        </div>
                        <div class="w-8 h-8 rounded-lg grid place-items-center shrink-0 shadow-2xs bg-info-bg text-info border border-info/20">
                            <x-icon name="receipt" class="w-4 h-4" />
                        </div>
                    </div>
                    <div class="home-wallet-value">
                        @forelse ($data['checks']['amounts'] as $amount)
                            <bdi dir="ltr">{{ $amount['display'] }} <span class="home-currency text-xs text-text-muted">{{ $amount['currency'] }}</span></bdi>
                        @empty
                            <span class="text-sm font-normal text-text-secondary">{{ __('dashboard.no_checks') }}</span>
                        @endforelse
                    </div>
                </a>
            @endif
        </div>

        @if ($data['accounts_url'])
            <div class="flex justify-end pt-1">
                <a href="{{ $data['accounts_url'] }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control text-sm font-bold text-text-primary bg-surface hover:bg-surface-soft border border-border shadow-2xs transition">
                    <x-icon name="bank" class="w-3.5 h-3.5 text-text-muted" />
                    <span>{{ __('dashboard.all_accounts') }}</span>
                    <span class="rtl:rotate-180 text-text-muted">&rarr;</span>
                </a>
            </div>
        @endif
    </section>

    <!-- Everyday Merchant Shortcuts -->
    <section class="space-y-3 pt-2">
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @can('sales.invoice.view')
            <a href="{{ route('invoices.index') }}" class="home-shortcut p-3.5 rounded-xl bg-info-bg border border-info/20 hover:border-info/20 hover:bg-info-bg shadow-2xs transition flex flex-col items-center text-center gap-2 group focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2">
                <div class="w-8 h-8 rounded-lg bg-info-bg text-info grid place-items-center shrink-0">
                    <x-icon name="receipt" class="w-4 h-4" />
                </div>
                <span class="text-xs font-bold text-info">{{ __('app.nav_sales_invoices') }}</span>
            </a>
            @endcan
            @can('sales.quote.view')
            <a href="{{ route('quotations.index') }}" class="home-shortcut p-3.5 rounded-xl bg-warning-bg border border-warning/20 hover:border-warning/20 hover:bg-warning-bg shadow-2xs transition flex flex-col items-center text-center gap-2 group focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2">
                <div class="w-8 h-8 rounded-lg bg-warning-bg text-warning grid place-items-center shrink-0">
                    <x-icon name="quote" class="w-4 h-4" />
                </div>
                <span class="text-xs font-bold text-warning">{{ __('app.nav_quotes') }}</span>
            </a>
            @endcan
            @can('purchasing.purchase.view')
            <a href="{{ route('purchases.index') }}" class="home-shortcut p-3.5 rounded-xl bg-info-bg border border-info/20 hover:border-info/20 hover:bg-info-bg shadow-2xs transition flex flex-col items-center text-center gap-2 group focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2">
                <div class="w-8 h-8 rounded-lg bg-info-bg text-info grid place-items-center shrink-0">
                    <x-icon name="cart" class="w-4 h-4" />
                </div>
                <span class="text-xs font-bold text-info">{{ __('app.nav_purchase_invoices') }}</span>
            </a>
            @endcan
            @can('money.expense.view')
            <a href="{{ route('expenses.index') }}" class="home-shortcut p-3.5 rounded-xl bg-danger-bg border border-danger/20 hover:border-danger/20 hover:bg-danger-bg shadow-2xs transition flex flex-col items-center text-center gap-2 group focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2">
                <div class="w-8 h-8 rounded-lg bg-danger-bg text-danger grid place-items-center shrink-0">
                    <x-icon name="money" class="w-4 h-4" />
                </div>
                <span class="text-xs font-bold text-danger">{{ __('app.nav_expenses') }}</span>
            </a>
            @endcan
            @canany(['inventory.stock.view', 'inventory.product.manage'])
            <a href="{{ route('inventory.expiry') }}" class="home-shortcut p-3.5 rounded-xl bg-warning-bg border border-warning/20 hover:border-warning/20 hover:bg-warning-bg shadow-2xs transition flex flex-col items-center text-center gap-2 group focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2">
                <div class="w-8 h-8 rounded-lg bg-warning-bg text-warning grid place-items-center shrink-0">
                    <x-icon name="expiry" class="w-4 h-4" />
                </div>
                <span class="text-xs font-bold text-warning">{{ __('app.nav_expiry_center') }}</span>
            </a>
            @endcanany
            @if(app(\App\Application\Reporting\Presentation\ReportRegistry::class)->hasVisible(app(\App\Support\Tenancy\CompanyContext::class)->company()))
            <a href="{{ route('reports.index') }}" class="home-shortcut p-3.5 rounded-xl bg-success-bg border border-success/20 hover:border-success/20 hover:bg-success-bg shadow-2xs transition flex flex-col items-center text-center gap-2 group focus:outline-hidden focus:ring-2 focus:ring-primary focus:ring-offset-2">
                <div class="w-8 h-8 rounded-lg bg-success-bg text-success grid place-items-center shrink-0">
                    <x-icon name="chart" class="w-4 h-4" />
                </div>
                <span class="text-xs font-bold text-success">{{ __('reports.title') }}</span>
            </a>
            @endif
        </div>
    </section>

    @if ($data['activity'] === [] && ! $data['accounts_url'] && $data['checks'] === null)
        <p class="rounded-2xl border border-border bg-surface p-6 text-sm text-text-secondary text-center">{{ __('dashboard.restricted_notice') }}</p>
    @endif
</div>
