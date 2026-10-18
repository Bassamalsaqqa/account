<div x-data="{ blocked: window.isDocumentBlocked($wire), busy: window.isDocumentBusy($wire), recovering: false,
        async review() {
            this.recovering = true;
            try { await window.resyncDocumentQueue($wire, true); this.blocked = false; }
            finally { this.recovering = false; }
        }
    }"
    @document-operation-state.window="if ($event.detail.id === $wire.$id) { blocked = $event.detail.blocked; busy = $event.detail.busy; }"
    x-cloak x-show="blocked" role="alert" class="rounded-control border border-danger/30 bg-danger-bg p-3 text-sm text-danger space-y-2">
    <p>{{ __('mobile.operation_review') }}</p>
    <button type="button" @click="review().catch(() => {})" :disabled="busy || recovering"
            class="min-h-11 rounded-control border border-danger px-3 py-2 font-semibold disabled:opacity-50">
        {{ __('mobile.operation_discard') }}
    </button>
</div>
