@props(['allocation', 'number', 'currency', 'unallocated', 'paymentCurrency'])
@php
    $due = \Brick\Math\BigDecimal::of((string) ($allocation['outstanding'] ?? '0'));
    $applied = \Brick\Math\BigDecimal::of((string) ($allocation['allocated_amount'] ?? '0'));
    $remaining = \Brick\Math\BigDecimal::max('0', $due->minus($applied));
@endphp
<section aria-label="{{ __('refinement.payment_summary') }}" class="rounded-control bg-surface-soft p-4 space-y-3">
    <p class="text-sm font-semibold text-text-primary"><bdi dir="ltr">{{ $number }}</bdi></p>
    <dl class="grid grid-cols-3 gap-3 text-xs">
        @foreach (['due_now' => $due, 'paying_now' => $applied, 'remaining_after' => $remaining] as $label => $value)
            <div><dt class="text-text-secondary">{{ __('refinement.'.$label) }}</dt>
                <dd class="mt-1 font-semibold text-text-primary"><bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($value, $currency) }} {{ $currency }}</bdi></dd>
            </div>
        @endforeach
    </dl>
    @if (\Brick\Math\BigDecimal::of($unallocated)->isPositive())
        <p class="text-xs text-text-secondary">{{ __('refinement.extra_payment') }} <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($unallocated, $paymentCurrency) }} {{ $paymentCurrency }}</bdi></p>
    @endif
</section>
