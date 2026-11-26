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
            <div class="inline-flex flex-wrap gap-1 rounded-control bg-surface-soft p-1 border border-border" role="group" aria-label="{{ __('dashboard.quick_periods') }}">
                @foreach (['today', 'this_week', 'this_month', 'last_month'] as $choice)
                    <button type="button" wire:click="choosePeriod('{{ $choice }}')" wire:loading.attr="disabled" aria-pressed="{{ $preset === $choice ? 'true' : 'false' }}"
                        class="app-button min-h-11 lg:min-h-9 rounded-control px-3.5 text-sm font-bold transition-colors cursor-pointer {{ $preset === $choice ? 'bg-surface text-primary shadow-xs border border-border-control/40' : 'text-text-secondary hover:text-text-primary hover:bg-surface/60' }}">
                        {{ __('dashboard.'.$choice) }}
                    </button>
                @endforeach
            </div>
            <form wire:submit="applyPeriod" class="flex flex-wrap items-end gap-2.5" aria-label="{{ __('dashboard.custom_dates') }}">
                <div class="flex flex-col">
                    <label for="home-from" class="mb-1 text-sm font-bold text-text-secondary leading-tight">{{ __('dashboard.from_date') }}</label>
                    <input id="home-from" type="date" wire:model="from" class="min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-surface px-3 py-0 text-sm text-text-primary tabular-nums box-border leading-normal focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr">
                </div>
                <div class="flex flex-col">
                    <label for="home-to" class="mb-1 text-sm font-bold text-text-secondary leading-tight">{{ __('dashboard.to_date') }}</label>
                    <input id="home-to" type="date" wire:model="to" class="min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-surface px-3 py-0 text-sm text-text-primary tabular-nums box-border leading-normal focus:border-primary focus:ring-1 focus:ring-primary" dir="ltr">
                </div>
                <div class="flex flex-col justify-end">
                    <button type="submit" wire:loading.attr="disabled" class="app-button min-h-11 lg:min-h-9.5 rounded-control bg-primary hover:bg-primary-hover px-4 text-sm font-bold text-white shadow-xs transition inline-flex items-center justify-center cursor-pointer">
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
                        <span class="size-2 rounded-full shrink-0 {{ $key === 'sales.summary' ? 'bg-success' : 'bg-info' }}" aria-hidden="true"></span>
                    </div>
                    <p class="home-total-value">
                        <bdi dir="ltr">{{ $metric['display'] }} <span class="home-currency">{{ $metric['currency'] }}</span></bdi>
                    </p>
                </a>
            @endforeach

            {{-- Current Balances (As of today) --}}
            @foreach ($data['accounts'] as $account)
                <a href="{{ $data['accounts_url'] }}" class="home-wallet home-wallet-{{ $account['type'] }} focus-visible:ring-2 focus-visible:ring-primary">
                    <div class="flex items-start justify-between gap-2">
                        <div class="space-y-1 min-w-0">
                            <span class="home-wallet-label truncate">{{ $account['name'] }}</span>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="home-wallet-type">{{ __('dashboard.account_'.$account['type']) }}</span>
                                <span class="text-xs font-medium text-text-muted">{{ __('dashboard.as_of') }} {{ $data['today'] }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="home-wallet-value">
                        <bdi dir="ltr">{{ $account['display'] ?? __('money.unavailable') }} <span class="home-currency text-xs text-text-secondary">{{ $account['currency'] }}</span></bdi>
                    </div>
                </a>
            @endforeach

            @if ($data['checks'] !== null)
                <a href="{{ $data['checks']['url'] }}" class="home-wallet home-wallet-checks focus-visible:ring-2 focus-visible:ring-primary">
                    <div class="flex items-start justify-between gap-2">
                        <div class="space-y-1 min-w-0">
                            <span class="home-wallet-label truncate">{{ __('dashboard.checks_held') }}</span>
                            <div class="flex items-center gap-1.5 flex-wrap">
                                <span class="home-wallet-type">{{ __('dashboard.account_check') }}</span>
                                <span class="text-xs font-medium text-text-muted">{{ __('dashboard.as_of') }} {{ $data['today'] }}</span>
                            </div>
                        </div>
                    </div>
                    <div class="home-wallet-value">
                        @forelse ($data['checks']['amounts'] as $amount)
                            <bdi dir="ltr">{{ $amount['display'] }} <span class="home-currency text-xs text-text-secondary">{{ $amount['currency'] }}</span></bdi>
                        @empty
                            <span class="text-sm font-normal text-text-secondary">{{ __('dashboard.no_checks') }}</span>
                        @endforelse
                    </div>
                </a>
            @endif
        </div>

        @if ($data['accounts_url'])
            <div class="flex justify-end pt-1">
                <a href="{{ $data['accounts_url'] }}" class="app-button inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control text-sm font-bold text-text-primary bg-surface hover:bg-surface-soft border border-border-control shadow-2xs transition">
                    <x-icon name="bank" class="size-4 text-text-secondary" aria-hidden="true" />
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
            <a href="{{ route('invoices.index') }}" class="home-shortcut focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <x-icon name="receipt" class="size-4.5" aria-hidden="true" />
                <span>{{ __('app.nav_sales_invoices') }}</span>
            </a>
            @endcan
            @can('sales.quote.view')
            <a href="{{ route('quotations.index') }}" class="home-shortcut focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <x-icon name="quote" class="size-4.5" aria-hidden="true" />
                <span>{{ __('app.nav_quotes') }}</span>
            </a>
            @endcan
            @can('purchasing.purchase.view')
            <a href="{{ route('purchases.index') }}" class="home-shortcut focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <x-icon name="cart" class="size-4.5" aria-hidden="true" />
                <span>{{ __('app.nav_purchase_invoices') }}</span>
            </a>
            @endcan
            @can('money.expense.view')
            <a href="{{ route('expenses.index') }}" class="home-shortcut focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <x-icon name="money" class="size-4.5" aria-hidden="true" />
                <span>{{ __('app.nav_expenses') }}</span>
            </a>
            @endcan
            @canany(['inventory.stock.view', 'inventory.product.manage'])
            <a href="{{ route('inventory.expiry') }}" class="home-shortcut focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <x-icon name="expiry" class="size-4.5" aria-hidden="true" />
                <span>{{ __('app.nav_expiry_center') }}</span>
            </a>
            @endcanany
            @if(app(\App\Application\Reporting\Presentation\ReportRegistry::class)->hasVisible(app(\App\Support\Tenancy\CompanyContext::class)->company()))
            <a href="{{ route('reports.index') }}" class="home-shortcut focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                <x-icon name="chart" class="size-4.5" aria-hidden="true" />
                <span>{{ __('reports.title') }}</span>
            </a>
            @endif
        </div>
    </section>

    @if ($data['activity'] === [] && ! $data['accounts_url'] && $data['checks'] === null)
        <p class="rounded-2xl border border-border bg-surface p-6 text-sm text-text-secondary text-center">{{ __('dashboard.restricted_notice') }}</p>
    @endif
</div>
