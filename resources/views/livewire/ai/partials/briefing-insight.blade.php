<article class="rounded-card border border-border bg-surface p-4 space-y-3">
    <h4 class="font-bold">{{ __($insight['label_key']) }}</h4>
    @if ($insight['kind'] === 'sales_comparison')
        <p class="text-sm leading-relaxed">
            {{ __('assistant_briefing.insights.current_net_sales') }}:
            <strong><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($insight['values']['current_net_sales'], $insight['currency']) }} {{ $insight['currency'] }}</bdi></strong>
            · {{ __('assistant_briefing.insights.previous_net_sales') }}:
            <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($insight['values']['previous_net_sales'], $insight['currency']) }} {{ $insight['currency'] }}</bdi>
        </p>
        <p class="text-sm font-semibold text-primary">
            {{ __('assistant_briefing.insights.trend_'.$insight['values']['trend']) }}:
            <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($insight['values']['difference'], $insight['currency']) }} {{ $insight['currency'] }}</bdi>
        </p>
        <p class="text-xs text-text-secondary"><bdi>{{ $insight['period']['start_date'] }} — {{ $insight['period']['end_date'] }}</bdi> · <bdi>{{ $insight['comparison_period']['start_date'] }} — {{ $insight['comparison_period']['end_date'] }}</bdi></p>
    @else
        <p class="text-sm leading-relaxed">{{ __('dashboard.briefing_action_'.$insight['kind']) }}</p>
        <dl class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
            @foreach ($insight['values'] as $key => $value)
                @if (! in_array($key, ['valid_count', 'unknown_count', 'total_active_lots'], true))
                    <div><dt class="inline text-text-secondary">{{ __('assistant_briefing.insights.'.$key) }}:</dt> <dd class="inline font-semibold"><bdi>{{ $value }}</bdi></dd></div>
                @endif
            @endforeach
        </dl>
        @foreach ($insight['amounts'] as $code => $amount)
            <p class="text-base font-bold"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($amount, $code) }} {{ $code }}</bdi></p>
        @endforeach
    @endif
    <a href="{{ $insight['source_url'] }}" class="inline-flex min-h-11 items-center gap-2 text-sm font-semibold text-primary underline">{{ __('assistant_briefing.insights.view_report') }} <x-icon name="chevron" class="h-4 w-4 rtl:rotate-180" aria-hidden="true" /></a>
</article>
