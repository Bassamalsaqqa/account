<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-surface rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div class="min-w-0 space-y-1.5">
                <div class="flex flex-wrap items-center gap-2.5">
                    <h1 class="text-2xl font-bold tracking-tight text-text-primary tabular-nums">
                        @if($document['purchase_number'])
                            <bdi dir="ltr">{{ $document['purchase_number'] }}</bdi>
                        @else
                            {{ __('purchasing.purchase_draft') }}
                        @endif
                    </h1>
                    <x-badge :variant="$document['status'] === 'posted' ? 'success' : ($document['status'] === 'void' ? 'danger' : 'draft')">
                        {{ __('purchasing.'.$document['status']) }}
                    </x-badge>
                    @if($payablePosition && $withCost)
                        <x-badge :variant="$payablePosition->status === 'settled' ? 'success' : ($payablePosition->status === 'partially_paid' ? 'warn' : ($payablePosition->status === 'credit' ? 'info' : 'neutral'))">
                            {{ __('purchasing.'.$payablePosition->status) }}
                        </x-badge>
                    @endif
                </div>
                <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-text-secondary">
                    <span class="inline-flex items-center gap-1.5">
                        {{ __('purchasing.vendor') }}:
                        @if($purchase->vendor)
                            <a href="{{ route('vendors.show', $purchase->vendor->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary border-border-control/40">
                                {{ $document['vendor_name'] }}
                            </a>
                        @else
                            <span class="font-bold text-text-primary">{{ $document['vendor_name'] }}</span>
                        @endif
                    </span>
                    @if (filled($purchase->vendor_invoice_number))
                        <span class="inline-flex max-w-full items-center gap-1.5">
                            {{ __('purchasing.vendor_invoice_number') }}:
                            <bdi dir="ltr" class="min-w-0 max-w-64 truncate font-semibold text-text-primary tabular-nums" title="{{ $purchase->vendor_invoice_number }}">{{ $purchase->vendor_invoice_number }}</bdi>
                        </span>
                    @endif
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                @if($purchase->isPosted() && $withCost && auth()->user()->can('purchasing.purchase.void'))
                    @if(auth()->user()->can('purchasing.purchase.create') && auth()->user()->can('purchasing.purchase.edit_draft'))
                        <button type="button" wire:click="openCorrection('edit')" class="touch-action bg-surface text-text-primary hover:bg-surface-soft text-sm font-bold"><x-icon name="edit" class="inline size-4" /> {{ __('corrections.edit') }}</button>
                    @endif
                    <button type="button" wire:click="openCorrection('delete')" class="touch-action bg-surface text-danger hover:bg-danger-bg text-sm font-bold">{{ __('corrections.delete') }}</button>
                @endif
                @if($canPost)
                    <button type="button" wire:click="post" wire:loading.attr="disabled" wire:target="post"
                            class="app-button min-h-11 lg:min-h-9.5 px-3.5 py-1.5 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                        {{ __('purchasing.post_purchase') }}
                    </button>
                @endif
                @if($canPayVendor)
                    <a href="{{ route('vendor-payments.create', ['purchase_id' => $purchase->id]) }}" class="app-button touch-action px-3.5 py-1.5 rounded-control bg-success text-white border-success text-sm font-bold shadow-xs hover:opacity-95">
                        + {{ __('purchasing.pay_vendor') }}
                    </a>
                @endif
                @if($canCreateReturn)
                    <a href="{{ route('purchase-returns.create', $document['public_id']) }}" class="touch-action px-3 py-1.5 rounded-control bg-surface border border-border-control text-sm font-bold text-text-primary hover:bg-surface-soft transition-colors">
                        ↩ {{ __('purchasing.create_purchase_return') }}
                    </a>
                @endif
                @if($canEdit)
                    <a href="{{ route('purchases.edit', $document['public_id']) }}" class="touch-action px-3 py-1.5 rounded-control bg-surface border border-border-control text-sm font-bold text-text-primary hover:bg-surface-soft transition-colors">
                        {{ __('purchasing.edit_purchase') }}
                    </a>
                @endif
                @if($purchase->isDraft() && auth()->user()->hasRole('Owner') && auth()->user()->can('purchasing.purchase.edit_draft') && auth()->user()->can('purchasing.cost.view'))
                    <button type="button" wire:click="deleteDraft" wire:confirm="{{ __('purchase_draft_delete.confirm') }}" wire:loading.attr="disabled"
                            class="app-button min-h-11 lg:min-h-9.5 px-3 py-1.5 rounded-control bg-surface border border-border-control text-sm font-semibold text-danger hover:bg-danger-bg focus-visible:ring-2 focus-visible:ring-primary cursor-pointer">
                        {{ __('purchase_draft_delete.delete') }}
                    </button>
                @endif
                @error('deleteDraft') <p role="alert" class="text-sm text-danger">{{ $message }}</p> @enderror
                @if($canCreatePurchase)
                    <a href="{{ route('purchases.create') }}" class="touch-action inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control bg-surface border border-border-control text-sm font-bold text-text-primary hover:bg-surface-soft transition-colors focus-visible:ring-2 focus-visible:ring-primary">
                        <x-icon name="plus" class="size-4 text-primary" />
                        <span>{{ __('release_b1_workflow.new_purchase') }}</span>
                    </a>
                @endif
                @if(auth()->user()?->can('purchasing.purchase.view'))
                    <x-document-actions route-name="pdf.purchase" :parameters="['publicId' => $document['public_id']]" :permissions="['purchasing.document.pdf']" />
                @endif
                <a href="{{ route('purchases.index') }}" class="touch-action px-3 py-1.5 rounded-control bg-surface-soft border border-border-control text-text-primary hover:bg-surface text-sm font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        @if($correctionHistory && $withCost)
            <p class="text-sm text-text-secondary">{{ __('corrections.retained') }}
                @php
                    $linkedId = (int) $correctionHistory->purchase_id === (int) $purchase->id ? $correctionHistory->replacement_id : $correctionHistory->purchase_id;
                $linkedPurchase = $linkedId ? \App\Models\Purchase::where('company_id', $purchase->company_id)->find($linkedId) : null;
                @endphp
                @if($linkedPurchase)
                    <a class="touch-action underline" href="{{ route('purchases.show', $linkedPurchase->public_id) }}">{{ $linkedPurchase->purchase_number ?? __('purchasing.draft') }} <bdi dir="ltr">#{{ $linkedPurchase->id }}</bdi></a>
                @endif
            </p>
        @endif
        <!-- Metadata Summary -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.purchase_date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $document['purchase_date'] ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.due_date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $document['due_date'] ?? __('purchasing.no_due_date') }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.receiving_warehouse') }}</span>
                <span class="font-bold text-text-primary">{{ $document['warehouse_name'] ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-xs">{{ __('purchasing.currency') }}</span>
                <span class="font-bold text-text-primary">{{ $document['currency_code'] ?? '—' }}</span>
            </div>
        </div>

        @if($payablePosition && $withCost)
            <div class="pt-4 border-t border-border">
                <x-document-summary-cards
                    :total="$document['grand_total_currency'] ?? (string) $payablePosition->purchase->grand_total"
                    :paid="(string) $payablePosition->activeAllocatedAmount"
                    :remaining="(string) $payablePosition->outstanding"
                    :currency="$document['currency_code']" :currency-scale="$document['currency_minor_units']"
                    :due-date="$document['due_date'] ?? null"
                    :returned="(string) $payablePosition->postedReturnedAmount"
                />
            </div>
        @endif
    </div>

    @if($document['status'] === 'draft')<p class="p-3 rounded-control bg-info-bg text-info text-sm">{{ __('purchasing.draft_notice') }}</p>@else<p class="p-3 rounded-control bg-success-bg text-success text-sm">{{ $document['status'] === 'void' ? __('corrections.retained') : __('purchasing.posted_notice') }} <bdi>{{ $document['posted_at'] }}</bdi></p>@endif
    @error('post')<p role="alert" class="p-3 bg-danger-bg text-danger rounded-control text-sm">{{ $message }}</p>@enderror
    @if(session('success'))<p role="status" class="text-success text-sm">{{ session('success') }}</p>@endif
    @if($duplicateWarning)<p role="status" class="p-3 rounded-control bg-warning-bg text-warning text-sm">{{ __('purchasing.duplicate_vendor_invoice') }}</p>@endif
    <section class="space-y-3">
        @foreach($document['lines'] as $line)
            <article class="bg-surface border border-border rounded-card p-4 space-y-3" wire:key="detail-line-{{ $line['public_id'] }}">
                <div class="flex flex-wrap justify-between gap-2"><h2 class="font-bold text-sm">{{ $line['item_description'] }}</h2><span class="text-xs text-text-secondary"><bdi>{{ $line['product_sku'] }}</bdi></span></div>
                <dl class="grid grid-cols-2 md:grid-cols-5 gap-3 text-sm">
                    <div><dt class="text-xs text-text-secondary">{{ __('purchasing.quantity') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line['quantity']) }}</bdi> {{ $line['unit_name'] }}</dd></div>
                    @if($withCost)
                        <div><dt class="text-xs text-text-secondary">{{ __('purchasing.unit_cost') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatMoneyInput($line['unit_cost'], $document['currency_minor_units']) }}</bdi></dd></div>
                        @foreach(['line_discount', 'line_tax', 'line_total'] as $field)<div><dt class="text-xs text-text-secondary">{{ __('purchasing.'.$field) }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line[$field], $document['currency_code'], $document['currency_minor_units']) }}</bdi></dd></div>@endforeach
                        @if(!empty($line['landed_cost_allocated_base']) && \Brick\Math\BigDecimal::of($line['landed_cost_allocated_base'])->isPositive())
                            <div><dt class="text-xs text-text-secondary">{{ __('purchasing.landed_cost') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['landed_cost_allocated_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</bdi></dd></div>
                            @if(!empty($line['inventory_unit_cost_base']))
                                <div><dt class="text-xs text-text-secondary">{{ __('purchasing.inventory_unit_cost') }}</dt><dd><bdi>{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line['inventory_unit_cost_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</bdi></dd></div>
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
        <section class="bg-surface border border-border rounded-card overflow-hidden">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-px bg-border">
                @foreach(['subtotal_currency' => 'subtotal', 'discount_total_currency' => 'discount', 'tax_total_currency' => 'tax', 'grand_total_currency' => 'total'] as $field => $label)
                    <div class="bg-surface p-3.5 sm:p-4">
                        <span class="text-xs font-medium text-text-secondary block">{{ __('purchasing.'.$label) }}</span>
                        <p class="font-bold mt-1 text-text-primary tabular-nums {{ $field === 'grand_total_currency' ? 'text-primary' : '' }}">
                            <bdi dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($document[$field], $document['currency_code'], $document['currency_minor_units']) }} {{ $document['currency_code'] }}</bdi>
                        </p>
                    </div>
                @endforeach
            </div>
            @if(!empty($document['total_landed_cost_base']) && \Brick\Math\BigDecimal::of($document['total_landed_cost_base'])->isPositive())
                <div class="border-t border-border px-4 py-3 bg-surface-soft flex flex-wrap justify-between items-center gap-2 text-sm">
                    <span class="font-semibold text-text-secondary">{{ __('purchasing.total_landed_cost') }}</span>
                    <p class="font-bold text-text-primary tabular-nums" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($document['total_landed_cost_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</p>
                </div>
            @endif
        </section>

        <!-- Landed Costs Section -->
        <section class="bg-surface border border-border rounded-card p-4 space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border pb-3">
                <h2 class="font-bold text-sm text-text-primary">{{ __('purchasing.landed_costs') }}</h2>
                @if(!empty($document['total_landed_cost_base']) && \Brick\Math\BigDecimal::of($document['total_landed_cost_base'])->isPositive())
                    <span class="text-xs font-semibold text-text-secondary">
                        {{ __('purchasing.total_landed_cost') }}: <bdi dir="ltr" class="tabular-nums font-bold text-text-primary">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($document['total_landed_cost_base'], $document['base_currency_code']) }} {{ $document['base_currency_code'] }}</bdi>
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
                        <div class="border border-border rounded-control p-3 bg-surface-soft flex flex-wrap items-center justify-between gap-3 text-xs">
                            <div>
                                <span class="font-bold text-primary">{{ $expense->expense_number }}</span>
                                <span class="text-text-secondary mx-1">·</span>
                                <span class="text-text-secondary">{{ $expense->description }}</span>
                                <span class="text-text-secondary mx-1">·</span>
                                <span class="text-text-muted tabular-nums">{{ $expense->expense_date->toDateString() }}</span>
                                <div class="mt-1 text-text-secondary">
                                    {{ __('purchasing.allocation_method') }}: <span class="font-semibold">{{ __('purchasing.method_'.$allocations->first()->allocation_method) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="font-bold text-sm text-text-primary tabular-nums" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($allocations->sum('allocated_base'), $document['base_currency_code']) }} {{ $document['base_currency_code'] }}
                                </span>
                                @if($canManageLanded)
                                    <button type="button" wire:click="removeLandedCost({{ $expenseId }})" wire:confirm="{{ __('purchasing.remove_landed_cost') }}?" class="app-button min-h-11 lg:min-h-8.5 px-2.5 py-1 rounded-control border border-border-control bg-surface text-danger hover:bg-danger-bg font-semibold transition-colors cursor-pointer" wire:loading.attr="disabled" wire:target="removeLandedCost">
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
                                <label class="block text-sm font-medium text-text-secondary mb-1">{{ __('purchasing.select_expense') }}</label>
                                <select wire:model.live="selectedExpenseId" class="w-full min-h-11 lg:min-h-9.5 text-sm rounded-control border border-border-control bg-surface px-2.5 py-2 text-text-primary">
                                    <option value="">-- {{ __('purchasing.select_expense') }} --</option>
                                    @foreach($availableExpenses as $availExp)
                                        <option value="{{ $availExp->id }}">
                                            {{ $availExp->expense_number }} - {{ $availExp->description }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($availExp->amount, $availExp->currency_code) }} {{ $availExp->currency_code }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-text-secondary mb-1">{{ __('purchasing.allocation_method') }}</label>
                                <select wire:model.live="allocationMethod" class="w-full min-h-11 lg:min-h-9.5 text-sm rounded-control border border-border-control bg-surface px-2.5 py-2 text-text-primary">
                                    <option value="value">{{ __('purchasing.method_value') }}</option>
                                    <option value="quantity">{{ __('purchasing.method_quantity') }}</option>
                                    <option value="manual">{{ __('purchasing.method_manual') }}</option>
                                </select>
                            </div>
                            <div class="flex items-end">
                                <button type="button" wire:click="attachLandedCost" wire:loading.attr="disabled" class="app-button min-h-11 lg:min-h-9.5 px-4 rounded-control bg-primary text-white text-sm font-bold hover:bg-primary-hover disabled:opacity-50 cursor-pointer">
                                    {{ __('purchasing.attach_landed_cost') }}
                                </button>
                            </div>
                        </div>

                        @if($allocationMethod === 'manual' && $selectedExpenseId)
                            <div class="p-3 bg-surface-soft border border-border rounded-control space-y-2">
                                <h4 class="text-xs font-bold text-text-secondary">{{ __('purchasing.method_manual') }}</h4>
                                @foreach($document['lines'] as $line)
                                    <div class="flex items-center justify-between gap-3 text-xs">
                                        <span>{{ $line['item_description'] }} (<bdi dir="ltr" class="tabular-nums">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayQuantity($line['quantity']) }}</bdi> {{ $line['unit_name'] }})</span>
                                        <input type="text" inputmode="decimal" dir="ltr" wire:model="manualAllocations.{{ $line['id'] }}" placeholder="0.00" class="w-32 min-h-11 lg:min-h-9.5 rounded-control border border-border-control bg-surface px-2.5 py-1 text-sm text-end tabular-nums text-text-primary">
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    @else
                        <p class="text-xs text-text-muted">{{ __('purchasing.no_available_expenses') }}</p>
                    @endif
                </div>
            @endif
        </section>
    @else<p class="text-sm text-text-secondary">{{ __('purchasing.cost_restricted') }}</p>@endif
    @if($document['notes'])<section class="bg-surface border border-border rounded-card p-4"><h2 class="font-bold text-sm">{{ __('purchasing.notes') }}</h2><p class="text-sm mt-2 whitespace-pre-wrap">{{ $document['notes'] }}</p></section>@endif
    @if($correctionOpen)
        <x-correction-preview :correction-mode="$correctionMode" :correction-preview="$correctionPreview" />
    @endif
</div>
