@props(['price'])
@if($price)
    <p class="mt-1 text-xs text-text-secondary leading-relaxed">
        {{ __('trade-cues.last_customer_price') }}
        <bdi dir="ltr" class="tabular-nums font-semibold">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($price['price'], $price['currency']) }} {{ $price['currency'] }}</bdi>
        / {{ app()->getLocale() === 'en' ? ($price['unit_en'] ?: $price['unit_ar']) : $price['unit_ar'] }}
        <span class="block">{{ __('trade-cues.price_on', ['date' => $price['date']]) }}</span>
    </p>
@endif
