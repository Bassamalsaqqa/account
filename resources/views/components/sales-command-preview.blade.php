@props([
    'preview' => [],
    'invoice' => null,
    'currency' => 'ILS',
    'confirmAction' => 'confirmPost',
    'cancelAction' => 'cancelPreview',
    'cancelReturnId' => 'invoice-post-button',
])

@php
    $invoiceData = is_array($preview['invoice'] ?? null) ? $preview['invoice'] : [];
    $totalsData = is_array($preview['totals'] ?? null) ? $preview['totals'] : [];
    $linesData = is_array($preview['lines'] ?? null) ? $preview['lines'] : [];
    $stockData = is_array($preview['stock_availability'] ?? null) ? $preview['stock_availability'] : [];
    $stockProducts = is_array($stockData['products'] ?? null) ? $stockData['products'] : [];
    $blockers = is_array($preview['blockers'] ?? null) ? $preview['blockers'] : [];
    $warnings = is_array($preview['warnings'] ?? null) ? $preview['warnings'] : [];
    $hasBlockers = !empty($blockers);

    // Fail-closed validation: must be array with all required totals, currency, and rate keys
    $hasValidPreview = !empty($preview)
        && is_array($preview)
        && isset($totalsData['grand_total_currency'], $totalsData['subtotal_currency'], $totalsData['discount_total_currency'], $totalsData['tax_total_currency'])
        && isset($invoiceData['currency_code'], $invoiceData['exchange_rate'])
        && is_array($linesData)
        && is_array($stockData);

    if ($hasValidPreview) {
        $subtotal = (string) $totalsData['subtotal_currency'];
        $discountTotal = (string) $totalsData['discount_total_currency'];
        $taxTotal = (string) $totalsData['tax_total_currency'];
        $grandTotal = (string) $totalsData['grand_total_currency'];
        $currencyCode = (string) $invoiceData['currency_code'];
        $exchangeRate = (string) $invoiceData['exchange_rate'];
        $canExecute = ($preview['can_execute'] ?? true) && !$hasBlockers && !empty($preview['confirmation_token']);
    } elseif ($invoice !== null) {
        // Fallback only to real columns on actual SalesInvoice model
        $subtotal = (string) $invoice->subtotal_currency;
        $discountTotal = (string) $invoice->discount_total_currency;
        $taxTotal = (string) $invoice->tax_total_currency;
        $grandTotal = (string) $invoice->grand_total_currency;
        $currencyCode = (string) $invoice->currency_code;
        $exchangeRate = (string) $invoice->exchange_rate;
        $canExecute = false;
    } else {
        $subtotal = null;
        $discountTotal = null;
        $taxTotal = null;
        $grandTotal = null;
        $currencyCode = $currency;
        $exchangeRate = '';
        $canExecute = false;
    }

    $expiresAt = $preview['expires_at'] ?? null;
    $customer = $invoiceData['customer'] ?? ($invoice?->customer ? [
        'name_ar' => $invoice->customer->name_ar,
        'name_en' => $invoice->customer->name_en,
        'business_name' => $invoice->customer->business_name,
    ] : null);
    $warehouse = $invoiceData['warehouse'] ?? ($invoice?->warehouse ? [
        'name_ar' => $invoice->warehouse->name_ar,
        'name_en' => $invoice->warehouse->name_en,
    ] : null);

    $customerName = '';
    if ($customer) {
        $customerName = app()->getLocale() === 'en'
            ? ($customer['name_en'] ?: $customer['name_ar'])
            : ($customer['name_ar'] ?: $customer['name_en']);
        if (!empty($customer['business_name'])) {
            $customerName .= ' (' . $customer['business_name'] . ')';
        }
    }

    $warehouseName = '';
    if ($warehouse) {
        $warehouseName = app()->getLocale() === 'en'
            ? ($warehouse['name_en'] ?: $warehouse['name_ar'])
            : ($warehouse['name_ar'] ?: $warehouse['name_en']);
    }

    // Stock disclaimer fully translated in AR
    $stockDisclaimer = (app()->getLocale() === 'ar' || empty($stockData['disclaimer']))
        ? __('commands.stock_disclaimer')
        : (string) $stockData['disclaimer'];
@endphp

