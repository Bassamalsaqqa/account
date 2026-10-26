import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {globalSearchComponent} from '../../resources/js/global-search.js';

const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => {resolve=a;reject=b;}); return {promise,resolve,reject}; };
const flush = () => new Promise(resolve => setImmediate(resolve));
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
    return {target,dialog,trigger,serverClose:()=>watcher(false),fireWatcher:(val)=>watcher(val)};
}

test('repeated modal dismissal releases browser inertness before returning focus', async () => {
    const {target,dialog,trigger} = fixture();
    for (let i=0;i<10;i++) {
        await target.openModal(); assert.equal(dialog.open,true);
        assert.equal(document.activeElement,target.$refs.searchInput);
        await target.closeModal(); assert.equal(dialog.open,false);
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
    target.$wire.close=async()=>{throw Error('offline');};await target.closeModal();
    await Promise.resolve();assert.equal(dialog.open,false);
});
test('closing before the Alpine tick prevents delayed modal activation', async () => {
    const {target,dialog}=fixture();let tick;target.$nextTick=fn=>tick=fn;
    await target.openModal();target.closeModal();tick();assert.equal(dialog.open,false);
});
test('detached or hidden old triggers are not refocused after navigation', async () => {
    const {target,trigger}=fixture();await target.openModal();trigger.isConnected=false;
    const active=document.activeElement;await target.closeModal();assert.equal(document.activeElement,active);
});
test('late close response does not cancel a new in-flight openModal (issue #62)', async () => {
    const {target,dialog,fireWatcher}=fixture();
    let resolveClose, resolveOpen;
    target.$wire.close = () => new Promise(r => { resolveClose = r; });
    target.$wire.open = () => new Promise(r => { resolveOpen = () => { target.$wire.isOpen = true; r(); }; });

    // Open 1
    const p1 = target.openModal();
    await flush();
    resolveOpen();
    await p1;
    assert.equal(dialog.open, true);

    // Close 1 (server close response pending)
    const closePromise = target.closeModal();
    assert.equal(dialog.open, false);
    assert.equal(target.clientOpen, false);

    // Immediate reopen (open 2 pending)
    const p2 = target.openModal();
    await flush();

    // Now old close response arrives and triggers watcher
    target.$wire.isOpen = false;
    fireWatcher(false);
    resolveClose();
    await closePromise;
    await flush();

    // Now open 2 finishes
    resolveOpen();
    await p2;

    assert.equal(dialog.open, true, 'Dialog must open successfully on second open');
    assert.equal(target.clientOpen, true, 'clientOpen must be true');
    assert.equal(target.isModalVisible(), true, 'isModalVisible must be true');
});

test('a newer open is not dispatched until the old close settles', async () => {
    const {target,dialog,fireWatcher}=fixture();
    await target.openModal();
    const close=deferred();let opens=0;
    target.$wire.close=async()=>{await close.promise;target.$wire.isOpen=false;fireWatcher(false);};
    target.$wire.open=async()=>{opens++;target.$wire.isOpen=true;};
    const closing=target.closeModal();const opening=target.openModal();
    await flush();assert.equal(opens,0);assert.equal(dialog.open,false);
    close.resolve();await closing;await opening;
    assert.equal(opens,1);assert.equal(dialog.open,true);
    target.$wire.isOpen=false;fireWatcher(false);
    assert.equal(dialog.open,false,'a fresh server policy/company reset still dismisses the modal');
});

test('queued opens cancelled by Escape or navigation never dispatch', async () => {
    for (const action of ['closeModal','destroy']) {
        const {target,dialog}=fixture();await target.openModal();
        const close=deferred();let opens=0;
        target.$wire.close=()=>close.promise;
        target.$wire.open=async()=>{opens++;target.$wire.isOpen=true;};
        const closing=target.closeModal();const opening=target.openModal();
        const cancellation=target[action]();
        close.resolve();await closing;await opening;await cancellation;
        assert.equal(opens,0);assert.equal(dialog.open,false);
    }
});

test('an old open response is drained before close and reauthorization', async () => {
    const {target,dialog}=fixture();const old=deferred();const calls=[];
    target.$wire.open=async()=>{calls.push('open');await old.promise;target.$wire.isOpen=true;};
    const first=target.openModal();await flush();
    target.$wire.close=async()=>{calls.push('close');target.$wire.isOpen=false;};
    const closing=target.closeModal();
    target.$wire.open=async()=>{calls.push('reopen');target.$wire.isOpen=false;};
    const reopening=target.openModal();old.resolve();
    await first;await closing;await reopening;
    assert.deepEqual(calls,['open','close','reopen']);
    assert.equal(dialog.open,false,'denied reauthorization must not reuse the old open grant');
});
test('rapid repeated open/close/open cycles maintain state consistency and visibility', async () => {
    const {target,dialog,fireWatcher}=fixture();
    for (let i = 0; i < 5; i++) {
        await target.openModal();
        assert.equal(dialog.open, true);
        assert.equal(target.isModalVisible(), true);
        const closeP = target.closeModal();
        const openP = target.openModal();
        fireWatcher(false);
        await closeP;
        await openP;
        assert.equal(dialog.open, true);
        assert.equal(target.isModalVisible(), true);
        await target.closeModal();
        assert.equal(dialog.open, false);
        assert.equal(target.isModalVisible(), false);
    }
});
test('Blade uses a native modal with morph-owned attributes excluded, without mutating siblings', () => {
    const blade=readFileSync(new URL('../../resources/views/livewire/global-search.blade.php',import.meta.url),'utf8');
    assert.match(blade,/<dialog x-ref="dialog"\s+wire:ignore\.self/);
    assert.match(blade,/@cancel\.prevent="closeModal\(\)"/);
    assert.doesNotMatch(blade,/x-trap|:aria-hidden|:inert/);
    assert.match(blade,/<\/dialog>/);
});
