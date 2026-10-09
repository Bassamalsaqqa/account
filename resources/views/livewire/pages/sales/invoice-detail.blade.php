<div class="space-y-6">
    <!-- Header Card -->
    <div class="bg-white rounded-card border border-border shadow-xs p-6 space-y-4">
        <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-mono font-bold tracking-tight text-text-primary" dir="ltr">
                        {{ $invoice->invoice_number ?? __('sales.draft') }}
                    </h1>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                        @if ($invoice->status === 'posted') bg-success-bg text-success
                        @elseif ($invoice->status === 'void') bg-danger-bg text-danger
                        @else bg-slate-100 text-slate-600 @endif">
                        {{ __('sales.' . $invoice->status) }}
                    </span>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold
                        @if ($paymentStatus === 'paid') bg-success-bg text-success
                        @elseif ($paymentStatus === 'partially_paid') bg-warning-bg text-warning
                        @elseif ($paymentStatus === 'credit') bg-primary-50 text-primary
                        @else bg-slate-100 text-slate-600 @endif">
                        {{ __('sales.' . $paymentStatus) }}
                    </span>
                </div>
                <div class="text-xs text-text-secondary mt-1">
                    {{ __('sales.customer') }}:
                    <a href="{{ route('customers.show', $invoice->customer->public_id) }}" class="font-bold text-text-primary hover:text-primary">
                        {{ $invoice->customer->displayName() }}
                    </a>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex flex-wrap items-center gap-2">
                @if ($canPost)
                    <button type="button"
                            wire:click="postInvoice"
                            wire:confirm="{{ __('sales.confirm_post_invoice') }}"
                            class="px-3.5 py-1.5 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-xs cursor-pointer">
                        ⚡ {{ __('sales.post') }}
                    </button>
                @endif

                @if ($canEditDraft)
                    <a href="{{ route('invoices.edit', $invoice->public_id) }}"
                       class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                        {{ __('sales.edit_invoice_draft') }}
                    </a>
                @endif

                @if ($canPay && \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($outstanding))
                    <a href="{{ route('payments.create', ['customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id]) }}"
                       class="px-3 py-1.5 rounded-control bg-success text-white text-xs font-bold hover:opacity-90 transition-opacity shadow-xs">
                        + {{ __('sales.new_payment') }}
                    </a>
                @endif

                @if ($canReturn)
                    <a href="{{ route('returns.create', ['invoice_id' => $invoice->id]) }}"
                       class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors">
                        ↩ {{ __('sales.new_return') }}
                    </a>
                @endif

                @if ($canVoid)
                    <button type="button"
                            wire:click="$set('showVoidModal', true)"
                            class="px-3 py-1.5 rounded-control bg-danger-bg text-danger border border-danger/30 text-xs font-bold hover:bg-danger hover:text-white transition-colors cursor-pointer">
                        ✕ {{ __('sales.void') }}
                    </button>
                @endif

                <x-document-actions route-name="pdf.invoice" :parameters="['publicId' => $invoice->public_id]" :permissions="['sales.document.pdf', 'sales.invoice.view']" />

                @if ($canShare)
                    <button type="button"
                            wire:click="$set('showShareModal', true)"
                            class="px-3 py-1.5 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors flex items-center gap-1">
                        🔗 {{ __('sales.share') }}
                    </button>
                @endif

                <a href="{{ route('invoices.index') }}"
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

        <!-- Metadata Summary -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 pt-4 border-t border-border text-xs">
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $invoice->issue_date ? $invoice->issue_date->toDateString() : '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.due_date') }}</span>
                <span class="font-bold text-text-primary" dir="ltr">{{ $invoice->due_date ? $invoice->due_date->toDateString() : '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.warehouse') }}</span>
                <span class="font-bold text-text-primary">{{ $invoice->warehouse?->displayName() ?? '—' }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.currency') }}</span>
                <span class="font-bold text-text-primary">{{ $invoice->currency_code }} ({{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatQuantity($invoice->exchange_rate, 4) }})</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('sales.outstanding_amount') }}</span>
                <span class="font-mono font-bold {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($outstanding) ? 'text-danger' : 'text-text-primary' }}" dir="ltr">
                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($outstanding, $invoice->currency_code) }} {{ $invoice->currency_code }}
                </span>
            </div>
        </div>
    </div>

    <!-- Lines Table -->
    <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
        <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
            {{ __('sales.invoice_details') }}
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
                        @if ($canViewCost)
                            <th class="py-2.5 px-4 text-end">{{ __('sales.cogs') }} (Base)</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @foreach ($invoice->lines as $line)
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
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->unit_price, $invoice->currency_code) }}
                            </td>
                            <td class="py-2.5 px-4 font-mono text-danger" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->discount_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->discount_amount, $invoice->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::isPositive($line->tax_amount) ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->tax_amount, $invoice->currency_code) : '—' }}
                            </td>
                            <td class="py-2.5 px-4 font-mono font-bold text-end" dir="ltr">
                                {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->line_total, $invoice->currency_code) }}
                            </td>
                            @if ($canViewCost)
                                <td class="py-2.5 px-4 font-mono text-end text-text-muted" dir="ltr">
                                    {{ $line->cogs_total_base !== null ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($line->cogs_total_base, $invoice->company->base_currency_code) : '—' }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <!-- Totals Footer -->
        <div class="p-6 bg-surface-soft/40 border-t border-border flex flex-col md:flex-row justify-between gap-6 text-xs">
            <div class="space-y-3 max-w-md">
                @if ($invoice->terms)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.terms') }}:</span>
                        <p class="text-text-secondary whitespace-pre-line text-[11px]">{{ $invoice->terms }}</p>
                    </div>
                @endif
                @if ($invoice->notes)
                    <div>
                        <span class="font-bold text-text-primary block">{{ __('sales.notes') }}:</span>
                        <p class="text-text-secondary whitespace-pre-line text-[11px]">{{ $invoice->notes }}</p>
                    </div>
                @endif
                @if ($invoice->void_reason)
                    <div class="p-2.5 bg-danger-bg border border-danger/20 rounded-control text-danger">
                        <span class="font-bold block">{{ __('sales.void_reason') }}:</span>
                        <p class="text-[11px] mt-0.5">{{ $invoice->void_reason }}</p>
                    </div>
                @endif
            </div>

            <div class="w-full md:w-64 space-y-2">
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.subtotal') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($invoice->subtotal, $invoice->currency_code) }} {{ $invoice->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.discount') }}:</span>
                    <span class="font-mono text-danger font-bold" dir="ltr">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($invoice->discount_total, $invoice->currency_code) }} {{ $invoice->currency_code }}</span>
                </div>
                <div class="flex justify-between py-1 border-b border-border/50">
                    <span class="text-text-muted">{{ __('sales.tax') }}:</span>
                    <span class="font-mono font-bold" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($invoice->tax_total, $invoice->currency_code) }} {{ $invoice->currency_code }}</span>
                </div>
                <div class="flex justify-between py-2 border-t border-border text-base font-extrabold text-text-primary">
                    <span>{{ __('sales.grand_total') }}:</span>
                    <span class="font-mono text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($invoice->grand_total, $invoice->currency_code) }} {{ $invoice->currency_code }}</span>
                </div>
                @if ($invoice->currency_code !== $invoice->company->base_currency_code)
                    <div class="flex justify-between text-[11px] text-text-muted">
                        <span>Base ({{ $invoice->company->base_currency_code }}):</span>
                        <span class="font-mono" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($invoice->base_grand_total, $invoice->company->base_currency_code) }}</span>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <!-- Linked Payments & Allocations (if any) -->
    @if ($invoice->allocations->isNotEmpty())
        <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
            <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
                {{ __('sales.tab_payments') }}
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                            <th class="py-2.5 px-4 text-start">{{ __('sales.payment_number') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('sales.date') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('sales.allocated') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('sales.realized_fx') }}</th>
                            <th class="py-2.5 px-4 text-end">{{ __('sales.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($invoice->allocations as $alloc)
                            <tr>
                                <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('payments.show', $alloc->payment->public_id) }}" class="text-primary hover:underline">
                                        {{ $alloc->payment->payment_number }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-4" dir="ltr">{{ $alloc->payment?->payment_date ? $alloc->payment->payment_date->toDateString() : '—' }}</td>
                                <td class="py-2.5 px-4 font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($alloc->payment_currency_amount, $alloc->payment->currency_code) }} {{ $alloc->payment->currency_code }}
                                </td>
                                <td class="py-2.5 px-4 font-mono" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::format($alloc->realized_fx_gain_loss_base) }}
                                </td>
                                <td class="py-2.5 px-4 text-end">
                                    <a href="{{ route('payments.show', $alloc->payment->public_id) }}" class="text-primary hover:underline font-bold text-[11px]">
                                         {{ __('sales.payment_details') }}
                                     </a>
                                 </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Linked Returns (if any) -->
    @if ($invoice->returns->isNotEmpty())
        <div class="bg-white rounded-card border border-border shadow-xs overflow-hidden">
            <div class="p-4 border-b border-border font-bold text-sm text-text-primary">
                {{ __('sales.tab_returns') }}
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-start text-xs border-collapse">
                    <thead>
                        <tr class="border-b border-border bg-surface-soft text-text-muted font-bold text-[11px] uppercase">
                            <th class="py-2.5 px-4 text-start">{{ __('sales.return_number') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('sales.date') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('sales.grand_total') }}</th>
                            <th class="py-2.5 px-4 text-start">{{ __('sales.status') }}</th>
                            <th class="py-2.5 px-4 text-end">{{ __('sales.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @foreach ($invoice->returns as $ret)
                            <tr>
                                <td class="py-2.5 px-4 font-mono font-bold" dir="ltr">
                                    <a href="{{ route('returns.show', $ret->public_id) }}" class="text-primary hover:underline">
                                        {{ $ret->return_number ?? __('sales.draft') }}
                                    </a>
                                </td>
                                <td class="py-2.5 px-4" dir="ltr">{{ $ret->issue_date ? $ret->issue_date->toDateString() : '—' }}</td>
                                <td class="py-2.5 px-4 font-bold" dir="ltr">
                                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($ret->grand_total, $ret->currency_code) }} {{ $ret->currency_code }}
                                </td>
                                <td class="py-2.5 px-4">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $ret->status === 'posted' ? 'bg-success-bg text-success' : 'bg-slate-100 text-slate-600' }}">
                                        {{ __('sales.' . $ret->status) }}
                                    </span>
                                </td>
                                <td class="py-2.5 px-4 text-end">
                                    <a href="{{ route('returns.show', $ret->public_id) }}" class="text-primary hover:underline font-bold text-[11px]">
                                        {{ __('sales.return_details') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Void Modal -->
    @if ($showVoidModal)
        <div class="fixed inset-0 z-50 bg-slate-900/50 backdrop-blur-xs flex items-center justify-center p-4">
            <div class="bg-white rounded-card border border-border shadow-xl max-w-md w-full p-6 space-y-4">
                <h3 class="font-bold text-base text-danger">
                    {{ __('sales.confirm_void_invoice') }}
                </h3>
                <div class="space-y-3 text-xs">
                    <div>
                        <label class="block font-bold text-text-primary mb-1">{{ __('sales.void_reason') }} <span class="text-danger">*</span></label>
                        <textarea wire:model="voidReason" rows="3" required class="w-full p-2.5 rounded-control border border-border text-xs"></textarea>
                        @error('voidReason') <span class="text-danger text-[11px] block mt-1">{{ $message }}</span> @enderror
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-border">
                    <button type="button" wire:click="$set('showVoidModal', false)" class="px-3 py-1.5 rounded-control border border-border text-xs font-bold">
                        {{ __('sales.cancel') }}
                    </button>
                    <button type="button" wire:click="voidInvoice" class="px-4 py-1.5 rounded-control bg-danger text-white text-xs font-bold">
                        {{ __('sales.void') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

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
