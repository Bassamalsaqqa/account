import test from 'node:test';
import assert from 'node:assert/strict';
import {companyDataDownload} from '../../resources/js/company-data-download.js';
import {profileTwoFactor} from '../../resources/js/profile-two-factor.js';

const json = (value, status = 200) => new Response(JSON.stringify(value), {status, headers:{'content-type':'application/json'}});
const tick = () => new Promise(resolve => setImmediate(resolve));
const routes = {enable:'/enable',confirm:'/confirm-totp',disable:'/disable',regenerateRecoveryCodes:'/regenerate',recoveryCodes:'/codes',passwordConfirm:'/password'};
const makeSecurity = () => Object.assign(profileTwoFactor({initialStatus:'enabled',routes,translations:{request_uncertain:'Reload authoritative state',generic_error:'Request failed'}}), {$nextTick:fn=>fn(),$refs:{}});

test('actual download fetch prefers JSON; 423 cannot download and requires an explicit confirmed retry', async () => {
    global.FormData = class {};
    let requests = 0, downloads = 0, confirmed = false;
    global.document = {body:{appendChild(){}},createElement:()=>({click(){downloads++;},remove(){}})};
    global.fetch = async (url, options) => {
        requests++;
        assert.equal(options.headers.Accept, 'application/json, application/zip');
        assert.equal(options.headers['X-Requested-With'], 'XMLHttpRequest');
        assert.equal(options.method, 'POST');
        if (!confirmed) return json({message:'Password confirmation required.'}, 423);
        return new Response('PK-synthetic', {headers:{'content-type':'application/zip','content-length':'12'}});
    };
    const target = companyDataDownload({confirmRequired:'Confirm password',failed:'Failed',started:'Download started'});
    const form = {action:'/settings/company-data/download'};
    await target.download(form);
    assert.equal(target.needsConfirmation, true);
    assert.equal(target.error, 'Confirm password');
    assert.equal(target.message, '');
    assert.equal(downloads, 0);
    assert.equal(requests, 1);
    confirmed = true;
    await tick();
    assert.equal(requests, 1, 'Confirmation must not automatically retry a sensitive export.');
    await target.download(form);
    assert.equal(requests, 2);
    assert.equal(downloads, 1);
    assert.equal(target.needsConfirmation, false);
    assert.equal(target.error, '');
    assert.equal(target.message, 'Download started');
});

for (const operation of ['enableTwoFactor','confirmTwoFactor','cancelPending','disableTwoFactor','regenerateRecoveryCodes']) {
    for (const status of [500,502,503]) {
        test(`${operation}: post-commit HTTP ${status} blocks retries and clears stale security material`, async () => {
            global.document = {querySelector:()=>null};
            let commits = 0;
            global.fetch = async (url, options) => {
                assert.ok(['POST','DELETE'].includes(options.method), 'Must not fetch/display recovery codes after an uncertain write.');
                commits++; // simulate a successful Fortify mutation before the response fails
                if (status === 502) return new Response('{malformed', {status,headers:{'content-type':'application/json'}});
                if (status === 503) return new Response('<html>Unavailable</html>', {status,headers:{'content-type':'text/html'}});
                return json({message:'Response failed after commit'}, status);
            };
            const target = makeSecurity();
            target.confirmationCode = '123456';
            target.recoveryCodes = ['SYNTHETIC-OBSOLETE'];
            target.showingRecoveryCodes = true;
            target.qrSvg = '<svg>SYNTHETIC</svg>';
            target.secretKey = 'SYNTHETIC';
            await target[operation]();
            assert.equal(commits, 1);
            assert.equal(target.uncertain, true);
            assert.equal(target.error, 'Reload authoritative state');
            assert.equal(target.success, null);
            assert.equal(target.loading, false);
            assert.deepEqual(target.recoveryCodes, []);
            assert.equal(target.showingRecoveryCodes, false);
            assert.equal(target.qrSvg, null);
            assert.equal(target.secretKey, null);
            await target[operation]();
            assert.equal(commits, 1, 'Blind retry can invalidate codes the user never received.');
        });
    }
}

test('confirmed pre-mutation TOTP validation errors remain safely retryable', async () => {
    global.document = {querySelector:()=>null};
    let writes = 0;
    global.fetch = async (url, options) => {
        if (options.method === 'GET') return json(['SYNTHETIC-CURRENT']);
        writes++;
        return writes === 1 ? json({errors:{code:['Invalid code']}}, 422) : new Response(null, {status:204});
    };
    const target = makeSecurity();
    target.status = 'pending';
    target.confirmationCode = '123456';
    await target.confirmTwoFactor();
    assert.equal(target.uncertain, false);
    assert.equal(target.error, 'Invalid code');
    await target.confirmTwoFactor();
    assert.equal(writes, 2);
    assert.equal(target.status, 'enabled');
    assert.deepEqual(target.recoveryCodes, ['SYNTHETIC-CURRENT']);
});

test('423 requires Fortify reauthentication before exactly one recovery-code mutation', async () => {
    global.document = {querySelector:()=>null};
    let confirmed = false, commits = 0;
    global.fetch = async (url, options) => {
        if (url === routes.passwordConfirm) { confirmed = true; return new Response(null, {status:204}); }
        if (!confirmed) return json({message:'Password confirmation required.'}, 423);
        if (options.method === 'GET') return json(['SYNTHETIC-CURRENT']);
        commits++;
        return new Response(null, {status:204});
    };
    const target = makeSecurity();
    const pending = target.regenerateRecoveryCodes();
    await tick();
    assert.equal(target.showPasswordModal, true);
    assert.equal(target.uncertain, false);
    assert.equal(commits, 0);
    target.confirmPasswordInput = 'synthetic-confirmation';
    await target.submitPasswordConfirm();
    await pending;
    assert.equal(commits, 1);
    assert.equal(target.uncertain, false);
    assert.deepEqual(target.recoveryCodes, ['SYNTHETIC-CURRENT']);
});

test('read-only recovery-code HTTP errors remain retryable', async () => {
    global.document = {querySelector:()=>null};
    let reads = 0;
    global.fetch = async () => ++reads === 1 ? json({}, 503) : json(['SYNTHETIC-CURRENT']);
    const target = makeSecurity();
    await target.fetchRecoveryCodes();
    assert.equal(target.uncertain, false);
    await target.fetchRecoveryCodes();
    assert.equal(reads, 2);
    assert.deepEqual(target.recoveryCodes, ['SYNTHETIC-CURRENT']);
});

test('malformed success JSON after a simulated mutation remains an uncertain outcome', async () => {
    global.document = {querySelector:()=>null};
    let commits = 0;
    global.fetch = async () => {
        commits++;
        return new Response('{malformed', {status:200,headers:{'content-type':'application/json'}});
    };
    const target = makeSecurity();
    await target.regenerateRecoveryCodes();
    await target.regenerateRecoveryCodes();
    assert.equal(commits, 1);
    assert.equal(target.uncertain, true);
    assert.equal(target.success, null);
    assert.equal(target.error, 'Reload authoritative state');
});