<div id="sales-command-preview-panel"
     role="region"
     aria-labelledby="sales-command-preview-heading"
     tabindex="-1"
     x-data="{
         returnFocusId: @js($cancelReturnId),
         init() { this.$nextTick(() => this.$el.focus()); },
         destroy() { document.getElementById(this.returnFocusId)?.focus(); }
     }"
     class="bg-white rounded-card border-2 border-primary/30 shadow-md p-5 space-y-5 transition-all">

    <!-- Header & Status (Static indicator badge avoiding pointless pulsing) -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pb-3 border-b border-border">
        <div>
            <div class="flex items-center gap-2">
                <span class="inline-block w-2.5 h-2.5 rounded-full bg-primary" aria-hidden="true"></span>
                <h2 id="sales-command-preview-heading" class="text-sm md:text-base font-bold text-text-primary">
                    {{ __('commands.preview_title') }}
                </h2>
            </div>
            <p class="text-xs text-text-secondary mt-0.5">
                {{ __('commands.preview_subtitle') }}
            </p>
        </div>

        @if ($expiresAt)
            <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-control bg-surface-soft border border-border text-[11px] text-text-muted">
                <span>⏱ {{ __('commands.token_expires_at') }}:</span>
                <span class="font-mono font-bold text-text-primary break-all" dir="ltr">{{ $expiresAt }}</span>
            </div>
        @endif
    </div>

    <!-- Missing / Malformed Preview Alert (Fail-Closed) -->
    @if (! $hasValidPreview)
        <div role="alert" class="p-3.5 rounded-control bg-danger-bg border border-danger/40 text-danger text-xs font-bold">
            {{ __('commands.preview_unavailable') }}
        </div>
    @endif

    <!-- Customer & Warehouse Server Identity -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-3 bg-surface-soft/40 rounded-control border border-border text-xs">
        <div>
            <span class="text-text-muted block text-[11px]">{{ __('commands.customer') }}</span>
            <span class="font-bold text-text-primary">{{ $customerName ?: '—' }}</span>
        </div>
        <div>
            <span class="text-text-muted block text-[11px]">{{ __('commands.warehouse') }}</span>
            <span class="font-bold text-text-primary">{{ $warehouseName ?: '—' }}</span>
        </div>
    </div>

    <!-- Prominent Stock Advisory Notice -->
    <div role="status" class="flex items-start gap-2.5 p-3 rounded-control bg-amber-50 border border-amber-200/80 text-amber-900 text-xs">
        <span class="text-amber-700 font-bold text-sm leading-none mt-0.5" aria-hidden="true">ℹ</span>
        <div class="space-y-0.5">
            <span class="font-bold block">{{ __('commands.advisory_stock_notice') }}</span>
            <p class="text-amber-800 text-[11px] leading-relaxed">{{ $stockDisclaimer }}</p>
        </div>
    </div>

    <!-- Blockers (if any) - Strict localized code or generic, never raw message leak -->
    @if ($hasBlockers)
        <div role="alert" aria-live="assertive" class="p-3.5 rounded-control bg-danger-bg border border-danger/40 text-danger text-xs space-y-1.5">
            <div class="font-bold flex items-center gap-1.5 text-xs">
                <span aria-hidden="true">🚫</span>
                <span>{{ __('commands.blockers') }} ({{ count($blockers) }})</span>
            </div>
            <ul class="list-disc list-inside ps-1 space-y-1 text-[11px] text-danger/90">
                @foreach ($blockers as $blocker)
                    @php
                        $code = is_array($blocker) ? ($blocker['code'] ?? null) : (is_string($blocker) ? $blocker : null);
                        $key = $code ? 'commands.code_' . $code : null;
                        $msg = ($key && \Illuminate\Support\Facades\Lang::has($key))
                            ? __($key)
                            : __('commands.code_generic');
                    @endphp
                    <li>{{ $msg }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Warnings (if any) - Strict localized code or generic, never raw message leak -->
    @if (!empty($warnings))
        <div role="status" class="p-3.5 rounded-control bg-warning/10 border border-warning/30 text-text-primary text-xs space-y-1.5">
            <div class="font-bold text-warning flex items-center gap-1.5 text-xs">
                <span aria-hidden="true">⚠️</span>
                <span>{{ __('commands.warnings') }} ({{ count($warnings) }})</span>
            </div>
            <ul class="list-disc list-inside ps-1 space-y-1 text-[11px] text-text-secondary">
                @foreach ($warnings as $warning)
                    @php
                        $code = is_array($warning) ? ($warning['code'] ?? null) : (is_string($warning) ? $warning : null);
                        $key = $code ? 'commands.code_' . $code : null;
                        $msg = ($key && \Illuminate\Support\Facades\Lang::has($key))
                            ? __($key)
                            : __('commands.code_generic');
                    @endphp
                    <li>{{ $msg }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Totals & EXACT Exchange Rate Summary Grid (Responsive, break-all for long numbers) -->
    @if ($grandTotal !== null)
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-5 gap-3 p-3.5 bg-surface-soft/60 rounded-control border border-border text-xs">
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('commands.currency') }}</span>
                <span class="font-bold font-mono text-text-primary break-all" dir="ltr">{{ $currencyCode }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('commands.exchange_rate') }}</span>
                <span class="font-bold font-mono text-text-primary break-all" dir="ltr">{{ $exchangeRate }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('commands.subtotal') }}</span>
                <span class="font-bold font-mono text-text-primary break-all" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($subtotal, $currencyCode) }}</span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('commands.discount_total') }} / {{ __('commands.tax_total') }}</span>
                <span class="font-bold font-mono text-text-primary break-all" dir="ltr">
                    <span class="text-danger">-{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($discountTotal, $currencyCode) }}</span> /
                    <span>+{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($taxTotal, $currencyCode) }}</span>
                </span>
            </div>
            <div>
                <span class="text-text-muted block text-[11px]">{{ __('commands.grand_total') }}</span>
                <span class="font-bold font-mono text-primary text-sm break-all" dir="ltr">
                    {{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($grandTotal, $currencyCode) }} {{ $currencyCode }}
                </span>
            </div>
        </div>
    @endif

    <!-- Stock Availability & FEFO Allocations -->
    @if (!empty($stockProducts))
        <div class="space-y-3 pt-3 border-t border-border">
            <h3 class="text-xs font-bold text-text-primary">
                {{ __('commands.stock_allocation') }}
            </h3>

            <div class="space-y-2">
                @foreach ($stockProducts as $productStock)
                    <div class="p-3 bg-surface-soft/60 rounded-control border border-border space-y-2 text-xs">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-1">
                            <span class="font-bold text-text-primary">{{ $productStock['product_name'] }}</span>
                            <div class="flex items-center gap-3 text-[11px] font-mono break-all" dir="ltr">
                                <span>{{ __('commands.quantity_needed') }}: <strong class="text-text-primary">{{ $productStock['quantity_needed_base'] }}</strong></span>
                                <span>| {{ __('commands.quantity_available') }}: <strong class="text-text-primary">{{ $productStock['quantity_available_base'] }}</strong></span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $productStock['is_sufficient'] ? 'bg-success-bg text-success' : 'bg-danger-bg text-danger' }}">
                                    {{ $productStock['is_sufficient'] ? __('commands.sufficient_stock') : __('commands.insufficient_stock') }}
                                </span>
                            </div>
                        </div>

                        @if (!empty($productStock['fefo_allocations']))
                            <div class="mt-1 pt-1.5 border-t border-border/50 text-[11px] space-y-1">
                                <span class="text-text-muted font-bold block">{{ __('commands.lot_number') }}:</span>
                                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-2">
                                    @foreach ($productStock['fefo_allocations'] as $alloc)
                                        <div class="p-1.5 bg-white rounded-control border border-border/60 text-[10px] font-mono break-all" dir="ltr">
                                            <div>{{ __('commands.lot') }}: <strong class="text-text-primary">{{ $alloc['lot_number'] }}</strong></div>
                                            @if (!empty($alloc['expiry_date']))
                                                <div class="text-text-muted">{{ __('commands.expiry') }}: {{ $alloc['expiry_date'] }}</div>
                                            @endif
                                            <div class="text-primary font-bold">{{ __('commands.qty') }}: {{ $alloc['quantity'] }}</div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- Line Items Table -->
    <div class="space-y-2">
        <h3 class="text-xs font-bold text-text-primary">
            {{ __('commands.lines_summary') }}
        </h3>
        <div class="overflow-x-auto rounded-control border border-border">
            <table class="w-full text-start text-xs border-collapse">
                <thead>
                    <tr class="bg-surface-soft text-text-muted font-bold text-[11px] border-b border-border">
                        <th class="py-2 px-3 text-start">#</th>
                        <th class="py-2 px-3 text-start">{{ __('commands.product') }}</th>
                        <th class="py-2 px-3 text-start">{{ __('commands.unit') }}</th>
                        <th class="py-2 px-3 text-start">{{ __('commands.quantity') }}</th>
                        <th class="py-2 px-3 text-start">{{ __('commands.unit_price') }}</th>
                        <th class="py-2 px-3 text-end">{{ __('commands.line_total') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($linesData as $idx => $line)
                        @php
                            $lineNumber = $line['line_number'] ?? ($idx + 1);
                            $productName = app()->getLocale() === 'en'
                                ? ($line['product_name_en'] ?? $line['item_description'] ?? '')
                                : ($line['product_name_ar'] ?? $line['item_description'] ?? '');
                            $description = $line['item_description'] ?? '';
                            $unitName = app()->getLocale() === 'en'
                                ? ($line['unit_name_en'] ?? '')
                                : ($line['unit_name_ar'] ?? '');
                            $quantity = (string) ($line['quantity'] ?? '');
                            $unitPrice = (string) ($line['unit_price'] ?? '');
                            $lineTotal = (string) ($line['line_total'] ?? '');
                        @endphp
                        <tr class="hover:bg-surface-soft/40">
                            <td class="py-2 px-3 text-text-muted">{{ $lineNumber }}</td>
                            <td class="py-2 px-3">
                                <span class="font-bold text-text-primary">{{ $description ?: $productName }}</span>
                                @if (!empty($line['product_sku']))
                                    <span class="text-[10px] text-text-muted font-mono block">{{ __('commands.sku') }}: {{ $line['product_sku'] }}</span>
                                @endif
                            </td>
                            <td class="py-2 px-3 text-text-muted">{{ $unitName ?: '—' }}</td>
                            <td class="py-2 px-3 font-mono break-all" dir="ltr">{{ $quantity }}</td>
                            <td class="py-2 px-3 font-mono break-all" dir="ltr">{{ $unitPrice !== '' ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($unitPrice, $currencyCode) : '—' }}</td>
                            <td class="py-2 px-3 font-mono font-bold text-end break-all" dir="ltr">{{ $lineTotal !== '' ? \App\Domain\Sales\Formatters\SalesMoneyFormatter::formatCurrency($lineTotal, $currencyCode) : '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-3 px-3 text-center text-text-muted">{{ __('commands.no_stock_allocation') }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if (isset($preview['settlement']))
        @include('livewire.partials.immediate-sale-review', ['review' => $preview['settlement']])
    @endif

    <!-- Action Buttons with Live Status and Focus Restoration -->
    <div class="flex items-center justify-end gap-3 pt-3 border-t border-border">
        <button type="button"
                wire:click="{{ $cancelAction }}"
                @click="if (returnFocusId) { setTimeout(() => document.getElementById(returnFocusId)?.focus(), 50); }"
                wire:loading.attr="disabled"
                class="min-h-11 px-4 py-2 rounded-control border border-border text-xs font-bold text-text-secondary hover:bg-surface-soft transition-colors cursor-pointer">
            {{ __('commands.cancel_preview') }}
        </button>

        @if ($canExecute && $hasValidPreview)
            <button type="button"
                    wire:click="{{ $confirmAction }}"
                    wire:loading.attr="disabled"
                    class="min-h-11 px-5 py-2 rounded-control bg-primary text-white text-xs font-bold hover:bg-primary-hover transition-colors shadow-sm cursor-pointer flex items-center gap-2">
                <span wire:loading wire:target="{{ $confirmAction }}" class="inline-block w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin" aria-hidden="true"></span>
                <span>⚡ {{ __('commands.confirm_post') }}</span>
            </button>
        @else
            <button type="button"
                    disabled
                    aria-disabled="true"
                    title="{{ __('commands.cannot_confirm_blockers') }}"
                    class="min-h-11 px-5 py-2 rounded-control bg-slate-200 text-slate-500 text-xs font-bold cursor-not-allowed">
                ⚡ {{ __('commands.confirm_post') }}
            </button>
        @endif
    </div>
</div>
