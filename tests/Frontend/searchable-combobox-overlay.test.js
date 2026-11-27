import test from 'node:test';
import assert from 'node:assert/strict';
import {
    searchableCombobox,
    computeOverlayGeometry,
    getActiveComboboxId,
    closeActiveCombobox,
} from '../../resources/js/searchable-combobox.js';

function makeComponent(config = {}) {
    const comp = searchableCombobox({
        id: 'test-cb',
        type: 'product',
        context: 'sales',
        ...config,
    });
    comp.$watch = () => {};
    comp.$dispatch = () => {};
    comp.$nextTick = (fn) => {
        if (typeof fn === 'function') fn();
    };
    comp.$refs = {
        searchInput: {
            focus() {},
            getBoundingClientRect: () => ({
                top: 120,
                bottom: 152,
                left: 100,
                right: 300,
                width: 200,
                height: 32,
            }),
        },
        listbox: {},
    };
    return comp;
}

test('singleton registry closes prior open combobox when another opens', () => {
    closeActiveCombobox();
    const first = makeComponent({ id: 'row-0-cb', label: 'Apple', value: 1 });
    const second = makeComponent({ id: 'row-1-cb', label: 'Banana', value: 2 });

    first.openOverlay();
    assert.equal(first.open, true);
    assert.equal(getActiveComboboxId(), 'row-0-cb');

    second.openOverlay();
    assert.equal(second.open, true);
    assert.equal(first.open, false);
    assert.equal(getActiveComboboxId(), 'row-1-cb');

    second.destroy();
    first.destroy();
    assert.equal(getActiveComboboxId(), null);
});

test('browse-on-focus searches empty string instead of formatted Name (SKU) label', async () => {
    const calls = [];
    const comp = makeComponent({
        id: 'edit-draft-cb',
        value: 42,
        label: 'Olive Oil (OIL-01)',
        options: [],
    });
    comp.$wire = {
        searchSelector: async (type, query, selectedId, options) => {
            calls.push({ type, query, selectedId, options });
            return {
                generation: options.generation,
                items: [
                    { id: 42, label: 'Olive Oil (OIL-01)' },
                    { id: 43, label: 'Sugar (SUG-02)' },
                ],
                has_more: false,
            };
        },
    };

    comp.handleFocus();
    await new Promise(resolve => setTimeout(resolve, 10));

    assert.equal(calls.length, 1);
    assert.equal(calls[0].query, '');
    assert.equal(calls[0].selectedId, 42);
    assert.equal(comp.items.length, 2);
    assert.equal(comp.query, 'Olive Oil (OIL-01)');
    assert.equal(comp.selectedId, '42');
    comp.destroy();
});

test('Enter always calls preventDefault and stopPropagation while overlay is open even if activeIndex is -1', () => {
    const comp = makeComponent({ id: 'kbd-cb', options: [{ id: 10, label: 'Ten' }] });
    comp.open = true;
    comp.activeIndex = -1;

    let prevented = false;
    let stopped = false;
    comp.handleKeydown({
        key: 'Enter',
        preventDefault() { prevented = true; },
        stopPropagation() { stopped = true; },
    });

    assert.equal(prevented, true);
    assert.equal(stopped, true);
    assert.equal(comp.selectedId, null);

    // ArrowDown -> Home -> End emit exact -option- activeId
    comp.handleKeydown({ key: 'ArrowDown', preventDefault() {} });
    assert.equal(comp.activeIndex, 0);
    assert.equal(comp.activeId, 'kbd-cb-option-10');
    assert.equal(comp.optionId({ id: 10 }), 'kbd-cb-option-10');

    // Escape stops propagation so enclosing dialog/form does not close/submit
    let escStopped = false;
    comp.handleKeydown({
        key: 'Escape',
        preventDefault() {},
        stopPropagation() { escStopped = true; },
    });
    assert.equal(escStopped, true);
    assert.equal(comp.open, false);
    comp.destroy();
});

