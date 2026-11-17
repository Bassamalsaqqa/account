import test from 'node:test';
import assert from 'node:assert/strict';
import { searchableCombobox } from '../../resources/js/searchable-combobox.js';

function deferred() {
    let resolve, reject;
    const promise = new Promise((res, rej) => {
        resolve = res;
        reject = rej;
    });
    return { promise, resolve, reject };
}

function createComponent(config = {}) {
    const component = searchableCombobox(config);
    component.$dispatch = () => {};
    component.$nextTick = (fn) => { if (fn) fn(); };
    component.$refs = { searchInput: { focus() {} } };
    component.$watch = () => {};
    if (component.init) {
        component.init();
    }
    return component;
}

test('old first-page response arriving after new input during debounce is discarded', async () => {
    const d1 = deferred();
    const d2 = deferred();
    let callCount = 0;

    const comp = createComponent({
        type: 'customer',
    });

    comp.$wire = {
        searchSelector: async (type, query, selectedId, options) => {
            callCount++;
            if (query === 'first') {
                return d1.promise;
            }
            if (query === 'second') {
                return d2.promise;
            }
            throw new Error(`Unexpected query: ${query}`);
        },
    };

    // User types 'first'
    comp.query = 'first';
    comp.handleInput();

    // Trigger performSearch directly with the generation captured for 'first'
    const firstSearchPromise = comp.performSearch(1, 'first');

    // While first search is pending in-flight, user types 'second'
    comp.query = 'second';
    comp.handleInput();

    // Second search is triggered
    const secondSearchPromise = comp.performSearch(2, 'second');

    // Old first search resolves late with stale items
    d1.resolve({
        generation: 1,
        items: [{ id: 101, label: 'Customer Stale' }],
        has_more: false,
        next_cursor: null,
    });
    await firstSearchPromise;

    // Component must NOT show stale first search items
    assert.deepEqual(comp.items, []);

    // New second search resolves
    d2.resolve({
        generation: 2,
        items: [{ id: 202, label: 'Customer Fresh' }],
        has_more: false,
        next_cursor: null,
    });
    await secondSearchPromise;

    // Component state reflects fresh search
    assert.equal(comp.items.length, 1);
    assert.equal(comp.items[0].id, 202);
    assert.equal(comp.items[0].label, 'Customer Fresh');
    assert.equal(comp.open, true);
});

test('old paging response arriving during debounce and clear is discarded and does not reopen', async () => {
    const dPage2 = deferred();

    const comp = createComponent({
        type: 'product',
        options: [{ id: 1, label: 'Product 1' }],
    });

    comp.hasMore = true;
    comp.nextCursor = 'valid-cursor-token';
    comp.open = true;

    comp.$wire = {
        searchSelector: async (type, query, selectedId, options) => {
            if (options.cursor) {
                return dPage2.promise;
            }
            return { generation: options.generation, items: [], has_more: false };
        },
    };

    // Start loading second page (in flight)
    const loadMorePromise = comp.loadMore();
    assert.equal(comp.loadingMore, true);

    // User types into input during debounce, then clicks clear
    comp.query = 'prod';
    comp.handleInput();
    await comp.clear();

    // After clear, state must be cleanly reset
    assert.deepEqual(comp.items, []);
    assert.equal(comp.open, false);
    assert.equal(comp.selectedId, null);
    assert.equal(comp.hasMore, false);
    assert.equal(comp.nextCursor, null);

    // In-flight paging resolves late
    dPage2.resolve({
        generation: 0,
        items: [{ id: 2, label: 'Product 2' }],
        has_more: false,
        next_cursor: null,
    });
    await loadMorePromise;

    // Component state must remain cleared and closed; no stale items resurrected
    assert.deepEqual(comp.items, []);
    assert.equal(comp.open, false);
    assert.equal(comp.hasMore, false);
    assert.equal(comp.loadingMore, false);
});

