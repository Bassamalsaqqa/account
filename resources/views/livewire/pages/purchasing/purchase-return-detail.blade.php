<div class="space-y-6">
    @foreach($this->correctionHistory as $link)
        <a href="{{ $link['url'] }}" class="touch-action text-sm underline">{{ $link['label'] }}</a>
    @endforeach
    @if($correctionOpen)
        <x-correction-preview :correction-mode="$correctionMode" :correction-preview="$correctionPreview" />
    @endif

    <!-- Header Card -->
    <div class="bg-surface rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-mono font-bold tracking-tight text-text-primary" dir="ltr">
                        {{ $document['return_number'] ?? __('purchasing.purchase_return_draft') }}
                    </h1>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                        @if ($document['status'] === 'posted') bg-success-bg text-success
                        @else bg-slate-100 text-slate-600 @endif">
                        {{ __('purchasing.'.$document['status']) }}
                    </span>
                </div>
                <div class="text-xs text-text-secondary mt-1">
                    {{ __('purchasing.vendor') }}:
                    @if(!empty($document['vendor_name']))
                        <span class="font-bold text-text-primary">{{ $document['vendor_name'] }}</span>
                    @endif
                </div>
                @if(!empty($document['purchase_public_id']))
                    <div class="text-xs text-text-secondary mt-1">
                        {{ __('purchasing.purchase_number') }}:
                        <a href="{{ route('purchases.show', $document['purchase_public_id']) }}" class="touch-action font-mono font-bold text-text-primary hover:text-primary" dir="ltr">
                            {{ $document['purchase_number'] ?? '—' }}
                        </a>
                    </div>
                @endif
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                <x-correction-shortcuts family="purchase_return" :public-id="$document['public_id']" :status="$document['status']" :detail="true" />
                @if($canEdit)
                    <x-row-action wire:click="deleteReturnDraft" wire:confirm="{{ __('sales.delete_draft_confirm') }}" wire:loading.attr="disabled" :label="__('corrections.delete')" icon="trash" tone="danger" />
                @endif

                @if($canPost)
                    <button type="button" wire:click="post" wire:confirm="{{ __('purchasing.post_return_confirmation') }}" wire:loading.attr="disabled" wire:target="post"
                            class="px-3.5 py-1.5 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                        {{ __('purchasing.post_return') }}
                    </button>
                @endif
                @if($canEdit)
                    <a href="{{ route('purchase-returns.edit', $document['public_id']) }}" class="touch-action px-3 py-1.5 rounded-control border border-border text-sm font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                        {{ __('purchasing.edit_purchase_return') }}
                    </a>
                @endif
                @if(auth()->user()?->can('purchasing.purchase.view'))
                    <x-document-actions route-name="pdf.purchase-return" :parameters="['publicId' => $document['public_id']]" :permissions="['purchasing.document.pdf']" />
                @endif
                <a href="{{ route('purchase-returns.index') }}" class="touch-action px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-sm font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        <!-- Metadata Summary -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.return_date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $document['return_date'] }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.purchase_number') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">
                    @if(!empty($document['purchase_public_id']))
                        <a href="{{ route('purchases.show', $document['purchase_public_id']) }}" class="touch-action text-primary hover:underline">
                            {{ $document['purchase_number'] ?? '—' }}
                        </a>
                    @else
                        {{ $document['purchase_number'] ?? '—' }}
                    @endif
                </span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.receiving_warehouse') }}</span>
                <span class="font-bold text-text-primary">{{ $document['warehouse_name'] ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.currency') }}</span>
                <span class="font-bold text-text-primary">{{ $document['currency_code'] }}</span>
            </div>
        </div>
    </div>

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
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['unit_cost'], $document['currency_code']) }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-secondary">{{ __('purchasing.line_discount') }}</dt>
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['line_discount'], $document['currency_code']) }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-secondary">{{ __('purchasing.line_tax') }}</dt>
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['line_tax'], $document['currency_code']) }}</bdi></dd>
                        </div>
                        <div>
                            <dt class="text-xs text-text-secondary">{{ __('purchasing.line_total') }}</dt>
                            <dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['line_total'], $document['currency_code']) }}</bdi></dd>
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
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($document['subtotal_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
            </div>
            <div>
                <span class="text-xs text-text-secondary">{{ __('purchasing.discount') }}</span>
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($document['discount_total_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
            </div>
            <div>
                <span class="text-xs text-text-secondary">{{ __('purchasing.tax') }}</span>
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($document['tax_total_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
            </div>
            <div>
                <span class="text-xs text-text-secondary">{{ __('purchasing.total') }}</span>
                <p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($document['grand_total_currency'], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p>
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
