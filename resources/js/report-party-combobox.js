export function reportPartyCombobox(config) {
    let generation = 0;
    let timer;
    let unwatch;
    return {
        open: false, busy: false, failed: false, active: -1,
        selected: '', label: '', term: '', options: [],
        init() {
            this.sync(config.initialSelected ?? '', config.initialValues ?? []);
            unwatch = config.watch?.(id => this.sync(id, [...this.options, ...(config.initialValues ?? [])]));
            this.$cleanup?.(() => this.destroy());
        },
        sync(id, values) {
            id = String(id ?? '');
            const label = id === '' ? '' : (values.find(item => String(item.id) === id)?.label ?? '');
            // Hydration/reset/back navigation must refresh authorized identity, even after revocation.
            if (id !== this.selected || label !== this.label || this.busy || this.open) {
                this.dismiss(); this.options = []; this.selected = id; this.label = label; this.term = label;
            }
        },
        async search() {
            clearTimeout(timer);
            const version = ++generation;
            this.open = true; this.busy = true; this.failed = false; this.active = -1; this.options = [];
            try {
                const query = this.selected && this.term === this.label ? '' : this.term;
                const rows = await config.lookup(query);
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
            if (event.key === 'Enter') {
                event.preventDefault();
                if (this.open) {
                    if (this.failed) {
                        this.search();
                    } else {
                        this.choose(this.active < 0 && this.options.length === 2 ? 1 : this.active);
                    }
                } else {
                    this.search();
                }
            }
            if (['ArrowDown', 'ArrowUp'].includes(event.key)) {
                event.preventDefault();
                if (!this.open || this.failed) { this.search(); return; }
                if (!this.options.length || this.busy) return;
                this.active = this.active < 0
                    ? (event.key === 'ArrowDown' && this.options.length > 1 ? 1 : this.options.length - 1)
                    : (this.active + (event.key === 'ArrowDown' ? 1 : -1) + this.options.length) % this.options.length;
                this.$nextTick?.(() => this.$refs?.list?.querySelectorAll('[role="option"]')[this.active]?.scrollIntoView({block: 'nearest'}));
            }
        },
        destroy() {
            this.dismiss();
            if (typeof unwatch === 'function') {
                unwatch();
                unwatch = null;
            }
        },
    };
}
if (typeof document !== 'undefined') {
    document.addEventListener('alpine:init', () => window.Alpine.data('reportPartyCombobox', reportPartyCombobox));
}