test('computeOverlayGeometry adapts popup width, bottom-flip, RTL clamp, and phone sheet mode cleanly', () => {
    // Desktop narrow cell expands cleanly within 1280px viewport
    const desktopNarrow = computeOverlayGeometry({
        triggerRect: { top: 200, bottom: 232, left: 120, right: 280, width: 160 },
        viewportWidth: 1280,
        viewportHeight: 800,
        dir: 'ltr',
    });
    assert.equal(desktopNarrow.mode, 'popup');
    assert.equal(desktopNarrow.placement, 'bottom');
    assert.equal(desktopNarrow.width, 280);
    assert.equal(desktopNarrow.left, 120);

    // Near bottom flips upward cleanly without clipping below viewport
    const bottomFlip = computeOverlayGeometry({
        triggerRect: { top: 720, bottom: 752, left: 100, right: 360, width: 260 },
        viewportWidth: 1280,
        viewportHeight: 800,
        dir: 'ltr',
    });
    assert.equal(bottomFlip.mode, 'popup');
    assert.equal(bottomFlip.placement, 'top');
    assert.ok(bottomFlip.top + bottomFlip.maxHeight <= 720);

    // RTL aligns right edge cleanly without horizontal overflow
    const rtlLeftEdge = computeOverlayGeometry({
        triggerRect: { top: 200, bottom: 232, left: 20, right: 180, width: 160 },
        viewportWidth: 820,
        viewportHeight: 1180,
        dir: 'rtl',
    });
    assert.equal(rtlLeftEdge.mode, 'popup');
    assert.ok(rtlLeftEdge.left >= 8);
    assert.ok(rtlLeftEdge.left + rtlLeftEdge.width <= 812);

    // Mobile 320x568 with sticky header + mobile bottom bar switches to full sheet
    const mobileSmall = computeOverlayGeometry({
        triggerRect: { top: 264, bottom: 308, left: 16, right: 304, width: 288 },
        viewportWidth: 320,
        viewportHeight: 568,
        safeTop: 60,
        safeBottom: 411,
        dir: 'ltr',
        isMobileSheetPreferred: true,
    });
    assert.equal(mobileSmall.mode, 'sheet');
    assert.equal(mobileSmall.width, 320);
    assert.equal(mobileSmall.maxHeight, 568);

    // Virtual keyboard shrinking visualViewport on phone forces sheet sizing to visualViewport
    const keyboardPhone = computeOverlayGeometry({
        triggerRect: { top: 180, bottom: 224, left: 16, right: 374, width: 358 },
        viewportWidth: 390,
        viewportHeight: 320,
        viewportOffsetTop: 20,
        dir: 'rtl',
    });
    assert.equal(keyboardPhone.mode, 'sheet');
    assert.equal(keyboardPhone.top, 20);
    assert.equal(keyboardPhone.maxHeight, 320);
});

test('syncFromServerProps updates morph attributes and row_key without clobbering active search query', () => {
    const comp = makeComponent({ id: 'invoice-desktop-line-1-product-id', extraArgs: ['uuid-old'] });
    comp.$refs.syncBridge = {
        dataset: {
            comboboxId: 'invoice-desktop-line-0-product-id',
            type: 'product',
            context: 'sales',
            selectedId: '99',
            selectedLabel: 'Barcoded Soap (BAR-99)',
            extraArgs: JSON.stringify(['uuid-row-b']),
            disabled: '0',
            hasError: '1',
            placeholder: 'Product',
            ariaLabel: 'Product 1',
        },
    };

    comp.syncFromServerProps();
    assert.equal(comp.id, 'invoice-desktop-line-0-product-id');
    assert.equal(comp.selectedId, '99');
    assert.equal(comp.selectedLabel, 'Barcoded Soap (BAR-99)');
    assert.equal(comp.query, 'Barcoded Soap (BAR-99)');
    assert.deepEqual(comp.extraArgs, ['uuid-row-b']);
    assert.equal(comp.hasError, true);

    // While user actively types a different query and server selectedId did NOT change, query is preserved
    comp.open = true;
    comp.query = 'typed-by-user';
    comp.$refs.syncBridge.dataset.selectedLabel = 'Barcoded Soap (BAR-99) v2';
    comp.syncFromServerProps();
    assert.equal(comp.query, 'typed-by-user');
    comp.destroy();
});


test('dismissed slow browse cannot reopen or supersede the next active picker', async () => {
    closeActiveCombobox();
    const first = makeComponent({ id: 'slow-picker' });
    const second = makeComponent({ id: 'next-picker' });
    let finish;
    first.$wire = { searchSelector: () => new Promise(resolve => { finish = resolve; }) };
    const pending = first.performSearch();
    second.openOverlay();
    first.closeOverlay();
    finish({ items: [{ id: 1, label: 'Late option' }], has_more: false });
    await pending;
    assert.equal(first.open, false);
    assert.equal(second.open, true);
    assert.equal(getActiveComboboxId(), 'next-picker');
    first.destroy(); second.destroy();
});

