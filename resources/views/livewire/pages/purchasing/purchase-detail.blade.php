<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div><a href="{{ route('purchases.index') }}" class="text-xs text-primary">{{ __('purchasing.purchases') }}</a><h1 class="text-2xl font-bold mt-1">{{ __('purchasing.purchase_draft') }}</h1></div>
        @if($canEdit)<a href="{{ route('purchases.edit', $document['public_id']) }}" class="inline-flex h-11 items-center justify-center px-4 rounded-control bg-primary text-white text-sm font-bold">{{ __('purchasing.edit_purchase') }}</a>@endif
    </header>
    <p class="p-3 rounded-control bg-info-bg text-info text-sm">{{ __('purchasing.draft_notice') }}</p>
    @if($duplicateWarning)<p role="status" class="p-3 rounded-control bg-warning-bg text-warning text-sm">{{ __('purchasing.duplicate_vendor_invoice') }}</p>@endif
    <section class="bg-surface border border-border rounded-card p-4">
        <dl class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            @foreach(['vendor_name' => 'vendor', 'vendor_invoice_number' => 'vendor_invoice_number', 'purchase_date' => 'purchase_date', 'due_date' => 'due_date', 'warehouse_name' => 'receiving_warehouse', 'currency_code' => 'currency'] as $field => $label)
                <div><dt class="text-xs text-text-secondary">{{ __('purchasing.'.$label) }}</dt><dd class="mt-1 font-semibold"><bdi>{{ $document[$field] ?? ($field === 'due_date' ? __('purchasing.no_due_date') : '—') }}</bdi></dd></div>
            @endforeach
        </dl>
    </section>
    <section class="space-y-3">
        @foreach($document['lines'] as $line)
            <article class="bg-surface border border-border rounded-card p-4 space-y-3" wire:key="detail-line-{{ $line['public_id'] }}">
                <div class="flex flex-wrap justify-between gap-2"><h2 class="font-bold text-sm">{{ $line['item_description'] }}</h2><span class="text-xs text-text-secondary"><bdi>{{ $line['product_sku'] }}</bdi></span></div>
                <dl class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
                    <div><dt class="text-xs text-text-secondary">{{ __('purchasing.quantity') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }}</bdi> {{ $line['unit_name'] }}</dd></div>
                    @if($withCost)
                        @foreach(['unit_cost', 'line_discount', 'line_tax', 'line_total'] as $field)<div><dt class="text-xs text-text-secondary">{{ __('purchasing.'.$field) }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line[$field], $document['currency_code']) }}</bdi></dd></div>@endforeach
                    @endif
                </dl>
                @if(count($line['lots']))
                    <h3 class="text-xs font-bold text-text-secondary">{{ __('purchasing.receiving_intent') }}</h3>
                    @foreach($line['lots'] as $lot)<div class="grid grid-cols-3 gap-2 text-xs bg-surface-soft p-3 rounded-control"><span><bdi>{{ $lot['lot_number'] ?? '—' }}</bdi></span><span><bdi>{{ $lot['expiry_date'] ?? __('purchasing.unknown_expiry') }}</bdi></span><span><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($lot['quantity']) }}</bdi> {{ $line['unit_name'] }}</span></div>@endforeach
                @endif
            </article>
        @endforeach
    </section>
    @if($withCost)
        <section class="bg-surface border border-border rounded-card p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach(['subtotal_currency' => 'subtotal', 'discount_total_currency' => 'discount', 'tax_total_currency' => 'tax', 'grand_total_currency' => 'total'] as $field => $label)<div><span class="text-xs text-text-secondary">{{ __('purchasing.'.$label) }}</span><p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document[$field], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p></div>@endforeach
        </section>
    @else<p class="text-sm text-text-secondary">{{ __('purchasing.cost_restricted') }}</p>@endif
    @if($document['notes'])<section class="bg-surface border border-border rounded-card p-4"><h2 class="font-bold text-sm">{{ __('purchasing.notes') }}</h2><p class="text-sm mt-2 whitespace-pre-wrap">{{ $document['notes'] }}</p></section>@endif
</div>
