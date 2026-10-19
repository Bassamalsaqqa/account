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
