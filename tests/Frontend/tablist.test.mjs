import test from 'node:test';
import assert from 'node:assert/strict';
import {
    consumePendingTabFocusTarget,
    handleTablistKeydown,
    isRtlTablist,
    rememberTabFocusTarget,
    restoreTabFocusTarget,
} from '../../resources/js/tablist.js';

function createTabFixture({count = 3, activeIndex = 0, dir = 'ltr', disabledIndices = []} = {}) {
    const clicks = [];
    const focuses = [];

    const tabs = Array.from({length: count}, (_, i) => {
        const disabled = disabledIndices.includes(i);
        return {
            index: i,
            disabled,
            getAttribute(name) {
                return name === 'aria-disabled' && disabled ? 'true' : null;
            },
            focus() {
                focuses.push(i);
                doc.activeElement = this;
            },
            click() {
                clicks.push(i);
            },
        };
    });

    const doc = {
        activeElement: tabs[activeIndex] ?? null,
        documentElement: {dir},
        body: {dir},
        defaultView: {
            getComputedStyle: () => ({direction: dir}),
        },
    };

    const tablist = {
        ownerDocument: doc,
        closest: selector => (selector === '[dir]' ? {getAttribute: () => dir} : null),
        querySelectorAll: selector => (selector === '[role="tab"]' ? tabs : []),
    };

    return {tabs, doc, tablist, clicks, focuses};
}

function makeEvent(key) {
    let prevented = false;
    return {
        key,
        preventDefault() {
            prevented = true;
        },
        wasPrevented: () => prevented,
    };
}

test('LTR tablist moves forward with ArrowRight, backward with ArrowLeft, and wraps cleanly', () => {
    const f = createTabFixture({count: 3, activeIndex: 0, dir: 'ltr'});

    let ev = makeEvent('ArrowRight');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.equal(ev.wasPrevented(), true);
    assert.deepEqual(f.focuses, [1]);
    assert.deepEqual(f.clicks, [1]);

    ev = makeEvent('End');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.deepEqual(f.clicks.at(-1), 2);

    ev = makeEvent('ArrowRight');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.deepEqual(f.clicks.at(-1), 0);

    ev = makeEvent('ArrowLeft');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.deepEqual(f.clicks.at(-1), 2);

    ev = makeEvent('Home');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.deepEqual(f.clicks.at(-1), 0);
});

test('RTL tablist reverses horizontal arrow direction', () => {
    const f = createTabFixture({count: 3, activeIndex: 0, dir: 'rtl'});
    assert.equal(isRtlTablist(f.tablist, f.doc), true);

    let ev = makeEvent('ArrowLeft');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.equal(ev.wasPrevented(), true);
    assert.deepEqual(f.clicks, [1]);

    ev = makeEvent('ArrowRight');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.deepEqual(f.clicks, [1, 0]);

    ev = makeEvent('ArrowRight');
    assert.equal(handleTablistKeydown(ev, f.tablist, f.doc), true);
    assert.deepEqual(f.clicks, [1, 0, 2]);
});

test('tablist skips disabled tabs and ignores unrelated keys or inactive focus', () => {
    const f = createTabFixture({count: 4, activeIndex: 0, dir: 'ltr', disabledIndices: [1, 2]});

    const arrowEv = makeEvent('ArrowRight');
    assert.equal(handleTablistKeydown(arrowEv, f.tablist, f.doc), true);
    assert.deepEqual(f.focuses, [3]);
    assert.deepEqual(f.clicks, [3]);

    const otherEv = makeEvent('Tab');
    assert.equal(handleTablistKeydown(otherEv, f.tablist, f.doc), false);
    assert.equal(otherEv.wasPrevented(), false);

    f.doc.activeElement = {};
    const strayEv = makeEvent('ArrowRight');
    assert.equal(handleTablistKeydown(strayEv, f.tablist, f.doc), false);
    assert.equal(strayEv.wasPrevented(), false);
});

test('tab focus recovery restores focus to replaced tab after morph unless user focused elsewhere', () => {
    let focusCalls = 0;
    const frames = [];
    const replacement = {
        id: 'tab-warehouses',
        isConnected: true,
        disabled: false,
        getAttribute: () => null,
        closest: () => null,
        getClientRects: () => [1],
        focus() {
            focusCalls++;
            doc.activeElement = this;
        },
    };
    const root = {isConnected: true, contains: node => node === replacement};
    const tablist = {closest: sel => (sel === '[wire\\:id]' ? root : null)};
    const doc = {
        body: {},
        activeElement: {},
        location: {href: '/products/1'},
        getElementById: id => (id === 'tab-warehouses' ? replacement : null),
    };
    doc.activeElement = doc.body;

    rememberTabFocusTarget({id: 'tab-warehouses'}, tablist, doc);
    const saved = consumePendingTabFocusTarget(root, doc);
    assert.equal(saved.id, 'tab-warehouses');
    assert.equal(consumePendingTabFocusTarget(root, doc), null);

    restoreTabFocusTarget(saved, doc, cb => frames.push(cb));
    while (frames.length) frames.shift()();
    assert.equal(focusCalls, 1);
    assert.equal(doc.activeElement, replacement);

    // Must not steal focus if user moved elsewhere before double-rAF completes
    doc.activeElement = {other: true};
    restoreTabFocusTarget(saved, doc, cb => frames.push(cb));
    while (frames.length) frames.shift()();
    assert.equal(focusCalls, 1);
});
