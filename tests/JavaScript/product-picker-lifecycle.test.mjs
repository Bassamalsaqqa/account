import test from 'node:test';
import assert from 'node:assert/strict';

function createPickerFixture(initialResults = []) {
    let addProductCalls = [];
    let addSelectedProductCalls = [];

    const wire = {
        searchResults: initialResults,
        productSearch: '',
        addProduct(id, unitId) {
            addProductCalls.push({ id, unitId });
        },
        addSelectedProduct() {
            addSelectedProductCalls.push(true);
        },
    };

    const state = {
        open: false,
        activeIndex: 0,
        wire,
        $refs: {
            resultsList: {
                querySelector(selector) {
                    return { scrollIntoView() {} };
                },
            },
        },
        $nextTick(fn) {
            fn();
        },
        $watch(expr, callback) {
            this._watcher = callback;
        },
        init() {
            if (this.wire.searchResults && this.wire.searchResults.length > 0) {
                this.open = true;
                this.activeIndex = 0;
            }
        },
        selectNext() {
            const count = (this.wire.searchResults || []).length;
            if (count > 0) {
                this.activeIndex = (this.activeIndex + 1) % count;
                this.scrollToActive();
            }
        },
        selectPrev() {
            const count = (this.wire.searchResults || []).length;
            if (count > 0) {
                this.activeIndex = (this.activeIndex - 1 + count) % count;
                this.scrollToActive();
            }
        },
        selectCurrent() {
            const results = this.wire.searchResults || [];
            if (results.length > 0 && results[this.activeIndex]) {
                const item = results[this.activeIndex];
                this.wire.addProduct(item.id, item.default_unit_id);
                this.open = false;
            } else {
                this.wire.addSelectedProduct();
                this.open = false;
            }
        },
        scrollToActive() {},
    };

    state.init();
    return { state, wire, addProductCalls, addSelectedProductCalls };
}

test('keyboard navigation cycles active index through search results', () => {
    const results = [
        { id: 101, default_unit_id: 1, name_ar: 'قهوة' },
        { id: 102, default_unit_id: 2, name_ar: 'شاي' },
        { id: 103, default_unit_id: 3, name_ar: 'سكر' },
    ];

    const { state } = createPickerFixture(results);
    assert.equal(state.open, true);
    assert.equal(state.activeIndex, 0);

    // Arrow Down moves to index 1
    state.selectNext();
    assert.equal(state.activeIndex, 1);

    // Arrow Down moves to index 2
    state.selectNext();
    assert.equal(state.activeIndex, 2);

    // Arrow Down wraps around to index 0
    state.selectNext();
    assert.equal(state.activeIndex, 0);

    // Arrow Up wraps to index 2
    state.selectPrev();
    assert.equal(state.activeIndex, 2);

    // Arrow Up moves to index 1
    state.selectPrev();
    assert.equal(state.activeIndex, 1);
});

test('selectCurrent adds highlighted item when candidates exist', () => {
    const results = [
        { id: 101, default_unit_id: 1, name_ar: 'قهوة' },
        { id: 102, default_unit_id: 5, name_ar: 'هيل' },
    ];

    const { state, addProductCalls } = createPickerFixture(results);
    state.selectNext(); // highlight index 1
    state.selectCurrent();

    assert.equal(state.open, false);
    assert.equal(addProductCalls.length, 1);
    assert.deepEqual(addProductCalls[0], { id: 102, unitId: 5 });
});

test('selectCurrent invokes addSelectedProduct when candidates array is empty', () => {
    const { state, addSelectedProductCalls } = createPickerFixture([]);
    state.selectCurrent();

    assert.equal(state.open, false);
    assert.equal(addSelectedProductCalls.length, 1);
});

test('empty initial results starts closed', () => {
    const { state } = createPickerFixture([]);
    assert.equal(state.open, false);
    assert.equal(state.activeIndex, 0);
});
