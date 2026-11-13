export function reportPartyCombobox(config) {
    let generation = 0;
    let timer;
    return {
        open: false, busy: false, failed: false, active: -1,
        selected: '', label: '', term: '', options: [],
        sync(id, values) {
            id = String(id ?? '');
            const label = values.find(item => String(item.id) === id)?.label ?? '';
            // Hydration/reset/back navigation must refresh authorized identity, even after revocation.
            if (id !== this.selected || label !== this.label) {
                this.dismiss(); this.selected = id; this.label = label; this.term = label;
            }
        },
        async search() {
            clearTimeout(timer);
            const version = ++generation;
            this.open = true; this.busy = true; this.failed = false; this.active = -1; this.options = [];
            try {
                const rows = await config.lookup(this.term);
                if (version !== generation) return;
                this.options = [{id: '', label: config.emptyLabel}, ...rows];
            } catch {
                if (version === generation) this.failed = true;
            } finally {
                if (version === generation) this.busy = false;
            }
        },
        input(value) {
            this.term = value; ++generation; this.open = true; this.busy = true; this.options = []; this.active = -1;
            clearTimeout(timer); timer = setTimeout(() => this.search(), 300);
        },
        dismiss() {
            ++generation; clearTimeout(timer); this.open = false; this.busy = false;
            this.active = -1; this.term = this.label;
        },
        choose(index) {
            if (this.busy || !this.open || !this.options[index]) return;
            const option = this.options[index];
            this.selected = String(option.id); this.label = option.id === '' ? '' : option.label;
            config.commit(this.selected); this.dismiss();
        },
        key(event) {
            if (event.isComposing) return;
            if (event.key === 'Escape') { if (this.open) event.preventDefault(); this.dismiss(); }
            if (event.key === 'Tab') this.dismiss();
            if (event.key === 'Enter' && this.open) { event.preventDefault(); this.choose(this.active); }
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                if (!this.open) { this.search(); return; }
                if (!this.options.length || this.busy) return;
                this.active = (this.active + (event.key === 'ArrowDown' ? 1 : -1) + this.options.length) % this.options.length;
                this.$nextTick?.(() => this.$refs?.list?.children[this.active]?.scrollIntoView({block: 'nearest'}));
            }
        },
        destroy() { this.dismiss(); },
    };
}
if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => window.Alpine.data('reportPartyCombobox', reportPartyCombobox));
}
