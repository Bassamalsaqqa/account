// Livewire 4's keyed insertBefore preserves the node but can blur its active child.
// Restore only that retained element, never a replacement or a deliberately removed row.
const revisions = new WeakMap();
const revisionFor = field => revisions.get(field) ?? 0;

export function captureRowFocus(root, doc = document) {
    const field = doc.activeElement;
    const row = field?.closest?.('[data-document-row]');
    if (!row || !root.contains(row)) return null;
    return {field, row, rowKey: row.dataset?.documentRow, revision: revisionFor(field), value: field.value, start: field.selectionStart, end: field.selectionEnd, direction: field.selectionDirection};
}

export function restoreRowFocus(saved, root, doc = document) {
    if (!saved || !saved.field.isConnected || !root.contains(saved.row) ||
        !saved.row.contains(saved.field) || saved.row.dataset?.documentRow !== saved.rowKey ||
        revisionFor(saved.field) !== saved.revision || saved.field.disabled || saved.field.readOnly ||
        (doc.activeElement !== doc.body && doc.activeElement !== saved.field)) return;
    saved.field.focus({preventScroll: true});
    // Reindexing can temporarily bind the retained node to the vacated index.
    // Restore text only when the final authoritative binding agrees exactly.
    const modelValue = saved.field._x_model?.get();
    const unchanged = modelValue !== undefined && String(modelValue ?? '') === saved.value;
    if (unchanged) saved.field.value = saved.value;
    if ((unchanged || modelValue === undefined) && saved.start !== null && saved.start !== undefined) {
        try { saved.field.setSelectionRange(saved.start, saved.end, saved.direction); } catch { /* number inputs */ }
    }
}

const bindingFor = field => [...field.attributes].find(attr => attr.name.startsWith('wire:model'))?.value;
export function captureRowBindings(root) {
    return [...root.querySelectorAll('[data-document-row] input, [data-document-row] select, [data-document-row] textarea')]
        .filter(field => bindingFor(field))
        .map(field => ({field, row: field.closest('[data-document-row]'), rowKey: field.closest('[data-document-row]').dataset?.documentRow, revision: revisionFor(field), binding: bindingFor(field)}));
}
export function syncReindexedRowBindings(saved, root) {
    for (const {field, row, rowKey, revision, binding} of saved) {
        if (!field.isConnected || !root.contains(row) || !row.contains(field) || row.dataset?.documentRow !== rowKey ||
            revisionFor(field) !== revision || field.disabled || field.readOnly || bindingFor(field) === binding) continue;
        const value = field._x_model?.get();
        // The same keyed control now reads a different positional path. Alpine
        // can leave its old, transient empty display after rebinding. Read the
        // final binding; never write back to the model or replay an input event.
        if (value !== undefined && !['checkbox', 'radio', 'file'].includes(field.type)) field.value = String(value ?? '');
    }
}

if (typeof document !== 'undefined') document.addEventListener('livewire:init', () => {
    window.Livewire.hook('component.init', ({component, cleanup}) => {
        const edited = event => revisions.set(event.target, revisionFor(event.target) + 1);
        component.el.addEventListener('input', edited, true);
        component.el.addEventListener('change', edited, true);
        cleanup(() => {
            component.el.removeEventListener('input', edited, true);
            component.el.removeEventListener('change', edited, true);
        });
        const stop = component.$wire.$interceptMessage(({onSuccess}) => {
            onSuccess(({onRender}) => {
                // onSuccess runs before mergeNewSnapshot; onRender follows the
                // Alpine transaction and morph. Both are installed Livewire 4 APIs.
                const saved = captureRowFocus(component.el);
                const bindings = captureRowBindings(component.el);
                onRender(() => {
                    syncReindexedRowBindings(bindings, component.el);
                    restoreRowFocus(saved, component.el);
                });
            });
        });
        cleanup(stop);
    });
});
