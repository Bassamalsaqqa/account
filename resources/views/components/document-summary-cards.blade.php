@props([
    'cards' => null,
    'total' => null,
    'paid' => '0.00',
    'remaining' => '0.00',
    'currency' => null,
    'dueDate' => null,
    'returned' => null,
])

<div {{ $attributes->merge(['class' => 'grid grid-cols-2 lg:grid-cols-4 gap-3']) }}>
    @if ($cards !== null)
        @foreach ($cards as $card)
            <div class="document-summary-card p-4 bg-surface border border-border rounded-card shadow-panel space-y-2">
                <span class="text-xs font-medium text-text-secondary block">{{ $card['label'] }}</span>
                <div class="text-lg font-bold font-mono num {{ ($card['variant'] ?? 'default') === 'success' ? 'text-success' : (($card['variant'] ?? 'default') === 'danger' ? 'text-danger' : (($card['variant'] ?? 'default') === 'primary' ? 'text-primary' : 'text-text-primary')) }}">
                    @if(isset($card['is_date']) && $card['is_date'])
                        <span class="text-sm font-bold font-mono num text-text-primary">{{ $card['raw_value'] ?? $card['value'] ?? '—' }}</span>
                    @else
                        {{ isset($card['raw_value']) ? $card['raw_value'] : \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($card['value'] ?? '0.00', $card['currency'] ?? $currency) }}
                        @if(!empty($card['currency'] ?? $currency))
                            <span class="text-xs font-normal text-text-secondary">{{ $card['currency'] ?? $currency }}</span>
                        @endif
                    @endif
                </div>
                @if(!empty($card['subtext']))
                    <span class="text-xs text-text-muted block" dir="ltr">{{ $card['subtext'] }}</span>
                @endif
            </div>
        @endforeach
    @else
        <!-- Total Card -->
        <div class="document-summary-card p-4 bg-surface border border-border rounded-card shadow-panel space-y-2">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.total') }}</span>
            <div class="text-lg font-bold font-mono num text-text-primary">
                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($total, $currency) }}
                <span class="text-xs font-normal text-text-secondary">{{ $currency }}</span>
            </div>
            @if($returned && \Brick\Math\BigDecimal::of((string) $returned)->isPositive())
                <span class="text-xs text-text-muted block" dir="ltr">
                    {{ __('sales.returns') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($returned, $currency) }}
                </span>
            @endif
        </div>

        <!-- Paid Card -->
        <div class="document-summary-card p-4 bg-surface border border-border rounded-card shadow-panel space-y-2">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.paid_amount') }}</span>
            <div class="text-lg font-bold font-mono num text-success">
                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($paid, $currency) }}
                <span class="text-xs font-normal text-text-secondary">{{ $currency }}</span>
            </div>
        </div>

        <!-- Remaining Card -->
        <div class="document-summary-card p-4 bg-surface border border-border rounded-card shadow-panel space-y-2">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.outstanding_amount') }}</span>
            <div class="text-lg font-bold font-mono num {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($remaining) ? 'text-danger' : 'text-text-primary' }}">
                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($remaining, $currency) }}
                <span class="text-xs font-normal text-text-secondary">{{ $currency }}</span>
            </div>
        </div>

        <!-- Due Date Card (or status fallback) -->
        <div class="document-summary-card p-4 bg-surface border border-border rounded-card shadow-panel space-y-2">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.due_date') }}</span>
            <div class="text-sm font-bold font-mono num text-text-primary pt-1">
                {{ $dueDate ?: '—' }}
            </div>
        </div>
    @endif
</div>
