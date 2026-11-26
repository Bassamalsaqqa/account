/**
 * Searchable Combobox Adaptive Overlay & Race-Safe Controller
 *
 * Enforces:
 * - Single active overlay per document (singleton registry)
 * - Fixed viewport positioning (popup) with flip/shift/clamp + visualViewport & RTL awareness
 * - Automatic mobile sheet fallback when available safe vertical space is constrained
 * - Modal containment safety inside x-trap.inert dialogs
 * - Generation + inputVersion + selectionToken + company-context race guards
 * - Reactive server sync via MutationObserver + Livewire morph bridge
 */

let activeComboboxController = null;
const mountedComboboxControllers = new Set();
let globalNavigationListenerRegistered = false;

export function getActiveComboboxId() {
    return activeComboboxController ? activeComboboxController.id : null;
}

export function closeActiveCombobox(exceptController = null) {
    if (!activeComboboxController) return;
    if (exceptController && (activeComboboxController === exceptController || activeComboboxController.id === exceptController.id)) {
        return;
    }
    activeComboboxController.closeOverlay({ restoreFocus: false, preserveQuery: false });
}

function registerNavigationCleanup() {
    if (globalNavigationListenerRegistered || typeof document === 'undefined' || typeof document.addEventListener !== 'function') {
        return;
    }
    globalNavigationListenerRegistered = true;
    document.addEventListener('livewire:navigating', () => {
        for (const controller of Array.from(mountedComboboxControllers)) {
            controller.closeOverlay({ restoreFocus: false, preserveQuery: false });
            controller.detachOpenListeners?.();
        }
        activeComboboxController = null;
    });
}

/**
 * Compute adaptive overlay placement and mode from viewport, trigger rect, chrome obstacles, and direction.
 *
 * @param {object} params
 * @returns {{ mode: 'popup'|'sheet', left: number, top: number, width: number, maxHeight: number, placement: 'bottom'|'top'|'sheet' }}
 */
export function computeOverlayGeometry({
    triggerRect,
    viewportWidth,
    viewportHeight,
    viewportOffsetTop = 0,
    viewportOffsetLeft = 0,
    safeTop = 8,
    safeBottom = null,
    dir = 'ltr',
    minPopupWidth = 280,
    desiredMaxHeight = 256,
    minPopupHeight = 180,
    margin = 8,
    gap = 4,
    isMobileSheetPreferred = false,
} = {}) {
    const vpWidth = Math.max(240, Number(viewportWidth) || 1280);
    const vpHeight = Math.max(200, Number(viewportHeight) || 800);
    const topBound = Math.max(margin, Number(safeTop) || margin) + viewportOffsetTop;
    const rawBottomBound = safeBottom !== null && safeBottom !== undefined
        ? Number(safeBottom)
        : (vpHeight - margin);
    const bottomBound = Math.min(viewportOffsetTop + vpHeight - margin, Math.max(topBound + 80, rawBottomBound + viewportOffsetTop));

    if (!triggerRect) {
        return {
            mode: 'sheet',
            placement: 'sheet',
            left: viewportOffsetLeft,
            top: viewportOffsetTop,
            width: vpWidth,
            maxHeight: vpHeight,
        };
    }

    const rectTop = Number(triggerRect.top) || 0;
    const rectBottom = Number(triggerRect.bottom) || rectTop;
    const rectLeft = Number(triggerRect.left) || 0;
    const rectRight = Number(triggerRect.right) || rectLeft;
    const rectWidth = Math.max(120, Number(triggerRect.width) || 240);

    const spaceBelow = Math.max(0, bottomBound - (rectBottom + gap));
    const spaceAbove = Math.max(0, (rectTop - gap) - topBound);
    const bestVerticalSpace = Math.max(spaceBelow, spaceAbove);

    const compactPhone = vpWidth < 640;
    const compressedKeyboard = vpHeight < 380 && vpWidth < 900;

    if (compactPhone || compressedKeyboard || bestVerticalSpace < minPopupHeight) {
        return {
            mode: 'sheet',
            placement: 'sheet',
            left: viewportOffsetLeft,
            top: viewportOffsetTop,
            width: vpWidth,
            maxHeight: vpHeight,
        };
    }

    const maxAllowedWidth = Math.max(180, vpWidth - (margin * 2));
    const targetWidth = Math.min(Math.max(rectWidth, minPopupWidth), maxAllowedWidth);

    let left = dir === 'rtl' ? (rectRight - targetWidth) : rectLeft;
    const minLeft = viewportOffsetLeft + margin;
    const maxLeft = Math.max(minLeft, viewportOffsetLeft + vpWidth - targetWidth - margin);
    left = Math.min(Math.max(left, minLeft), maxLeft);

    const openBelow = spaceBelow >= Math.min(desiredMaxHeight, 210) || spaceBelow >= spaceAbove;
    const placement = openBelow ? 'bottom' : 'top';
    const availableHeight = openBelow ? spaceBelow : spaceAbove;
    const maxHeight = Math.max(120, Math.min(desiredMaxHeight, Math.floor(availableHeight)));
    const top = openBelow
        ? Math.max(topBound, rectBottom + gap)
        : Math.max(topBound, (rectTop - gap) - maxHeight);

    return {
        mode: 'popup',
        placement,
        left: Math.round(left),
        top: Math.round(top),
        width: Math.round(targetWidth),
        maxHeight,
    };
}