test('clearing a sheet restores the visible trigger, never the hidden sheet input', async () => {
    const comp = makeComponent({ value: 1, label: 'Selected' });
    let triggerFocus = 0;
    let sheetFocus = 0;
    comp.overlayMode = 'sheet';
    comp.$refs.searchInput.focus = () => { triggerFocus++; comp.handleFocus(); };
    comp.$refs.sheetInput = { focus() { sheetFocus++; } };
    comp.$wire = { $set: async () => {} };
    await comp.clear();
    assert.equal(comp.open, false);
    assert.equal(triggerFocus, 1);
    assert.equal(sheetFocus, 0);
    comp.destroy();
});

test('handleInput marks pendingDebounce as loading truth until debounce triggers performSearch without false No results', async () => {
    const comp = makeComponent({ id: 'debounce-cb', options: [{ id: 1, label: 'Alpha' }] });
    let resolvedCalls = 0;
    let resolveSearch;
    comp.$wire = {
        searchSelector: async (_type, query, _sel, opts) => {
            resolvedCalls++;
            return new Promise((resolve) => {
                resolveSearch = () => resolve({
                    generation: opts.generation,
                    items: [{ id: 2, label: 'أسعد الصباح' }],
                    has_more: false,
                });
            });
        },
    };

    comp.query = 'أسعد';
    comp.handleInput();

    // Immediately after clearing items, pendingDebounce keeps UI in loading state rather than showing false No results
    assert.equal(comp.open, true);
    assert.equal(comp.items.length, 0);
    assert.equal(comp.pendingDebounce, true);
    assert.equal(comp.searching, false);
    assert.equal(comp.failed, false);

    // Wait past 220ms debounce so performSearch starts synchronously before async resolution
    await new Promise((r) => setTimeout(r, 250));
    assert.equal(comp.pendingDebounce, false);
    assert.equal(comp.searching, true);
    assert.equal(resolvedCalls, 1);

    resolveSearch();
    await new Promise((r) => setTimeout(r, 10));
    assert.equal(comp.pendingDebounce, false);
    assert.equal(comp.searching, false);
    assert.equal(comp.items.length, 1);
    comp.destroy();
});

test('missing wire actions surface visible failure instead of silent no-results or silent success', async () => {
    const comp = makeComponent({ id: 'broken-wire', onSelect: 'quickAddProduct', options: [] });
    comp.$wire = {};

    await comp.performSearch();
    assert.equal(comp.failed, true);
    assert.equal(comp.searching, false);
    assert.equal(comp.pendingDebounce, false);

    comp.items = [{ id: 10, label: 'Soap' }];
    await comp.selectItem(comp.items[0]);
    assert.equal(comp.failed, true);
    assert.equal(comp.selectedId, null);
    assert.equal(comp.query, '');
    comp.destroy();
});

test('pagination failure keeps page 1 results and retrySearch resumes loadMore instead of wiping list', async () => {
    const comp = makeComponent({ id: 'paged-cb', options: [] });
    let failNext = true;
    const seenCursors = [];
    comp.$wire = {
        searchSelector: async (_type, _query, _sel, opts) => {
            seenCursors.push(opts.cursor ?? null);
            if (!opts.cursor) {
                return {
                    generation: opts.generation,
                    items: [{ id: 1, label: 'Page One Item' }],
                    has_more: true,
                    next_cursor: 'cursor-page-2',
                };
            }
            if (failNext) {
                failNext = false;
                throw new Error('503 temporary');
            }
            return {
                generation: opts.generation,
                items: [{ id: 2, label: 'Page Two Item' }],
                has_more: false,
                next_cursor: null,
            };
        },
    };

    await comp.performSearch();
    assert.equal(comp.items.length, 1);
    assert.equal(comp.hasMore, true);
    assert.equal(comp.nextCursor, 'cursor-page-2');

    await comp.loadMore();
    assert.equal(comp.failed, true);
    assert.equal(comp.items.length, 1);
    assert.equal(comp.nextCursor, 'cursor-page-2');

    comp.retrySearch();
    await new Promise((r) => setTimeout(r, 15));
    assert.equal(comp.failed, false);
    assert.deepEqual(comp.items.map((i) => i.id), [1, 2]);
    assert.deepEqual(seenCursors, [null, 'cursor-page-2', 'cursor-page-2']);
    comp.destroy();
});

