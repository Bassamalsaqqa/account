import test from 'node:test';
import assert from 'node:assert/strict';
import {
    installNumericSelectOnFocus,
    isSelectableNumericInput,
    selectInputValue,
} from '../../resources/js/numeric-select-on-focus.js';

function createFakeDocument() {
    const listeners = new Map();
    const doc = {
        activeElement: null,
        addEventListener(type, fn) {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push(fn);
        },
        removeEventListener(type, fn) {
            if (!listeners.has(type)) return;
            listeners.set(type, listeners.get(type).filter((h) => h !== fn));
        },
        emit(type, event) {
            for (const fn of [...(listeners.get(type) ?? [])]) {
                fn(event);
            }
        },
        listenerCount(type) {
            return (listeners.get(type) ?? []).length;
        },
    };
    return doc;
}

function createFakeInput({
    tagName = 'INPUT',
    type = 'text',
    value = '100.00',
    inputMode = '',
    readOnly = false,
    disabled = false,
    attrs = {},
} = {}) {
    const attrMap = new Map(Object.entries(attrs));
    if (inputMode) attrMap.set('inputmode', inputMode);
    if (type) attrMap.set('type', type);

    return {
        tagName,
        type,
        value,
        inputMode,
        readOnly,
        disabled,
        autocomplete: attrMap.get('autocomplete') ?? '',
        selectedCount: 0,
        selectionRange: null,
        hasAttribute(name) {
            return attrMap.has(name);
        },
        getAttribute(name) {
            return attrMap.has(name) ? attrMap.get(name) : null;
        },
        select() {
            this.selectedCount += 1;
            this.selectionRange = [0, String(this.value).length];
        },
    };
}

function createEvent(target, extra = {}) {
    return {
        target,
        defaultPrevented: false,
        preventDefault() {
            this.defaultPrevented = true;
        },
        ...extra,
    };
}

test('isSelectableNumericInput targets semantic numeric inputs and excludes OTP, text, dates, passwords, and readonly fields', () => {
    assert.equal(isSelectableNumericInput(createFakeInput({ inputMode: 'decimal' })), true);
    assert.equal(isSelectableNumericInput(createFakeInput({ type: 'number', inputMode: 'decimal' })), true);
    assert.equal(isSelectableNumericInput(createFakeInput({ type: 'number', inputMode: 'numeric' })), true);
    assert.equal(isSelectableNumericInput(createFakeInput({ attrs: { 'data-select-on-focus': '' } })), true);

    assert.equal(isSelectableNumericInput(createFakeInput({ inputMode: 'numeric', attrs: { autocomplete: 'one-time-code' } })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ inputMode: 'decimal', attrs: { 'data-no-select-on-focus': '' } })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ inputMode: 'decimal', readOnly: true })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ inputMode: 'decimal', disabled: true })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ type: 'password', inputMode: 'numeric' })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ type: 'date', inputMode: 'numeric' })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ type: 'search', inputMode: 'decimal' })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ type: 'text', inputMode: '' })), false);
    assert.equal(isSelectableNumericInput(createFakeInput({ tagName: 'TEXTAREA', inputMode: 'decimal' })), false);
});

test('selectInputValue skips empty values without clearing valid inputs', () => {
    const empty = createFakeInput({ inputMode: 'decimal', value: '' });
    const zero = createFakeInput({ inputMode: 'decimal', value: '0' });
    assert.equal(selectInputValue(empty), false);
    assert.equal(empty.selectedCount, 0);
    assert.equal(selectInputValue(zero), true);
    assert.equal(zero.selectedCount, 1);
    assert.deepEqual(zero.selectionRange, [0, 1]);
});

test('keyboard focus selects value without clearing it on focusout', () => {
    const doc = createFakeDocument();
    const stop = installNumericSelectOnFocus(doc, {
        scheduleMicrotask: (fn) => fn(),
        scheduleFrame: (fn) => fn(),
    });
    const input = createFakeInput({ inputMode: 'decimal', value: '25.50' });
    doc.activeElement = input;
    doc.emit('focusin', createEvent(input));
    assert.equal(input.selectedCount, 1);
    assert.deepEqual(input.selectionRange, [0, 5]);
    doc.activeElement = null;
    doc.emit('focusout', createEvent(input));
    assert.equal(input.value, '25.50');
    stop();
});

