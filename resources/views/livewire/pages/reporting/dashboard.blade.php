<div class="dashboard-shell">
    {{-- Header: Merchant's daily review title, today date, and permission-scoped actions --}}
    <header class="dashboard-header">
        <div class="dashboard-heading-group">
            <div class="dashboard-title-row">
                <h1 class="dashboard-title">
                    {{ __('dashboard.title') }}
                </h1>
                @if (!empty($data['today']))
                    <span class="dashboard-date-badge">
                        <span>{{ __('dashboard.today_label') }}:</span>
                        <bdi class="num">{{ $data['today'] }}</bdi>
                    </span>
                @endif
            </div>
            <p class="dashboard-subtitle">{{ __('dashboard.subtitle') }}</p>
        </div>

        <div class="dashboard-actions">
            @can('sales.invoice.create')
                <a href="{{ route('invoices.create') }}" class="dashboard-btn-primary">
                    <x-icon name="plus" class="w-4 h-4 shrink-0" aria-hidden="true" />
                    <span>{{ __('dashboard.new_sale') }}</span>
                </a>
            @endcan

            @can('purchasing.purchase.create')
                <a href="{{ route('purchases.create') }}" class="dashboard-btn-secondary">
                    <x-icon name="cart" class="w-4 h-4 shrink-0" aria-hidden="true" />
                    <span>{{ __('dashboard.new_purchase') }}</span>
                </a>
            @endcan

            @can('ai.assistant.use')
                <button type="button" wire:click="openAssistant" class="dashboard-btn-secondary" wire:loading.attr="disabled" wire:target="openAssistant">
                    <x-icon name="sparkles" class="w-4 h-4 text-primary shrink-0" aria-hidden="true" />
                    <span>{{ __('dashboard.ask_assistant') }}</span>
                </button>
            @endcan

            <a href="{{ route('reports.index') }}" class="dashboard-btn-secondary">
                <span>{{ __('dashboard.all_reports') }}</span>
            </a>
        </div>
    </header>

    {{-- Period Filter Bar: One-click presets and deferred custom date range --}}
    <section class="dashboard-period-bar" aria-label="{{ __('dashboard.quick_periods') }}">
        <div class="dashboard-period-tabs-row">
            <div class="dashboard-period-tabs" role="group" aria-label="{{ __('dashboard.quick_periods') }}">
                @php
                    $quickPresets = [
                        'today' => __('dashboard.today'),
                        'this_week' => __('dashboard.this_week'),
                        'this_month' => __('dashboard.this_month'),
                        'last_month' => __('dashboard.last_month'),
                    ];
                @endphp
                @foreach ($quickPresets as $presetKey => $presetLabel)
                    <button type="button"
                            wire:click="choosePeriod('{{ $presetKey }}')"
                            wire:loading.attr="disabled"
                            wire:target="choosePeriod,applyPeriod"
                            class="dashboard-period-tab {{ $preset === $presetKey && empty($from) && empty($to) ? 'is-active' : '' }}">
                        {{ $presetLabel }}
                    </button>
                @endforeach
            </div>

            <span wire:loading wire:target="choosePeriod,applyPeriod" class="text-xs text-text-secondary dashboard-loading-pulse">
                {{ __('dashboard.updating') }}
            </span>
        </div>

        <details class="dashboard-period-details" {{ !in_array($preset, ['today', 'this_week', 'this_month', 'last_month'], true) || !empty($from) || !empty($to) ? 'open' : '' }}>
            <summary>
                <span>{{ __('dashboard.custom_period') }}</span>
                <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180 shrink-0" aria-hidden="true" />
            </summary>

            <form wire:submit="applyPeriod" class="dashboard-period-form">
                <div class="dashboard-form-field">
                    <label for="dashboard-preset" class="dashboard-form-label">{{ __('dashboard.preset_select') }}</label>
                    <select id="dashboard-preset" wire:model="preset" class="dashboard-form-select">
                        @foreach (\App\Application\Reporting\DTO\ReportPeriod::ALLOWED_PRESETS as $val)
                            <option value="{{ $val }}">{{ \Illuminate\Support\Facades\Lang::has('reports.presets.'.$val) ? __('reports.presets.'.$val) : $val }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="dashboard-form-field">
                    <label for="dashboard-from" class="dashboard-form-label">{{ __('dashboard.from_date') }}</label>
                    <input id="dashboard-from" type="date" wire:model="from" class="dashboard-form-input" />
                </div>

                <div class="dashboard-form-field">
                    <label for="dashboard-to" class="dashboard-form-label">{{ __('dashboard.to_date') }}</label>
                    <input id="dashboard-to" type="date" wire:model="to" class="dashboard-form-input" />
                </div>

                <button type="submit"
                        wire:loading.attr="disabled"
                        wire:target="choosePeriod,applyPeriod"
                        class="dashboard-btn-primary">
                    <span>{{ __('dashboard.apply') }}</span>
                </button>
            </form>

            @error('preset')
                <p role="alert" class="mt-2 text-xs font-semibold text-danger">{{ $message }}</p>
            @enderror
        </details>
    </section>

    {{-- Main Two-Column Row: Performance + Needs Attention --}}
    @if (!empty($data['activity']) || !empty($data['attention']) || $data['comparison'] !== null)
        <div class="dashboard-grid-two-col">
            {{-- Performance Column --}}
            <section class="dashboard-card" aria-labelledby="heading-performance">
                <div class="dashboard-card-header">
                    <div class="dashboard-card-title-group">
                        <x-icon name="chart" class="w-5 h-5 text-primary" aria-hidden="true" />
                        <h2 id="heading-performance" class="dashboard-card-title">{{ __('dashboard.performance_heading') }}</h2>
                    </div>
                    @if (isset($data['activity']['sales.summary']))
                        <a href="{{ $data['activity']['sales.summary']['url'] }}" class="touch-action dashboard-card-link">
                            <span>{{ __('dashboard.view_full_report') }}</span>
                            <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180 shrink-0" aria-hidden="true" />
                        </a>
                    @endif
                </div>

                {{-- Canonical Net Sales Comparison --}}
                @if ($data['comparison'] !== null)
                    <div class="dashboard-comparison-block">
                        <div class="dashboard-comparison-header">
                            <span class="dashboard-comparison-title">{{ __('dashboard.net_sales') }}</span>
                            <span class="dashboard-comparison-period-badge">
                                <bdi class="num">{{ $data['period']['start_date'] }}</bdi> â€“ <bdi class="num">{{ $data['period']['end_date'] }}</bdi>
                            </span>
                        </div>

                        @if ($data['comparison']['month_to_date'])
                            <p class="text-sm text-text-secondary">{{ __('dashboard.month_to_date_notice') }}</p>
                        @endif
                        @if ($data['comparison']['unequal_days'])
                            <p class="text-sm text-text-secondary">{{ __('dashboard.unequal_month_days') }}</p>
                        @endif

                        <div class="dashboard-comparison-values">
                            <div class="dashboard-comparison-value-item">
                                <span class="dashboard-comparison-val-label">{{ __('dashboard.current_period') }}</span>
                                <span class="dashboard-comparison-amount-current num">
                                    <bdi>{{ $data['comparison']['current_display'] }} {{ $data['currency'] }}</bdi>
                                </span>
                            </div>
                            <div class="dashboard-comparison-value-item">
                                <span class="dashboard-comparison-val-label">
                                    {{ __('dashboard.previous_period') }} (<bdi class="num">{{ $data['comparison']['previous_period']['start_date'] }}</bdi> â€“ <bdi class="num">{{ $data['comparison']['previous_period']['end_date'] }}</bdi>)
                                </span>
                                <span class="dashboard-comparison-amount-prior num">
                                    <bdi>{{ $data['comparison']['previous_display'] }} {{ $data['currency'] }}</bdi>
                                </span>
                            </div>
                        </div>

                        {{-- Horizontal Comparison Bars (Aria-Hidden, Accompanied by Accessible Values) --}}
                        <div class="dashboard-comparison-bars" aria-hidden="true">
                            <div class="dashboard-bar-row">
                                <span class="dashboard-bar-label">{{ __('dashboard.current_period') }}</span>
                                <div class="dashboard-bar-track">
                                    <div class="dashboard-bar-fill" style="width: {{ $data['comparison']['current_width'] }}%;"></div>
                                </div>
                            </div>
                            <div class="dashboard-bar-row">
                                <span class="dashboard-bar-label">{{ __('dashboard.previous_period') }}</span>
                                <div class="dashboard-bar-track">
                                    <div class="dashboard-bar-fill dashboard-bar-fill-prior" style="width: {{ $data['comparison']['previous_width'] }}%;"></div>
                                </div>
                            </div>
                        </div>

                        {{-- Difference & Direction --}}
                        <div class="dashboard-comparison-diff">
                            <span class="dashboard-diff-badge {{ $data['comparison']['direction'] > 0 ? 'is-positive' : ($data['comparison']['direction'] < 0 ? 'is-negative' : 'is-neutral') }}">
                                @if ($data['comparison']['direction'] > 0)
                                    <x-icon name="arrow-up" class="w-3.5 h-3.5 shrink-0" aria-hidden="true" />
                                    <bdi class="num">+{{ $data['comparison']['difference_display'] }} {{ $data['currency'] }}</bdi>
                                    @if ($data['comparison']['percent'] !== null)
                                        <span class="num">(+{{ $data['comparison']['percent'] }}%)</span>
                                    @endif
                                @elseif ($data['comparison']['direction'] < 0)
                                    <x-icon name="arrow-up" class="w-3.5 h-3.5 shrink-0 rotate-180" aria-hidden="true" />
                                    <bdi class="num">-{{ $data['comparison']['difference_display'] }} {{ $data['currency'] }}</bdi>
                                    @if ($data['comparison']['percent'] !== null)
                                        <span class="num">({{ $data['comparison']['percent'] }}%)</span>
                                    @endif
                                @else
                                    <span><bdi class="num">{{ $data['comparison']['difference_display'] }}</bdi></span>
                                @endif
                            </span>

                            <span class="dashboard-diff-desc">
                                @if ($data['comparison']['direction'] > 0)
                                    {{ __('dashboard.comparison_increase') }}
                                @elseif ($data['comparison']['direction'] < 0)
                                    {{ __('dashboard.comparison_decrease') }}
                                @else
                                    {{ __('dashboard.comparison_same') }}
                                @endif
                            </span>
                        </div>
                    </div>
                @endif

                {{-- Supplemental Selected First Metrics --}}
                @php
                    $supplemental = [];
                    // Profit first metric
                    if (isset($data['activity']['profit']) && !empty($data['activity']['profit']['metrics'])) {
                        $firstProfit = $data['activity']['profit']['metrics'][0];
                        $supplemental[] = [
                            'label' => __('dashboard.metric_net_profit'),
                            'display' => $firstProfit['display'],
                            'url' => $data['activity']['profit']['url'],
                        ];
                    }
                    // Purchases summary first metric
                    if (isset($data['activity']['purchases.summary']) && !empty($data['activity']['purchases.summary']['metrics'])) {
                        $firstPurch = $data['activity']['purchases.summary']['metrics'][0];
                        $supplemental[] = [
                            'label' => __('dashboard.metric_net_purchases'),
                            'display' => $firstPurch['display'],
                            'url' => $data['activity']['purchases.summary']['url'],
                        ];
                    }
                    // Expenses summary first metric
                    if (isset($data['activity']['expenses.summary']) && !empty($data['activity']['expenses.summary']['metrics'])) {
                        $firstExp = $data['activity']['expenses.summary']['metrics'][0];
                        $supplemental[] = [
                            'label' => __('dashboard.metric_operating_expenses'),
                            'display' => $firstExp['display'],
                            'url' => $data['activity']['expenses.summary']['url'],
                        ];
                    }
                    // Sales invoice count & average
                    if (isset($data['activity']['sales.summary']) && !empty($data['activity']['sales.summary']['metrics'])) {
                        $salesMetrics = collect($data['activity']['sales.summary']['metrics']);
                        $countMetric = $salesMetrics->firstWhere('key', 'original_invoice_count');
                        $avgMetric = $salesMetrics->firstWhere('key', 'average_invoice_base');
                        if ($countMetric) {
                            $supplemental[] = [
                                'label' => __('dashboard.metric_invoice_count'),
                                'display' => $countMetric['display'],
                                'url' => $data['activity']['sales.summary']['url'],
                            ];
                        }
                        if ($avgMetric) {
                            $supplemental[] = [
                                'label' => __('dashboard.metric_average_invoice'),
                                'display' => $avgMetric['display'],
                                'url' => $data['activity']['sales.summary']['url'],
                            ];
                        }
                    }
                @endphp

                @if (!empty($supplemental))
                    <div class="dashboard-supplemental-block">
                        <span class="dashboard-supplemental-title">{{ __('dashboard.supplemental_heading') }}</span>
                        <div class="dashboard-metrics-grid">
                            @foreach ($supplemental as $sItem)
                                <a href="{{ $sItem['url'] }}" class="dashboard-metric-tile">
                                    <span class="dashboard-metric-tile-label">{{ $sItem['label'] }}</span>
                                    <span class="dashboard-metric-tile-value num"><bdi>{{ $sItem['display'] }}</bdi></span>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif
            </section>

            {{-- Needs Attention Column --}}
            <section class="dashboard-card dashboard-attention-card" aria-labelledby="heading-attention">
                <div class="dashboard-card-header">
                    <div class="dashboard-card-title-group">
                        <x-icon name="bell" class="w-5 h-5 text-warning" aria-hidden="true" />
                        <h2 id="heading-attention" class="dashboard-card-title">{{ __('dashboard.attention_heading') }}</h2>
                    </div>
                    @if (!empty($data['attention']))
                        <span class="dashboard-attention-pill">
                            <span>{{ count($data['attention']) === 1 ? __('dashboard.attention_category_single') : __('dashboard.attention_categories_count', ['count' => count($data['attention'])]) }}</span>
                        </span>
                    @endif
                </div>

                @if (!empty($data['attention']))
                    <ul class="dashboard-attention-list">
                        @foreach ($data['attention'] as $alertItem)
                            @php
                                $alertIcon = match ($alertItem['key']) {
                                    'customers.overdue' => 'users',
                                    'purchases.unpaid' => 'cart',
                                    'money.due-checks' => 'check',
                                    'money.returned-checks' => 'history',
                                    'inventory.low-stock' => 'box',
                                    'inventory.expiry' => 'expiry',
                                    'payroll.unpaid' => 'bank',
                                    default => 'bell',
                                };
                                $isAlertDanger = in_array($alertItem['key'], ['customers.overdue', 'money.returned-checks', 'inventory.expiry'], true);
                            @endphp
                            <li><a href="{{ $alertItem['url'] }}" class="dashboard-attention-item">
                                <div class="dashboard-attention-item-main">
                                    <div class="dashboard-attention-item-icon {{ $isAlertDanger ? 'is-danger' : '' }}" aria-hidden="true">
                                        <x-icon :name="$alertIcon" class="w-5 h-5" />
                                    </div>
                                    <div class="dashboard-attention-item-info">
                                        <span class="dashboard-attention-item-title">{{ $alertItem['title'] }}</span>
                                        @if (!empty($alertItem['metrics']))
                                            <div class="dashboard-attention-metrics">
                                                @foreach ($alertItem['metrics'] as $metric)
                                                    <span class="dashboard-attention-metric-pill num"><bdi>{{ $metric['display'] }}</bdi></span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </div>
                                <div class="dashboard-attention-item-badge {{ $isAlertDanger ? 'is-danger' : '' }}">
                                    <bdi class="num">{{ $alertItem['count'] }}</bdi>
                                </div>
                            </a></li>
                        @endforeach
                    </ul>
                @else
                    <div class="dashboard-empty-state">
                        <div class="dashboard-empty-icon" aria-hidden="true">
                            <x-icon name="check-circle" class="w-6 h-6 text-success" />
                        </div>
                        <h3 class="dashboard-empty-title">{{ __('dashboard.attention_empty') }}</h3>
                        <p class="dashboard-empty-text">{{ __('dashboard.attention_empty_detail') }}</p>
                    </div>
                @endif
            </section>
        </div>
    @endif

    {{-- Three-Column Money & Party Balances Section --}}
    @if (!empty($data['positions']))
        <section class="dashboard-balances-section" aria-labelledby="heading-balances">
            <div class="dashboard-balances-header">
                <div>
                    <h2 id="heading-balances" class="dashboard-balances-title">{{ __('dashboard.balances_heading') }}</h2>
                    <p class="dashboard-balances-note">{{ __('dashboard.balances_note') }}</p>
                </div>
            </div>

            <div class="dashboard-grid-three-col">
                {{-- Column 1: Cash & Bank Balances --}}
                @if (isset($data['positions']['money.balances']))
                    @php
                        $moneyReport = $data['positions']['money.balances'];
                        $totalBaseMetric = null;
                        $currencyBalances = [];
                        foreach ($moneyReport['metrics'] as $metric) {
                            if ($metric['key'] === 'total_base_balance') {
                                $totalBaseMetric = $metric;
                            } elseif ($metric['key'] === 'currency_balances') {
                                $currencyBalances[] = $metric;
                            }
                        }
                    @endphp
                    <div class="dashboard-card dashboard-balance-card">
                        <div class="dashboard-card-header">
                            <div class="dashboard-card-title-group">
                                <x-icon name="bank" class="w-5 h-5 text-primary" aria-hidden="true" />
                                <h3 class="dashboard-card-title">{{ $moneyReport['title'] }}</h3>
                            </div>
                            <a href="{{ $moneyReport['url'] }}" class="touch-action dashboard-card-link">
                                <span>{{ __('dashboard.view_full_report') }}</span>
                                <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180 shrink-0" aria-hidden="true" />
                            </a>
                        </div>

                        @if ($totalBaseMetric)
                            <div class="dashboard-balance-primary-box">
                                <span class="dashboard-balance-primary-label">{{ __('dashboard.base_total') }}</span>
                                <span class="dashboard-balance-primary-value num"><bdi>{{ $totalBaseMetric['display'] }}</bdi></span>
                            </div>
                        @endif

                        @if (!empty($currencyBalances))
                            <div class="dashboard-currencies-list">
                                <span class="dashboard-currencies-title">{{ __('dashboard.currency_breakdown') }}</span>
                                @foreach ($currencyBalances as $cb)
                                    <div class="dashboard-currency-row">
                                        <span class="dashboard-currency-badge">{{ $cb['currency'] }}</span>
                                        <span class="dashboard-currency-amount num"><bdi>{{ $cb['display'] }}</bdi></span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                {{-- Column 2: Customer Receivables --}}
                @if (isset($data['positions']['customers.balances']))
                    @php
                        $custReport = $data['positions']['customers.balances'];
                    @endphp
                    <div class="dashboard-card dashboard-balance-card">
                        <div class="dashboard-card-header">
                            <div class="dashboard-card-title-group">
                                <x-icon name="users" class="w-5 h-5 text-primary" aria-hidden="true" />
                                <h3 class="dashboard-card-title">{{ $custReport['title'] }}</h3>
                            </div>
                            <a href="{{ $custReport['url'] }}" class="touch-action dashboard-card-link">
                                <span>{{ __('dashboard.view_full_report') }}</span>
                                <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180 shrink-0" aria-hidden="true" />
                            </a>
                        </div>

                        <div class="dashboard-currencies-list">
                            <span class="dashboard-currencies-title">{{ __('dashboard.currency_breakdown') }}</span>
                            @forelse ($custReport['metrics'] as $cm)
                                <div class="dashboard-currency-row">
                                    <span class="dashboard-currency-badge">{{ $cm['currency'] }}</span>
                                    <span class="dashboard-currency-amount num"><bdi>{{ $cm['display'] }}</bdi></span>
                                </div>
                            @empty
                                <p class="text-xs text-text-secondary">{{ __('dashboard.no_balances') }}</p>
                            @endforelse
                        </div>

                        <p class="text-[11px] text-text-muted mt-auto">{{ __('dashboard.separate_currency_notice') }}</p>
                    </div>
                @endif

                {{-- Column 3: Vendor Payables --}}
                @if (isset($data['positions']['vendors.balances']))
                    @php
                        $vendReport = $data['positions']['vendors.balances'];
                    @endphp
                    <div class="dashboard-card dashboard-balance-card">
                        <div class="dashboard-card-header">
                            <div class="dashboard-card-title-group">
                                <x-icon name="cart" class="w-5 h-5 text-primary" aria-hidden="true" />
                                <h3 class="dashboard-card-title">{{ $vendReport['title'] }}</h3>
                            </div>
                            <a href="{{ $vendReport['url'] }}" class="touch-action dashboard-card-link">
                                <span>{{ __('dashboard.view_full_report') }}</span>
                                <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180 shrink-0" aria-hidden="true" />
                            </a>
                        </div>

                        <div class="dashboard-currencies-list">
                            <span class="dashboard-currencies-title">{{ __('dashboard.currency_breakdown') }}</span>
                            @forelse ($vendReport['metrics'] as $vm)
                                <div class="dashboard-currency-row">
                                    <span class="dashboard-currency-badge">{{ $vm['currency'] }}</span>
                                    <span class="dashboard-currency-amount num"><bdi>{{ $vm['display'] }}</bdi></span>
                                </div>
                            @empty
                                <p class="text-xs text-text-secondary">{{ __('dashboard.no_balances') }}</p>
                            @endforelse
                        </div>

                        <p class="text-[11px] text-text-muted mt-auto">{{ __('dashboard.separate_currency_notice') }}</p>
                    </div>
                @endif
            </div>

            {{-- Compact Expandable Links for Other Permitted Positions (e.g. Valuation, Checks, Payroll) --}}
            @php
                $otherPositions = array_diff_key($data['positions'], array_flip(['money.balances', 'customers.balances', 'vendors.balances']));
            @endphp
            @if (!empty($otherPositions))
                <details class="dashboard-more-positions">
                    <summary class="dashboard-more-summary">
                        <span class="dashboard-more-title">
                            <x-icon name="chart" class="w-4 h-4 text-primary shrink-0" aria-hidden="true" />
                            <span>{{ __('dashboard.other_positions') }} {{ __('dashboard.other_positions_count', ['count' => count($otherPositions)]) }}</span>
                        </span>
                        <x-icon name="chevron" class="w-4 h-4 rtl:rotate-180 shrink-0" aria-hidden="true" />
                    </summary>

                    <div class="dashboard-more-grid">
                        @foreach ($otherPositions as $otherKey => $otherReport)
                            <div class="dashboard-more-item">
                                <div class="dashboard-more-item-header">
                                    <h4 class="dashboard-more-item-title">{{ $otherReport['title'] }}</h4>
                                    <a href="{{ $otherReport['url'] }}" class="touch-action dashboard-more-link">
                                        <span>{{ __('dashboard.view_full_report') }}</span>
                                        <x-icon name="chevron" class="w-3.5 h-3.5 rtl:rotate-180 shrink-0" aria-hidden="true" />
                                    </a>
                                </div>
                                @if (!empty($otherReport['metrics']))
                                    <div class="dashboard-more-metrics">
                                        @foreach ($otherReport['metrics'] as $om)
                                            <span class="dashboard-more-metric-pill num"><bdi>{{ $om['display'] }}</bdi></span>
                                        @endforeach
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </details>
            @endif
        </section>
    @endif

    {{-- Restricted Notice when no reports are permitted --}}
    @if (empty($data['activity']) && empty($data['positions']) && empty($data['alerts']))
        <div class="dashboard-restricted-card">
            <p>{{ __('dashboard.restricted_notice') }}</p>
        </div>
    @endif
</div>
