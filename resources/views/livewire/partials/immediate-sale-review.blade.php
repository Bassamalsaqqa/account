    <section aria-labelledby="settlement-review-heading" class="space-y-3 border-t border-border pt-5">
        <h3 id="settlement-review-heading" class="text-base font-bold text-text-primary">{{ __('fin1.review') }}</h3>
        <dl class="grid gap-3 text-sm sm:grid-cols-2">
            <div><dt class="text-text-secondary">{{ __('fin1.payment_method') }}</dt><dd class="font-semibold">{{ __('fin1.'.$review['payment_method']) }}</dd></div>
            <div><dt class="text-text-secondary">{{ __('fin1.money_account_id') }}</dt><dd class="font-semibold">{{ $review['payment_method'] === 'check' ? __('fin1.check_control') : (app()->getLocale() === 'ar' ? ($review['account_name_ar'] ?: $review['account_name_en']) : ($review['account_name_en'] ?: $review['account_name_ar'])) }}</dd></div>
            @foreach (['received_amount' => 'payment_currency', 'invoice_amount' => 'invoice_currency', 'outstanding_invoice_amount' => 'invoice_currency', 'unallocated_payment_amount' => 'payment_currency'] as $field => $currencyField)
                <div><dt class="text-text-secondary">{{ __('fin1.'.$field) }}</dt><dd dir="ltr" class="text-start font-semibold tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($review[$field], $review[$currencyField]) }} {{ $review[$currencyField] }}</dd></div>
            @endforeach
            @foreach (['payment_date', 'exchange_rate', ...($review['payment_method'] === 'check' ? ['check_number', 'bank_name', 'drawer', 'due_date'] : [])] as $field)
                <div><dt class="text-text-secondary">{{ __('fin1.'.$field) }}</dt><dd class="break-words font-semibold">{{ $review[$field] ?? '—' }}</dd></div>
            @endforeach
        </dl>
        <p class="text-sm text-text-secondary">{{ __('fin1.atomic_help') }}</p>
    </section>
