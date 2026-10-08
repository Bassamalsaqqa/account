<div class="space-y-5">
    <header class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
        <div class="min-w-0"><a href="{{ route('purchases.index') }}" class="text-xs text-primary">{{ __('purchasing.purchases') }}</a><h1 class="text-2xl font-bold mt-1 break-words">{{ $document['purchase_number'] ?? __('purchasing.purchase_draft') }}</h1><p class="text-sm mt-1">{{ __('purchasing.'.$document['status']) }}</p></div>
        <div class="flex items-center gap-3">
            @if($canPayVendor)<a href="{{ route('vendor-payments.create', ['purchase_id' => $purchase->id]) }}" class="inline-flex h-11 items-center justify-center px-4 rounded-control bg-success text-white text-sm font-bold shadow-sm hover:opacity-90">{{ __('purchasing.pay_vendor') }}</a>@endif
            @if($canCreateReturn)<a href="{{ route('purchase-returns.create', $document['public_id']) }}" class="inline-flex h-11 items-center justify-center px-4 rounded-control border border-border bg-surface text-sm font-bold hover:bg-surface-soft">{{ __('purchasing.create_purchase_return') }}</a>@endif
            @if($canEdit)<a href="{{ route('purchases.edit', $document['public_id']) }}" class="inline-flex h-11 items-center justify-center px-4 rounded-control bg-primary text-white text-sm font-bold">{{ __('purchasing.edit_purchase') }}</a>@endif
        </div>
    </header>
    @if($document['status'] === 'draft')<p class="p-3 rounded-control bg-info-bg text-info text-sm">{{ __('purchasing.draft_notice') }}</p>@else<p class="p-3 rounded-control bg-success-bg text-success text-sm">{{ __('purchasing.posted_notice') }} <bdi>{{ $document['posted_at'] }}</bdi></p>@endif
    @error('post')<p role="alert" class="p-3 bg-danger-bg text-danger rounded-control text-sm">{{ $message }}</p>@enderror
    @if(session('success'))<p role="status" class="text-success text-sm">{{ session('success') }}</p>@endif
    @if($canPost)
        <section class="border border-border bg-surface rounded-card p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <p class="text-sm max-w-prose">{{ __('purchasing.post_confirmation') }}</p>
            <button type="button" wire:click="post" wire:confirm="{{ __('purchasing.post_confirmation') }}" wire:loading.attr="disabled" wire:target="post" class="shrink-0 h-11 px-4 rounded-control bg-primary text-white text-sm font-bold disabled:opacity-50">{{ __('purchasing.post_purchase') }}</button>
        </section>
    @endif
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
                        @if(!empty($line['landed_cost_allocated_base']) && \Brick\Math\BigDecimal::of($line['landed_cost_allocated_base'])->isPositive())
                            <div><dt class="text-xs text-text-secondary">{{ __('purchasing.landed_cost') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['landed_cost_allocated_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</bdi></dd></div>
                            @if(!empty($line['inventory_unit_cost_base']))
                                <div><dt class="text-xs text-text-secondary">{{ __('purchasing.inventory_unit_cost') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($line['inventory_unit_cost_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</bdi></dd></div>
                            @endif
                        @endif
                    @endif
                </dl>
                @if(count($line['lots']))
                    <h3 class="text-xs font-bold text-text-secondary">{{ __('purchasing.'.($document['status'] === 'draft' ? 'receiving_intent' : 'received_lots')) }}</h3>
                    @foreach($line['lots'] as $lot)<div class="grid grid-cols-3 gap-2 text-xs bg-surface-soft p-3 rounded-control"><span><bdi>{{ $lot['lot_number'] ?? '—' }}</bdi></span><span><bdi>{{ $lot['expiry_date'] ?? __('purchasing.unknown_expiry') }}</bdi></span><span><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($lot['quantity']) }}</bdi> {{ $line['unit_name'] }}</span></div>@endforeach
                @endif
            </article>
        @endforeach
    </section>
    @if($withCost)
        <section class="bg-surface border border-border rounded-card p-4 grid grid-cols-2 md:grid-cols-4 gap-4">
            @foreach(['subtotal_currency' => 'subtotal', 'discount_total_currency' => 'discount', 'tax_total_currency' => 'tax', 'grand_total_currency' => 'total'] as $field => $label)<div><span class="text-xs text-text-secondary">{{ __('purchasing.'.$label) }}</span><p class="font-bold mt-1"><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document[$field], $document['currency_code']) }} {{ $document['currency_code'] }}</bdi></p></div>@endforeach
            @if(!empty($document['total_landed_cost_base']) && \Brick\Math\BigDecimal::of($document['total_landed_cost_base'])->isPositive())
                <div class="col-span-full border-t border-border pt-3 flex justify-between items-center text-sm">
                    <span class="font-bold text-text-secondary">{{ __('purchasing.total_landed_cost') }}</span>
                    <p class="font-bold font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document['total_landed_cost_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</p>
                </div>
            @endif
        </section>

        <!-- Landed Costs Section -->
        <section class="bg-surface border border-border rounded-card p-4 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <h2 class="font-bold text-sm text-text-primary">{{ __('purchasing.landed_costs') }}</h2>
                @if(!empty($document['total_landed_cost_base']) && \Brick\Math\BigDecimal::of($document['total_landed_cost_base'])->isPositive())
                    <span class="text-xs font-semibold text-text-secondary">
                        {{ __('purchasing.total_landed_cost') }}: <bdi class="font-mono text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($document['total_landed_cost_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</bdi>
                    </span>
                @endif
            </div>

            @if($landedError)
                <p role="alert" class="p-3 bg-danger-bg text-danger rounded-control text-xs">{{ $landedError }}</p>
            @endif

            @if($landedAllocations->isNotEmpty())
                <div class="space-y-3">
                    @foreach($landedAllocations as $expenseId => $allocations)
                        @php $expense = $allocations->first()->expense; @endphp
                        <div class="border border-border/80 rounded-control p-3 bg-surface-soft flex flex-wrap items-center justify-between gap-3 text-xs">
                            <div>
                                <span class="font-bold text-primary">{{ $expense->expense_number }}</span>
                                <span class="text-text-secondary mx-1">·</span>
                                <span class="text-text-secondary">{{ $expense->description }}</span>
                                <span class="text-text-secondary mx-1">·</span>
                                <span class="text-text-muted">{{ $expense->expense_date->toDateString() }}</span>
                                <div class="mt-1 text-text-secondary">
                                    {{ __('purchasing.allocation_method') }}: <span class="font-semibold">{{ __('purchasing.method_'.$allocations->first()->allocation_method) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-bold font-mono text-sm" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($allocations->sum('allocated_base'), $document['base_currency_code']) }} {{ $document['base_currency_code'] }}
                                </span>
                                @if($canManageLanded)
                                    <button type="button" wire:click="removeLandedCost({{ $expenseId }})" wire:confirm="{{ __('purchasing.remove_landed_cost') }}?" class="px-2.5 py-1 rounded bg-danger/10 text-danger hover:bg-danger/20 font-semibold transition-colors">
                                        {{ __('purchasing.remove_landed_cost') }}
                                    </button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            @if($canManageLanded)
                <div class="border-t border-border pt-3 space-y-3">
                    <h3 class="text-xs font-bold text-text-secondary">{{ __('purchasing.attach_landed_cost') }}</h3>
                    @if($availableExpenses->isNotEmpty())
                        <div class="grid sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('purchasing.select_expense') }}</label>
                                <select wire:model.live="selectedExpenseId" class="w-full text-xs rounded-control border border-border bg-white px-2.5 py-2">
                                    <option value="">-- {{ __('purchasing.select_expense') }} --</option>
                                    @foreach($availableExpenses as $availExp)
                                        <option value="{{ $availExp->id }}">
                                            {{ $availExp->expense_number }} - {{ $availExp->description }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($availExp->amount, $availExp->currency_code) }} {{ $availExp->currency_code }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-text-secondary mb-1">{{ __('purchasing.allocation_method') }}</label>
                                <select wire:model.live="allocationMethod" class="w-full text-xs rounded-control border border-border bg-white px-2.5 py-2">
                                    <option value="value">{{ __('purchasing.method_value') }}</option>
                                    <option value="quantity">{{ __('purchasing.method_quantity') }}</option>
                                    <option value="manual">{{ __('purchasing.method_manual') }}</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="button" wire:click="attachLandedCost" wire:loading.attr="disabled" class="h-9 px-4 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover disabled:opacity-50">
                                    {{ __('purchasing.attach_landed_cost') }}
                                </button>
                            </div>
                        </div>

                        @if($allocationMethod === 'manual' && $selectedExpenseId)
                            <div class="p-3 bg-surface-soft border border-border rounded-control space-y-2">
                                <h4 class="text-xs font-bold text-text-secondary">{{ __('purchasing.method_manual') }}</h4>
                                @foreach($document['lines'] as $line)
                                    <div class="flex items-center justify-between gap-3 text-xs">
                                        <span>{{ $line['item_description'] }} ({{ $line['quantity'] }} {{ $line['unit_name'] }})</span>
                                        <input type="text" wire:model="manualAllocations.{{ $line['id'] }}" placeholder="0.00" class="w-32 rounded border border-border bg-white px-2 py-1 text-xs text-end font-mono">
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-text-muted italic">{{ __('purchasing.no_available_expenses') }}</p>
                    @endif
                </div>
            @endif
        </section>
        @if($payablePosition !== null)
            <section class="bg-surface border border-border rounded-card p-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-xs">
                <div>
                    <span class="text-text-secondary block text-[11px]">{{ __('purchasing.all_statuses') }}</span>
                    <span class="inline-block mt-1 px-2.5 py-0.5 rounded-full text-xs font-bold {{ $payablePosition->status === 'settled' ? 'bg-success-bg text-success' : ($payablePosition->status === 'partially_paid' ? 'bg-warning-bg text-warning' : ($payablePosition->status === 'credit' ? 'bg-info-bg text-info' : 'bg-surface-soft text-text-secondary')) }}">
                        {{ __('purchasing.'.$payablePosition->status) }}
                    </span>
                </div>
                <div>
                    <span class="text-text-secondary block text-[11px]">{{ __('purchasing.allocated') }}</span>
                    <p class="font-mono font-bold mt-1 text-text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payablePosition->activeAllocatedAmount, $document['currency_code']) }} {{ $document['currency_code'] }}</p>
                </div>
                <div>
                    <span class="text-text-secondary block text-[11px]">{{ __('purchasing.purchase_returns') }}</span>
                    <p class="font-mono font-bold mt-1 text-text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payablePosition->postedReturnedAmount, $document['currency_code']) }} {{ $document['currency_code'] }}</p>
                </div>
                <div>
                    <span class="text-text-secondary block text-[11px]">{{ __('purchasing.outstanding_balance') }}</span>
                    <p class="font-mono font-bold mt-1 {{ $payablePosition->hasOutstanding() ? 'text-danger' : 'text-text-primary' }}" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($payablePosition->outstanding, $document['currency_code']) }} {{ $document['currency_code'] }}</p>
                </div>
            </section>
        @endif
    @else<p class="text-sm text-text-secondary">{{ __('purchasing.cost_restricted') }}</p>@endif
    @if($document['notes'])<section class="bg-surface border border-border rounded-card p-4"><h2 class="font-bold text-sm">{{ __('purchasing.notes') }}</h2><p class="text-sm mt-2 whitespace-pre-wrap">{{ $document['notes'] }}</p></section>@endif
</div>
