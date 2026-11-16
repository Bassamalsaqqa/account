/**
 * Searchable Combobox Component for Laravel / Livewire / Alpine
 * ARIA 1.2 Combobox pattern with keyboard navigation, debounce, generation tracking,
 * stale response discard, bidi isolation, and touch-first responsiveness.
 */
export function searchableCombobox(config) {
    let debounceTimer = null;
    let generation = 0;

    return {
        id: config.id || 'combobox-' + Math.random().toString(36).substring(2, 9),
        type: config.type || 'customer',
        wireModel: config.wireModel || null,
        onSelect: config.onSelect || null,
        extraArgs: config.extraArgs || [],
        disabled: config.disabled || false,
        required: config.required || false,
        placeholder: config.placeholder || '',

        open: false,
        query: config.label || '',
        selectedId: config.value !== undefined && config.value !== '' && config.value !== null ? String(config.value) : null,
        selectedLabel: config.label || '',
        items: Array.isArray(config.options) ? config.options : [],
        activeIndex: -1,
        activeId: null,
        searching: false,
        hasMore: false,
        failed: false,
        offline: false,
        currentCompanyId: null,

        init() {
            // Livewire model synchronization
            if (this.wireModel && this.$wire) {
                this.$watch('selectedId', (val) => {
                    const normVal = (val !== null && val !== undefined && val !== '') ? String(val) : null;
                    if (this.wireModel && this.$wire) {
                        const currentWireVal = this.$wire.get(this.wireModel);
                        const normWireVal = (currentWireVal !== null && currentWireVal !== undefined && currentWireVal !== '') ? String(currentWireVal) : null;
                        if (normWireVal !== normVal) {
                            this.$wire.set(this.wireModel, normVal);
                        }
                    }
                });

                this.$wire.$watch(this.wireModel, (newVal) => {
                    const normVal = (newVal !== null && newVal !== undefined && newVal !== '') ? String(newVal) : null;
                    if (normVal !== this.selectedId) {
                        this.selectedId = normVal;
                        if (!normVal) {
                            this.selectedLabel = '';
                            this.query = '';
                        } else {
                            const found = this.items.find(i => String(i.id) === String(normVal));
                            if (found) {
                                this.selectedLabel = found.label;
                                this.query = found.label;
                            }
                        }
                    }
                });
            }

            // Initial selection sync
            if (this.selectedId && !this.selectedLabel && this.items.length) {
                const found = this.items.find(i => String(i.id) === String(this.selectedId));
                if (found) {
                    this.selectedLabel = found.label;
                    this.query = found.label;
                }
            }
        },

        get isSelected() {
            return this.selectedId !== null && this.selectedId !== '';
        },

        get listboxId() {
            return this.id + '-listbox';
        },

        isBlocked() {
            if (this.disabled) return true;
            if (typeof window !== 'undefined' && typeof window.isDocumentBlocked === 'function' && window.isDocumentBlocked(this.$wire)) {
                return true;
            }
            if (this.$wire && (this.$wire.documentBusy || this.$wire.saving)) {
                return true;
            }
            return false;
        },

        handleInput() {
            if (this.isBlocked()) return;
            this.open = true;
            this.failed = false;
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                this.performSearch();
            }, 200);
        },

        handleFocus() {
            if (this.isBlocked()) return;
            if (!this.open) {
                this.open = true;
                if (!this.items.length || (this.query && this.query !== this.selectedLabel)) {
                    this.performSearch();
                }
            }
        },

        async performSearch() {
            if (!this.$wire || typeof this.$wire.searchSelector !== 'function') {
                return;
            }

            const currentGen = ++generation;
            this.searching = true;
            this.failed = false;

            try {
                const response = await this.$wire.searchSelector(
                    this.type,
                    this.query,
                    this.selectedId ? parseInt(this.selectedId, 10) : null,
                    {
                        generation: currentGen,
                        limit: 25,
                    }
                );

                // Discard stale responses from older generations or company switches
                if (response.generation !== generation) {
                    return;
                }
                if (this.currentCompanyId !== null && response.company_id && response.company_id !== this.currentCompanyId) {
                    return;
                }
                if (response.company_id) {
                    this.currentCompanyId = response.company_id;
                }

                this.items = Array.isArray(response.items) ? response.items : [];
                this.hasMore = Boolean(response.has_more);
                this.activeIndex = -1;
                this.activeId = null;
                this.open = true;
            } catch (err) {
                if (currentGen === generation) {
                    this.failed = true;
                }
            } finally {
                if (currentGen === generation) {
                    this.searching = false;
                }
            }
        },

        handleKeydown(e) {
            if (this.isBlocked()) return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (!this.open) {
                        this.open = true;
                    } else if (this.items.length) {
                        this.activeIndex = (this.activeIndex + 1) % this.items.length;
                        this.updateActiveId();
                        this.scrollToActive();
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (this.open && this.items.length) {
                        this.activeIndex = this.activeIndex <= 0 ? this.items.length - 1 : this.activeIndex - 1;
                        this.updateActiveId();
                        this.scrollToActive();
                    }
                    break;
                case 'Enter':
                    if (this.open && this.activeIndex >= 0 && this.items[this.activeIndex]) {
                        e.preventDefault();
                        this.selectItem(this.items[this.activeIndex]);
                    }
                    break;
                case 'Escape':
                    if (this.open) {
                        e.preventDefault();
                        this.open = false;
                        this.query = this.selectedLabel;
                        this.activeIndex = -1;
                        this.activeId = null;
                    }
                    break;
                case 'Tab':
                    if (this.open) {
                        if (this.activeIndex >= 0 && this.items[this.activeIndex]) {
                            this.selectItem(this.items[this.activeIndex]);
                        } else {
                            this.open = false;
                            this.query = this.selectedLabel;
                        }
                    }
                    break;
            }
        },

        updateActiveId() {
            if (this.activeIndex >= 0 && this.items[this.activeIndex]) {
                this.activeId = this.id + '-option-' + this.items[this.activeIndex].id;
            } else {
                this.activeId = null;
            }
        },

        scrollToActive() {
            this.$nextTick(() => {
                const el = document.getElementById(this.activeId);
                if (el && el.scrollIntoView) {
                    el.scrollIntoView({ block: 'nearest' });
                }
            });
        },

        async selectItem(item) {
            if (this.isBlocked() || !item) return;

            const prevId = this.selectedId;
            const prevLabel = this.selectedLabel;
            const prevQuery = this.query;

            try {
                if (this.onSelect && this.$wire && typeof this.$wire[this.onSelect] === 'function') {
                    // Canonical server action execution
                    const success = await this.$wire[this.onSelect](
                        ...this.extraArgs,
                        parseInt(item.id, 10),
                        item.meta?.matched_product_unit_id || null
                    );
                    if (success === false) {
                        // Backend rejected selection; restore previous display
                        this.selectedId = prevId;
                        this.selectedLabel = prevLabel;
                        this.query = prevQuery;
                        return;
                    }
                } else if (this.wireModel && this.$wire) {
                    await this.$wire.$set(this.wireModel, item.id);
                }

                // Canonical success confirmed
                this.selectedId = String(item.id);
                this.selectedLabel = item.label;
                this.query = item.label;
                this.open = false;
                this.activeIndex = -1;
                this.activeId = null;
                this.failed = false;

                this.$dispatch('combobox-selected', {
                    id: this.id,
                    type: this.type,
                    value: item.id,
                    item: item,
                });
            } catch (err) {
                // Restore state on failure - NO fake optimistic cleared state!
                this.selectedId = prevId;
                this.selectedLabel = prevLabel;
                this.query = prevQuery;
                this.failed = true;
            }
        },

        async clear() {
            if (this.isBlocked()) return;

            const prevId = this.selectedId;
            const prevLabel = this.selectedLabel;
            const prevQuery = this.query;

            try {
                if (this.onSelect && this.$wire && typeof this.$wire[this.onSelect] === 'function') {
                    const success = await this.$wire[this.onSelect](...this.extraArgs, null, null);
                    if (success === false) {
                        this.selectedId = prevId;
                        this.selectedLabel = prevLabel;
                        this.query = prevQuery;
                        return;
                    }
                } else if (this.wireModel && this.$wire) {
                    await this.$wire.$set(this.wireModel, null);
                }

                // Confirm clear on client
                this.selectedId = null;
                this.selectedLabel = '';
                this.query = '';
                this.open = false;
                this.activeIndex = -1;
                this.activeId = null;
                this.failed = false;

                this.$dispatch('combobox-cleared', { id: this.id });
                this.$nextTick(() => {
                    this.$refs.searchInput?.focus();
                });
            } catch (err) {
                this.selectedId = prevId;
                this.selectedLabel = prevLabel;
                this.query = prevQuery;
                this.failed = true;
            }
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
    window.searchableCombobox = searchableCombobox;
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
