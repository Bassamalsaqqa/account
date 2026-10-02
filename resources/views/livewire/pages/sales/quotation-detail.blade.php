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
                    <a href="{{ route('customers.show', $quotation->customer->public_id) }}" class="font-bold text-text-primary hover:text-primary">
                        {{ $quotation->customer->displayName() }}
                    </a>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                @if ($canConvert)
                    <button type="button"
                            wire:click="convertToInvoice"
                            class="px-3 py-1.5 rounded-control bg-success text-white text-xs font-bold hover:opacity-90 transition-opacity shadow-xs">
                        ⚡ {{ __('sales.convert_to_invoice') }}
                    </button>
                @endif

                @if ($canSend)
                    <button type="button"
                            wire:click="markAsSent"
                            class="px-3 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs">
                        {{ __('sales.mark_as_sent') }}
                    </button>
                @endif

                @if ($quotation->status === 'sent')
                    <button type="button"
                            wire:click="markAsAccepted"
                            class="px-3 py-1.5 rounded-control bg-success-bg text-success border border-success/30 text-xs font-bold hover:bg-success hover:text-white transition-colors">
                        ✓ {{ __('sales.mark_as_accepted') }}
                    </button>
                    <button type="button"
                            wire:click="markAsRejected"
                            class="px-3 py-1.5 rounded-control bg-danger-bg text-danger border border-danger/30 text-xs font-bold hover:bg-danger hover:text-white transition-colors">
                        ✕ {{ __('sales.mark_as_rejected') }}
                    </button>
                @endif

                @if ($canEdit)
                    <a href="{{ route('quotations.edit', $quotation->public_id) }}"
                       class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                        {{ __('sales.edit_quotation') }}
                    </a>
                @endif

                <a href="{{ route('pdf.quotation', $quotation->public_id) }}"
                   target="_blank"
                   class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors flex items-center gap-1">
                    📄 {{ __('sales.print') }}
                </a>

                @if ($canShare)
                    <button type="button"
                            wire:click="$set('showShareModal', true)"
                            class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors flex items-center gap-1">
                        🔗 {{ __('sales.share') }}
                    </button>
                @endif

                <a href="{{ route('quotations.index') }}"
                   class="px-3 py-1.5 rounded-control bg-surface-soft text-text-secondary hover:text-text-primary text-xs font-bold transition-colors">
                    {{ __('sales.back') }}
                </a>
            </div>
        </div>

        @if ($shareUrl)
        <button type="button" wire:click="revokeShareLink" class="text-xs text-danger font-bold">{{ __('sales.revoke_share') }}</button>
            <div class="p-3 bg-primary-50 rounded-control border border-primary/20 flex items-center justify-between gap-2 text-xs">
                <div class="flex items-center gap-2 truncate">
                    <span class="font-bold text-primary">{{ __('sales.share_link') }}:</span>
                    <span class="font-mono text-text-secondary truncate" dir="ltr">{{ $shareUrl }}</span>
                </div>
                <button type="button"
                        onclick="navigator.clipboard.writeText('{{ $shareUrl }}'); alert('{{ __('sales.copy_link') }}');"
                        class="px-2.5 py-1 rounded-control bg-primary text-white font-bold text-[11px] shrink-0">
                    {{ __('sales.copy_link') }}
                </button>
            </div>
        @endif

        <!-- Document Metadata Details -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $quotation->issue_date ? $quotation->issue_date->toDateString() : '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.valid_until') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $quotation->expiry_date ? $quotation->expiry_date->toDateString() : '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.currency') }}</span>
                <span class="font-bold text-text-primary">{{ $quotation->currency_code }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.exchange_rate') }}</span>
                <span class="font-mono text-text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($quotation->exchange_rate, 4) }}</span>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('sales.quotation_details') }}
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-start text-xs border-collapse">
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
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->unit_price, $quotation->currency_code) }}
                            </td>
                            <td class="py-2.5 px-4 font-mono text-danger" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->discount_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->discount_amount, $quotation->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->tax_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->tax_amount, $quotation->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono font-bold text-end" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->line_total, $quotation->currency_code) }}
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
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($quotation->subtotal, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.discount') }}:</span>
                    <span class="font-mono text-danger font-bold" dir="ltr">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($quotation->discount_total, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.tax') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($quotation->tax_total, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
                <div class="flex justify-between py-2 border-t border-border text-base font-extrabold text-text-primary">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span class="font-mono text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($quotation->grand_total, $quotation->currency_code) }} {{ $quotation->currency_code }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Share Modal -->
    @if ($showShareModal)
        <div class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-card border border-border shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="font-bold text-base text-text-primary">
                    {{ __('sales.public_share') }}
                </h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.share_expires_in') }}</label>
                        <input type="number" wire:model="shareExpiryDays" class="w-full h-8 px-2 rounded-control border border-border" />
                    </div>
                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.optional_password') }}</label>
                        <input type="password" wire:model="sharePassword" class="w-full h-8 px-2 rounded-control border border-border" />
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <button type="button" wire:click="$set('showShareModal', false)" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold">
                        {{ __('sales.cancel') }}
                    </button>
                    <button type="button" wire:click="createShareLink" class="px-4 py-1.5 rounded-control bg-primary text-white text-xs font-bold">
                        {{ __('sales.create_share') }}
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
