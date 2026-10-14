<dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
    @foreach (['invoice_number' => 'invoice_number', 'issue_date' => 'issue_date', 'due_date' => 'due_date', 'status' => 'status', 'payment_status' => 'payment_status'] as $field => $label)
        <div class="rounded-control bg-surface p-2">
            <dt class="text-xs text-text-secondary">{{ __('assistant_invoice.'.$label) }}</dt>
            <dd class="font-semibold mt-1"><bdi>{{ in_array($field, ['status', 'payment_status'], true) && \Illuminate\Support\Facades\Lang::has('assistant_invoice.'.$answer['rows'][0][$field]) ? __('assistant_invoice.'.$answer['rows'][0][$field]) : ($answer['rows'][0][$field] ?? __('assistant_v2.value_unavailable')) }}</bdi></dd>
        </div>
    @endforeach
    @foreach (['subtotal_currency' => 'subtotal', 'discount_total_currency' => 'discount', 'tax_total_currency' => 'tax', 'grand_total_currency' => 'grand_total', 'outstanding_balance' => 'outstanding_balance'] as $field => $label)
        <div class="rounded-control bg-surface p-2">
            <dt class="text-xs text-text-secondary">{{ __('assistant_invoice.'.$label) }}</dt>
            <dd class="font-semibold mt-1"><bdi>{{ $answer['totals'][$field] === null ? __('assistant_v2.value_unavailable') : \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($answer['totals'][$field], $answer['currency']['filter_currency_code']) }} {{ $answer['currency']['filter_currency_code'] }}</bdi></dd>
        </div>
    @endforeach
</dl>
@if ($answer['totals']['outstanding_balance'] === null)
    <p class="text-xs text-text-secondary">{{ __('assistant_invoice.draft_outstanding') }}</p>
@endif
<section class="space-y-2">
    <h4 class="text-sm font-semibold">{{ __('assistant_invoice.line_items') }}</h4>
    @foreach ($answer['scope']['lines'] as $line)
        <div class="rounded-control border border-border p-3 space-y-1 text-sm">
            <p class="font-semibold break-words">{{ $line['item_description'] }}</p>
            <p><bdi>{{ $line['sku'] }}</bdi></p>
            <p>{{ __('assistant_invoice.quantity') }}: <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }} {{ $line['unit_name'] }}</bdi>
                · {{ __('assistant_invoice.unit_price') }}: <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['unit_price'], $answer['currency']['filter_currency_code']) }} {{ $answer['currency']['filter_currency_code'] }}</bdi></p>
            <p class="font-semibold">{{ __('assistant_invoice.line_total') }}: <bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['total'], $answer['currency']['filter_currency_code']) }} {{ $answer['currency']['filter_currency_code'] }}</bdi></p>
        </div>
    @endforeach
    <p class="text-xs text-text-secondary">{{ __('assistant_invoice.line_sample', ['shown' => count($answer['scope']['lines']), 'total' => $answer['scope']['total_lines']]) }}</p>
</section>