test('second page append deduplicates existing items and preserves pagination order', async () => {
    const dPage2 = deferred();

    const comp = createComponent({
        type: 'product',
    });

    comp.items = [
        { id: 10, label: 'Widget A' },
        { id: 20, label: 'Widget B' },
    ];
    comp.hasMore = true;
    comp.nextCursor = 'token-page-2';
    comp.open = true;

    comp.$wire = {
        searchSelector: async (type, query, selectedId, options) => {
            assert.equal(options.cursor, 'token-page-2');
            return dPage2.promise;
        },
    };

    const loadMorePromise = comp.loadMore();

    // Page 2 returns an overlapping record (id 20) and a new record (id 30)
    dPage2.resolve({
        generation: 0,
        items: [
            { id: 20, label: 'Widget B' },
            { id: 30, label: 'Widget C' },
        ],
        has_more: true,
        next_cursor: 'token-page-3',
    });

    await loadMorePromise;

    // Duplicate item 20 must NOT be appended twice
    assert.equal(comp.items.length, 3);
    assert.deepEqual(comp.items.map(i => i.id), [10, 20, 30]);
    assert.equal(comp.hasMore, true);
    assert.equal(comp.nextCursor, 'token-page-3');
    assert.equal(comp.loadingMore, false);
});

test('company scope change invalidates generation and discards previous results', async () => {
    const dSearch = deferred();

    const comp = createComponent({
        type: 'customer',
    });
    comp.currentCompanyId = 1;

    comp.$wire = {
        searchSelector: async () => dSearch.promise,
    };

    comp.query = 'Acme';
    comp.handleInput();
    const searchPromise = comp.performSearch(1, 'Acme');

    // Switch company while search is pending
    comp.setCompany(2);

    assert.deepEqual(comp.items, []);
    assert.equal(comp.open, false);

    // Stale company search resolves
    dSearch.resolve({
        generation: 1,
        company_id: 1,
        items: [{ id: 99, label: 'Acme Old Company' }],
        has_more: false,
    });
    await searchPromise;

    // Results rejected because company/generation changed
    assert.deepEqual(comp.items, []);
    assert.equal(comp.open, false);
});

test('changed query immediately removes old rows and refuses stale selection', async () => {
    let selections = 0;
    const oldItem = { id: 123, label: 'Old query customer' };
    const comp = createComponent({ type: 'customer', onSelect: 'choose' });
    comp.$wire = { choose: async () => { selections++; } };
    comp.items = [oldItem];
    comp.activeIndex = 0;
    comp.hasMore = true;
    comp.query = 'new query';
    comp.handleInput();
    assert.deepEqual(comp.items, []);
    assert.equal(comp.activeIndex, -1);
    assert.equal(comp.hasMore, false);
    await comp.selectItem(oldItem);
    assert.equal(selections, 0);
    assert.equal(comp.selectedId, null);
    comp.setCompany(null); // Cancels the pending debounce without starting a request.
});

test('Livewire absent-property function proxies do not block search; real busy flags do', () => {
    const comp = createComponent();
    comp.$wire = { documentBusy: () => {}, saving: () => {} };
    assert.equal(comp.isBlocked(), false);
    comp.$wire.documentBusy = true;
    assert.equal(comp.isBlocked(), true);
    comp.$wire.documentBusy = false;
    comp.$wire.saving = true;
    assert.equal(comp.isBlocked(), true);
});

test('selectItem immediately sets truthful label, closes listbox, and marks syncing before server resolution', async () => {
    const dServer = deferred();
    const item = { id: 42, label: 'Widget Super', meta: { matched_product_unit_id: 7 } };
    let dispatched = [];

    const comp = createComponent({
        type: 'product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item],
    });
    comp.$dispatch = (event, detail) => { dispatched.push({ event, detail }); };
    comp.$wire = {
        selectProduct: async (lineIndex, productId, unitId) => {
            assert.equal(lineIndex, 0);
            assert.equal(productId, 42);
            assert.equal(unitId, 7);
            return dServer.promise;
        },
    };

    comp.open = true;
    comp.query = 'wid';

    // Call selectItem - MUST NOT await yet!
    const selectPromise = comp.selectItem(item);

    // Immediate truthful state check: 0ms presentation before server finishes
    assert.equal(comp.selectedId, '42');
    assert.equal(comp.selectedLabel, 'Widget Super');
    assert.equal(comp.query, 'Widget Super');
    assert.equal(comp.open, false);
    assert.equal(comp.syncing, true);
    assert.equal(dispatched.length, 1);
    assert.equal(dispatched[0].event, 'combobox-selected');
    assert.equal(dispatched[0].detail.value, 42);

    // Server finishes with success
    dServer.resolve(true);
    await selectPromise;

    // State confirmed, syncing cleared
    assert.equal(comp.selectedId, '42');
    assert.equal(comp.selectedLabel, 'Widget Super');
    assert.equal(comp.query, 'Widget Super');
    assert.equal(comp.syncing, false);
    assert.equal(comp.failed, false);
});

