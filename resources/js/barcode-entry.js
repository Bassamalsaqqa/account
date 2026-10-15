import { enqueueDocumentOperation } from './document-operations.js';

export function barcodeEntry(wire) {
    return {
        entry: '', scanned: false, pending: 0, feedback: '',
        async submit() {
            const code = this.entry.trim();
            if (!code || this.pending >= 10) return;
            this.entry = '';
            this.pending++;
            this.scanned = false;
            this.feedback = '';
            try {
                const result = await enqueueDocumentOperation(wire, 'scanCameraBarcode', [code]);
                this.scanned = Boolean(result?.ok);
                if (!result?.ok) {
                    this.feedback = result?.message || '';
                    if (!this.entry && this.pending === 1) this.entry = code;
                }
            } catch {
                if (!this.entry && this.pending === 1) this.entry = code;
                this.feedback = this.$el.dataset.networkError;
            } finally { this.pending--; }
        },
        focusInput() {
            if (![...document.querySelectorAll('[role="dialog"],dialog[open]')].some(el => el.getClientRects().length)) this.$refs.scanner?.focus();
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('barcodeEntry', barcodeEntry);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
