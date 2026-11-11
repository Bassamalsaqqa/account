import test from 'node:test';
import assert from 'node:assert/strict';
import { productPickerComponent } from '../../resources/js/product-search-picker.js';

function createPickerInstance(initialResults = [], initialQuery = '') {
    const addProductCalls = [];
    const addSelectedProductCalls = [];
    const watchers = {};

    const wire = {
        searchResults: initialResults,
        productSearch: initialQuery,
        addProduct(id, unitId) {
            addProductCalls.push({ id, unitId });
        },
        addSelectedProduct() {
            addSelectedProductCalls.push(true);
        },
        set(prop, val) {
            this[prop] = val;
            if (watchers[`$wire.${prop}`]) {
                watchers[`$wire.${prop}`](val);
            }
        },
    };

    const instance = productPickerComponent({ prefix: 'test-picker', context: 'sales' });

    instance.$wire = wire;
    instance.$refs = {
        resultsList: {
            querySelector() {
                return { scrollIntoView() {} };
            },
        },
    };
    instance.$nextTick = (fn) => fn();
    instance.$watch = (expr, cb) => {
        watchers[expr] = cb;
    };

    instance.init();

    return {
        instance,
        wire,
        watchers,
        addProductCalls,
        addSelectedProductCalls,
        setSearchResults(results) {
            wire.searchResults = results;
            if (watchers['$wire.searchResults']) {
                watchers['$wire.searchResults'](results);
            }
        },
        setProductSearch(term) {
            wire.productSearch = term;
            if (watchers['$wire.productSearch']) {
                watchers['$wire.productSearch'](term);
            }
        },
    };
}

test('shipped controller keyboard navigation cycles active index through search results', () => {
    const results = [
        { id: 101, default_unit_id: 1, name_ar: 'قهوة' },
        { id: 102, default_unit_id: 2, name_ar: 'شاي' },
        { id: 103, default_unit_id: 3, name_ar: 'سكر' },
    ];

    const { instance, setProductSearch, setSearchResults } = createPickerInstance([], '');
    assert.equal(instance.open, false);
    assert.equal(instance.activeIndex, -1);

    // Typing a query with results opens picker and highlights first item (index 0)
    setProductSearch('قهوة');
    setSearchResults(results);
    assert.equal(instance.open, true);
    assert.equal(instance.activeIndex, 0);

    // Arrow Down moves to index 1
    instance.selectNext();
    assert.equal(instance.activeIndex, 1);

    // Arrow Down moves to index 2
    instance.selectNext();
    assert.equal(instance.activeIndex, 2);

    // Arrow Down wraps around to index 0
    instance.selectNext();
    assert.equal(instance.activeIndex, 0);

    // Arrow Up wraps to index 2
    instance.selectPrev();
    assert.equal(instance.activeIndex, 2);

    // Arrow Up moves to index 1
    instance.selectPrev();
    assert.equal(instance.activeIndex, 1);
});

test('shipped controller selectCurrent adds highlighted item when candidates exist', () => {
    const results = [
        { id: 101, default_unit_id: 1, name_ar: 'قهوة' },
        { id: 102, default_unit_id: 5, name_ar: 'هيل' },
    ];

    const { instance, setProductSearch, setSearchResults, addProductCalls } = createPickerInstance([], '');
    setProductSearch('هيل');
    setSearchResults(results);

    instance.selectNext(); // highlight index 1
    instance.selectCurrent();

    assert.equal(instance.open, false);
    assert.equal(instance.activeIndex, -1);
    assert.equal(addProductCalls.length, 1);
    assert.deepEqual(addProductCalls[0], { id: 102, unitId: 5 });
});

test('shipped controller empty results opens and displays empty state without getting stuck', () => {
    const { instance, setProductSearch, setSearchResults } = createPickerInstance([], '');

    // User types query that returns 0 results
    setProductSearch('منتج_غير_موجود');
    setSearchResults([]);

    // Dropdown MUST open so user sees "No matching products", with activeIndex -1 (no selection)
    assert.equal(instance.open, true);
    assert.equal(instance.activeIndex, -1);
});

test('shipped controller clearing search input resets open state and active index', () => {
    const { instance, setProductSearch, setSearchResults } = createPickerInstance([], '');

    setProductSearch('قهوة');
    setSearchResults([{ id: 101, default_unit_id: 1, name_ar: 'قهوة' }]);
    assert.equal(instance.open, true);

    // Clearing search closes dropdown
    instance.clearSearch();
    assert.equal(instance.open, false);
    assert.equal(instance.activeIndex, -1);
    assert.equal(instance.$wire.productSearch, '');
});

test('shipped controller empty initial query starts closed with activeIndex -1', () => {
    const { instance } = createPickerInstance([], '');
    assert.equal(instance.open, false);
    assert.equal(instance.activeIndex, -1);
});