test('selectItem rolls back to previous truthful state if server rejects with false', async () => {
    const dServer = deferred();
    const item = { id: 88, label: 'Rejected Product' };

    const comp = createComponent({
        type: 'product',
        value: '10',
        label: 'Initial Product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item, { id: 10, label: 'Initial Product' }],
    });
    comp.$wire = {
        selectProduct: async () => dServer.promise,
    };

    comp.open = true;
    const selectPromise = comp.selectItem(item);

    // Immediate optimistic presentation
    assert.equal(comp.selectedId, '88');
    assert.equal(comp.selectedLabel, 'Rejected Product');
    assert.equal(comp.open, false);

    // Server rejects
    dServer.resolve(false);
    await selectPromise;

    // State cleanly rolled back to previous truthful value
    assert.equal(comp.selectedId, '10');
    assert.equal(comp.selectedLabel, 'Initial Product');
    assert.equal(comp.query, 'Initial Product');
    assert.equal(comp.failed, true);
    assert.equal(comp.syncing, false);
});

test('selectItem rolls back to previous truthful state if server throws error', async () => {
    const dServer = deferred();
    const item = { id: 99, label: 'Error Product' };

    const comp = createComponent({
        type: 'product',
        value: '5',
        label: 'Safe Product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item],
    });
    comp.$wire = {
        selectProduct: async () => dServer.promise,
    };

    const selectPromise = comp.selectItem(item);
    assert.equal(comp.selectedId, '99');

    // Server network error
    dServer.reject(new Error('Network disconnect'));
    await selectPromise;

    // Rolled back
    assert.equal(comp.selectedId, '5');
    assert.equal(comp.selectedLabel, 'Safe Product');
    assert.equal(comp.query, 'Safe Product');
    assert.equal(comp.failed, true);
    assert.equal(comp.syncing, false);
});

test('clear immediately blanks query and resets selection, rolling back if server rejects', async () => {
    const dServer = deferred();
    let clearEvents = 0;

    const comp = createComponent({
        type: 'customer',
        value: '50',
        label: 'Customer Fifty',
        onSelect: 'selectCustomer',
        extraArgs: [],
    });
    comp.$dispatch = (event) => { if (event === 'combobox-cleared') clearEvents++; };
    comp.$wire = {
        selectCustomer: async () => dServer.promise,
    };

    const clearPromise = comp.clear();

    // Immediate clear
    assert.equal(comp.selectedId, null);
    assert.equal(comp.selectedLabel, '');
    assert.equal(comp.query, '');
    assert.equal(comp.syncing, true);
    assert.equal(clearEvents, 1);

    // Server rejects clear
    dServer.resolve(false);
    await clearPromise;

    // Rolled back to Customer Fifty
    assert.equal(comp.selectedId, '50');
    assert.equal(comp.selectedLabel, 'Customer Fifty');
    assert.equal(comp.query, 'Customer Fifty');
    assert.equal(comp.failed, true);
    assert.equal(comp.syncing, false);
});

test('pending selection serializes clicks and rejection restores only the confirmed choice', async () => {
    const request = deferred();
    const first = { id: 1, label: 'First' };
    const second = { id: 2, label: 'Second' };
    const comp = createComponent({ type: 'product', value: '9', label: 'Confirmed', onSelect: 'selectProduct', options: [first, second] });
    let calls = 0;
    comp.$wire = { selectProduct: () => { calls++; return request.promise; } };
    const pending = comp.selectItem(first);
    await comp.selectItem(second);
    await comp.clear();
    assert.equal(calls, 1);
    assert.equal(comp.selectedId, '1');
    request.resolve(false);
    await pending;
    assert.equal(comp.selectedId, '9');
    assert.equal(comp.selectedLabel, 'Confirmed');
    assert.equal(comp.syncing, false);
    assert.equal(comp.failed, true);
});

