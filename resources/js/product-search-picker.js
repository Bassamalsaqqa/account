import { enqueueDocumentOperation } from './document-operations.js';

export function productPickerComponent({ prefix = 'product', context = 'sales' } = {}) {
    return {
        prefix,
        context,
        open: false,
        activeIndex: -1,
        inputFocused: false,
        inputQuery: null,
        pending: false,
        feedback: '',

        async submitSearch() {
            if (this.pending) return;
            const query = String(this.inputQuery ?? this.$wire.productSearch ?? '').trim();
            if (!query) return;
            const selected = this.resultsReady() ? (this.$wire.searchResults || [])[this.activeIndex] : null;
            this.pending = true;
            this.feedback = '';
            try {
                // Flush the debounced field before accepting Enter from a hardware scanner.
                await this.$wire.set('productSearch', query);
                if (String(this.inputQuery ?? query).trim() !== query) return;
                this.inputQuery = query;
                if (!this.resultsReady()) return;
                const results = this.$wire.searchResults || [];
                if (results.length) {
                    const item = selected
                        ? results.find(item => item.id === selected.id && item.default_unit_id === selected.default_unit_id)
                        : results[0];
                    await this.commitCandidate(item);
                }
            } catch {
                this.feedback = this.$el?.dataset.networkError || '';
            } finally {
                this.pending = false;
            }
        },

        async commitCandidate(item) {
            if (!item || !this.resultsReady()) return;
            this.close();
            await enqueueDocumentOperation(this.$wire, 'addProduct', [item.id, item.default_unit_id]);
            this.inputQuery = null;
        },

        onInput(value) {
            this.inputQuery = String(value);
            this.close();
        },

        resultsReady() {
            const query = this.inputQuery ?? String(this.$wire.productSearch || '');
            return query.length > 0 && query === this.$wire.searchResultsQuery
                && query === String(this.$wire.productSearch || '');
        },

        init() {
            this.$watch('$wire.searchResults', (val) => {
                const count = (val || []).length;
                const query = String(this.$wire.productSearch || '').trim();
                if (query.length > 0 && this.resultsReady()) {
                    this.open = true;
                    this.activeIndex = count > 0 ? 0 : -1;
                    if (count > 0) {
                        this.scrollToActive();
                    }
                } else {
                    this.open = false;
                    this.activeIndex = -1;
                }
            });

            this.$watch('$wire.searchResultsQuery', () => {
                this.open = this.resultsReady();
                this.activeIndex = this.open && (this.$wire.searchResults || []).length ? 0 : -1;
            });

            this.$watch('$wire.productSearch', (val) => {
                const query = String(val || '').trim();
                if (!query) {
                    this.open = false;
                    this.activeIndex = -1;
                }
            });
        },

        onFocus() {
            this.inputFocused = true;
            const query = String(this.$wire.productSearch || '').trim();
            if (query.length > 0 && this.resultsReady()) {
                this.open = true;
                const count = (this.$wire.searchResults || []).length;
                this.activeIndex = count > 0 ? 0 : -1;
            }
        },

        onBlur() {
            this.inputFocused = false;
        },

        selectNext() {
            if (!this.resultsReady()) return;
            const count = (this.$wire.searchResults || []).length;
            if (count > 0) {
                this.activeIndex = (this.activeIndex + 1) % count;
                this.scrollToActive();
            }
        },

        selectPrev() {
            if (!this.resultsReady()) return;
            const count = (this.$wire.searchResults || []).length;
            if (count > 0) {
                this.activeIndex = (this.activeIndex - 1 + count) % count;
                this.scrollToActive();
            }
        },

        async selectCurrent() {
            if (this.pending || !this.resultsReady()) return;
            const item = (this.$wire.searchResults || [])[this.activeIndex];
            if (!item) return;
            this.pending = true;
            this.feedback = '';
            try { await this.commitCandidate(item); }
            catch { this.feedback = this.$el?.dataset.networkError || ''; }
            finally { this.pending = false; }
        },

        async addCandidate(id, defaultUnitId) {
            if (this.pending || !this.resultsReady()) return;
            const results = this.$wire.searchResults || [];
            const index = results.findIndex(item => item.id === id && item.default_unit_id === defaultUnitId);
            if (index < 0) return;
            this.activeIndex = index;
            await this.selectCurrent();
        },

        clearSearch() {
            this.inputQuery = '';
            this.$wire.set('productSearch', '');
            this.close();
            if (typeof document !== 'undefined') {
                const input = document.getElementById(`${this.prefix}-product-search`);
                if (input && typeof input.focus === 'function') {
                    input.focus();
                }
            }
        },

        close() {
            this.open = false;
            this.activeIndex = -1;
        },

        scrollToActive() {
            if (typeof this.$nextTick === 'function') {
                this.$nextTick(() => {
                    if (this.$refs && this.$refs.resultsList) {
                        const el = this.$refs.resultsList.querySelector(`[data-index='${this.activeIndex}']`);
                        if (el && typeof el.scrollIntoView === 'function') {
                            el.scrollIntoView({ block: 'nearest' });
                        }
                    }
                });
            }
        },
    };
}

if (typeof window !== 'undefined') {
    window.productPickerComponent = productPickerComponent;
}
