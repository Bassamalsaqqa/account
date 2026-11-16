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
        nextCursor: null,
        loadingMore: false,
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

            // Scope invalidation on type changes
            if (this.$watch) {
                this.$watch('type', () => {
                    this.invalidateIntent();
                    this.items = [];
                    this.hasMore = false;
                    this.nextCursor = null;
                    this.open = false;
                });
            }

            if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
                window.addEventListener('company-changed', (e) => {
                    this.setCompany(e?.detail?.companyId ?? null);
                });
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
            if (this.$wire && (this.$wire.documentBusy === true || this.$wire.saving === true)) {
                return true;
            }
            return false;
        },

        invalidateIntent() {
            const nextGen = ++generation;
            if (debounceTimer) {
                clearTimeout(debounceTimer);
                debounceTimer = null;
            }
            this.nextCursor = null;
            this.loadingMore = false;
            this.searching = false;
            return nextGen;
        },

        setCompany(companyId) {
            this.invalidateIntent();
            this.currentCompanyId = companyId;
            this.items = [];
            this.hasMore = false;
            this.nextCursor = null;
            this.open = false;
        },

        handleInput() {
            if (this.isBlocked()) return;
            const currentGen = this.invalidateIntent();
            this.items = [];
            this.hasMore = false;
            this.activeIndex = -1;
            this.activeId = null;
            this.open = true;
            this.failed = false;
            const capturedQuery = this.query;
            debounceTimer = setTimeout(() => {
                this.performSearch(currentGen, capturedQuery);
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

        async performSearch(expectedGen, expectedQuery) {
            if (this.isBlocked()) return;
            if (expectedGen !== undefined && expectedGen !== generation) return;
            if (!this.$wire || typeof this.$wire.searchSelector !== 'function') {
                return;
            }

            const currentGen = expectedGen !== undefined ? expectedGen : this.invalidateIntent();
            const searchQuery = expectedQuery !== undefined ? expectedQuery : this.query;
            const expectedCompanyId = this.currentCompanyId;

            this.searching = true;
            this.failed = false;

            try {
                const response = await this.$wire.searchSelector(
                    this.type,
                    searchQuery,
                    this.selectedId ? parseInt(this.selectedId, 10) : null,
                    {
                        generation: currentGen,
                        limit: 25,
                    }
                );

                // Discard stale responses from older generations, changed queries, or company switches
                if (response.generation !== generation || currentGen !== generation) {
                    return;
                }
                if (this.query !== searchQuery) {
                    return;
                }
                if (expectedCompanyId !== null && response.company_id && response.company_id !== expectedCompanyId) {
                    return;
                }
                if (response.company_id) {
                    this.currentCompanyId = response.company_id;
                }

                this.items = Array.isArray(response.items) ? response.items : [];
                this.hasMore = Boolean(response.has_more);
                this.nextCursor = response.next_cursor || null;
                if (response.selected_item && String(this.selectedId) === String(response.selected_item.id)) {
                    this.selectedLabel = response.selected_item.label;
                }
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

        async loadMore() {
            if (this.isBlocked() || this.loadingMore || !this.hasMore || !this.nextCursor) {
                return;
            }
            if (!this.$wire || typeof this.$wire.searchSelector !== 'function') {
                return;
            }

            const currentGen = generation;
            const currentQuery = this.query;
            const expectedCompanyId = this.currentCompanyId;
            const cursorToLoad = this.nextCursor;

            this.loadingMore = true;
            this.failed = false;

            try {
                const response = await this.$wire.searchSelector(
                    this.type,
                    currentQuery,
                    this.selectedId ? parseInt(this.selectedId, 10) : null,
                    {
                        generation: currentGen,
                        limit: 25,
                        cursor: cursorToLoad,
                    }
                );

                // Discard stale responses from older generations, changed queries, or company switches
                if (response.generation !== generation || currentGen !== generation) {
                    return;
                }
                if (this.query !== currentQuery) {
                    return;
                }
                if (expectedCompanyId !== null && response.company_id && response.company_id !== expectedCompanyId) {
                    return;
                }

                const newItems = Array.isArray(response.items) ? response.items : [];
                const seen = new Set(this.items.map(i => String(i.id)));
                for (const item of newItems) {
                    const idStr = String(item.id);
                    if (!seen.has(idStr)) {
                        seen.add(idStr);
                        this.items.push(item);
                    }
                }

                this.hasMore = Boolean(response.has_more);
                this.nextCursor = response.next_cursor || null;
            } catch (err) {
                if (currentGen === generation) {
                    this.failed = true;
                }
            } finally {
                if (currentGen === generation) {
                    this.loadingMore = false;
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
            if (!this.items.some(candidate => String(candidate.id) === String(item.id))) return;

            const currentGen = this.invalidateIntent();
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
                    if (currentGen !== generation) {
                        return;
                    }
                    if (success === false) {
                        // Backend rejected selection; restore previous display
                        this.selectedId = prevId;
                        this.selectedLabel = prevLabel;
                        this.query = prevQuery;
                        return;
                    }
                } else if (this.wireModel && this.$wire) {
                    await this.$wire.$set(this.wireModel, item.id);
                    if (currentGen !== generation) {
                        return;
                    }
                }

                if (currentGen !== generation) {
                    return;
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
                if (currentGen === generation) {
                    // Restore state on failure - NO fake optimistic cleared state!
                    this.selectedId = prevId;
                    this.selectedLabel = prevLabel;
                    this.query = prevQuery;
                    this.failed = true;
                }
            }
        },

        async clear() {
            if (this.isBlocked()) return;

            const currentGen = this.invalidateIntent();
            const prevId = this.selectedId;
            const prevLabel = this.selectedLabel;
            const prevQuery = this.query;
            const prevItems = this.items;
            const prevHasMore = this.hasMore;

            // Immediately cancel debounce, reset items, hasMore, nextCursor, loadingMore, searching, open
            this.items = [];
            this.hasMore = false;
            this.nextCursor = null;
            this.loadingMore = false;
            this.searching = false;
            this.open = false;

            try {
                if (this.onSelect && this.$wire && typeof this.$wire[this.onSelect] === 'function') {
                    const success = await this.$wire[this.onSelect](...this.extraArgs, null, null);
                    if (currentGen !== generation) {
                        return;
                    }
                    if (success === false) {
                        this.selectedId = prevId;
                        this.selectedLabel = prevLabel;
                        this.query = prevQuery;
                        this.items = prevItems;
                        this.hasMore = prevHasMore;
                        return;
                    }
                } else if (this.wireModel && this.$wire) {
                    await this.$wire.$set(this.wireModel, null);
                    if (currentGen !== generation) {
                        return;
                    }
                }

                if (currentGen !== generation) {
                    return;
                }

                // Confirm clear on client
                this.selectedId = null;
                this.selectedLabel = '';
                this.query = '';
                this.activeIndex = -1;
                this.activeId = null;
                this.failed = false;

                this.$dispatch('combobox-cleared', { id: this.id });
                this.$nextTick(() => {
                    this.$refs.searchInput?.focus();
                });
            } catch (err) {
                if (currentGen === generation) {
                    this.selectedId = prevId;
                    this.selectedLabel = prevLabel;
                    this.query = prevQuery;
                    this.items = prevItems;
                    this.hasMore = prevHasMore;
                    this.failed = true;
                }
            }
        },

        clearSelection() {
            return this.clear();
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
