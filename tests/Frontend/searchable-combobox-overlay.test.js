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
