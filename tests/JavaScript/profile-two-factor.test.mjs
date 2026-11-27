import test from 'node:test';
import assert from 'node:assert/strict';
import {profileTwoFactor} from '../../resources/js/profile-two-factor.js';

const tick = () => new Promise(resolve => setImmediate(resolve));
const json = (value, status=200) => new Response(JSON.stringify(value), {status,headers:{'content-type':'application/json'}});
const routes = {qrCode:'/qr',secretKey:'/key',passwordConfirm:'/confirm',recoveryCodes:'/codes',enable:'/enable'};
const make = () => Object.assign(profileTwoFactor({routes,translations:{generic_error:'Failed',request_uncertain:'Reload status'}}), {$nextTick:fn=>fn(),$refs:{}});

test('late QR response cannot restore secrets after cleanup', async () => {
    global.document = {querySelector:()=>null};
    let finish;
    global.fetch = () => new Promise(resolve => { finish=resolve; });
    const target=make();
    const work=target.loadSetupData();
    target.clearSecrets();
    finish(json({svg:'<svg>synthetic-secret</svg>'}));
    await work;
    assert.equal(target.qrSvg,null);
    assert.equal(target.secretKey,null);
    assert.equal(target.error,null);
});

test('canceling password confirmation settles pending work', async () => {
    global.fetch = async () => json({},423);
    const target=make();
    const work=target.loadSetupData();
    await tick();
    assert.equal(target.showPasswordModal,true);
    target.cancelPasswordConfirm();
    await work;
    assert.equal(target.showPasswordModal,false);
    assert.equal(target.loading,false);
    assert.equal(target.qrSvg,null);
});

test('expired confirmation resumes QR and key sequentially through Fortify', async () => {
    const calls=[];
    let confirmed=false;
    global.fetch=async url=>{
        calls.push(url);
        if(url==='/confirm') { confirmed=true; return json({}); }
        if(!confirmed) return json({},423);
        return url==='/qr' ? json({svg:'<svg>synthetic</svg>'}) : json({secretKey:'SYNTHETIC-KEY'});
    };
    const target=make();
    const work=target.loadSetupData();
    await tick();
    target.confirmPasswordInput='fixture-password';
    await target.submitPasswordConfirm();
    await work;
    assert.deepEqual(calls,['/qr','/confirm','/qr','/key']);
    assert.equal(target.secretKey,'SYNTHETIC-KEY');
    assert.equal(target.confirmPasswordInput,'');
    target.clearSecrets();
});

test('canceling a rejected POST confirmation does not imply an uncertain mutation', async () => {
    global.fetch = async () => json({}, 423);
    const target = make();
    const work = target.enableTwoFactor();
    await tick();
    assert.equal(target.showPasswordModal, true);
    target.cancelPasswordConfirm();
    await work;
    assert.equal(target.uncertain, false);
    assert.equal(target.loading, false);
    assert.equal(target.status, 'disabled');
});

test('late recovery-code response cannot repopulate a cleared component', async () => {
    let finish;
    global.fetch=()=>new Promise(resolve=>{finish=resolve;});
    const target=make();
    const work=target.fetchRecoveryCodes();
    target.clearSecrets();
    finish(json(['synthetic-code']));
    await work;
    assert.deepEqual(target.recoveryCodes,[]);
    assert.equal(target.showingRecoveryCodes,false);
});

test('backgrounding and navigation clear secrets and teardown removes listeners', () => {
    global.window=new EventTarget();
    global.document=new EventTarget();
    document.querySelector=()=>null;
    document.hidden=false;
    const target=make();
    target.init();
    target.qrSvg='synthetic';
    document.hidden=true;
    document.dispatchEvent(new Event('visibilitychange'));
    assert.equal(target.qrSvg,null);
    target.recoveryCodes=['synthetic'];
    document.dispatchEvent(new Event('livewire:navigating'));
    assert.deepEqual(target.recoveryCodes,[]);
    target.destroy();
    target.qrSvg='listener-removal-probe';
    window.dispatchEvent(new Event('pagehide'));
    assert.equal(target.qrSvg,'listener-removal-probe');
    target.clearSecrets();
    delete global.window;
});

test('uncertain mutation outcome blocks blind retries and rapid double operations', async () => {
    let fail;
    let count=0;
    global.fetch=()=>{count++; return new Promise((resolve,reject)=>{fail=reject;});};
    const target=make();
    const work=target.enableTwoFactor();
    await target.enableTwoFactor();
    assert.equal(count,1);
    fail(new TypeError('Synthetic connection lost'));
    await work;
    assert.equal(target.uncertain,true);
    assert.equal(target.error,'Reload status');
    await target.enableTwoFactor();
    assert.equal(count,1);
});

