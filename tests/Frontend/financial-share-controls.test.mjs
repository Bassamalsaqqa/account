import assert from 'node:assert/strict';
import test from 'node:test';
import { financialShareControls } from '../../resources/js/financial-share-controls.js';

const wire = { $watch() {} };
test('copy uses the selected URL and reports success', async () => {
    let copied;
    const controls = financialShareControls(wire, { clipboard: { writeText: async value => { copied = value; } } });
    await controls.copy('https://example.test/s/selected');
    assert.equal(copied, 'https://example.test/s/selected');
    assert.equal(controls.feedback, 'copied');
    assert.equal(controls.busy, false);
});
test('missing and rejected clipboard APIs provide actionable failure state', async () => {
    for (const browser of [{}, { clipboard: { writeText: async () => { throw new Error('Denied'); } } }]) {
        const controls = financialShareControls(wire, browser);
        await controls.copy('https://example.test/s/selected');
        assert.equal(controls.feedback, 'copy-failed');
        assert.equal(controls.busy, false);
    }
});
test('native share success means handoff, not recipient delivery', async () => {
    let received;
    const controls = financialShareControls(wire, { share: async data => { received = data; } });
    const data = { title: 'Shared document', text: 'Open the secure link below:', url: 'https://example.test/s/selected' };
    await controls.share(data);
    assert.deepEqual(received, data);
    assert.equal(controls.feedback, 'shared');
});
test('user cancellation remains quiet while other share failures are visible', async () => {
    for (const [name, expected] of [['AbortError', ''], ['NotAllowedError', 'share-failed']]) {
        const controls = financialShareControls(wire, { share: async () => { throw Object.assign(new Error('Native failure'), { name }); } });
        await controls.share({ url: 'https://example.test/s/selected' });
        assert.equal(controls.feedback, expected);
        assert.equal(controls.busy, false);
    }
});
test('missing native API and empty URL never report successful sharing', async () => {
    const controls = financialShareControls(wire, {});
    await controls.share({ url: '' });
    assert.equal(controls.feedback, '');
    await controls.share({ url: 'https://example.test/s/selected' });
    assert.equal(controls.feedback, 'share-failed');
});
test('in-flight duplicate actions are ignored and old link completion cannot claim current success', async () => {
    let finish, calls = 0, changed;
    const controls = financialShareControls({ $watch: (_key, callback) => { changed = callback; } }, {
        clipboard: { writeText: () => { calls++; return new Promise(resolve => { finish = resolve; }); } },
    });
    controls.init();
    const pending = controls.copy('https://example.test/s/old');
    await controls.copy('https://example.test/s/old');
    await controls.share({ url: 'https://example.test/s/old' });
    assert.equal(calls, 1);
    changed();
    finish();
    await pending;
    assert.equal(controls.feedback, '');
    assert.equal(controls.busy, false);
});
