// Typed unsaved-form corrections only. Browser voice lives in the assistant launcher.
const register = () => window.Alpine.data('transactionCorrections', (wire, options) => {
    let generation = 0;
    const acknowledge = async (prepared, turn, transcript = false) => {
        if (typeof prepared !== 'string') return;
        if (turn !== generation) {
            if (transcript) await wire.discardPreparedAiTranscript(prepared);
            else await wire.discardPreparedAiInput(prepared);
            return;
        }
        // Inference leaves all form fields editable. Only the short local commit is protected from new input.
        const controls = [...(wire.$el?.querySelectorAll?.('input, select, textarea, button') || [])];
        const enabled = controls.filter(control => !control.disabled);
        enabled.forEach(control => { control.disabled = true; });
        try {
            if (transcript) await wire.applyPreparedAiTranscript(prepared);
            else await wire.applyPreparedAiInput(prepared);
        }
        finally { enabled.forEach(control => { control.disabled = false; }); }
    };
    return {
        busy: false, error: '',
        init() {
            this.leave = () => this.clear();
            this.manualEdit = (event) => {
                if (!this.busy) return;
                const binding = event.target?.getAttributeNames?.().find(name => name.startsWith('wire:model'));
                if (!binding || event.target.getAttribute(binding).startsWith('aiCorrection')) return;
                this.clear(); this.error = options.changedError || options.deliveryError;
            };
            wire.$el?.addEventListener('input', this.manualEdit, true);
            wire.$el?.addEventListener('change', this.manualEdit, true);
            window.addEventListener('pagehide', this.leave);
            document.addEventListener('livewire:navigating', this.leave);
        },
        async applyTyped(transcript = false, clarification = false) {
            if (this.busy) return;
            const turn = generation;
            this.busy = true; this.error = '';
            try {
                const prepared = transcript
                    ? (clarification ? await wire.prepareAiTranscriptClarification() : await wire.prepareAiTranscript())
                    : await wire.correctAiInput();
                await acknowledge(prepared, turn, transcript);
            } catch { if (turn === generation) this.error = options.deliveryError; }
            finally { if (turn === generation) this.busy = false; }
        },
        clear() {
            generation++;
            this.busy = false;
        },
        destroy() {
            this.clear();
            wire.$el?.removeEventListener('input', this.manualEdit, true);
            wire.$el?.removeEventListener('change', this.manualEdit, true);
            window.removeEventListener('pagehide', this.leave);
            document.removeEventListener('livewire:navigating', this.leave);
        },
    };
});

if (window.Alpine) register();
else document.addEventListener('alpine:init', register, { once: true });
