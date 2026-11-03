<div class="merchant-home space-y-6" wire:loading.class="opacity-70" wire:target="choosePeriod,applyPeriod">
    <header class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-2xl font-bold text-text-primary">{{ __('dashboard.home') }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            @can('purchasing.purchase.create')
                <a href="{{ route('purchases.create') }}" class="home-action home-action-secondary">
                    <x-icon name="plus" class="w-4 h-4 shrink-0" /><span class="whitespace-nowrap">{{ __('dashboard.new_purchase') }}</span>
                </a>
            @endcan
            @can('sales.invoice.create')
                <a href="{{ route('invoices.create') }}" class="home-action home-action-primary">
                    <x-icon name="plus" class="w-4 h-4 shrink-0" /><span class="whitespace-nowrap">{{ __('dashboard.new_sale') }}</span>
                </a>
            @endcan
        </div>
    </header>
    <section aria-label="{{ __('dashboard.quick_periods') }}" class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="inline-flex flex-wrap gap-1 rounded-control border border-border bg-surface p-1" role="group" aria-label="{{ __('dashboard.quick_periods') }}">
                @foreach (['today', 'this_week', 'this_month', 'last_month'] as $choice)
                    <button type="button" wire:click="choosePeriod('{{ $choice }}')" wire:loading.attr="disabled" aria-pressed="{{ $preset === $choice ? 'true' : 'false' }}"
                        class="min-h-9 rounded-control px-3 text-sm font-medium {{ $preset === $choice ? 'bg-primary-50 text-primary' : 'text-text-secondary hover:bg-surface-soft' }}">
                        {{ __('dashboard.'.$choice) }}
                    </button>
                @endforeach
            </div>
                <form wire:submit="applyPeriod" class="flex flex-wrap items-end gap-2" aria-label="{{ __('dashboard.custom_dates') }}">
                    <div>
                        <label for="home-from" class="mb-1 block text-xs text-text-secondary">{{ __('dashboard.from_date') }}</label>
                        <input id="home-from" type="date" wire:model="from" class="min-h-10 rounded-control border-border bg-surface text-sm" dir="ltr">
                    </div>
                    <div>
                        <label for="home-to" class="mb-1 block text-xs text-text-secondary">{{ __('dashboard.to_date') }}</label>
                        <input id="home-to" type="date" wire:model="to" class="min-h-10 rounded-control border-border bg-surface text-sm" dir="ltr">
                    </div>
                    <button type="submit" wire:loading.attr="disabled" class="min-h-10 rounded-control bg-primary px-3 text-sm font-semibold text-white">{{ __('dashboard.apply') }}</button>
                </form>
        </div>
        @error('preset') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($data['activity'] as $key => $metric)
                <a href="{{ $metric['url'] }}" class="home-total {{ $key === 'sales.summary' ? 'home-total-sales' : 'home-total-purchases' }}">
                    <h2 class="home-total-label">{{ __('dashboard.'.($key === 'sales.summary' ? 'sales_total' : 'purchases_total')) }}</h2>
                    <p class="home-total-value"><bdi dir="ltr">{{ $metric['display'] }} <span class="home-currency">{{ $metric['currency'] }}</span></bdi></p>
                </a>
            @endforeach
        </div>
    </section>
    @if ($data['accounts_url'] || $data['checks'] !== null)
        <section class="space-y-3" aria-labelledby="home-balances">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 id="home-balances" class="font-semibold text-text-primary">{{ __('dashboard.money_now') }}</h2>
                <span class="text-xs text-text-secondary"><bdi dir="ltr">{{ $data['today'] }}</bdi></span>
            </div>
            <dl class="home-wallets">
                @foreach ($data['accounts'] as $account)
                    <div class="home-wallet home-wallet-{{ $account['type'] }}">
                        <dt><span class="home-wallet-label">{{ $account['name'] }}</span><span class="home-wallet-type">{{ __('dashboard.account_'.$account['type']) }}</span></dt>
                        <dd class="home-wallet-value"><bdi dir="ltr">{{ $account['display'] ?? __('money.unavailable') }} <span class="home-currency">{{ $account['currency'] }}</span></bdi></dd>
                    </div>
                @endforeach
                @if ($data['checks'] !== null)
                    <div class="home-wallet home-wallet-checks">
                        <dt><a href="{{ $data['checks']['url'] }}" class="home-wallet-label hover:underline">{{ __('dashboard.checks_held') }}</a></dt>
                        <dd class="home-wallet-value">
                            @forelse ($data['checks']['amounts'] as $amount)
                                <bdi dir="ltr">{{ $amount['display'] }} <span class="home-currency">{{ $amount['currency'] }}</span></bdi>
                            @empty
                                <span class="text-sm font-normal text-text-secondary">{{ __('dashboard.no_checks') }}</span>
                            @endforelse
                        </dd>
                    </div>
                @endif
            </dl>
            @if ($data['accounts_url'])
                <a href="{{ $data['accounts_url'] }}" class="inline-flex min-h-10 items-center text-sm font-medium text-primary hover:underline">{{ __('dashboard.all_accounts') }}</a>
            @endif
        </section>
    @endif
    @if ($data['activity'] === [] && ! $data['accounts_url'] && $data['checks'] === null)
        <p class="rounded-card border border-border bg-surface p-5 text-sm text-text-secondary">{{ __('dashboard.restricted_notice') }}</p>
    @endif
</div>
