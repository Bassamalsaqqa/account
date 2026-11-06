@props(['total', 'currency', 'valid' => true, 'canConfirm' => false, 'confirmLabel' => null])
<div class="mobile-document-actions md:hidden fixed inset-x-0 z-30 border-t border-border bg-surface px-3 py-2 shadow-lg" style="bottom: var(--mobile-nav-height, calc(4.5rem + env(safe-area-inset-bottom)));">
    <div class="mx-auto max-w-lg space-y-2">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <span class="block text-xs text-text-secondary">{{ __('mobile.grand_total') }}</span>
                @if ($valid)
                    <bdi data-document-total class="block truncate font-mono text-sm font-bold text-primary" dir="ltr">{{ \App\Domain\Sales\Formatters\SalesMoneyFormatter::displayMoney($total, $currency) }} {{ $currency }}</bdi>
                @else
                    <span role="status" class="text-xs text-text-secondary">{{ __('camera.totals_unavailable') }}</span>
                @endif
            </div>
            <div class="flex gap-2">
                <button type="button" @click="$dispatch('mobile-camera-open')" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" aria-label="{{ __('mobile.scan') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border bg-surface-soft text-text-primary hover:bg-primary-50 focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"><x-icon name="barcode" class="h-5 w-5" /></button>
                <button type="button" @click="handleAddLine()" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" aria-label="{{ __('mobile.add_item') }}" class="inline-flex min-h-11 min-w-11 items-center justify-center rounded-control border border-border bg-surface-soft text-text-primary hover:bg-primary-50 focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50"><x-icon name="plus" class="h-5 w-5" /></button>
            </div>
        </div>
        <div class="flex gap-2">
            <button type="button" @click="handleSaveDraft()" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-0 flex-1 rounded-control border border-primary px-3 text-sm font-semibold text-primary hover:bg-primary-50 focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50">{{ __('mobile.save_draft') }}</button>
            @if ($canConfirm)
                <button type="button" data-mobile-confirm @click="if (!saving && !window.isDocumentBlocked($wire) && ![...document.querySelectorAll('[role=dialog]')].some(el => el.getClientRects().length > 0)) { saving = true; window.enqueueDocumentOperation($wire, 'saveAndConfirm').catch(() => { operationFailed = true; }).finally(() => saving = false); }" :disabled="saving || window.isDocumentBlocked($wire)" wire:loading.attr="disabled" class="min-h-11 min-w-0 flex-1 rounded-control bg-primary px-3 text-sm font-semibold text-white hover:bg-primary-hover focus-visible:ring-2 focus-visible:ring-primary disabled:opacity-50">{{ $confirmLabel }}</button>
            @endif
        </div>
    </div>
</div>
