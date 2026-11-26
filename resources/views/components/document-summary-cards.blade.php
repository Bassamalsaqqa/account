@props([
    'currencyScale' => 2,
    'cards' => null,
    'total' => null,
    'paid' => '0.00',
    'remaining' => '0.00',
    'currency' => null,
    'dueDate' => null,
    'returned' => null,
])

@php
    $cardCount = $cards !== null ? count($cards) : 4;
    $gridColsClass = match (true) {
        $cardCount === 1 => 'grid-cols-1',
        $cardCount === 2 => 'grid-cols-2',
        $cardCount === 3 => 'grid-cols-1 sm:grid-cols-3',
        default => 'grid-cols-2 lg:grid-cols-4',
    };
@endphp

<div {{ $attributes->merge(['class' => "grid {$gridColsClass} gap-px bg-border border border-border rounded-card overflow-hidden"]) }}>
    @if ($cards !== null)
        @foreach ($cards as $card)
            @php
                $variant = $card['variant'] ?? 'default';
                $valueColor = match ($variant) {
                    'success' => 'text-success',
                    'danger' => 'text-danger',
                    'primary' => 'text-primary',
                    default => 'text-text-primary',
                };
            @endphp
            <div class="document-summary-card bg-surface p-3.5 sm:p-4 flex flex-col justify-between gap-1.5">
                <span class="text-xs font-medium text-text-secondary block">{{ $card['label'] }}</span>
                <div class="text-lg font-bold tabular-nums num {{ $valueColor }}">
                    @if(isset($card['is_date']) && $card['is_date'])
                        <bdi dir="ltr" class="text-sm font-bold tabular-nums num text-text-primary">{{ $card['raw_value'] ?? $card['value'] ?? '—' }}</bdi>
                    @else
                        <bdi dir="ltr">{{ isset($card['raw_value']) ? $card['raw_value'] : \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($card['value'] ?? '0.00', $card['currency'] ?? $currency, (int) $currencyScale) }}</bdi>
                        @if(!empty($card['currency'] ?? $currency))
                            <span class="text-xs font-medium text-text-secondary">{{ $card['currency'] ?? $currency }}</span>
                        @endif
                    @endif
                </div>
                @if(!empty($card['subtext']))
                    <span class="text-xs text-text-muted block" dir="ltr">{{ $card['subtext'] }}</span>
                @endif
            </div>
        @endforeach
    @else
        <!-- Total -->
        <div class="document-summary-card bg-surface p-3.5 sm:p-4 flex flex-col justify-between gap-1.5">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.total') }}</span>
            <div class="text-lg font-bold tabular-nums num text-text-primary">
                <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($total, $currency, (int) $currencyScale) }}</bdi>
                @if($currency)
                    <span class="text-xs font-medium text-text-secondary">{{ $currency }}</span>
                @endif
            </div>
            @if($returned && \Brick\Math\BigDecimal::of((string) $returned)->isPositive())
                <span class="text-xs text-text-muted block">
                    {{ __('sales.returns') }}: <bdi dir="ltr" class="tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($returned, $currency, (int) $currencyScale) }}</bdi>
                </span>
            @endif
        </div>

        <!-- Paid -->
        <div class="document-summary-card bg-surface p-3.5 sm:p-4 flex flex-col justify-between gap-1.5">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.paid_amount') }}</span>
            <div class="text-lg font-bold tabular-nums num text-success">
                <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($paid, $currency, (int) $currencyScale) }}</bdi>
                @if($currency)
                    <span class="text-xs font-medium text-text-secondary">{{ $currency }}</span>
                @endif
            </div>
        </div>

        <!-- Remaining -->
        <div class="document-summary-card bg-surface p-3.5 sm:p-4 flex flex-col justify-between gap-1.5">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.outstanding_amount') }}</span>
            <div class="text-lg font-bold tabular-nums num {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($remaining) ? 'text-danger' : 'text-text-primary' }}">
                <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($remaining, $currency, (int) $currencyScale) }}</bdi>
                @if($currency)
                    <span class="text-xs font-medium text-text-secondary">{{ $currency }}</span>
                @endif
            </div>
        </div>

        <!-- Due Date -->
        <div class="document-summary-card bg-surface p-3.5 sm:p-4 flex flex-col justify-between gap-1.5">
            <span class="text-xs font-medium text-text-secondary block">{{ __('sales.due_date') }}</span>
            <div class="text-sm font-bold tabular-nums num text-text-primary">
                <bdi dir="ltr">{{ $dueDate ?: '—' }}</bdi>
            </div>
        </div>
    @endif
</div>
