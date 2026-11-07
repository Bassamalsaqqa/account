<section aria-labelledby="purchase-settlement-review-heading" class="space-y-3 border-t border-border pt-5">
    <h3 id="purchase-settlement-review-heading" class="text-base font-bold text-text-primary">{{ __('purchase_settlement.review') }}</h3>
    <dl class="grid gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-text-secondary">{{ __('purchase_settlement.payment_method') }}</dt>
            <dd class="font-semibold">{{ __('purchase_settlement.'.$review['payment_method']) }}</dd>
        </div>
        <div>
            <dt class="text-text-secondary">{{ __('purchase_settlement.money_account_id') }}</dt>
            <dd class="font-semibold">{{ $review['payment_method'] === 'check' ? __('purchase_settlement.check_control') : (app()->getLocale() === 'ar' ? ($review['account_name_ar'] ?: $review['account_name_en']) : ($review['account_name_en'] ?: $review['account_name_ar'])) }}</dd>
        </div>
        @foreach (['paid_amount' => 'payment_currency', 'purchase_amount' => 'purchase_currency', 'outstanding_purchase_amount' => 'purchase_currency', 'unallocated_payment_amount' => 'payment_currency'] as $field => $currencyField)
            <div>
                <dt class="text-text-secondary">{{ __('purchase_settlement.'.$field) }}</dt>
                <dd dir="ltr" class="text-start font-semibold tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($review[$field], $review[$currencyField]) }} {{ $review[$currencyField] }}</dd>
            </div>
        @endforeach
        @foreach (['payment_date', 'exchange_rate', ...($review['payment_method'] === 'check' ? ['check_number', 'bank_name', 'drawer', 'due_date'] : [])] as $field)
            <div>
                <dt class="text-text-secondary">{{ __('purchase_settlement.'.$field) }}</dt>
                <dd class="break-words font-semibold">{{ $review[$field] ?? '—' }}</dd>
            </div>
        @endforeach
    </dl>
    <p class="text-sm text-text-secondary">{{ __('purchase_settlement.atomic_help') }}</p>
</section>
