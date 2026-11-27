import test from 'node:test';
import assert from 'node:assert/strict';
import {lockRowEditors, shouldGuardRowAction, shouldGuardRowEdit} from '../../resources/js/document-row-mutations.js';

function fixture() {
    const fields = ['INPUT', 'TEXTAREA', 'SELECT', 'BUTTON'].map(tagName => ({tagName, readOnly: false, disabled: false, isConnected: true}));
    const row = {setAttribute: (key, value) => row[key] = value, removeAttribute: key => delete row[key]};
    const status = {textContent: '', dataset: {busyLabel: 'Updating items…'}};
    const root = {getAttribute: () => 'component-1', dispatchEvent: () => {}, dataset: {}, querySelector: () => status,
        querySelectorAll: selector => selector === '[data-document-row]' ? [row] : fields};
    return {root, fields, row, status};
}
test('structural lock preserves focusable text controls and blocks selects/taps with truthful busy status', () => {
    const {root, fields, row, status} = fixture();
    const release = lockRowEditors(root);
    assert.equal(fields[0].readOnly, true);
    assert.equal(fields[0].disabled, false);
    assert.equal(fields[1].readOnly, true);
    assert.equal(fields[2].disabled, true);
    assert.equal(fields[3].disabled, true);
    assert.equal(row['aria-busy'], 'true');
    assert.equal(status.textContent, 'Updating items…');
    release();
    assert.equal(fields[0].readOnly, false);
    assert.equal(fields[2].disabled, false);
    assert.equal(root.dataset.rowMutationBusy, undefined);
    assert.equal(status.textContent, '');
});
test('successful morph owns final permission/readonly state; cleanup never re-enables a restricted control', () => {
    const {root, fields} = fixture();
    const release = lockRowEditors(root);
    release(true);
    assert.equal(fields[0].readOnly, true);
    assert.equal(fields[2].disabled, true);
});
test('overlapping structural render windows keep the lock until the last render', () => {
    const {root, fields} = fixture();
    const first = lockRowEditors(root);
    const second = lockRowEditors(root);
    first();
    assert.equal(root.dataset.rowMutationBusy, 'true');
    assert.equal(fields[0].readOnly, true);
    second();
    assert.equal(root.dataset.rowMutationBusy, undefined);
    assert.equal(fields[0].readOnly, false);
});

test('Save and Confirm queue reject before dispatch during a structural change, then allow an explicit retry', async () => {
    const {enqueueDocumentOperation} = await import('../../resources/js/document-operations.js');
    let calls = 0;
    const status = {dataset: {retryLabel: 'Retry after update'}, textContent: ''};
    const root = {dataset: {rowMutationBusy: 'true'}, querySelector: () => status};
    const wire = {$id: 'guarded-document', $el: root, saveDraft: async () => {calls++;}, saveAndConfirm: async () => {calls++;}};
    await assert.rejects(enqueueDocumentOperation(wire, 'saveDraft'), /updating/);
    await assert.rejects(enqueueDocumentOperation(wire, 'saveAndConfirm'), /updating/);
    assert.equal(calls, 0);
    assert.equal(status.textContent, 'Retry after update');
    delete root.dataset.rowMutationBusy;
    await enqueueDocumentOperation(wire, 'saveDraft');
    assert.equal(calls, 1);
});


test('an initially empty document registers structural protection before its first row is added', async () => {
    const priorDocument = globalThis.document;
    const priorWindow = globalThis.window;
    let init;
    let registered = false;
    const status = {};
    const component = {el: {querySelector: selector => selector === '[data-row-mutation-status]' ? status : null, addEventListener() {}, removeEventListener() {}}, $wire: {$interceptMessage() {registered = true; return () => {};}}};
    const cleanups = [];
    try {
        globalThis.document = {
            addEventListener: (name, callback) => {init = callback;},
            dispatchEvent: () => true,
        };
        globalThis.window = {
            dispatchEvent: () => true,
            addEventListener: () => {},
            removeEventListener: () => {},
            Livewire: {hook: (name, callback) => callback({component, cleanup: callback => cleanups.push(callback)})},
        };
        await import('../../resources/js/document-row-mutations.js?empty-document');
        init();
        assert.equal(registered, true);
        for (const cleanup of cleanups) cleanup();
    } finally {
        if (priorDocument === undefined) delete globalThis.document; else globalThis.document = priorDocument;
        if (priorWindow === undefined) delete globalThis.window; else globalThis.window = priorWindow;
    }
});

