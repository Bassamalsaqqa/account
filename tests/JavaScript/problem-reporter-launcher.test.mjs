import test from 'node:test';
import assert from 'node:assert/strict';
import { launchProblemReporter } from '../../resources/js/problem-reporter-launcher.js';

test('failed chunk shows safe accessible retry feedback and successful retry removes it', async () => {
    let error = null;
    const draft = { value: 'unsaved amount' };
    const trigger = { insertAdjacentElement: (_, node) => { error = node; } };
    const doc = {
        documentElement: { lang: 'en' },
        getElementById: () => error,
        createElement: () => ({ setAttribute(k, v) { this[k] = v; }, remove() { error = null; } }),
        querySelector: () => ({ content: 'req_' + 'a'.repeat(32) }),
    };
    assert.equal(await launchProblemReporter(trigger, async () => { throw new Error('SECRET server details'); }, doc), false);
    assert.equal(error.role, 'alert');
    assert.match(error.textContent, /retry/);
    assert.match(error.textContent, /req_a{32}/);
    assert.doesNotMatch(error.textContent, /SECRET/);
    assert.equal(draft.value, 'unsaved amount');
    let opens = 0;
    assert.equal(await launchProblemReporter(trigger, async () => ({ openProblemReporter: () => { opens++; } }), doc), true);
    assert.equal(opens, 1);
    assert.equal(error, null);
});
