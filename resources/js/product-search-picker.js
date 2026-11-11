export function productPickerComponent({ prefix = 'product', context = 'sales' } = {}) {
    return {
        prefix,
        context,
        open: false,
        activeIndex: -1,
        inputFocused: false,

        init() {
            this.$watch('$wire.searchResults', (val) => {
                const count = (val || []).length;
                const query = String(this.$wire.productSearch || '').trim();
                if (query.length > 0) {
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
            if (query.length > 0) {
                this.open = true;
                const count = (this.$wire.searchResults || []).length;
                this.activeIndex = count > 0 ? 0 : -1;
            }
        },

        onBlur() {
            this.inputFocused = false;
        },

        selectNext() {
            const count = (this.$wire.searchResults || []).length;
            if (count > 0) {
                this.activeIndex = (this.activeIndex + 1) % count;
                this.scrollToActive();
            }
        },

        selectPrev() {
            const count = (this.$wire.searchResults || []).length;
            if (count > 0) {
                this.activeIndex = (this.activeIndex - 1 + count) % count;
                this.scrollToActive();
            }
        },

        selectCurrent() {
            const results = this.$wire.searchResults || [];
            if (results.length > 0 && this.activeIndex >= 0 && results[this.activeIndex]) {
                const item = results[this.activeIndex];
                this.close();
                if (typeof window !== 'undefined' && typeof window.enqueueDocumentOperation === 'function') {
                    window.enqueueDocumentOperation(this.$wire, 'addProduct', [item.id, item.default_unit_id]);
                } else {
                    this.$wire.addProduct(item.id, item.default_unit_id);
                }
            } else {
                const query = String(this.$wire.productSearch || '').trim();
                if (query.length > 0) {
                    this.close();
                    if (typeof window !== 'undefined' && typeof window.enqueueDocumentOperation === 'function') {
                        window.enqueueDocumentOperation(this.$wire, 'addSelectedProduct', []);
                    } else {
                        this.$wire.addSelectedProduct();
                    }
                }
            }
        },

        addCandidate(id, defaultUnitId) {
            this.close();
            if (typeof window !== 'undefined' && typeof window.enqueueDocumentOperation === 'function') {
                window.enqueueDocumentOperation(this.$wire, 'addProduct', [id, defaultUnitId]);
            } else {
                this.$wire.addProduct(id, defaultUnitId);
            }
        },

        clearSearch() {
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
