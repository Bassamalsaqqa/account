// Only browser actions on an explicitly issued link; never issues or sends a document.
export function financialShareControls(wire, browser = globalThis.navigator) {
    let generation = 0;
    return {
        busy: false, feedback: '',
        init() { wire.$watch('url', () => this.resetFeedback()); },
        resetFeedback() { generation++; this.feedback = ''; },
        async copy(url) {
            if (this.busy || !url) return;
            this.resetFeedback();
            const turn = generation;
            this.busy = true;
            try {
                if (!browser?.clipboard?.writeText) throw new Error('Clipboard unavailable');
                await browser.clipboard.writeText(url);
                if (turn === generation) this.feedback = 'copied';
            } catch {
                if (turn === generation) this.feedback = 'copy-failed';
            } finally { this.busy = false; }
        },
        async share(data) {
            if (this.busy || !data.url) return;
            this.resetFeedback();
            const turn = generation;
            this.busy = true;
            try {
                if (typeof browser?.share !== 'function') throw new Error('Sharing unavailable');
                await browser.share(data);
                if (turn === generation) this.feedback = 'shared';
            } catch (error) {
                if (turn === generation && error?.name !== 'AbortError') this.feedback = 'share-failed';
            } finally { this.busy = false; }
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('financialShareControls', financialShareControls);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
