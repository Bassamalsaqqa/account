// Positional Livewire models must not accept edits while the server changes the
// row/lot structure. Keep text controls focused and selectable with readonly.
const mutations = new Set(['addLine', 'removeLine', 'selectProduct', 'changeUnit', 'scanBarcode', 'scanCameraBarcode', 'saveQuickProduct', 'customizeLots', 'resetToAutoLot', 'addLot', 'removeLot', 'applyPreparedAiInput', 'applyPreparedAiTranscript', 'undoAiInput', 'mountAiInput']);
const guardedDocumentActions = new Set([
    ...mutations,
    'save',
    'saveDraft',
    'saveAndConfirm',
    'confirmPost',
    'cancelPreview',
    'discardAiInput',
    'cancelAiCorrection',
]);
const locks = new WeakMap();

function wireClickMethod(button) {
    const raw = button?.getAttribute?.('wire:click') || '';
    const openParen = raw.indexOf('(');
    return (openParen >= 0 ? raw.slice(0, openParen) : raw).trim();
}

export function shouldGuardRowAction(target) {
    const button = target?.closest?.('button, input[type="submit"]');
    if (!button) return false;
    if (button.closest?.('[data-document-row]')) return true;
    if (button.closest?.('[data-row-mutation-action], [data-mobile-confirm]')) return true;
    if (guardedDocumentActions.has(wireClickMethod(button))) return true;
    return button.type === 'submit' && !button.closest?.('[role="dialog"]');
}

export function shouldGuardRowEdit(event) {
    if (event?.type === 'beforeinput') {
        return Boolean(event.target?.closest?.('[data-document-row]'));
    }
    if (event?.type === 'keydown') {
        if (event.key !== 'Enter') return false;
        if (event.target?.closest?.('[data-document-row], [data-row-mutation-enter]')) return true;
        return shouldGuardRowAction(event.target);
    }
    return false;
}
export function lockRowEditors(root) {
    const existing = locks.get(root);
    if (existing) { existing.count++; return (...args) => existing.release(...args); }
    const saved = [...root.querySelectorAll('[data-document-row] input, [data-document-row] textarea, [data-document-row] select, [data-document-row] button')]
        .map(field => ({field, readonly: field.readOnly, disabled: field.disabled}));
    root.dataset.rowMutationBusy = 'true';
    for (const {field} of saved) {
        if (field.tagName === 'INPUT' || field.tagName === 'TEXTAREA') field.readOnly = true;
        else field.disabled = true;
    }
    for (const row of root.querySelectorAll('[data-document-row]')) row.setAttribute('aria-busy', 'true');
    const status = root.querySelector('[data-row-mutation-status]');
    if (status) status.textContent = status.dataset.busyLabel;
    const state = {count: 1, morphed: false, release: null};
    const release = (morphed = false) => {
        state.morphed ||= morphed;
        if (--state.count > 0) return;
        locks.delete(root);
        delete root.dataset.rowMutationBusy;
        for (const {field, readonly, disabled} of saved) {
            if (!field.isConnected || state.morphed) continue;
            // Bound permission/loading state wins over the temporary lock.
            if (field.tagName === 'INPUT' || field.tagName === 'TEXTAREA') field.readOnly = readonly;
            else field.disabled = disabled;
        }
        for (const row of root.querySelectorAll('[data-document-row]')) row.removeAttribute('aria-busy');
        if (status?.textContent === status?.dataset.busyLabel) status.textContent = '';
        root.dispatchEvent(new CustomEvent('document-row-mutation-settled', {bubbles: true, detail: {id: root.getAttribute('wire:id')}}));
    };
    state.release = release;
    locks.set(root, state);
    return release;
}
if (typeof document !== 'undefined') document.addEventListener('livewire:init', () => {
    window.Livewire.hook('component.init', ({component, cleanup}) => {
        // Empty saved drafts can acquire rows later without component reinitialization.
        if (!component.el.querySelector('[data-row-mutation-status]')) return;
        const releases = new Set();
        const showRetry = () => {
            const status = component.el.querySelector('[data-row-mutation-status]');
            if (status) status.textContent = status.dataset.retryLabel;
        };
        // Guard ONLY structurally locked line controls, row-mutation actions, and save/confirm submission controls.
        // Never block Cancel/Back anchors, outside free-form notes, or unrelated quick-create modals.
        const stopAction = event => {
            if (!releases.size || !shouldGuardRowAction(event.target)) return;
            showRetry();
            event.preventDefault(); event.stopImmediatePropagation();
        };
        const stopEdit = event => {
            if (!releases.size || !shouldGuardRowEdit(event)) return;
            showRetry();
            event.preventDefault(); event.stopImmediatePropagation();
        };
        const stopSubmit = event => {
            if (!releases.size || event.target?.closest?.('[role="dialog"]')) return;
            showRetry();
            event.preventDefault(); event.stopImmediatePropagation();
        };
        component.el.addEventListener('click', stopAction, true);
        component.el.addEventListener('beforeinput', stopEdit, true);
        component.el.addEventListener('keydown', stopEdit, true);
        component.el.addEventListener('submit', stopSubmit, true);
        const stop = component.$wire.$interceptMessage(({message, onSuccess, onFinish}) => {
            const changesRows = [...message.actions].some(action => mutations.has(action.name));
            if (!changesRows) return;
            const unlock = lockRowEditors(component.el);
            releases.add(unlock);
            let rendering = false;
            let finished = false;
            const release = (morphed = false) => { if (finished) return; finished = true; unlock(morphed); releases.delete(unlock); };
            onSuccess(({payload, onRender}) => { rendering = true; onRender(() => release(Boolean(payload.effects?.html))); });
            onFinish(() => { if (!rendering) release(); });
        });
        cleanup(() => { stop(); for (const release of releases) release(); releases.clear(); component.el.removeEventListener('click', stopAction, true); component.el.removeEventListener('beforeinput', stopEdit, true); component.el.removeEventListener('keydown', stopEdit, true); component.el.removeEventListener('submit', stopSubmit, true); });
    });
});
