<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('purchase-returns.index') }}" class="text-xs text-primary">{{ __('purchasing.purchase_returns') }}</a>
            <h1 class="text-2xl font-bold mt-1 break-words">{{ $document['return_number'] ?? __('purchasing.purchase_return_draft') }}</h1>
            <p class="text-sm mt-1">{{ __('purchasing.'.$document['status']) }}</p>
        </div>
        @if($canEdit)
            <a href="{{ route('purchase-returns.edit', $document['public_id']) }}" class="inline-flex h-11 items-center justify-center px-4 rounded-control bg-primary text-white text-sm font-bold">
                {{ __('purchasing.edit_purchase_return') }}
            </a>
        @endif
    </header>

    @if($document['status'] === 'draft')
        <p class="p-3 rounded-control bg-info-bg text-info text-sm">{{ __('purchasing.draft_notice') }}</p>
    @else
        <p class="p-3 rounded-control bg-success-bg text-success text-sm">{{ __('purchasing.purchase_return_posted_notice') }} <bdi>{{ $document['posted_at'] }}</bdi></p>
    @endif

    @error('post')
        <p role="alert" class="p-3 bg-danger-bg text-danger rounded-control text-sm">{{ $message }}</p>
    @enderror

    @if(session('success'))
        <p role="status" class="text-success text-sm">{{ session('success') }}</p>
    @endif

    @if($canPost)
        <section class="border border-border bg-surface rounded-card p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <p class="text-sm max-w-prose">{{ __('purchasing.post_return_confirmation') }}</p>
            <button type="button" wire:click="post" wire:confirm="{{ __('purchasing.post_return_confirmation') }}" wire:loading.attr="disabled" wire:target="post" class="shrink-0 h-11 px-4 rounded-control bg-primary text-white text-sm font-bold disabled:opacity-50">
                {{ __('purchasing.post_return') }}
            </button>
        </section>
    @endif

    <section class="bg-surface border border-border rounded-card p-4">
        <dl class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.vendor') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $document['vendor_name'] ?? '—' }}</bdi></dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.purchase_number') }}</dt>
                <dd class="mt-1 font-semibold">
                    @if(!empty($document['purchase_public_id']))
                        <a href="{{ route('purchases.show', $document['purchase_public_id']) }}" class="text-primary hover:underline">
                            <bdi>{{ $document['purchase_number'] ?? '—' }}</bdi>
                        </a>
                    @else
                        <bdi>{{ $document['purchase_number'] ?? '—' }}</bdi>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.return_date') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $document['return_date'] }}</bdi></dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.receiving_warehouse') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $document['warehouse_name'] ?? '—' }}</bdi></dd>
            </div>
            <div>
                <dt class="text-xs text-text-secondary">{{ __('purchasing.currency') }}</dt>
                <dd class="mt-1 font-semibold"><bdi>{{ $document['currency_code'] }}</bdi></dd>
            </div>
        </dl>
    </section>

    <section class="space-y-3">
        @foreach($document['lines'] as $line)
            <article class="bg-surface border border-border rounded-card p-4 space-y-3" wire:key="detail-line-{{ $line['public_id'] }}">
                <div class="flex flex-wrap justify-between gap-2">
                    <h2 class="font-bold text-sm">{{ $line['item_description'] }}</h2>
                    <span class="text-xs text-text-secondary"><bdi>{{ $line['product_sku'] }}</bdi></span>
                </div>
                <dl class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
                    <div>
                        <dt class="text-xs text-text-secondary">{{ __('purchasing.quantity') }}</dt>
                        <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }}</bdi> {{ $line['unit_name'] }}</dd>
                    </div>
                    @if($withCost)
                        <div>
                            <dt class="text-xs text-text-secondary">{{ __('purchasing.unit_cost') }}</dt>
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['unit_cost'], $document['currency_code']) }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-secondary">{{ __('purchasing.line_discount') }}</dt>
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['line_discount'], $document['currency_code']) }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-secondary">{{ __('purchasing.line_tax') }}</dt>
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['line_tax'], $document['currency_code']) }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-secondary">{{ __('purchasing.line_total') }}</dt>
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['line_total'], $document['currency_code']) }}</bdi></dd>
                        </div>
                    @endif
                </dl>
                @if(count($line['allocations']))
                    <h3 class="text-xs font-bold text-text-secondary">{{ __('purchasing.return_allocations') }}</h3>
                    @foreach($line['allocations'] as $lot)
                        <div class="grid grid-cols-3 gap-2 text-xs bg-surface-soft p-3 rounded-control">
                            <span><bdi>{{ $lot['lot_number'] ?? '—' }}</bdi></span>
                            <span><bdi>{{ $lot['expiry_date'] ?? __('purchasing.unknown_expiry') }}</bdi></span>
                            <span><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($lot['quantity']) }}</bdi> {{ $line['unit_name'] }}</span>
                        </div>
                    @endforeach
                @endif
            </article>
        @endforeach
    </section>

    @if($withCost)
        <section class="bg-surface border border-border rounded-card p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <span class="text-xs text-text-secondary">{{ __('purchasing.subtotal') }}</span>
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document['subtotal_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
            </div>
            <div>
                <span class="text-xs text-text-secondary">{{ __('purchasing.discount') }}</span>
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document['discount_total_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
            </div>
            <div>
                <span class="text-xs text-text-secondary">{{ __('purchasing.tax') }}</span>
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document['tax_total_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
            </div>
            <div>
                <span class="text-xs text-text-secondary">{{ __('purchasing.total') }}</span>
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document['grand_total_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
            </div>
        </section>
    @else
        <p class="text-sm text-text-secondary">{{ __('purchasing.cost_restricted') }}</p>
    @endif

    @if(!empty($document['reason']))
        <section class="bg-surface border border-border rounded-card p-4">
            <h2 class="font-bold text-sm">{{ __('purchasing.return_reason') }}</h2>
            <p class="text-sm mt-2 whitespace-pre-wrap">{{ $document['reason'] }}</p>
        </section>
    @endif

    @if(!empty($document['notes']))
        <section class="bg-surface border border-border rounded-card p-4">
            <h2 class="font-bold text-sm">{{ __('purchasing.notes') }}</h2>
            <p class="text-sm mt-2 whitespace-pre-wrap">{{ $document['notes'] }}</p>
        </section>
    @endif
</div>
