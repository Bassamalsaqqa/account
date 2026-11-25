const DIRECT_TYPES = new Set(['text', 'number']);
const NUMERIC_INPUT_MODES = new Set(['decimal', 'numeric']);
const NON_EDITING_KEYS = new Set(['Tab', 'Shift', 'Control', 'Alt', 'Meta', 'Escape']);
const installedRoots = new WeakSet();

export function isSelectableNumericInput(el) {
    if (!el || typeof el !== 'object') return false;
    if (String(el.tagName || '').toUpperCase() !== 'INPUT') return false;
    if (el.disabled || el.readOnly) return false;

    const type = String(el.type ?? el.getAttribute?.('type') ?? 'text').toLowerCase();
    if (!DIRECT_TYPES.has(type)) return false;

    const autocomplete = String(el.getAttribute?.('autocomplete') ?? el.autocomplete ?? '').toLowerCase();
    if (autocomplete === 'one-time-code') return false;
    if (el.hasAttribute?.('data-no-select-on-focus')) return false;

    const inputMode = String(el.inputMode ?? el.getAttribute?.('inputmode') ?? '').toLowerCase();
    return NUMERIC_INPUT_MODES.has(inputMode) || Boolean(el.hasAttribute?.('data-select-on-focus'));
}

export function selectInputValue(el) {
    if (!isSelectableNumericInput(el)) return false;
    if (el.value === undefined || el.value === null || String(el.value) === '') return false;
    try {
        el.select?.();
        return true;
    } catch {
        return false;
    }
}

export function installNumericSelectOnFocus(root = typeof document !== 'undefined' ? document : null, options = {}) {
    if (!root || typeof root.addEventListener !== 'function' || installedRoots.has(root)) {
        return () => {};
    }
    installedRoots.add(root);

    const scheduleFrame = options.scheduleFrame ?? ((fn) => {
        if (typeof globalThis.requestAnimationFrame === 'function') {
            return globalThis.requestAnimationFrame(fn);
        }
        return setTimeout(fn, 0);
    });
    const scheduleMicrotask = options.scheduleMicrotask ?? ((fn) => queueMicrotask(fn));

    let pointerDown = null;
    let suppressMouseUpFor = null;
    let reselectPointerUpFor = null;
    let typedSinceFocus = false;

    const activeEl = () => root.activeElement ?? null;

    const resetPointerGuards = () => {
        pointerDown = null;
        suppressMouseUpFor = null;
        reselectPointerUpFor = null;
    };

    const recordPointerDown = (event) => {
        const target = event?.target;
        if (!isSelectableNumericInput(target)) {
            if (!pointerDown || pointerDown.target !== target) pointerDown = null;
            return;
        }
        if (typeof event.button === 'number' && event.button !== 0) {
            pointerDown = null;
            return;
        }
        const alreadyFocused = pointerDown?.target === target
            ? pointerDown.alreadyFocused
            : activeEl() === target;
        pointerDown = {
            target,
            alreadyFocused,
            x: typeof event.clientX === 'number' ? event.clientX : null,
            y: typeof event.clientY === 'number' ? event.clientY : null,
            dragged: false,
        };
    };

    const onPointerMove = (event) => {
        if (!pointerDown || pointerDown.dragged) return;
        if (pointerDown.x === null || pointerDown.y === null) return;
        if (typeof event.clientX !== 'number' || typeof event.clientY !== 'number') return;
        if (Math.hypot(event.clientX - pointerDown.x, event.clientY - pointerDown.y) > 4) {
            pointerDown.dragged = true;
            if (suppressMouseUpFor === pointerDown.target) suppressMouseUpFor = null;
            if (reselectPointerUpFor === pointerDown.target) reselectPointerUpFor = null;
        }
    };

    const onFocusIn = (event) => {
        const target = event?.target;
        typedSinceFocus = false;
        if (!isSelectableNumericInput(target)) {
            resetPointerGuards();
            return;
        }
        const fromInitialPointer = pointerDown?.target === target && !pointerDown.alreadyFocused && !pointerDown.dragged;
        suppressMouseUpFor = fromInitialPointer ? target : null;
        reselectPointerUpFor = fromInitialPointer ? target : null;
        selectInputValue(target);
    };

    const onMouseUp = (event) => {
        const target = event?.target;
        const guardTarget = suppressMouseUpFor;
        suppressMouseUpFor = null;
        if (!guardTarget || target !== guardTarget || typedSinceFocus) return;
        if (activeEl() !== guardTarget || !isSelectableNumericInput(guardTarget)) return;
        event.preventDefault?.();
        selectInputValue(guardTarget);
    };

    const onPointerUp = (event) => {
        const target = event?.target;
        const guardTarget = reselectPointerUpFor;
        reselectPointerUpFor = null;
        pointerDown = null;
        if (!guardTarget || target !== guardTarget || typedSinceFocus) return;
        const reselectIfPending = () => {
            if (typedSinceFocus || activeEl() !== guardTarget || !isSelectableNumericInput(guardTarget)) return;
            selectInputValue(guardTarget);
        };
        reselectIfPending();
        scheduleMicrotask(reselectIfPending);
        scheduleFrame(reselectIfPending);
    };

    const markUserEdited = (event) => {
        const target = event?.target;
        if (!isSelectableNumericInput(target)) return;
        if (event.type === 'keydown' && NON_EDITING_KEYS.has(event.key)) return;
        typedSinceFocus = true;
        suppressMouseUpFor = null;
        reselectPointerUpFor = null;
    };

    const onFocusOut = () => {
        typedSinceFocus = false;
        resetPointerGuards();
    };

    const listeners = [
        ['pointerdown', recordPointerDown, true],
        ['mousedown', recordPointerDown, true],
        ['pointermove', onPointerMove, true],
        ['mousemove', onPointerMove, true],
        ['pointerup', onPointerUp, true],
        ['mouseup', onMouseUp, true],
        ['pointercancel', resetPointerGuards, true],
        ['focusin', onFocusIn, true],
        ['focusout', onFocusOut, true],
        ['keydown', markUserEdited, true],
        ['beforeinput', markUserEdited, true],
        ['input', markUserEdited, true],
    ];

    for (const [type, handler, capture] of listeners) {
        root.addEventListener(type, handler, capture);
    }

    return () => {
        for (const [type, handler, capture] of listeners) {
            root.removeEventListener(type, handler, capture);
        }
        installedRoots.delete(root);
    };
}

if (typeof document !== 'undefined') {
    installNumericSelectOnFocus(document);
}