function measureFixedObstacles(vpHeight) {
    if (typeof document === 'undefined' || typeof document.querySelectorAll !== 'function') {
        return { safeTop: 8, safeBottom: vpHeight - 8 };
    }

    let safeTop = 8;
    let safeBottom = vpHeight - 8;

    const headerCandidates = document.querySelectorAll('.mobile-top-bar, header.sticky, [data-sticky-top]');
    headerCandidates.forEach(el => {
        if (!el || typeof el.getBoundingClientRect !== 'function') return;
        const rect = el.getBoundingClientRect();
        if (rect.height > 0 && rect.width > 0 && rect.top <= 4 && rect.bottom < vpHeight * 0.4) {
            safeTop = Math.max(safeTop, rect.bottom + 4);
        }
    });

    const bottomCandidates = document.querySelectorAll('.mobile-document-actions, .mobile-bottom-nav, [data-sticky-bottom]');
    bottomCandidates.forEach(el => {
        if (!el || typeof el.getBoundingClientRect !== 'function') return;
        const style = typeof window !== 'undefined' && window.getComputedStyle ? window.getComputedStyle(el) : null;
        if (style && (style.display === 'none' || style.visibility === 'hidden')) return;
        const rect = el.getBoundingClientRect();
        if (rect.height > 0 && rect.width > 0 && rect.top > vpHeight * 0.35 && rect.top < vpHeight) {
            safeBottom = Math.min(safeBottom, rect.top - 4);
        }
    });

    return { safeTop, safeBottom };
}

