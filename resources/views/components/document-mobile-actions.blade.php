@props(['total', 'currency', 'currencyScale' => 2, 'valid' => true, 'canConfirm' => false, 'confirmLabel' => null])
<div class="mobile-document-actions md:hidden fixed inset-x-0 z-30 border-t border-border-control bg-surface-soft px-3 py-2 shadow-lg" style="bottom: var(--mobile-nav-height, calc(4.5rem + env(safe-area-inset-bottom)));">
    <div class="mx-auto flex max-w-lg flex-wrap items-center justify-between gap-2">
        <div class="min-w-16 flex-1">
            <span class="block text-xs text-text-secondary">{{ __('mobile.grand_total') }}</span>
            @if ($valid)
                <bdi data-document-total class="block break-all font-mono text-sm font-bold text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($total, $currency) }} {{ $currency }}</bdi>
            @else
                <span role="status" title="{{ __('camera.totals_unavailable') }}" class="text-xs text-text-secondary">{{ __('mobile.total_unavailable_short') }}</span>
            @endif
        </div>
        <div class="flex shrink-0 gap-2">
            <button type="button" data-row-mutation-action @click="handleSaveDraft()" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" @class(['min-h-11 rounded-control border px-2 text-sm font-semibold focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50', 'border-border-control bg-surface text-text-primary hover:bg-canvas' => $canConfirm, 'border-primary bg-primary text-white hover:bg-primary-hover' => ! $canConfirm])>{{ __('mobile.save_draft') }}</button>
            @if ($canConfirm)
                <button type="button" data-row-mutation-action data-mobile-confirm @click="if (!saving && !window.isDocumentBlocked($wire) && ![...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0)) { saving = true; window.enqueueDocumentOperation($wire, 'saveAndConfirm').catch(() => { operationFailed = true; }).finally(() => saving = false); }" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 rounded-control bg-primary px-2 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50">{{ $confirmLabel }}</button>
            @endif
        </div>
    </div>
</div>
