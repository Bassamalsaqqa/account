import test from 'node:test';
import assert from 'node:assert/strict';
import {captureDialogReturnTarget, restoreDialogReturnTarget, rememberDialogTrigger} from '../../resources/js/dialog-focus.js';

function fixture() {
    let calls = 0;
    const trigger = {id: 'preview', isConnected: true, disabled: false, getAttribute: () => null, closest: () => null, getClientRects: () => [1], focus() {calls++; doc.activeElement = this;}};
    const root = {isConnected: true, contains: candidate => candidate === trigger || candidate === replacement};
    const replacement = {...trigger};
    const doc = {body: {}, activeElement: trigger, location: {href: '/catalogs/one'}, getElementById: () => replacement};
    const dialog = {contains: () => false, closest: () => root};
    const saved = captureDialogReturnTarget(dialog, doc);
    doc.activeElement = doc.body;
    const frames = [];
    const frame = callback => frames.push(callback);
    return {doc, trigger, replacement, root, saved, frame, flush: () => {while (frames.length) frames.shift()();}, calls: () => calls};
}

test('dialog focus waits through teardown and finds the stable replacement trigger', () => {
    const f = fixture();
    f.trigger.isConnected = false;
    restoreDialogReturnTarget(f.saved, f.doc, f.frame);
    assert.equal(f.calls(), 0);
    f.flush();
    assert.equal(f.calls(), 1);
    assert.equal(f.doc.activeElement, f.replacement);
});

test('dialog return never steals deliberate focus or crosses navigation/component boundaries', () => {
    for (const change of [f => {f.doc.activeElement = {};}, f => {f.doc.location.href = '/other';}, f => {f.root.isConnected = false;}]) {
        const f = fixture();
        restoreDialogReturnTarget(f.saved, f.doc, f.frame);
        change(f);
        f.flush();
        assert.equal(f.calls(), 0);
    }
});

test('hidden, disabled, inert and removed triggers are not focused', () => {
    for (const change of [f => {f.replacement.disabled = true;}, f => {f.replacement.isConnected = false;}, f => {f.replacement.getClientRects = () => [];}, f => {f.replacement.closest = () => ({});}]) {
        const f = fixture();
        change(f);
        restoreDialogReturnTarget(f.saved, f.doc, f.frame);
        f.flush();
        assert.equal(f.calls(), 0);
    }
});


test('opener captured before loading survives disabled-button blur and initial trap focus', () => {
    for (const trapHasFocus of [false, true]) {
        const f = fixture();
        f.trigger.closest = () => f.root;
        rememberDialogTrigger(f.trigger, f.doc);
        const modalField = {};
        f.doc.activeElement = trapHasFocus ? modalField : f.doc.body;
        const saved = captureDialogReturnTarget({closest: () => f.root, contains: node => node === modalField}, f.doc);
        assert.equal(saved.trigger, f.trigger);
        assert.equal(saved.id, 'preview');
    }
});
