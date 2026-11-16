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
