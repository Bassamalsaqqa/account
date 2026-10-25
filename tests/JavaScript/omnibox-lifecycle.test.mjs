import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {globalSearchComponent} from '../../resources/js/global-search.js';

const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b;}); return {promise,resolve,reject}; };
function fixture() {
    const trigger = {isConnected:true,getClientRects:()=>[{}],closest:()=>null,focus(){document.activeElement=this;}};
    global.document = {activeElement:trigger,querySelectorAll:()=>[]};
    Object.defineProperty(global,'navigator',{value:{onLine:true},configurable:true});
    let watcher;
    const target = globalSearchComponent();
    const dialog = {open:false,showModal(){this.open=true;},close(){this.open=false;}};
    target.$refs = {dialog,searchInput:{focus(){document.activeElement=this;}}};
    target.$nextTick = fn => fn();
    target.$wire = {isOpen:false,$watch(key,fn){watcher=fn;},async open(){this.isOpen=true;},async close(){this.isOpen=false;},async $set(){}};
    target.init();
    return {target,dialog,trigger,serverClose:()=>watcher(false)};
}

test('repeated modal dismissal releases browser inertness before returning focus', async () => {
    const {target,dialog,trigger} = fixture();
    for (let i=0;i<10;i++) {
        await target.openModal(); assert.equal(dialog.open,true);
        assert.equal(document.activeElement,target.$refs.searchInput);
        target.closeModal(); assert.equal(dialog.open,false);
        assert.equal(document.activeElement,trigger);
    }
});
test('server company/authorization reset closes the dialog locally', async () => {
    const {target,dialog,trigger,serverClose}=fixture();
    await target.openModal();serverClose();
    assert.equal(dialog.open,false);assert.equal(target.clientOpen,false);
    assert.equal(document.activeElement,trigger);
});
test('dismissal or component destruction cannot be undone by an in-flight open', async () => {
    for (const action of ['closeModal','destroy']) {
        const {target,dialog}=fixture();const pending=deferred();
        target.$wire.open=()=>pending.promise;
        const open=target.openModal();target[action]();
        target.$wire.isOpen=true;pending.resolve();await open;
        assert.equal(dialog.open,false);assert.equal(target.clientOpen,false);
    }
});
test('rejected open/close requests never leave a local modal or block retry', async () => {
    const {target,dialog}=fixture();target.$wire.open=async()=>{throw Error('offline');};
    await target.openModal();assert.equal(dialog.open,false);assert.equal(target.failed,true);
    target.$wire.open=async()=>{target.$wire.isOpen=true;};await target.openModal();
    target.$wire.close=async()=>{throw Error('offline');};target.closeModal();
    await Promise.resolve();assert.equal(dialog.open,false);
});
test('closing before the Alpine tick prevents delayed modal activation', async () => {
    const {target,dialog}=fixture();let tick;target.$nextTick=fn=>tick=fn;
    await target.openModal();target.closeModal();tick();assert.equal(dialog.open,false);
});
test('detached or hidden old triggers are not refocused after navigation', async () => {
    const {target,trigger}=fixture();await target.openModal();trigger.isConnected=false;
    const active=document.activeElement;target.closeModal();assert.equal(document.activeElement,active);
});
test('Blade uses a native modal with morph-owned attributes excluded, without mutating siblings', () => {
    const blade=readFileSync(new URL('../../resources/views/livewire/global-search.blade.php',import.meta.url),'utf8');
    assert.match(blade,/<dialog x-ref="dialog"\s+wire:ignore\.self/);
    assert.match(blade,/@cancel\.prevent="closeModal\(\)"/);
    assert.doesNotMatch(blade,/x-trap|:aria-hidden|:inert/);
    assert.match(blade,/<\/dialog>/);
});