test('resetAfterSelect clears quick-add combobox after server acceptance and rolls back on failure', async () => {
    const comp = makeComponent({
        id: 'purchase-product-search',
        type: 'product',
        context: 'purchasing',
        onSelect: 'quickAddProduct',
        resetAfterSelect: true,
        options: [{ id: 5, label: 'Toothpaste (TP-1)', meta: { matched_product_unit_id: 9 } }],
    });
    const received = [];
    let allow = true;
    comp.$wire = {
        quickAddProduct: async (...args) => {
            received.push(args);
            return allow;
        },
    };

    const option = comp.items[0];

    await comp.selectItem(option);
    assert.deepEqual(received, [[5, 9]]);
    assert.equal(comp.selectedId, null);
    assert.equal(comp.selectedLabel, '');
    assert.equal(comp.query, '');
    assert.deepEqual(comp.items, []);
    assert.equal(comp.hasMore, false);
    assert.equal(comp.nextCursor, null);
    assert.equal(comp.failed, false);

    allow = false;
    comp.items = [option];
    await comp.selectItem(option);
    assert.equal(comp.failed, true);
    assert.equal(comp.selectedId, null);
    comp.destroy();
});

test('resetAfterSelect does not wipe newer input typed while select RPC is pending', async () => {
    let resolveQuickAdd;
    const comp = makeComponent({
        id: 'purchase-product-search',
        type: 'product',
        context: 'purchasing',
        onSelect: 'quickAddProduct',
        resetAfterSelect: true,
        options: [{ id: 5, label: 'Toothpaste (TP-1)' }],
    });
    comp.$wire = {
        quickAddProduct: () => new Promise((resolve) => { resolveQuickAdd = resolve; }),
    };

    const pendingSelect = comp.selectItem(comp.items[0]);
    assert.equal(comp.syncing, true);
    assert.equal(comp.query, 'Toothpaste (TP-1)');

    // Simulate newer typing occurring before quickAddProduct RPC settles
    comp.query = 'زيت';
    comp.handleInput();
    assert.equal(comp.query, 'زيت');

    resolveQuickAdd(true);
    await pendingSelect;

    assert.equal(comp.syncing, false);
    assert.equal(comp.selectedId, null);
    assert.equal(comp.query, 'زيت');
    comp.destroy();
});

test('failed selectItem on cached empty-query browse clears cleanly on reopen without clearing real search errors or nextCursor', async () => {
    const cachedOptions = [
        { id: 10, label: 'Cached Item A' },
        { id: 11, label: 'Cached Item B' },
    ];
    const seenCursors = [];
    let failNextLoadMore = true;
    const comp = makeComponent({
        id: 'browse-refusal-cb',
        onSelect: 'quickAddProduct',
        resetAfterSelect: true,
        options: cachedOptions,
    });
    comp.hasMore = true;
    comp.nextCursor = 'cursor-next';
    comp.lastSearchedQuery = '';
    comp.$wire = {
        quickAddProduct: async () => false,
        searchSelector: async (_t, _q, _s, opts) => {
            seenCursors.push(opts.cursor ?? null);
            if (failNextLoadMore) {
                failNextLoadMore = false;
                throw new Error('503 search outage');
            }
            return {
                generation: opts.generation,
                items: [{ id: 12, label: 'Page 2 Item C' }],
                has_more: false,
                next_cursor: null,
            };
        },
    };

    // 1. Failed selection with cached empty-query browse items sets failed=true, searchFailed=false, and preserves nextCursor
    comp.open = true;
    await comp.selectItem(cachedOptions[0]);
    assert.equal(comp.open, false);
    assert.equal(comp.failed, true);
    assert.equal(comp.searchFailed, false);
    assert.equal(comp.items.length, 2);
    assert.equal(comp.hasMore, true);
    assert.equal(comp.nextCursor, 'cursor-next');

    // Reopening valid cached browse must NOT show stale search error banner
    comp.handleFocus();
    assert.equal(comp.open, true);
    assert.equal(comp.failed, false);
    assert.equal(comp.searchFailed, false);
    assert.equal(seenCursors.length, 0);
    assert.equal(comp.nextCursor, 'cursor-next');

    // 2. Real loadMore failure sets searchFailed=true and loadMoreFailed=true and persists across close/reopen
    await comp.loadMore();
    assert.deepEqual(seenCursors, ['cursor-next']);
    assert.equal(comp.failed, true);
    assert.equal(comp.searchFailed, true);
    assert.equal(comp.loadMoreFailed, true);
    assert.equal(comp.nextCursor, 'cursor-next');

    comp.closeOverlay();
    comp.handleFocus();
    assert.equal(comp.open, true);
    assert.equal(comp.failed, true);
    assert.equal(comp.searchFailed, true);
    assert.equal(comp.loadMoreFailed, true);

    // Clicking Retry dispatches loadMore with preserved nextCursor
    comp.retrySearch();
    await new Promise((r) => setTimeout(r, 15));
    assert.deepEqual(seenCursors, ['cursor-next', 'cursor-next']);
    assert.equal(comp.failed, false);
    assert.equal(comp.searchFailed, false);
    assert.equal(comp.loadMoreFailed, false);
    assert.deepEqual(comp.items.map((i) => i.id), [10, 11, 12]);
    comp.destroy();
});

