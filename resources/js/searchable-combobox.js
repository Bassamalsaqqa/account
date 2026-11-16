/**
 * Searchable Combobox Component for Laravel / Livewire / Alpine
 * ARIA 1.2 Combobox pattern with keyboard navigation, debounce, stale response discard,
 * bidi isolation, and touch-first responsiveness.
 */
export function searchableCombobox(config) {
    let debounceTimer = null;
    let generation = 0;

    return {
        id: config.id || 'combobox-' + Math.random().toString(36).substring(2, 9),
        type: config.type || 'customer',
        context: config.context || null,
        capability: config.capability || null,
        wireModel: config.wireModel || null,
        onSelect: config.onSelect || null,
        extraArgs: config.extraArgs || [],
        disabled: config.disabled || false,
        required: config.required || false,
        placeholder: config.placeholder || '',

        open: false,
        query: config.label || '',
        selectedId: config.value !== undefined && config.value !== '' ? String(config.value) : null,
        selectedLabel: config.label || '',
        items: Array.isArray(config.options) ? config.options : [],
        activeIndex: -1,
        activeId: null,
        searching: false,
        hasMore: false,
        failed: false,
        offline: false,

        init() {
            if (this.wireModel && this.$wire) {
                this.$wire.$watch(this.wireModel, (newVal) => {
                    const normVal = (newVal !== null && newVal !== undefined && newVal !== '') ? String(newVal) : null;
                    if (normVal !== this.selectedId) {
                        this.selectedId = normVal;
                        if (!normVal) {
                            this.selectedLabel = '';
                            this.query = '';
                        }
                    }
                });
            }

            // Sync initial selection
            if (this.selectedId && !this.selectedLabel && this.items.length) {
                const found = this.items.find(i => String(i.id) === String(this.selectedId));
                if (found) {
                    this.selectedLabel = found.label;
                    this.query = found.label;
                }
            }
        },

        get listboxId() {
            return this.id + '-listbox';
        },

        get isSelected() {
            return this.selectedId !== null && this.selectedId !== '';
        },

        handleInput() {
            if (this.disabled) return;
            generation++;
            this.open = true;
            this.failed = false;
            this.activeIndex = -1;
            this.activeId = null;

            if (debounceTimer) {
                clearTimeout(debounceTimer);
            }

            debounceTimer = setTimeout(() => {
                this.performSearch();
            }, 200);
        },

        handleFocus() {
            if (this.disabled) return;
            this.open = true;
            if (!this.items.length && this.$wire) {
                this.performSearch();
            }
        },

        async performSearch() {
            if (!this.$wire || typeof this.$wire.searchSelector !== 'function') return;

            this.offline = !navigator.onLine;
            if (this.offline) {
                this.failed = true;
                return;
            }

            const currentGen = generation;
            this.searching = true;
            this.failed = false;

            try {
                const options = {
                    generation: currentGen,
                    context: this.context,
                    capability: this.capability,
                    extraArgs: this.extraArgs,
                };

                const res = await this.$wire.searchSelector(
                    this.type,
                    this.query || '',
                    this.selectedId ? parseInt(this.selectedId, 10) : null,
                    options
                );

                // Discard stale out-of-order response
                if (res.generation !== generation) {
                    return;
                }

                this.items = res.items || [];
                this.hasMore = Boolean(res.has_more);
                this.searching = false;
                this.activeIndex = -1;
                this.activeId = null;
            } catch (err) {
                if (currentGen === generation) {
                    this.failed = true;
                    this.searching = false;
                }
            }
        },

        handleKeydown(event) {
            if (this.disabled) return;

            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    if (!this.open) {
                        this.open = true;
                        this.performSearch();
                        return;
                    }
                    if (this.items.length > 0) {
                        this.activeIndex = (this.activeIndex + 1) % this.items.length;
                        this.updateActiveItem();
                    }
                    break;

                case 'ArrowUp':
                    event.preventDefault();
                    if (!this.open) {
                        this.open = true;
                        this.performSearch();
                        return;
                    }
                    if (this.items.length > 0) {
                        this.activeIndex = (this.activeIndex - 1 + this.items.length) % this.items.length;
                        this.updateActiveItem();
                    }
                    break;

                case 'Enter':
                    if (this.open && this.activeIndex >= 0 && this.items[this.activeIndex]) {
                        event.preventDefault();
                        this.selectItem(this.items[this.activeIndex]);
                    }
                    break;

                case 'Escape':
                    if (this.open) {
                        event.preventDefault();
                        this.open = false;
                        this.query = this.selectedLabel;
                        this.activeIndex = -1;
                        this.activeId = null;
                    }
                    break;

                case 'Tab':
                    this.open = false;
                    this.query = this.selectedLabel;
                    break;
            }
        },

        updateActiveItem() {
            if (this.activeIndex >= 0 && this.items[this.activeIndex]) {
                const optId = this.id + '-opt-' + this.items[this.activeIndex].id;
                this.activeId = optId;
                this.$nextTick(() => {
                    const el = document.getElementById(optId);
                    if (el && typeof el.scrollIntoView === 'function') {
                        el.scrollIntoView({ block: 'nearest' });
                    }
                });
            } else {
                this.activeId = null;
            }
        },

        selectItem(item) {
            this.selectedId = String(item.id);
            this.selectedLabel = item.label;
            this.query = item.label;
            this.open = false;
            this.activeIndex = -1;
            this.activeId = null;

            // Notify Livewire via wireModel or custom onSelect action
            if (this.onSelect && this.$wire && typeof this.$wire[this.onSelect] === 'function') {
                const unitId = item.meta && item.meta.matched_product_unit_id !== undefined
                    ? item.meta.matched_product_unit_id
                    : null;
                if (unitId !== null) {
                    this.$wire[this.onSelect](...this.extraArgs, parseInt(item.id, 10), unitId);
                } else {
                    this.$wire[this.onSelect](...this.extraArgs, parseInt(item.id, 10));
                }
            } else if (this.wireModel && this.$wire) {
                this.$wire.$set(this.wireModel, parseInt(item.id, 10));
            }

            this.$dispatch('combobox-selected', {
                id: item.id,
                label: item.label,
                meta: item.meta || {},
            });
        },

        clear() {
            if (this.disabled) return;
            generation++;
            this.selectedId = null;
            this.selectedLabel = '';
            this.query = '';
            this.open = false;
            this.activeIndex = -1;
            this.activeId = null;

            if (this.onSelect && this.$wire && typeof this.$wire[this.onSelect] === 'function') {
                this.$wire[this.onSelect](...this.extraArgs, null);
            } else if (this.wireModel && this.$wire) {
                this.$wire.$set(this.wireModel, null);
            }

            this.$dispatch('combobox-cleared', { id: this.id });
            this.$nextTick(() => {
                this.$refs.searchInput?.focus();
            });
        },

        handleOutsideClick() {
            if (this.open) {
                this.open = false;
                this.query = this.selectedLabel;
                this.activeIndex = -1;
                this.activeId = null;
            }
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => {
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            window.Alpine.data('searchableCombobox', searchableCombobox);
        }
    };
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register, { once: true });
    }
}