test('structural guard blocks locked row editors and mutation/save actions while allowing Cancel anchors, outside notes, and unrelated modals', async () => {
    const status = {textContent: '', dataset: {busyLabel: 'Updating items…', retryLabel: 'Retry after update'}};
    const makeTarget = ({matchesSelectors = [], ancestors = {}, tagName = 'INPUT', type = 'text', attrs = {}} = {}) => {
        const el = {
            tagName,
            type,
            readOnly: false,
            disabled: false,
            isConnected: true,
            getAttribute: name => attrs[name] ?? null,
            matches: selector => selector.split(',').map(s => s.trim()).some(s => matchesSelectors.includes(s)),
            closest: selector => {
                for (const key of selector.split(',').map(s => s.trim())) {
                    if (ancestors[key]) return ancestors[key];
                }
                return null;
            },
        };
        if (tagName === 'BUTTON') {
            ancestors['button'] = el;
        } else if (tagName === 'INPUT' && type === 'submit') {
            ancestors['input[type="submit"]'] = el;
        }
        return el;
    };

    const rowMarker = {setAttribute() {}, removeAttribute() {}};
    const rowInput = makeTarget({ancestors: {'[data-document-row]': rowMarker}});
    const rowRemoveBtn = makeTarget({tagName: 'BUTTON', type: 'button', attrs: {'wire:click': "removeLine('rk-1')"}, ancestors: {'[data-document-row]': rowMarker}});
    const addLineBtn = makeTarget({tagName: 'BUTTON', type: 'button', ancestors: {'[data-row-mutation-action]': true}});
    const mobileConfirmBtn = makeTarget({tagName: 'BUTTON', type: 'button', ancestors: {'[data-mobile-confirm]': true}});
    const quickProductSaveBtn = makeTarget({tagName: 'BUTTON', type: 'button', attrs: {'wire:click': 'saveQuickProduct'}, ancestors: {'[role="dialog"]': true, '[data-row-mutation-action]': true}});
    const formSubmitBtn = makeTarget({tagName: 'BUTTON', type: 'submit'});
    const barcodeInput = makeTarget({ancestors: {'[data-row-mutation-enter]': true}});

    const cancelAnchor = makeTarget({tagName: 'A'});
    const outsideNotes = makeTarget({tagName: 'TEXTAREA'});
    const discountToggleBtn = makeTarget({tagName: 'BUTTON', type: 'button', attrs: {'wire:click': 'toggleDiscountControl'}});
    const quickCustomerSaveBtn = makeTarget({tagName: 'BUTTON', type: 'button', attrs: {'wire:click': 'saveQuickCustomer'}, ancestors: {'[role="dialog"]': true}});
    const modalDismissBtn = makeTarget({tagName: 'BUTTON', type: 'button', ancestors: {'[role="dialog"]': true}});
    const modalSubmitBtn = makeTarget({tagName: 'BUTTON', type: 'submit', ancestors: {'[role="dialog"]': true}});
    const modalInput = makeTarget({tagName: 'INPUT', ancestors: {'[role="dialog"]': true}});

    // Direct boundary predicate assertions
    assert.equal(shouldGuardRowEdit({type: 'beforeinput', target: rowInput}), true);
    assert.equal(shouldGuardRowEdit({type: 'keydown', key: 'Enter', target: rowInput}), true);
    assert.equal(shouldGuardRowEdit({type: 'keydown', key: 'Enter', target: barcodeInput}), true);
    assert.equal(shouldGuardRowAction(rowRemoveBtn), true);
    assert.equal(shouldGuardRowAction(addLineBtn), true);
    assert.equal(shouldGuardRowAction(mobileConfirmBtn), true);
    assert.equal(shouldGuardRowAction(quickProductSaveBtn), true);
    assert.equal(shouldGuardRowAction(formSubmitBtn), true);

    assert.equal(shouldGuardRowEdit({type: 'keydown', key: 'Enter', target: cancelAnchor}), false);
    assert.equal(shouldGuardRowAction(cancelAnchor), false);
    assert.equal(shouldGuardRowEdit({type: 'beforeinput', target: outsideNotes}), false);
    assert.equal(shouldGuardRowEdit({type: 'keydown', key: 'Enter', target: outsideNotes}), false);
    assert.equal(shouldGuardRowAction(discountToggleBtn), false);
    assert.equal(shouldGuardRowAction(quickCustomerSaveBtn), false);
    assert.equal(shouldGuardRowAction(modalDismissBtn), false);
    assert.equal(shouldGuardRowAction(modalSubmitBtn), false);
    assert.equal(shouldGuardRowEdit({type: 'keydown', key: 'Enter', target: modalInput}), false);

    // End-to-end Livewire intercept listener reproduction during active delayed removeLine
    const priorDocument = globalThis.document;
    const priorWindow = globalThis.window;
    let init;
    let interceptCb;
    const listeners = new Map();
    const cleanups = [];
    const component = {
        el: {
            dataset: {},
            getAttribute: () => 'doc-comp-1',
            dispatchEvent() {},
            querySelector: selector => selector === '[data-row-mutation-status]' ? status : null,
            querySelectorAll: selector => selector === '[data-document-row]' ? [rowMarker] : [rowInput, rowRemoveBtn],
            addEventListener: (name, fn, capture) => listeners.set(`${name}:${Boolean(capture)}`, fn),
            removeEventListener: (name, fn, capture) => listeners.delete(`${name}:${Boolean(capture)}`),
        },
        $wire: {
            $interceptMessage(cb) { interceptCb = cb; return () => {}; },
        },
    };
    try {
        globalThis.document = {
            addEventListener: (name, callback) => {init = callback;},
            dispatchEvent: () => true,
        };
        globalThis.window = {
            dispatchEvent: () => true,
            addEventListener: () => {},
            removeEventListener: () => {},
            Livewire: {hook: (name, callback) => callback({component, cleanup: callback => cleanups.push(callback)})},
        };
        await import('../../resources/js/document-row-mutations.js?scoped-capture');
        init();

        let onSuccessCb;
        let onFinishCb;
        interceptCb({
            message: {actions: [{name: 'removeLine'}]},
            onSuccess: cb => { onSuccessCb = cb; },
            onFinish: cb => { onFinishCb = cb; },
        });
        assert.equal(component.el.dataset.rowMutationBusy, 'true');
        assert.equal(rowInput.readOnly, true);
        assert.equal(rowRemoveBtn.disabled, true);

        const dispatch = (type, extra) => {
            const evt = {type, defaultPrevented: false, stopped: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; }, ...extra};
            listeners.get(`${type}:true`)(evt);
            return evt;
        };

        // Guarded during busy
        assert.equal(dispatch('keydown', {key: 'Enter', target: rowInput}).defaultPrevented, true);
        assert.equal(dispatch('beforeinput', {target: rowInput}).defaultPrevented, true);
        assert.equal(dispatch('click', {target: addLineBtn}).defaultPrevented, true);
        assert.equal(dispatch('click', {target: quickProductSaveBtn}).defaultPrevented, true);
        assert.equal(dispatch('submit', {target: makeTarget({tagName: 'FORM'})}).defaultPrevented, true);
        assert.equal(status.textContent, 'Retry after update');

        // Unblocked during busy: Cancel anchor Enter/click, outside notes Enter/input, quick-customer modal
        status.textContent = 'Updating items…';
        for (const allowed of [
            dispatch('keydown', {key: 'Enter', target: cancelAnchor}),
            dispatch('click', {target: cancelAnchor}),
            dispatch('keydown', {key: 'Enter', target: outsideNotes}),
            dispatch('beforeinput', {target: outsideNotes}),
            dispatch('keydown', {key: 'Enter', target: modalInput}),
            dispatch('click', {target: quickCustomerSaveBtn}),
            dispatch('click', {target: modalDismissBtn}),
            dispatch('submit', {target: makeTarget({tagName: 'FORM', ancestors: {'[role="dialog"]': true}})}),
        ]) {
            assert.equal(allowed.defaultPrevented, false);
            assert.equal(allowed.stopped, false);
        }
        assert.equal(status.textContent, 'Updating items…');

        onSuccessCb({payload: {effects: {html: '<div></div>'}}, onRender: fn => fn()});
        onFinishCb();
        assert.equal(component.el.dataset.rowMutationBusy, undefined);
    } finally {
        for (const cleanup of cleanups) cleanup();
        if (priorDocument === undefined) delete globalThis.document; else globalThis.document = priorDocument;
        if (priorWindow === undefined) delete globalThis.window; else globalThis.window = priorWindow;
    }
});

