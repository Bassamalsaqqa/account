@props([
    'total',
    'paid' => '0.00',
    'remaining' => '0.00',
    'currency',
    'dueDate' => null,
    'returned' => null,
])

<div {{ $attributes->merge(['class' => 'grid grid-cols-2 lg:grid-cols-4 gap-3']) }}>
    <!-- Total Card -->
    <div class="p-3.5 bg-surface-soft border border-border rounded-card space-y-1">
        <span class="text-[11px] font-bold text-text-muted uppercase tracking-wider block">{{ __('sales.total') }}</span>
        <div class="text-lg font-bold font-mono dir-ltr text-text-primary">
            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($total, $currency) }}
            <span class="text-xs font-normal text-text-secondary">{{ $currency }}</span>
        </div>
        @if($returned && \Brick\Math\BigDecimal::of((string) $returned)->isPositive())
            <span class="text-[10px] text-text-muted block" dir="ltr">
                {{ __('sales.returns') }}: {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($returned, $currency) }}
            </span>
        @endif
    </div>

    <!-- Paid Card -->
    <div class="p-3.5 bg-surface-soft border border-border rounded-card space-y-1">
        <span class="text-[11px] font-bold text-text-muted uppercase tracking-wider block">{{ __('sales.paid_amount') }}</span>
        <div class="text-lg font-bold font-mono dir-ltr text-success">
            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($paid, $currency) }}
            <span class="text-xs font-normal text-text-secondary">{{ $currency }}</span>
        </div>
    </div>

    <!-- Remaining Card -->
    <div class="p-3.5 bg-surface-soft border border-border rounded-card space-y-1">
        <span class="text-[11px] font-bold text-text-muted uppercase tracking-wider block">{{ __('sales.outstanding_amount') }}</span>
        <div class="text-lg font-bold font-mono dir-ltr {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($remaining) ? 'text-danger' : 'text-text-primary' }}">
            {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($remaining, $currency) }}
            <span class="text-xs font-normal text-text-secondary">{{ $currency }}</span>
        </div>
    </div>

    <!-- Due Date Card (or status fallback) -->
    <div class="p-3.5 bg-surface-soft border border-border rounded-card space-y-1">
        <span class="text-[11px] font-bold text-text-muted uppercase tracking-wider block">{{ __('sales.due_date') }}</span>
        <div class="text-sm font-bold font-mono dir-ltr text-text-primary pt-1">
            {{ $dueDate ?: '—' }}
        </div>
    </div>
</div>
