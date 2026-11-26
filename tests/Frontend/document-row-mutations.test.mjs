import test from 'node:test';
import assert from 'node:assert/strict';
import {lockRowEditors} from '../../resources/js/document-row-mutations.js';

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