export function searchableCombobox(config) {
    let generation = 0;
    let selectionToken = 0;
    let debounceTimer = null;

    const initialSelectedId = (config.value !== undefined && config.value !== '' && config.value !== null)
        ? String(config.value)
        : null;

    return {
        id: config.id || ('combobox-' + Math.random().toString(36).substring(2, 9)),
        type: config.type || 'customer',
        context: config.context ?? null,
        wireModel: config.wireModel || null,
        onSelect: config.onSelect || null,
        extraArgs: Array.isArray(config.extraArgs) ? config.extraArgs : [],
        disabled: Boolean(config.disabled),
        required: Boolean(config.required),
        hasError: Boolean(config.hasError),
        placeholder: config.placeholder || '',
        ariaLabel: config.ariaLabel || '',

        open: false,
        overlayMode: 'popup',
        overlayStyle: '',
        query: config.label || '',
        selectedId: initialSelectedId,
        selectedLabel: config.label || '',
        items: Array.isArray(config.options) ? config.options : [],
        activeIndex: -1,
        activeId: null,
        searching: false,
        hasMore: false,
        nextCursor: null,
        loadingMore: false,
        syncing: false,
        failed: false,
        offline: false,
        liveAnnouncement: '',
        currentCompanyId: null,

        inputVersion: 0,
        lastSearchedQuery: undefined,
        lastSyncedSelectedId: initialSelectedId,
        lastSyncedSelectedLabel: config.label || '',

        _openListenersAttached: false,
        _onDocPointerDown: null,
        _onDocFocusIn: null,
        _onViewportChange: null,
        _onOnline: null,
        _onOffline: null,
        _repositionRaf: null,
        _syncObserver: null,
        _morphHookCleanup: null,
        _movedOverlayToModal: false,
        _destroyed: false,

        init() {
            registerNavigationCleanup();
            mountedComboboxControllers.add(this);

            if (typeof navigator !== 'undefined') {
                this.offline = navigator.onLine === false;
            }

            if (typeof window !== 'undefined' && typeof window.addEventListener === 'function') {
                this._onOnline = () => {
                    this.offline = false;
                };
                this._onOffline = () => {
                    this.offline = true;
                };
                window.addEventListener('online', this._onOnline);
                window.addEventListener('offline', this._onOffline);
            }

            this.setupServerSyncBridge();

            // Livewire model synchronization
            if (this.wireModel && this.$wire) {
                if (typeof this.$watch === 'function') {
                    this.$watch('selectedId', (val) => {
                        const normVal = (val !== null && val !== undefined && val !== '') ? String(val) : null;
                        if (this.wireModel && this.$wire && !this.syncing && typeof this.$wire.get === 'function') {
                            const currentWireVal = this.$wire.get(this.wireModel);
                            const normWireVal = (currentWireVal !== null && currentWireVal !== undefined && currentWireVal !== '') ? String(currentWireVal) : null;
                            if (normWireVal !== normVal && typeof this.$wire.set === 'function') {
                                this.$wire.set(this.wireModel, normVal);
                            }
                        }
                    });
                }

                if (typeof this.$wire.$watch === 'function') {
                    this.$wire.$watch(this.wireModel, (newVal) => {
                        const normVal = (newVal !== null && newVal !== undefined && newVal !== '') ? String(newVal) : null;
                        if (this.syncing) {
                            return;
                        }
                        if (normVal !== this.selectedId) {
                            this.selectedId = normVal;
                            this.lastSyncedSelectedId = normVal;
                            if (!normVal) {
                                this.selectedLabel = '';
                                this.query = '';
                                this.lastSyncedSelectedLabel = '';
                            } else {
                                const found = this.items.find(i => String(i.id) === String(normVal));
                                if (found) {
                                    this.selectedLabel = found.label;
                                    this.query = found.label;
                                    this.lastSyncedSelectedLabel = found.label;
                                } else if (this.$wire && typeof this.$wire.searchSelector === 'function') {
                                    const lookupToken = selectionToken;
                                    this.$wire.searchSelector(this.type, '', parseInt(normVal, 10), { limit: 1 })
                                        .then((res) => {
                                            if (lookupToken === selectionToken && String(this.selectedId) === String(normVal) && !this.syncing) {
                                                const item = res?.selected_item || (res?.items || []).find(i => String(i.id) === String(normVal));
                                                if (item) {
                                                    this.selectedLabel = item.label;
                                                    this.lastSyncedSelectedLabel = item.label;
                                                    if (!this.open) {
                                                        this.query = item.label;
                                                    }
                                                }
                                            }
                                        }).catch(() => {});
                                }
                            }
                        }
                    });
                }
            }

            // Initial selection sync
            if (this.selectedId && !this.selectedLabel) {
                const found = this.items.find(i => String(i.id) === String(this.selectedId));
                if (found) {
                    this.selectedLabel = found.label;
                    this.query = found.label;
                    this.lastSyncedSelectedLabel = found.label;
                } else if (this.$wire && typeof this.$wire.searchSelector === 'function') {
                    const lookupToken = selectionToken;
                    const initialId = String(this.selectedId);
                    this.$wire.searchSelector(this.type, '', parseInt(initialId, 10), { limit: 1 })
                        .then((res) => {
                            if (lookupToken === selectionToken && String(this.selectedId) === initialId && !this.syncing) {
                                const item = res?.selected_item || (res?.items || []).find(i => String(i.id) === initialId);
                                if (item) {
                                    this.selectedLabel = item.label;
                                    this.lastSyncedSelectedLabel = item.label;
                                    if (!this.open) {
                                        this.query = item.label;
                                    }
                                }
                            }
                        }).catch(() => {});
                }
            }

            // Watch open transitions cleanly
            if (typeof this.$watch === 'function') {
                this.$watch('open', (isOpen) => {
                    if (!isOpen) {
                        this.activeIndex = -1;
                        this.activeId = null;
                        this.searching = false;
                        this.loadingMore = false;
                        this.detachOpenListeners();
                        if (activeComboboxController && activeComboboxController.id === this.id) {
                            activeComboboxController = null;
                        }
                    } else {
                        closeActiveCombobox(this);
                        activeComboboxController = this;
                        this.ensureOverlayPlacement();
                        this.repositionOverlay();
                        this.attachOpenListeners();
                    }
                });
            }
        },

        setupServerSyncBridge() {
            const bridge = this.$refs?.syncBridge;
            if (!bridge || typeof MutationObserver === 'undefined') {
                return;
            }

            this.syncFromServerProps();

            this._syncObserver = new MutationObserver(() => {
                this.syncFromServerProps();
            });
            this._syncObserver.observe(bridge, {
                attributes: true,
                attributeFilter: [
                    'data-combobox-id',
                    'data-selected-id',
                    'data-selected-label',
                    'data-extra-args',
                    'data-disabled',
                    'data-has-error',
                    'data-type',
                    'data-context',
                    'data-placeholder',
                    'data-aria-label',
                ],
            });

            if (typeof window !== 'undefined' && window.Livewire && typeof window.Livewire.hook === 'function') {
                const cleanup = window.Livewire.hook('morph.updated', ({ el }) => {
                    if (this._destroyed || !this.$el) return;
                    if (el === this.$el || this.$el.contains?.(el) || el.contains?.(this.$el)) {
                        this.syncFromServerProps();
                        if (this.open) {
                            this.repositionOverlay();
                        }
                    }
                });
                if (typeof cleanup === 'function') {
                    this._morphHookCleanup = cleanup;
                }
            }
        },

        syncFromServerProps() {
            const bridge = this.$refs?.syncBridge;
            if (!bridge || !bridge.dataset) return;

            const ds = bridge.dataset;
            if (ds.comboboxId && ds.comboboxId !== this.id) {
                this.id = ds.comboboxId;
                this.updateActiveId();
            }
            if (ds.type) this.type = ds.type;
            if (ds.context !== undefined) this.context = ds.context || null;
            if (ds.placeholder !== undefined) this.placeholder = ds.placeholder;
            if (ds.ariaLabel !== undefined) this.ariaLabel = ds.ariaLabel;
            if (ds.disabled !== undefined) this.disabled = ds.disabled === '1';
            if (ds.hasError !== undefined) this.hasError = ds.hasError === '1';

            if (ds.extraArgs) {
                try {
                    const parsed = JSON.parse(ds.extraArgs);
                    if (Array.isArray(parsed)) {
                        this.extraArgs = parsed;
                    }
                } catch {
                    // Ignore malformed bridge metadata.
                }
            }

            if (this.syncing) {
                return;
            }

            const nextId = ds.selectedId !== undefined && ds.selectedId !== '' && ds.selectedId !== '0'
                ? String(ds.selectedId)
                : null;
            const nextLabel = ds.selectedLabel ?? '';

            const idChanged = nextId !== this.lastSyncedSelectedId;
            const labelChanged = nextLabel !== this.lastSyncedSelectedLabel;

            if (idChanged || (!this.open && labelChanged)) {
                selectionToken++;
                this.selectedId = nextId;
                this.selectedLabel = nextLabel;
                this.query = nextLabel;
                this.lastSyncedSelectedId = nextId;
                this.lastSyncedSelectedLabel = nextLabel;
            }
        },

        ensureOverlayPlacement() {
            const panel = this.$refs?.overlayPanel;
            if (!panel || !this.$el || typeof this.$el.closest !== 'function') {
                return;
            }
            const modalHost = this.$el.closest('[role="dialog"][aria-modal="true"]');
            if (modalHost && panel.parentElement !== modalHost) {
                modalHost.appendChild(panel);
                this._movedOverlayToModal = true;
            }
        },

        repositionOverlay() {
            if (!this.open) return;

            const input = this.$refs?.searchInput;
            if (!input || typeof input.getBoundingClientRect !== 'function' || typeof window === 'undefined') {
                this.overlayMode = 'popup';
                this.overlayStyle = '';
                return;
            }

            const triggerRect = input.getBoundingClientRect();
            const vv = window.visualViewport;
            const viewportWidth = vv ? vv.width : window.innerWidth;
            const viewportHeight = vv ? vv.height : window.innerHeight;
            const viewportOffsetTop = vv ? vv.offsetTop : 0;
            const viewportOffsetLeft = vv ? vv.offsetLeft : 0;

            const dir = typeof document !== 'undefined' && document.documentElement
                ? ((typeof window.getComputedStyle === 'function' ? window.getComputedStyle(document.documentElement).direction : null) || document.documentElement.dir || 'ltr')
                : 'ltr';

            const { safeTop, safeBottom } = measureFixedObstacles(viewportHeight);

            const geom = computeOverlayGeometry({
                triggerRect,
                viewportWidth,
                viewportHeight,
                viewportOffsetTop,
                viewportOffsetLeft,
                safeTop,
                safeBottom,
                dir,
            });

            const previousMode = this.overlayMode;
            this.overlayMode = geom.mode;

            if (geom.mode === 'sheet') {
                this.overlayStyle = `top: ${Math.round(geom.top)}px; left: ${Math.round(geom.left)}px; width: ${Math.round(geom.width)}px; height: ${Math.round(geom.maxHeight)}px;`;
                if (previousMode !== 'sheet' && this.$refs?.sheetInput && typeof this.$nextTick === 'function') {
                    this.$nextTick(() => {
                        this.$refs.sheetInput?.focus?.({ preventScroll: true });
                    });
                }
            } else {
                let offsetX = 0;
                let offsetY = 0;
                const panel = this.$refs?.overlayPanel;
                if (panel && this._movedOverlayToModal && panel.parentElement && typeof panel.parentElement.getBoundingClientRect === 'function') {
                    const style = window.getComputedStyle ? window.getComputedStyle(panel.parentElement) : null;
                    if (style && ((style.transform && style.transform !== 'none') || (style.filter && style.filter !== 'none') || style.contain === 'layout' || style.contain === 'paint')) {
                        const parentRect = panel.parentElement.getBoundingClientRect();
                        offsetX = parentRect.left;
                        offsetY = parentRect.top;
                    }
                }
                const appliedLeft = geom.left - offsetX;
                const appliedTop = geom.top - offsetY;
                this.overlayStyle = `top: ${Math.round(appliedTop)}px; left: ${Math.round(appliedLeft)}px; width: ${Math.round(geom.width)}px; --combobox-max-h: ${Math.round(geom.maxHeight)}px;`;
            }
        },

        attachOpenListeners() {
            if (this._openListenersAttached || typeof document === 'undefined' || typeof document.addEventListener !== 'function' || typeof window === 'undefined' || typeof window.addEventListener !== 'function') {
                return;
            }
            this._openListenersAttached = true;

            this._onDocPointerDown = (e) => {
                if (!this.open) return;
                const target = e.target;
                if (this.$el?.contains?.(target) || this.$refs?.overlayPanel?.contains?.(target)) {
                    return;
                }
                this.handleOutsideClick();
            };

            this._onDocFocusIn = (e) => {
                if (!this.open || this.overlayMode === 'sheet') return;
                const target = e.target;
                if (!target || target === document.body || target === document.documentElement) {
                    return;
                }
                if (this.$el?.contains?.(target) || this.$refs?.overlayPanel?.contains?.(target)) {
                    return;
                }
                this.handleOutsideClick();
            };

            this._onViewportChange = () => {
                if (!this.open) return;
                if (this.$el && this.$el.isConnected === false) {
                    this.closeOverlay({ restoreFocus: false, preserveQuery: false });
                    return;
                }
                if (this._repositionRaf) return;
                const raf = typeof requestAnimationFrame === 'function' ? requestAnimationFrame : (fn) => setTimeout(fn, 16);
                this._repositionRaf = raf(() => {
                    this._repositionRaf = null;
                    this.repositionOverlay();
                });
            };

            document.addEventListener('pointerdown', this._onDocPointerDown, true);
            document.addEventListener('focusin', this._onDocFocusIn, true);
            window.addEventListener('resize', this._onViewportChange, { passive: true });
            window.addEventListener('scroll', this._onViewportChange, { capture: true, passive: true });
            if (window.visualViewport) {
                window.visualViewport.addEventListener('resize', this._onViewportChange, { passive: true });
                window.visualViewport.addEventListener('scroll', this._onViewportChange, { passive: true });
            }
        },

        detachOpenListeners() {
            if (!this._openListenersAttached || typeof document === 'undefined' || typeof document.removeEventListener !== 'function' || typeof window === 'undefined' || typeof window.removeEventListener !== 'function') {
                return;
            }
            this._openListenersAttached = false;

            if (this._onDocPointerDown) {
                document.removeEventListener('pointerdown', this._onDocPointerDown, true);
                this._onDocPointerDown = null;
            }
            if (this._onDocFocusIn) {
                document.removeEventListener('focusin', this._onDocFocusIn, true);
                this._onDocFocusIn = null;
            }
            if (this._onViewportChange) {
                window.removeEventListener('resize', this._onViewportChange);
                window.removeEventListener('scroll', this._onViewportChange, { capture: true });
                if (window.visualViewport) {
                    window.visualViewport.removeEventListener('resize', this._onViewportChange);
                    window.visualViewport.removeEventListener('scroll', this._onViewportChange);
                }
                this._onViewportChange = null;
            }
            if (this._repositionRaf && typeof cancelAnimationFrame === 'function') {
                cancelAnimationFrame(this._repositionRaf);
                this._repositionRaf = null;
            }
        },

        destroy() {
            this._destroyed = true;
            this.invalidateIntent();
            this.detachOpenListeners();
            mountedComboboxControllers.delete(this);

            if (activeComboboxController === this) {
                activeComboboxController = null;
            }
            if (this._syncObserver) {
                this._syncObserver.disconnect();
                this._syncObserver = null;
            }
            if (typeof this._morphHookCleanup === 'function') {
                this._morphHookCleanup();
                this._morphHookCleanup = null;
            }
            if (typeof window !== 'undefined' && typeof window.removeEventListener === 'function') {
                if (this._onOnline) {
                    window.removeEventListener('online', this._onOnline);
                    this._onOnline = null;
                }
                if (this._onOffline) {
                    window.removeEventListener('offline', this._onOffline);
                    this._onOffline = null;
                }
            }
            if (this._movedOverlayToModal && this.$refs?.overlayPanel && this.$refs.overlayPanel.isConnected) {
                this.$refs.overlayPanel.remove();
            }
        },

        get isSelected() {
            return this.selectedId !== null && this.selectedId !== '';
        },

        get listboxId() {
            return this.id + '-listbox';
        },

        optionId(item) {
            if (!item || item.id === undefined || item.id === null) {
                return '';
            }
            return this.id + '-option-' + item.id;
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

        invalidateScope() {
            this.invalidateIntent();
            this.syncing = false;
            return ++selectionToken;
        },

        setCompany(companyId) {
            this.invalidateScope();
            this.currentCompanyId = companyId;
            this.items = [];
            this.hasMore = false;
            this.nextCursor = null;
            this.open = false;
            this.activeIndex = -1;
            this.activeId = null;
            this.lastSearchedQuery = undefined;
        },

        openOverlay() {
            if (this.isBlocked()) return;
            closeActiveCombobox(this);
            activeComboboxController = this;
            this.open = true;
            this.ensureOverlayPlacement();
            this.repositionOverlay();
            this.attachOpenListeners();

            if (this.overlayMode === 'sheet' && this.$refs?.sheetInput && typeof this.$nextTick === 'function') {
                this.$nextTick(() => {
                    this.$refs.sheetInput?.focus?.({ preventScroll: true });
                });
            }
        },

        closeOverlay({ restoreFocus = false, preserveQuery = false } = {}) {
            ++generation;
            if (debounceTimer) { clearTimeout(debounceTimer); debounceTimer = null; }
            this.searching = false;
            this.loadingMore = false;
            const wasSheet = this.overlayMode === 'sheet';
            this.open = false;
            if (!preserveQuery) {
                this.query = this.selectedLabel;
            }
            this.activeIndex = -1;
            this.activeId = null;
            this.detachOpenListeners();
            if (activeComboboxController && activeComboboxController.id === this.id) {
                activeComboboxController = null;
            }
            if (restoreFocus && wasSheet && this.$refs?.searchInput?.focus) {
                if (typeof this.$nextTick === 'function') {
                    this.$nextTick(() => this.$refs.searchInput?.focus?.({ preventScroll: true }));
                } else {
                    this.$refs.searchInput.focus({ preventScroll: true });
                }
            }
        },

        handleTriggerClick() {
            if (this.isBlocked()) return;
            if (!this.open) {
                this.handleFocus();
            } else {
                this.repositionOverlay();
            }
        },

        handleInput() {
            if (this.isBlocked()) return;

            this.inputVersion++;
            const currentGen = this.invalidateIntent();
            const capturedQuery = this.query;
            const capturedInputVersion = this.inputVersion;

            this.items = [];
            this.hasMore = false;
            this.activeIndex = -1;
            this.activeId = null;
            this.failed = false;

            if (!this.open) {
                this.openOverlay();
            }
            this.nextCursor = null;

            debounceTimer = setTimeout(() => {
                if (currentGen !== generation || capturedInputVersion !== this.inputVersion) return;
                this.performSearch(currentGen, capturedQuery, capturedInputVersion);
            }, 220);
        },

        handleFocus() {
            if (this.isBlocked()) return;
            if (!this.open) {
                this.openOverlay();
                const displayingSelected = this.isSelected && this.query === this.selectedLabel;
                const effectiveBrowseQuery = displayingSelected ? '' : this.query;
                const needsSelectedBrowse = displayingSelected && (this.lastSearchedQuery !== '' || this.items.length <= 1);
                if (!this.items.length || needsSelectedBrowse || (this.query && this.query !== this.selectedLabel)) {
                    this.performSearch(undefined, effectiveBrowseQuery, this.inputVersion);
                }
            }
        },

        retrySearch() {
            if (this.isBlocked()) return;
            const displayingSelected = this.isSelected && this.query === this.selectedLabel;
            this.performSearch(undefined, displayingSelected ? '' : this.query, this.inputVersion);
        },

        async performSearch(expectedGen, expectedQuery, expectedInputVersion = this.inputVersion) {
            if (this.isBlocked()) return;
            if (expectedGen !== undefined && expectedGen !== generation) return;
            if (!this.$wire || typeof this.$wire.searchSelector !== 'function') {
                return;
            }

            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                this.offline = true;
                this.failed = true;
                this.searching = false;
                return;
            }

            const currentGen = expectedGen !== undefined ? expectedGen : this.invalidateIntent();
            const displayingSelected = this.isSelected && this.query === this.selectedLabel;
            const searchQuery = expectedQuery !== undefined ? expectedQuery : (displayingSelected ? '' : this.query);
            const expectedCompanyId = this.currentCompanyId;

            this.searching = true;
            this.failed = false;
            this.offline = false;

            try {
                const response = await this.$wire.searchSelector(
                    this.type,
                    searchQuery,
                    this.selectedId ? parseInt(this.selectedId, 10) : null,
                    { generation: currentGen, limit: 25 }
                );

                // Discard stale responses from older generations, changed queries, or company switches
                if (!response || (response.generation !== undefined && Number(response.generation) !== generation) || currentGen !== generation) {
                    return;
                }
                if (expectedInputVersion !== this.inputVersion) {
                    return;
                }
                const isSelectedBrowse = searchQuery === '' && this.isSelected && this.query === this.selectedLabel;
                if (!isSelectedBrowse && this.query !== searchQuery) {
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
                this.lastSearchedQuery = searchQuery;
                if (!this.syncing && response.selected_item && String(this.selectedId) === String(response.selected_item.id)) {
                    this.selectedLabel = response.selected_item.label;
                    this.lastSyncedSelectedLabel = response.selected_item.label;
                }
                this.activeIndex = -1;
                this.activeId = null;
                if (!this.open) {
                    this.openOverlay();
                } else {
                    this.ensureOverlayPlacement();
                    this.repositionOverlay();
                }

                if (this.items.length > 0 && this.open) {
                    this.liveAnnouncement = String(this.items.length);
                } else {
                    this.liveAnnouncement = '';
                }
            } catch (err) {
                if (currentGen === generation) {
                    this.failed = true;
                    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                        this.offline = true;
                    }
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
            const expectedCompanyId = this.currentCompanyId;
            const cursorToLoad = this.nextCursor;
            const expectedInputVersion = this.inputVersion;
            const displayingSelected = this.isSelected && this.query === this.selectedLabel;
            const searchQuery = displayingSelected ? '' : this.query;

            this.loadingMore = true;

            try {
                const response = await this.$wire.searchSelector(
                    this.type,
                    searchQuery,
                    this.selectedId ? parseInt(this.selectedId, 10) : null,
                    {
                        cursor: cursorToLoad,
                        generation: currentGen,
                        limit: 25,
                    }
                );

                if (!response || (response.generation !== undefined && Number(response.generation) !== generation) || currentGen !== generation) {
                    return;
                }
                if (expectedInputVersion !== this.inputVersion) {
                    return;
                }
                const isSelectedBrowse = searchQuery === '' && this.isSelected && this.query === this.selectedLabel;
                if (!isSelectedBrowse && this.query !== searchQuery) {
                    return;
                }
                if (expectedCompanyId !== null && response.company_id && response.company_id !== expectedCompanyId) {
                    return;
                }

                if (Array.isArray(response.items)) {
                    const existingIds = new Set(this.items.map(i => String(i.id)));
                    const incoming = response.items.filter(i => !existingIds.has(String(i.id)));
                    this.items = [...this.items, ...incoming];
                }

                this.hasMore = Boolean(response.has_more);
                this.nextCursor = response.next_cursor || null;
            } catch (err) {
                if (currentGen === generation) {
                    this.failed = true;
                    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                        this.offline = true;
                    }
                }
            } finally {
                if (currentGen === generation) {
                    this.loadingMore = false;
                }
            }
        },

        handleOverlayKeydown(e) {
            if (this.overlayMode !== 'sheet' || !this.open) {
                return;
            }
            if (e.key === 'Escape') {
                e.preventDefault();
                e.stopPropagation();
                this.closeOverlay({ restoreFocus: true });
                return;
            }
            if (e.key === 'Tab') {
                const panel = this.$refs?.overlayPanel;
                if (!panel || typeof panel.querySelectorAll !== 'function') return;
                const focusables = Array.from(
                    panel.querySelectorAll('button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])')
                ).filter(el => el.offsetParent !== null || el === document.activeElement);
                if (focusables.length === 0) return;
                const first = focusables[0];
                const last = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        },

        handleKeydown(e) {
            if (this.isBlocked()) return;

            switch (e.key) {
                case 'ArrowDown':
                    e.preventDefault();
                    if (!this.open) {
                        this.handleFocus();
                    } else if (this.items.length) {
                        this.activeIndex = (this.activeIndex + 1) % this.items.length;
                        this.updateActiveId();
                        this.scrollToActive();
                    }
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    if (!this.open) {
                        this.handleFocus();
                    } else if (this.items.length) {
                        this.activeIndex = this.activeIndex <= 0 ? this.items.length - 1 : this.activeIndex - 1;
                        this.updateActiveId();
                        this.scrollToActive();
                    }
                    break;
                case 'Home':
                    if (this.open && this.items.length) {
                        e.preventDefault();
                        this.activeIndex = 0;
                        this.updateActiveId();
                        this.scrollToActive();
                    }
                    break;
                case 'End':
                    if (this.open && this.items.length) {
                        e.preventDefault();
                        this.activeIndex = this.items.length - 1;
                        this.updateActiveId();
                        this.scrollToActive();
                    }
                    break;
                case 'Enter':
                    if (this.open) {
                        e.preventDefault();
                        e.stopPropagation?.();
                        if (this.activeIndex >= 0 && this.items[this.activeIndex]) {
                            this.selectItem(this.items[this.activeIndex]);
                        }
                    }
                    break;
                case 'Escape':
                    if (this.open) {
                        e.preventDefault();
                        e.stopPropagation?.();
                        this.closeOverlay({ restoreFocus: true });
                    }
                    break;
                case 'Tab':
                    if (this.open && this.overlayMode !== 'sheet') {
                        if (this.activeIndex >= 0 && this.items[this.activeIndex]) {
                            this.selectItem(this.items[this.activeIndex]);
                        } else {
                            this.closeOverlay({ restoreFocus: false });
                        }
                    }
                    break;
            }
        },

        updateActiveId() {
            if (this.activeIndex >= 0 && this.items[this.activeIndex]) {
                this.activeId = this.optionId(this.items[this.activeIndex]);
            } else {
                this.activeId = null;
            }
        },

        scrollToActive() {
            if (typeof this.$nextTick === 'function') {
                this.$nextTick(() => {
                    if (typeof document === 'undefined' || !this.activeId) return;
                    const el = document.getElementById(this.activeId);
                    if (el && el.scrollIntoView) {
                        el.scrollIntoView({ block: 'nearest' });
                    }
                });
            }
        },

        async selectItem(item) {
            if (this.isBlocked() || this.syncing || !item) return;
            if (!this.items.some(candidate => String(candidate.id) === String(item.id))) return;

            this.invalidateIntent();
            const currentToken = ++selectionToken;
            const prevId = this.selectedId;
            const prevLabel = this.selectedLabel;
            const prevQuery = this.query;
            const wasSheet = this.overlayMode === 'sheet';

            // Immediate truthful label and close before server await
            this.selectedId = String(item.id);
            this.selectedLabel = item.label;
            this.query = item.label;
            this.closeOverlay({ restoreFocus: wasSheet, preserveQuery: true });
            this.failed = false;
            this.syncing = true;

            this.$dispatch('combobox-selected', {
                id: this.id,
                type: this.type,
                value: item.id,
                item: item,
            });

            try {
                if (this.onSelect && this.$wire && typeof this.$wire[this.onSelect] === 'function') {
                    const success = await this.$wire[this.onSelect](
                        ...this.extraArgs,
                        parseInt(item.id, 10),
                        item.meta?.matched_product_unit_id || null
                    );
                    if (currentToken !== selectionToken) {
                        return;
                    }
                    if (success === false) {
                        this.selectedId = prevId;
                        this.selectedLabel = prevLabel;
                        this.query = prevQuery;
                        this.failed = true;
                        this.$dispatch('combobox-selected', {
                            id: this.id,
                            type: this.type,
                            value: prevId,
                            item: null,
                        });
                        return;
                    }
                } else if (this.wireModel && this.$wire) {
                    if (typeof this.$wire.$set === 'function') {
                        await this.$wire.$set(this.wireModel, item.id);
                    } else if (typeof this.$wire.set === 'function') {
                        await this.$wire.set(this.wireModel, item.id);
                    }
                    if (currentToken !== selectionToken) {
                        return;
                    }
                }

                if (currentToken !== selectionToken) {
                    return;
                }

                this.lastSyncedSelectedId = String(item.id);
                this.lastSyncedSelectedLabel = item.label;
                this.failed = false;
            } catch (err) {
                if (currentToken === selectionToken) {
                    this.selectedId = prevId;
                    this.selectedLabel = prevLabel;
                    this.query = prevQuery;
                    this.failed = true;
                    this.$dispatch('combobox-selected', {
                        id: this.id,
                        type: this.type,
                        value: prevId,
                        item: null,
                    });
                }
            } finally {
                if (currentToken === selectionToken) {
                    this.syncing = false;
                }
            }
        },

        async clear() {
            if (this.isBlocked() || this.syncing) return;

            this.invalidateIntent();
            const currentToken = ++selectionToken;
            const prevId = this.selectedId;
            const prevLabel = this.selectedLabel;
            const prevQuery = this.query;
            const prevItems = this.items;
            const prevHasMore = this.hasMore;
            this.selectedId = null;
            this.selectedLabel = '';
            this.query = '';
            this.items = [];
            this.hasMore = false;
            this.nextCursor = null;
            this.loadingMore = false;
            this.searching = false;
            this.closeOverlay({ restoreFocus: false, preserveQuery: true });
            this.failed = false;
            this.lastSearchedQuery = undefined;
            this.syncing = true;

            this.$dispatch('combobox-cleared', { id: this.id });
            if (typeof this.$nextTick === 'function') {
                this.$nextTick(() => {
                    this.$refs.searchInput?.focus?.({ preventScroll: true });
                });
            }

            try {
                if (this.onSelect && this.$wire && typeof this.$wire[this.onSelect] === 'function') {
                    const success = await this.$wire[this.onSelect](...this.extraArgs, null, null);
                    if (currentToken !== selectionToken) {
                        return;
                    }
                    if (success === false) {
                        this.selectedId = prevId;
                        this.selectedLabel = prevLabel;
                        this.query = prevQuery;
                        this.items = prevItems;
                        this.hasMore = prevHasMore;
                        this.failed = true;
                        return;
                    }
                } else if (this.wireModel && this.$wire) {
                    if (typeof this.$wire.$set === 'function') {
                        await this.$wire.$set(this.wireModel, null);
                    } else if (typeof this.$wire.set === 'function') {
                        await this.$wire.set(this.wireModel, null);
                    }
                    if (currentToken !== selectionToken) {
                        return;
                    }
                }

                if (currentToken !== selectionToken) {
                    return;
                }

                this.lastSyncedSelectedId = null;
                this.lastSyncedSelectedLabel = '';
                this.failed = false;
            } catch (err) {
                if (currentToken === selectionToken) {
                    this.selectedId = prevId;
                    this.selectedLabel = prevLabel;
                    this.query = prevQuery;
                    this.items = prevItems;
                    this.hasMore = prevHasMore;
                    this.failed = true;
                }
            } finally {
                if (currentToken === selectionToken) {
                    this.syncing = false;
                }
            }
        },

        clearSelection() {
            return this.clear();
        },

        handleOutsideClick() {
            if (this.open) {
                this.closeOverlay({ restoreFocus: false });
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
    } else if (typeof document !== 'undefined' && typeof document.addEventListener === 'function') {
        document.addEventListener('alpine:init', register, { once: true });
    }
}
