export function globalSearchComponent() {
    let generation = 0;
    let opening = false;
    let destroyed = false;
    // Keep server lifecycle calls in user order; dismiss locally without waiting
    // for the network. A stale close must settle before a newer open is sent.
    let lifecycle = Promise.resolve();
    return {
        clientOpen: false, activeIndex: -1, activeId: null, term: '',
        searching: false, dirty: false, failed: false, offline: false,
        previouslyFocused: null,
        init() {
            this.$wire.$watch('isOpen', value => {
                if (!value && !opening && this.clientOpen) {
                    this.dismiss();
                }
            });
        },
        destroy() { destroyed = true; this.dismiss(false); },
        isModalVisible() { return this.clientOpen; },
        handleWindowKeydown(event) {
            if (event.repeat || !(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k') return;
            if ([...document.querySelectorAll('dialog[open],[role="dialog"]')].some(el => el.id !== 'global-search-dialog' && el.getClientRects().length)) return;
            event.preventDefault();
            this.clientOpen || opening ? this.closeModal() : this.openModal();
        },
        async openModal() {
            if (this.clientOpen || opening || destroyed) return;
            const version = ++generation;
            opening = true;
            this.previouslyFocused = document.activeElement;
            this.failed = false;
            this.offline = !navigator.onLine;
            try {
                lifecycle = lifecycle.catch(() => {}).then(() => {
                    if (!destroyed && version === generation) return this.$wire.open();
                });
                await lifecycle;
                if (destroyed || version !== generation) return;
                this.clientOpen = this.$wire.isOpen;
                this.$nextTick(() => {
                    if (destroyed || version !== generation || !this.clientOpen) return;
                    // Native modal inertness is owned by the browser, not sibling
                    // aria-hidden mutations that can race Livewire morphs.
                    this.$refs.dialog.showModal();
                    this.$refs.searchInput?.focus();
                });
            } catch { if (!destroyed && version === generation) this.failed = true; }
            finally { if (version === generation) opening = false; }
        },
        dismiss(restoreFocus = true) {
            generation++;
            opening = false;
            this.clientOpen = false;
            this.term = '';
            this.resetActive();
            if (this.$refs.dialog?.open) this.$refs.dialog.close();
            const trigger = this.previouslyFocused;
            if (restoreFocus && trigger?.isConnected && trigger.getClientRects().length
                && !trigger.closest('[inert], [aria-hidden="true"]')) trigger.focus();
        },
        async closeModal() {
            this.dismiss();
            lifecycle = lifecycle.catch(() => {}).then(() => this.$wire.close());
            try {
                await lifecycle;
            } catch {}
        },
        queryChanged() { generation++; this.dirty = true; this.failed = false; this.resetActive(); },
        async search() {
            if (this.searching || !this.clientOpen) return;
            const version = generation;
            const term = this.term;
            this.offline = !navigator.onLine;
            if (this.offline) return;
            this.searching = true;
            try {
                await this.$wire.$set('query', term);
                if (version === generation) this.dirty = false;
            } catch { this.failed = true; }
            finally {
                this.searching = false;
                if (version !== generation && this.clientOpen) this.search();
            }
        },
        resetActive() { this.activeIndex = -1; this.activeId = null; },
        getItems() {
            if (this.dirty || this.searching || this.failed || !this.clientOpen) return [];
            return [...document.querySelectorAll('#global-search-results-list a[role="option"]')].filter(el => el.getClientRects().length);
        },
        navigate(direction) {
            const items = this.getItems();
            if (!items.length) return this.resetActive();
            this.activeIndex = (this.activeIndex + direction + items.length) % items.length;
            this.activeId = items[this.activeIndex].id;
            items[this.activeIndex].scrollIntoView({ block: 'nearest' });
        },
        selectCurrent() {
            const item = this.getItems()[this.activeIndex];
            if (item) item.click();
        },
    };
}
if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('globalSearchComponent', globalSearchComponent);
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
