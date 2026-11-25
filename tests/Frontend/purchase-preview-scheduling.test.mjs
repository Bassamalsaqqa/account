import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const template = readFileSync(new URL('../../resources/views/livewire/pages/purchasing/purchase-form.blade.php', import.meta.url), 'utf8');
const expression = template.match(/x-data="([\s\S]*?)"\n/)[1];

function mount() {
    let finishSave;
    let interceptor;
    let commits = 0;
    const timers = new Map();
    const context = {
        $wire: {
            $commit: () => commits++,
            $interceptMessage: (callback) => { interceptor = callback; return () => {}; },
        },
        document: { querySelectorAll: () => [] },
        window: {
            isDocumentBlocked: () => false,
            enqueueDocumentOperation: () => new Promise((resolve) => { finishSave = resolve; }),
        },
        setTimeout: (fn) => { const id = Symbol(); timers.set(id, fn); return id; },
        clearTimeout: (id) => timers.delete(id),
    };
    const state = vm.runInNewContext(`(${expression})`, context);
    state.init();
    return {
        state, context,
        get commits() { return commits; },
        finishSave: () => finishSave(),
        runTimers: () => { for (const fn of [...timers.values()]) fn(); timers.clear(); },
        messageCreated: () => { let send; interceptor({ onSend: (fn) => { send = fn; } }); return send; },
    };
}

for (const action of ['draft', 'confirm']) {
    test(`an edit during ${action} save refreshes once after the save becomes idle`, async () => {
        const form = mount();
        if (action === 'draft') form.state.handleSaveDraft();
        else {
            const handler = template.match(/@click="([^"]*'saveAndConfirm'[^\"]*)"/)[1];
            vm.runInNewContext(`with (state) { ${handler} }`, { ...form.context, state: form.state });
        }
        form.state.documentBusy = true;
        form.state.schedulePurchasePreview();
        form.runTimers();
        assert.equal(form.commits, 0);
        // The queue announces idle before the caller's promise finally handler.
        form.state.documentBusy = false;
        form.state.flushPendingPreview();
        assert.equal(form.commits, 0);
        form.finishSave();
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(form.state.saving, false);
        assert.equal(form.state.previewPending, false);
        assert.equal(form.commits, 1);
    });
}

test('an older outgoing message does not discard a newer pending edit', () => {
    const form = mount();
    form.state.schedulePurchasePreview();
    const send = form.messageCreated();
    form.state.schedulePurchasePreview();
    send();
    form.runTimers();
    assert.equal(form.commits, 1);
});
