export function globalSearchComponent() {
    let generation = 0;
    return {
        clientOpen: false, activeIndex: -1, activeId: null, term: '',
        searching: false, dirty: false, failed: false, offline: false,
        previouslyFocused: null,
        init() {
            this.$wire.$watch('isOpen', value => { if (!value) this.clientOpen = false; });
        },
        isModalVisible() { return this.clientOpen && this.$wire.isOpen; },
        handleWindowKeydown(event) {
            if (event.repeat || !(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'k') return;
            if ([...document.querySelectorAll('dialog[open],[role="dialog"]')].some(el => el.id !== 'global-search-dialog' && el.getClientRects().length)) return;
            event.preventDefault();
            this.clientOpen ? this.closeModal() : this.openModal();
        },
        async openModal() {
            if (this.clientOpen) return;
            this.previouslyFocused = document.activeElement;
            this.failed = false;
            this.offline = !navigator.onLine;
            try {
                await this.$wire.open();
                this.clientOpen = this.$wire.isOpen;
                this.$nextTick(() => this.$refs.searchInput?.focus());
            } catch { this.failed = true; }
        },
        closeModal() {
            generation++;
            this.clientOpen = false;
            this.term = '';
            this.resetActive();
            this.$wire.close().catch(() => {});
            this.previouslyFocused?.focus();
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