test('first click selects existing value and prevents mouseup caret reset; second click preserves normal editing', () => {
    const doc = createFakeDocument();
    const queuedFrames = [];
    const stop = installNumericSelectOnFocus(doc, {
        scheduleMicrotask: (fn) => fn(),
        scheduleFrame: (fn) => queuedFrames.push(fn),
    });

    const input = createFakeInput({ inputMode: 'decimal', value: '123.45' });

    // First click from unfocused state
    doc.emit('pointerdown', createEvent(input, { button: 0, clientX: 40, clientY: 20 }));
    doc.emit('mousedown', createEvent(input, { button: 0, clientX: 40, clientY: 20 }));
    doc.activeElement = input;
    doc.emit('focusin', createEvent(input));
    doc.emit('pointerup', createEvent(input, { button: 0, clientX: 40, clientY: 20 }));
    const firstMouseUp = createEvent(input, { button: 0, clientX: 40, clientY: 20 });
    doc.emit('mouseup', firstMouseUp);
    for (const fn of queuedFrames.splice(0)) fn();

    assert.equal(firstMouseUp.defaultPrevented, true);
    assert.ok(input.selectedCount >= 1);

    // Second click inside already-focused input: must NOT preventDefault or re-select
    const beforeCount = input.selectedCount;
    input.selectionRange = [3, 3];
    doc.emit('pointerdown', createEvent(input, { button: 0, clientX: 28, clientY: 20 }));
    doc.emit('mousedown', createEvent(input, { button: 0, clientX: 28, clientY: 20 }));
    doc.emit('pointerup', createEvent(input, { button: 0, clientX: 28, clientY: 20 }));
    const secondMouseUp = createEvent(input, { button: 0, clientX: 28, clientY: 20 });
    doc.emit('mouseup', secondMouseUp);

    assert.equal(secondMouseUp.defaultPrevented, false);
    assert.equal(input.selectedCount, beforeCount);
    assert.deepEqual(input.selectionRange, [3, 3]);
    stop();
});

test('pointer drag across input cancels initial auto-select', () => {
    const doc = createFakeDocument();
    const stop = installNumericSelectOnFocus(doc, {
        scheduleMicrotask: (fn) => fn(),
        scheduleFrame: (fn) => fn(),
    });
    const input = createFakeInput({ inputMode: 'decimal', value: '100.00' });

    doc.emit('pointerdown', createEvent(input, { button: 0, clientX: 10, clientY: 10 }));
    doc.emit('mousedown', createEvent(input, { button: 0, clientX: 10, clientY: 10 }));
    doc.activeElement = input;
    doc.emit('focusin', createEvent(input));
    const countOnFocus = input.selectedCount;

    doc.emit('pointermove', createEvent(input, { clientX: 30, clientY: 10 }));
    doc.emit('pointerup', createEvent(input, { button: 0, clientX: 30, clientY: 10 }));
    const up = createEvent(input, { button: 0, clientX: 30, clientY: 10 });
    doc.emit('mouseup', up);

    assert.equal(up.defaultPrevented, false);
    assert.equal(input.selectedCount, countOnFocus);
    stop();
});

test('touch pointerup re-selects unless user starts typing before frame runs', () => {
    const doc = createFakeDocument();
    const microtasks = [];
    const frames = [];
    const stop = installNumericSelectOnFocus(doc, {
        scheduleMicrotask: (fn) => microtasks.push(fn),
        scheduleFrame: (fn) => frames.push(fn),
    });
    const input = createFakeInput({ inputMode: 'decimal', value: '50.00' });

    // Scenario A: tap without immediate typing -> microtask & frame restore selection
    doc.emit('pointerdown', createEvent(input, { button: 0, pointerType: 'touch' }));
    doc.activeElement = input;
    doc.emit('focusin', createEvent(input));
    input.selectionRange = [5, 5]; // Simulate mobile browser collapsing caret before pointerup
    doc.emit('pointerup', createEvent(input, { button: 0, pointerType: 'touch' }));
    for (const fn of microtasks.splice(0)) fn();
    for (const fn of frames.splice(0)) fn();
    assert.deepEqual(input.selectionRange, [0, 5]);

    // Scenario B: user starts typing (including Android IME Unidentified) before rAF fires -> must NOT clobber typed input
    doc.activeElement = null;
    doc.emit('focusout', createEvent(input));
    doc.emit('pointerdown', createEvent(input, { button: 0, pointerType: 'touch' }));
    doc.activeElement = input;
    doc.emit('focusin', createEvent(input));
    doc.emit('pointerup', createEvent(input, { button: 0, pointerType: 'touch' }));
    input.value = '9';
    input.selectionRange = [1, 1];
    doc.emit('keydown', createEvent(input, { key: 'Unidentified' }));
    for (const fn of microtasks.splice(0)) fn();
    for (const fn of frames.splice(0)) fn();
    assert.deepEqual(input.selectionRange, [1, 1]);

    // Duplicate installation guard
    const dupStop = installNumericSelectOnFocus(doc);
    assert.equal(doc.listenerCount('focusin'), 1);
    dupStop();
    stop();
    assert.equal(doc.listenerCount('focusin'), 0);
});