test('after selection confirmation another choice can dispatch normally', async () => {
    const first = { id: 10, label: 'First' };
    const second = { id: 20, label: 'Second' };
    const comp = createComponent({ type: 'product', onSelect: 'selectProduct', options: [first, second] });
    const calls = [];
    comp.$wire = { selectProduct: async (...args) => { calls.push(args); return true; } };
    await comp.selectItem(first);
    await comp.selectItem(second);
    assert.equal(calls.length, 2);
    assert.equal(comp.selectedId, '20');
    assert.equal(comp.syncing, false);
});

test('pendingclear focus: clear followed by immediate focus/performSearch properly clears syncing and avoids watch suppression', async () => {
    const dServer = deferred();
    const dSearch = deferred();

    const comp = createComponent({
        type: 'customer',
        value: '50',
        label: 'Customer Fifty',
        onSelect: 'selectCustomer',
        extraArgs: [],
    });

    let searchCount = 0;
    comp.$wire = {
        selectCustomer: async () => dServer.promise,
        searchSelector: async () => {
            searchCount++;
            return dSearch.promise;
        },
    };

    // When clear() focuses searchInput, simulate @focus -> handleFocus()
    comp.$refs.searchInput.focus = () => {
        comp.handleFocus();
    };

    const clearPromise = comp.clear();

    assert.equal(comp.selectedId, null);
    assert.equal(comp.syncing, true);
    assert.equal(comp.searching, true);
    assert.equal(searchCount, 1);

    // Server clears selection successfully
    dServer.resolve(true);
    await clearPromise;

    // Syncing MUST be cleared to false even though handleFocus/performSearch incremented search generation
    assert.equal(comp.syncing, false);
    assert.equal(comp.selectedId, null);
    assert.equal(comp.selectedLabel, '');

    dSearch.resolve({ generation: 1, items: [{ id: 100, label: 'Customer Hundred' }] });
    await Promise.resolve();

    assert.equal(comp.syncing, false);
});

test('search while selectionpending: performSearch does not overwrite pending selection label and resolves syncing cleanly', async () => {
    const dSelect = deferred();
    const dSearch = deferred();
    const item = { id: 42, label: 'Widget Super', meta: { matched_product_unit_id: 7 } };

    const comp = createComponent({
        type: 'product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item],
    });

    comp.$wire = {
        selectProduct: async () => dSelect.promise,
        searchSelector: async () => dSearch.promise,
    };

    // User selects item
    const selectPromise = comp.selectItem(item);
    assert.equal(comp.selectedId, '42');
    assert.equal(comp.selectedLabel, 'Widget Super');
    assert.equal(comp.syncing, true);

    // While selection is pending, performSearch runs (e.g. user input / focus)
    const searchPromise = comp.performSearch();
    assert.equal(comp.searching, true);
    assert.equal(comp.syncing, true);

    // Search resolves with a different selected_item
    dSearch.resolve({
        generation: 2,
        items: [{ id: 42, label: 'Outdated Server Name' }],
        selected_item: { id: 42, label: 'Outdated Server Name' },
    });
    await searchPromise;

    // Pending selection label must NOT be overwritten while syncing!
    assert.equal(comp.selectedLabel, 'Widget Super');

    // Server selection completes
    dSelect.resolve(true);
    await selectPromise;

    assert.equal(comp.selectedId, '42');
    assert.equal(comp.selectedLabel, 'Widget Super');
    assert.equal(comp.syncing, false);
    assert.equal(comp.failed, false);
});

