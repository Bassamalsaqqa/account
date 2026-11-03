<div class="space-y-6">
    <!-- Header -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-mono font-bold tracking-tight text-text-primary" dir="ltr">
                        {{ $quotation->quotation_number }}
                    </h1>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                        @if ($quotation->status === 'accepted' || $quotation->status === 'converted') bg-success-bg text-success
                        @elseif ($quotation->status === 'sent') bg-primary-50 text-primary
                        @elseif ($quotation->status === 'rejected') bg-danger-bg text-danger
                        @else bg-slate-100 text-slate-600 @endif">
                        {{ __('sales.status_' . $quotation->status) }}
                    </span>
                </div>
                <div class="text-xs text-text-secondary mt-1">
                    {{ __('sales.customer') }}:
                    <a href="{{ route('customers.show', $quotation->customer->public_id) }}" class="touch-action font-bold text-text-primary hover:text-primary">
                        {{ $quotation->customer->displayName() }}
                    </a>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                @if ($canConvert)
                    <button type="button"
                            wire:click="convertToInvoice"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control bg-success text-white text-xs font-bold hover:opacity-90 transition-opacity shadow-xs cursor-pointer" wire:loading.attr="disabled" wire:target="convertToInvoice">
                        <x-icon name="plus" class="w-3.5 h-3.5" />
                        <span>{{ __('sales.convert_to_invoice') }}</span>
                    </button>
                @endif

                @if ($quotation->isConverted() && $quotation->convertedInvoice && $canViewInvoice)
                    @if ($quotation->convertedInvoice->isDraft() && $canEditInvoiceDraft)
                        <a href="{{ route('invoices.edit', $quotation->convertedInvoice->public_id) }}"
                           class="touch-action inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs">
                            <x-icon name="edit" class="w-3.5 h-3.5" />
                            <span>{{ __('workflow.edit_invoice_draft') }}</span>
                        </a>
                    @else
                        <a href="{{ route('invoices.show', $quotation->convertedInvoice->public_id) }}"
                           class="touch-action inline-flex items-center gap-1.5 px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                            <x-icon name="file" class="w-3.5 h-3.5" />
                            <span>{{ __('workflow.view_invoice_draft') }}</span>
                        </a>
                    @endif
                @endif

                @if ($canSend)
                    <button type="button"
                            wire:click="markAsSent"
                            class="px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer" wire:loading.attr="disabled" wire:target="markAsSent">
                        {{ __('sales.mark_as_sent') }}
                    </button>
                @endif

                @if ($quotation->status === 'sent')
                    <button type="button"
                            wire:click="markAsAccepted"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-control bg-success-bg text-success border border-success/30 text-xs font-bold hover:bg-success hover:text-white transition-colors cursor-pointer" wire:loading.attr="disabled" wire:target="markAsAccepted">
                        <x-icon name="check" class="w-3.5 h-3.5" />
                        <span>{{ __('sales.mark_as_accepted') }}</span>
                    </button>
                    <button type="button"
                            wire:click="markAsRejected"
                            class="inline-flex items-center gap-1 px-3 py-1.5 rounded-control bg-danger-bg text-danger border border-danger/30 text-xs font-bold hover:bg-danger hover:text-white transition-colors cursor-pointer" wire:loading.attr="disabled" wire:target="markAsRejected">
                        <x-icon name="close" class="w-3.5 h-3.5" />
                        <span>{{ __('sales.mark_as_rejected') }}</span>
                    </button>
                @endif

                @if ($canReturnToDraft)
                    <button type="button"
                            wire:click="returnToDraft"
                            class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer" wire:loading.attr="disabled" wire:target="returnToDraft">
                        {{ __('sales.return_to_draft') }}
                    </button>
                @endif

                @if ($canEdit)
                    <a href="{{ route('quotations.edit', $quotation->public_id) }}"
                       class="touch-action px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                        {{ __('sales.edit_quotation') }}
                    </a>
                @endif

                <x-document-actions route-name="pdf.quotation" :parameters="['publicId' => $quotation->public_id]" :permissions="['sales.document.pdf', 'sales.quote.view']" />

                <a href="{{ route('quotations.index') }}"
                   class="touch-action px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        <!-- Document Metadata Slots -->
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3 pt-4 border-t border-border text-xs">
            <div class="p-3 rounded-xl bg-surface-soft border border-border/70">
                <span class="text-text-muted block text-[11px] font-semibold">{{ __('sales.date') }}</span>
                <span class="font-bold text-text-primary text-sm mt-0.5 block" dir="ltr">
                    {{ $quotation->issue_date ? $quotation->issue_date->toDateString() : '—' }}
                </span>
            </div>
            <div class="p-3 rounded-xl bg-surface-soft border border-border/70">
                <span class="text-text-muted block text-[11px] font-semibold">{{ __('sales.valid_until') }}</span>
                <div class="flex items-center gap-1.5 mt-0.5 flex-wrap">
                    <span class="font-bold text-text-primary text-sm" dir="ltr">
                        {{ $quotation->expiry_date ? $quotation->expiry_date->toDateString() : '—' }}
                    </span>
                    @if ($quotation->expiry_date && $quotation->expiry_date->isPast() && !in_array($quotation->status, ['accepted', 'converted'], true))
                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold bg-danger-bg text-danger border border-danger/20">
                            {{ __('sales.expired') }}
                        </span>
                    @endif
                </div>
            </div>
            @if (isset($company) && $quotation->currency_code !== $company->base_currency_code && \Brick\Math\BigDecimal::of((string) $quotation->exchange_rate)->compareTo(1) !== 0)
                <div class="p-3 rounded-xl bg-surface-soft border border-border/70">
                    <span class="text-text-muted block text-[11px] font-semibold">{{ __('sales.exchange_rate') }}</span>
                    <span class="font-mono text-text-primary text-xs mt-0.5 block" dir="ltr">
                        1 {{ $quotation->currency_code }} = {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($quotation->exchange_rate, 4) }} {{ $company->base_currency_code }}
                    </span>
                </div>
            @endif
            @if ($quotation->warehouse)
                <div class="p-3 rounded-xl bg-surface-soft border border-border/70">
                    <span class="text-text-muted block text-[11px] font-semibold">{{ __('sales.warehouse') }}</span>
                    <span class="font-bold text-text-primary text-sm mt-0.5 block">{{ $quotation->warehouse->displayName() }}</span>
                </div>
            @endif
        </div>
    </div>

    <!-- Provenance Banner -->
    @if ($quotation->isConverted() && $quotation->convertedInvoice)
        <div class="bg-primary-50/50 border border-primary/20 rounded-card p-4 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <span class="text-primary text-lg">🔗</span>
                <div>
                    <h3 class="text-xs font-bold text-text-primary">{{ __('workflow.converted_to_invoice') }}</h3>
                    <p class="text-[11px] text-text-secondary">
                        {{ __('workflow.converted_invoice_provenance') }}:
                        @if ($canViewInvoice)
                            <a href="{{ route('invoices.show', $quotation->convertedInvoice->public_id) }}" class="touch-action font-mono font-bold text-primary hover:underline" dir="ltr">
                                {{ $quotation->convertedInvoice->invoice_number ?? __('sales.draft_invoice') }}
                            </a>
                        @else
                            <span class="font-mono font-bold text-text-primary" dir="ltr">
                                {{ $quotation->convertedInvoice->invoice_number ?? __('sales.draft_invoice') }}
                            </span>
                        @endif
                        @if ($quotation->converted_at)
                            <span class="text-text-muted">({{ $quotation->converted_at->toDateString() }})</span>
                        @endif
                    </p>
                </div>
            </div>
            @if ($canViewInvoice)
                <div class="flex items-center gap-2">
                    @if ($quotation->convertedInvoice->isDraft() && $canEditInvoiceDraft)
                        <a href="{{ route('invoices.edit', $quotation->convertedInvoice->public_id) }}"
                           class="touch-action px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs">
                            {{ __('workflow.edit_invoice_draft') }}
                        </a>
                    @else
                        <a href="{{ route('invoices.show', $quotation->convertedInvoice->public_id) }}"
                           class="touch-action px-3 py-1.5 rounded-control border border-border bg-white text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                            {{ __('workflow.view_invoice_draft') }}
                        </a>
                    @endif
                </div>
            @endif
        </div>
    @endif

    <!-- Items Table -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('sales.quotation_details') }}
        </div>
        <div class="overflow-x-auto">
            <table class="data-table w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                        <th class="py-2.5 px-4 text-start">#</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.product') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.quantity') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.unit_price') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.discount') }}</th>
                        <th class="py-2.5 px-4 text-start">{{ __('sales.tax') }}</th>
                        <th class="py-2.5 px-4 text-end">{{ __('sales.total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($quotation->lines as $line)
                        <tr class="hover:bg-surface-soft/40">
                            <td class="py-2.5 px-4 text-text-muted">{{ $line->line_number }}</td>
                            <td class="py-2.5 px-4">
                                <div class="font-bold text-text-primary">{{ $line->item_description }}</div>
                                @if ($line->product_sku)
                                    <div class="text-[10px] font-mono text-text-muted">SKU: {{ $line->product_sku }}</div>
                                @endif
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($line->quantity) }} {{ $line->unit_name_ar ?? '' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line->unit_price, $quotation->currency_code) }}
                            </td>
                            <td class="py-2.5 px-4 font-mono text-danger" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->discount_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line->discount_amount, $quotation->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->tax_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line->tax_amount, $quotation->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono font-bold text-end" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($line->line_total, $quotation->currency_code) }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Totals Footer -->
        <div class="p-6 bg-surface-soft/40 border-t border-border flex flex-col md:flex-row justify-between gap-6 text-xs">
            <div class="space-y-3 max-w-md">
                @if ($quotation->terms)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.terms') }}:</span>
                        <p class="text-text-secondary whitespace-pre-line text-[11px]">{{ $quotation->terms }}</p>
                    </div>
                @endif
                @if ($quotation->notes)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.notes') }}:</span>
                        <p class="text-text-secondary whitespace-pre-line text-[11px]">{{ $quotation->notes }}</p>
                    </div>
                @endif
            </div>

            <div class="w-full md:w-64 space-y-2">
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.subtotal') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($quotation->subtotal, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.discount') }}:</span>
                    <span class="font-mono text-danger font-bold" dir="ltr">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($quotation->discount_total, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.tax') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($quotation->tax_total, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
                <div class="flex justify-between py-2 border-t border-border text-base font-extrabold text-text-primary">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span class="font-mono text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($quotation->grand_total, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Modal -->


    @if(! $quotation->isDraft() && collect(['sales.document.share', 'sales.quote.view'])->every(fn ($permission) => auth()->user()->can($permission)))
        <livewire:financial-share-manager subject-type="quotation" :subject-id="$quotation->id" :key="'financial-share-'.$quotation->public_id" />
    @endif
</div>