test('quickAddProduct triggers structural busy lock, blocks row editors/actions/FIFO queue, and releases on render', async () => {
    const {enqueueDocumentOperation, enqueueQuantityStep} = await import('../../resources/js/document-operations.js');
    const status = {textContent: '', dataset: {busyLabel: 'Updating items…', retryLabel: 'Retry after update'}};
    const rowMarker = {setAttribute() {}, removeAttribute() {}};
    const makeControl = (tagName, type = 'text', attrs = {}, ancestors = {}) => {
        const el = {
            tagName,
            type,
            readOnly: false,
            disabled: false,
            isConnected: true,
            getAttribute: name => attrs[name] ?? null,
            closest: selector => {
                for (const key of selector.split(',').map(s => s.trim())) {
                    if (ancestors[key]) return ancestors[key];
                }
                return null;
            },
        };
        if (tagName === 'BUTTON') ancestors['button'] = el;
        return el;
    };

    const rowInput = makeControl('INPUT', 'text', {}, {'[data-document-row]': rowMarker});
    const rowRemoveBtn = makeControl('BUTTON', 'button', {'wire:click': "removeLine('rk-1')"}, {'[data-document-row]': rowMarker});
    const saveDraftBtn = makeControl('BUTTON', 'submit', {});
    const confirmBtn = makeControl('BUTTON', 'button', {}, {'[data-row-mutation-action]': true, '[data-mobile-confirm]': true});
    const addLineBtn = makeControl('BUTTON', 'button', {}, {'[data-row-mutation-action]': true});
    const barcodeInput = makeControl('INPUT', 'text', {}, {'[data-row-mutation-enter]': true});

    const priorDocument = globalThis.document;
    const priorWindow = globalThis.window;
    let init;
    let interceptCb;
    const listeners = new Map();
    const cleanups = [];
    let wireCalls = 0;

    const component = {
        el: {
            dataset: {},
            getAttribute: () => 'doc-quickadd-1',
            dispatchEvent() {},
            querySelector: selector => selector === '[data-row-mutation-status]' ? status : null,
            querySelectorAll: selector => selector === '[data-document-row]' ? [rowMarker] : [rowInput, rowRemoveBtn],
            addEventListener: (name, fn, capture) => listeners.set(`${name}:${Boolean(capture)}`, fn),
            removeEventListener: (name, fn, capture) => listeners.delete(`${name}:${Boolean(capture)}`),
        },
        $wire: {
            $interceptMessage(cb) { interceptCb = cb; return () => {}; },
        },
    };
    const wireProxy = {
        $id: 'doc-quickadd-1',
        $el: component.el,
        saveDraft: async () => { wireCalls++; },
        saveAndConfirm: async () => { wireCalls++; },
        addLine: async () => { wireCalls++; },
        scanCameraBarcode: async () => { wireCalls++; },
    };

    try {
        globalThis.document = {
            addEventListener: (name, callback) => {init = callback;},
            dispatchEvent: () => true,
        };
        globalThis.window = {
            dispatchEvent: () => true,
            addEventListener: () => {},
            removeEventListener: () => {},
            Livewire: {hook: (name, callback) => callback({component, cleanup: callback => cleanups.push(callback)})},
        };
        await import('../../resources/js/document-row-mutations.js?quickadd-structural');
        init();

        let onSuccessCb;
        let onFinishCb;
        interceptCb({
            message: {actions: [{name: 'quickAddProduct'}]},
            onSuccess: cb => { onSuccessCb = cb; },
            onFinish: cb => { onFinishCb = cb; },
        });

        assert.equal(component.el.dataset.rowMutationBusy, 'true');
        assert.equal(rowInput.readOnly, true);
        assert.equal(rowRemoveBtn.disabled, true);
        assert.equal(status.textContent, 'Updating items…');

        const dispatch = (type, extra) => {
            const evt = {type, defaultPrevented: false, stopped: false, preventDefault() { this.defaultPrevented = true; }, stopImmediatePropagation() { this.stopped = true; }, ...extra};
            listeners.get(`${type}:true`)(evt);
            return evt;
        };

        assert.equal(dispatch('click', {target: saveDraftBtn}).defaultPrevented, true);
        assert.equal(dispatch('click', {target: confirmBtn}).defaultPrevented, true);
        assert.equal(dispatch('click', {target: addLineBtn}).defaultPrevented, true);
        assert.equal(dispatch('click', {target: rowRemoveBtn}).defaultPrevented, true);
        assert.equal(dispatch('beforeinput', {target: rowInput}).defaultPrevented, true);
        assert.equal(dispatch('keydown', {key: 'Enter', target: barcodeInput}).defaultPrevented, true);
        assert.equal(dispatch('submit', {target: makeControl('FORM')}).defaultPrevented, true);
        assert.equal(status.textContent, 'Retry after update');

        await assert.rejects(enqueueDocumentOperation(wireProxy, 'saveDraft'), /updating/);
        await assert.rejects(enqueueDocumentOperation(wireProxy, 'saveAndConfirm'), /updating/);
        await assert.rejects(enqueueDocumentOperation(wireProxy, 'addLine'), /updating/);
        await assert.rejects(enqueueDocumentOperation(wireProxy, 'scanCameraBarcode', ['123']), /updating/);
        await assert.rejects(enqueueQuantityStep({$id: 'doc-quickadd-1', $el: component.el, lines: [{quantity: '1'}]}, 0, 'up'), /updating/);
        assert.equal(wireCalls, 0);

        onSuccessCb({payload: {effects: {html: '<div></div>'}}, onRender: fn => fn()});
        onFinishCb();
        assert.equal(component.el.dataset.rowMutationBusy, undefined);
        await enqueueDocumentOperation(wireProxy, 'saveDraft');
        assert.equal(wireCalls, 1);
    } finally {
        for (const cleanup of cleanups) cleanup();
        if (priorDocument === undefined) delete globalThis.document; else globalThis.document = priorDocument;
        if (priorWindow === undefined) delete globalThis.window; else globalThis.window = priorWindow;
    }
});