test('stale modelwatch while selectionpending: incoming wireModel watch does not revert newer choice', async () => {
    const dSelect = deferred();
    const item1 = { id: 10, label: 'Old Product' };
    const item2 = { id: 20, label: 'New Choice' };

    let wireWatchHandler = null;
    const comp = searchableCombobox({
        type: 'product',
        wireModel: 'product_id',
        value: '10',
        label: 'Old Product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item1, item2],
    });

    comp.$dispatch = () => {};
    comp.$nextTick = (fn) => { if (fn) fn(); };
    comp.$refs = { searchInput: { focus() {} } };
    comp.$watch = () => {};
    comp.$wire = {
        get: () => '10',
        set: () => {},
        $set: async () => {},
        $watch: (prop, handler) => {
            if (prop === 'product_id') wireWatchHandler = handler;
        },
        selectProduct: async () => dSelect.promise,
    };
    comp.init();

    assert.equal(comp.selectedId, '10');

    // User clicks New Choice
    const selectPromise = comp.selectItem(item2);
    assert.equal(comp.selectedId, '20');
    assert.equal(comp.selectedLabel, 'New Choice');
    assert.equal(comp.syncing, true);

    // Livewire emits an older model value while selection is in flight
    assert.ok(wireWatchHandler);
    wireWatchHandler('10');

    // Choice must NOT be reverted
    assert.equal(comp.selectedId, '20');
    assert.equal(comp.selectedLabel, 'New Choice');

    // Server resolves
    dSelect.resolve(true);
    await selectPromise;

    assert.equal(comp.selectedId, '20');
    assert.equal(comp.selectedLabel, 'New Choice');
    assert.equal(comp.syncing, false);
});

test('initwatch/focus interleaving: fallback search on initwatch does not invalidate concurrent focus search', async () => {
    const dLookup = deferred();
    const dFocusSearch = deferred();

    let wireWatchHandler = null;
    const comp = searchableCombobox({
        type: 'customer',
        wireModel: 'customer_id',
        value: '77',
        label: '',
        options: [],
    });

    comp.$dispatch = () => {};
    comp.$nextTick = (fn) => { if (fn) fn(); };
    comp.$refs = { searchInput: { focus() {} } };
    comp.$watch = () => {};
    comp.$wire = {
        get: () => '77',
        set: () => {},
        $watch: (prop, handler) => {
            if (prop === 'customer_id') wireWatchHandler = handler;
        },
        searchSelector: async (type, query, selectedId, options) => {
            if (options && options.limit === 1) {
                return dLookup.promise;
            }
            return dFocusSearch.promise;
        },
    };
    comp.init();

    // Trigger initwatch
    if (wireWatchHandler) {
        wireWatchHandler('77');
    }

    // Concurrently trigger focus search
    comp.handleFocus();
    assert.equal(comp.open, true);
    assert.equal(comp.searching, true);

    // Lookup resolves
    dLookup.resolve({
        generation: 0,
        selected_item: { id: 77, label: 'Customer Seventy Seven' },
    });
    await new Promise(r => setTimeout(r, 0));

    assert.equal(comp.selectedLabel, 'Customer Seventy Seven');

    // Focus search resolves
    dFocusSearch.resolve({
        generation: 1,
        items: [{ id: 77, label: 'Customer Seventy Seven' }, { id: 78, label: 'Customer 78' }],
    });
    await new Promise(r => setTimeout(r, 0));

    assert.equal(comp.items.length, 2);
    assert.equal(comp.selectedLabel, 'Customer Seventy Seven');
    assert.equal(comp.syncing, false);
    assert.equal(comp.failed, false);
});

test('scopechange invalidates pending selection and prevents stale rollback across companies', async () => {
    const dSelect = deferred();
    const item = { id: 99, label: 'Company A Item' };

    const comp = createComponent({
        type: 'product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item],
    });

    comp.$wire = {
        selectProduct: async () => dSelect.promise,
    };

    // User selects item
    const selectPromise = comp.selectItem(item);
    assert.equal(comp.selectedId, '99');
    assert.equal(comp.syncing, true);

    // Company switches before server responds
    comp.setCompany(2);
    assert.equal(comp.currentCompanyId, 2);
    assert.equal(comp.syncing, false);

    // Late rejection arrives from old company
    dSelect.resolve(false);
    await selectPromise;

    // Discarded; does not trigger rollback or failed state for new company
    assert.equal(comp.failed, false);
    assert.equal(comp.syncing, false);
});
