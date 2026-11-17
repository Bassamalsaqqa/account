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

test('rapid selections: late rejection of first selection does not rollback second selection', async () => {
    const dFirst = deferred();
    const dSecond = deferred();

    const item1 = { id: 1, label: 'Item 1' };
    const item2 = { id: 2, label: 'Item 2' };

    const comp = createComponent({
        type: 'product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item1, item2],
    });

    comp.$wire = {
        selectProduct: async (lineIndex, id) => {
            if (id === 1) return dFirst.promise;
            if (id === 2) return dSecond.promise;
        },
    };

    // User rapidly clicks Item 1, then changes mind and clicks Item 2
    const p1 = comp.selectItem(item1);
    assert.equal(comp.selectedId, '1');

    const p2 = comp.selectItem(item2);
    assert.equal(comp.selectedId, '2');
    assert.equal(comp.selectedLabel, 'Item 2');

    // First selection rejects late
    dFirst.resolve(false);
    await p1;

    // Item 2 MUST NOT be rolled back by stale first response!
    assert.equal(comp.selectedId, '2');
    assert.equal(comp.selectedLabel, 'Item 2');

    // Second selection succeeds
    dSecond.resolve(true);
    await p2;

    assert.equal(comp.selectedId, '2');
    assert.equal(comp.selectedLabel, 'Item 2');
    assert.equal(comp.syncing, false);
    assert.equal(comp.failed, false);
});

test('rapid selections: late success of first selection does not overwrite second selection', async () => {
    const dFirst = deferred();
    const dSecond = deferred();

    const item1 = { id: 10, label: 'First Choice' };
    const item2 = { id: 20, label: 'Second Choice' };

    const comp = createComponent({
        type: 'product',
        onSelect: 'selectProduct',
        extraArgs: [0],
        options: [item1, item2],
    });

    comp.$wire = {
        selectProduct: async (lineIndex, id) => {
            if (id === 10) return dFirst.promise;
            if (id === 20) return dSecond.promise;
        },
    };

    const p1 = comp.selectItem(item1);
    const p2 = comp.selectItem(item2);

    assert.equal(comp.selectedId, '20');

    // First request resolves late with success
    dFirst.resolve(true);
    await p1;

    // State must remain Second Choice
    assert.equal(comp.selectedId, '20');
    assert.equal(comp.selectedLabel, 'Second Choice');

    dSecond.resolve(true);
    await p2;

    assert.equal(comp.selectedId, '20');
    assert.equal(comp.selectedLabel, 'Second Choice');
});
