import test from 'node:test';
import assert from 'node:assert/strict';
import {captureRowFocus, restoreRowFocus} from '../../resources/js/document-row-focus.js';

function fixture() {
    const row = {contains: el => el === field};
    const root = {contains: el => el === row};
    const doc = {body: {}, activeElement: null};
    const field = {isConnected: true, selectionStart: 2, selectionEnd: 4, selectionDirection: 'backward',
        closest: () => row, focus: () => {doc.activeElement = field;},
        setSelectionRange: (...args) => {field.range = args;}};
    doc.activeElement = field;
    return {row, root, doc, field};
}
test('retained row moved by morph restores active child and selection', () => {
    const {root, doc, field} = fixture();
    const saved = captureRowFocus(root, doc);
    doc.activeElement = doc.body;
    restoreRowFocus(saved, root, doc);
    assert.equal(doc.activeElement, field);
    assert.deepEqual(field.range, [2, 4, 'backward']);
});
test('removed child and deliberate focus elsewhere are left alone', () => {
    const {root, doc, field} = fixture();
    const saved = captureRowFocus(root, doc);
    field.isConnected = false;
    doc.activeElement = doc.body;
    restoreRowFocus(saved, root, doc);
    assert.equal(doc.activeElement, doc.body);
    field.isConnected = true;
    const other = {};
    doc.activeElement = other;
    restoreRowFocus(saved, root, doc);
    assert.equal(doc.activeElement, other);
});
test('newer model edit keeps its value and never restores stale selection', () => {
    const {root, doc, field} = fixture();
    field.value = 'old';
    const saved = captureRowFocus(root, doc);
    field.value = 'newer';
    field._x_model = {get: () => 'newer'};
    restoreRowFocus(saved, root, doc);
    assert.equal(field.value, 'newer');
    assert.equal(field.range, undefined);
});
test('transient blank display recovers only an unchanged final bound string', () => {
    const {root, doc, field} = fixture();
    field.value = 'exact 1.000';
    const saved = captureRowFocus(root, doc);
    field.value = '';
    field._x_model = {get: () => 'exact 1.000'};
    restoreRowFocus(saved, root, doc);
    assert.equal(field.value, 'exact 1.000');
    assert.deepEqual(field.range, [2, 4, 'backward']);
});