test('non-reset selectItem and clear rollback preserve nextCursor without desynchronizing hasMore', async () => {
    const comp = makeComponent({
        id: 'party-cursor-cb',
        onSelect: 'selectVendor',
        resetAfterSelect: false,
        options: [
            { id: 1, label: 'Vendor 1' },
            { id: 2, label: 'Vendor 2' },
        ],
    });
    comp.hasMore = true;
    comp.nextCursor = 'vendor-cursor-2';
    comp.lastSearchedQuery = '';
    let allowClear = false;
    comp.$wire = {
        selectVendor: async (_id) => allowClear,
    };

    // Successful selection keeps cached page 1 cursor intact for subsequent reopen + loadMore
    allowClear = true;
    await comp.selectItem(comp.items[0]);
    assert.equal(comp.selectedId, '1');
    assert.equal(comp.hasMore, true);
    assert.equal(comp.nextCursor, 'vendor-cursor-2');

    // Failed clear() restores nextCursor alongside items and hasMore
    allowClear = false;
    await comp.clear();
    assert.equal(comp.failed, true);
    assert.equal(comp.selectedId, '1');
    assert.equal(comp.items.length, 2);
    assert.equal(comp.hasMore, true);
    assert.equal(comp.nextCursor, 'vendor-cursor-2');
    comp.destroy();
});

test('isBlocked respects rowMutationBusy and window.isDocumentBusy', () => {
    const comp = makeComponent({ id: 'busy-check-cb' });
    const wire = { $el: { dataset: {} } };
    comp.$wire = wire;

    assert.equal(comp.isBlocked(), false);
    wire.$el.dataset.rowMutationBusy = 'true';
    assert.equal(comp.isBlocked(), true);
    delete wire.$el.dataset.rowMutationBusy;
    assert.equal(comp.isBlocked(), false);

    const prevWindow = globalThis.window;
    try {
        globalThis.window = { isDocumentBusy: (w) => w === wire };
        assert.equal(comp.isBlocked(), true);
    } finally {
        if (prevWindow === undefined) delete globalThis.window;
        else globalThis.window = prevWindow;
    }
    comp.destroy();
});

test('desktop Escape closes overlay after Load More blur via overlay and document keydown handlers', () => {
    const comp = makeComponent({ id: 'escape-overlay-cb' });
    const keydownListeners = [];
    let focusCalled = false;
    const prevDocument = globalThis.document;
    const prevWindow = globalThis.window;

    try {
        globalThis.document = {
            body: {},
            activeElement: null,
            addEventListener(type, fn) {
                if (type === 'keydown') keydownListeners.push(fn);
            },
            removeEventListener(type, fn) {
                if (type === 'keydown') {
                    const idx = keydownListeners.indexOf(fn);
                    if (idx !== -1) keydownListeners.splice(idx, 1);
                }
            },
        };
        globalThis.document.activeElement = globalThis.document.body;
        globalThis.window = {
            addEventListener() {},
            removeEventListener() {},
        };

        comp.$refs = {
            searchInput: {
                focus() { focusCalled = true; },
            },
            overlayPanel: { contains: () => false },
        };
        comp.overlayMode = 'popup';
        comp.open = true;
        comp.attachOpenListeners();

        let stopped = false;
        let prevented = false;
        comp.handleOverlayKeydown({
            key: 'Escape',
            preventDefault() { prevented = true; },
            stopPropagation() { stopped = true; },
        });
        assert.equal(comp.open, false);
        assert.equal(prevented, true);
        assert.equal(stopped, true);
        assert.equal(focusCalled, true);

        focusCalled = false;
        comp.open = true;
        comp.attachOpenListeners();
        assert.equal(keydownListeners.length, 1);
        keydownListeners[0]({
            key: 'Escape',
            preventDefault() {},
            stopPropagation() {},
        });
        assert.equal(comp.open, false);
        assert.equal(focusCalled, true);
        assert.equal(keydownListeners.length, 0);
    } finally {
        comp.destroy();
        if (prevDocument === undefined) delete globalThis.document;
        else globalThis.document = prevDocument;
        if (prevWindow === undefined) delete globalThis.window;
        else globalThis.window = prevWindow;
    }
});