test('cancelPasswordConfirm during in-flight POST /confirm-password does not abort, set uncertain, or drop secrets', async () => {
    let resolveConfirm;
    let confirmAborted = false;
    global.fetch = async (url, opts) => {
        if (url === '/confirm') {
            opts.signal.addEventListener('abort', () => { confirmAborted = true; });
            return new Promise((resolve) => { resolveConfirm = resolve; });
        }
        return json({}, 423);
    };

    const target = make();
    const pendingWork = target.loadSetupData().catch(() => {});
    await tick();
    assert.equal(target.showPasswordModal, true);

    target.confirmPasswordInput = 'secret-pass';
    const confirming = target.submitPasswordConfirm();
    assert.equal(target.confirmingPassword, true);

    // Attempt cancel while confirmation request is actively in-flight
    target.cancelPasswordConfirm();
    assert.equal(confirmAborted, false);
    assert.equal(target.uncertain, false);
    assert.equal(target.showPasswordModal, true);

    // Resolve POST /confirm cleanly and confirm subsequent retry runs without uncertain
    let qrCalls = 0;
    global.fetch = async (url) => {
        qrCalls++;
        return url === '/qr' ? json({svg: '<svg>ok</svg>'}) : json({secretKey: 'KEY-OK'});
    };
    resolveConfirm(json({}));
    await confirming;
    await pendingWork;

    assert.equal(target.confirmingPassword, false);
    assert.equal(target.showPasswordModal, false);
    assert.equal(target.uncertain, false);
    assert.equal(qrCalls, 2);
});

test('modal focus management focuses safe controls on open, restores opener focus on close, and keeps clearSecrets working during background purge', async () => {
    let focusedElement = null;
    const makeBtn = (id) => ({
        id,
        isConnected: true,
        disabled: false,
        getAttribute: () => null,
        closest: () => null,
        getClientRects: () => [{ width: 80, height: 44 }],
        focus() { focusedElement = this; },
    });

    const regenTrigger = makeBtn('regen-trigger');
    const regenCancelBtn = makeBtn('regen-cancel');
    const disableTrigger = makeBtn('disable-trigger');
    const disableCancelBtn = makeBtn('disable-cancel');
    const passwordInput = makeBtn('reauth_password_input');

    global.document = {
        querySelector: () => null,
        activeElement: regenTrigger,
        body: {},
        location: { href: 'http://localhost/profile' },
        getElementById: (id) => ({ 'regen-trigger': regenTrigger, 'disable-trigger': disableTrigger }[id] || null),
        addEventListener() {},
        removeEventListener() {},
    };

    const target = Object.assign(
        profileTwoFactor({ routes, translations: { generic_error: 'Failed', request_uncertain: 'Reload status' } }),
        {
            $nextTick: (fn) => fn(),
            $refs: {
                regenerateCancelButton: regenCancelBtn,
                disableCancelButton: disableCancelBtn,
                passwordConfirmInput: passwordInput,
            },
        }
    );

    target.openRegenerateModal(regenTrigger);
    assert.equal(target.showRegenerateModal, true);
    assert.equal(focusedElement, regenCancelBtn);
    target.closeRegenerateModal();
    assert.equal(target.showRegenerateModal, false);
    assert.equal(focusedElement, regenTrigger);

    focusedElement = null;
    target.openDisableModal(disableTrigger);
    assert.equal(target.showDisableModal, true);
    assert.equal(focusedElement, disableCancelBtn);
    target.closeDisableModal();
    assert.equal(target.showDisableModal, false);
    assert.equal(focusedElement, disableTrigger);

    // Cancel before POST submission closes password modal and returns focus
    global.fetch = async () => json({}, 423);
    global.document.activeElement = regenTrigger;
    focusedElement = null;
    const blocked = target.loadSetupData();
    await tick();
    assert.equal(focusedElement, passwordInput);
    target.cancelPasswordConfirm();
    await blocked;
    assert.equal(focusedElement, regenTrigger);

    // Lifecycle clearSecrets still works unconditionally during confirmingPassword
    let aborted = false;
    global.fetch = async (url, opts) => {
        if (url === '/confirm') {
            opts.signal.addEventListener('abort', () => { aborted = true; });
            return new Promise(() => {});
        }
        return json({}, 423);
    };
    const blocked2 = target.loadSetupData();
    await tick();
    target.confirmPasswordInput = 'secret-pass';
    void target.submitPasswordConfirm();
    assert.equal(target.confirmingPassword, true);
    target.clearSecrets();
    await blocked2;
    assert.equal(aborted, true);
    assert.equal(target.showPasswordModal, false);
    assert.equal(target.confirmPasswordInput, '');
});
