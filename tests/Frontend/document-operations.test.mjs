import test from 'node:test';
import assert from 'node:assert/strict';
import {enqueueDocumentOperation, enqueueQuantityStep, isDocumentBusy, isDocumentBlocked, resyncDocumentQueue} from '../../resources/js/document-operations.js';
import {barcodeEntry} from '../../resources/js/barcode-entry.js';
const wait = ms => new Promise(resolve => setTimeout(resolve, ms));

test('failed secondary helper stops queued Save and requires explicit review without replay', async () => {
    const calls = [];
    const wire = {$id: 'secondary-failure',
        async stepInventoryQuantity() { calls.push('step'); await wait(10); throw new Error('Disconnected'); },
        async save() { calls.push('save'); },
        async $refresh() { calls.push('refresh'); },
    };
    const step = enqueueDocumentOperation(wire, 'stepInventoryQuantity', ['up']);
    const save = enqueueDocumentOperation(wire, 'save');
    const results = await Promise.allSettled([step, save]);
    assert.deepEqual(results.map(result => result.status), ['rejected', 'rejected']);
    assert.deepEqual(calls, ['step']);
    assert.equal(isDocumentBlocked(wire), true);
    await resyncDocumentQueue(wire, true);
    assert.deepEqual(calls, ['step', 'refresh']);
    assert.equal(isDocumentBlocked(wire), false);
    await enqueueDocumentOperation(wire, 'save');
    assert.deepEqual(calls, ['step', 'refresh', 'save']);
});
function fixture(id, latency = 0) {
    const calls = [];
    const wire = {$id:id, lines:[{quantity:'1', product_id:10, product_unit_id:20, unit_price:'2.00', lots:[]}],
        async batchStepQuantity(index, expected, identity, directions) {
            calls.push({method:'quantity', expected, identity, directions:[...directions]});
            await wait(latency);
            // Test double uses integer-only BigInt; fractional arithmetic is tested on PHP/MariaDB.
            let quantity=BigInt(expected);
            for(const direction of directions) { const next=quantity+(direction==='up'?1n:-1n); if(next>0n) quantity=next; }
            wire.lines[index].quantity=String(quantity);
            return {ok:true,quantity:String(quantity)};
        },
        async saveDraft(){calls.push({method:'save',quantity:wire.lines[0].quantity});},
        async addLine(){calls.push({method:'add'});},
        async scanCameraBarcode(code){calls.push({method:'scan',code,quantity:wire.lines[0].quantity});return {ok:true};},
        async recalculate(){calls.push({method:'recalculate'});},
    };
    return {wire,calls};
}
test('20 taps coalesce to one request; no optimistic quantity; exact ordered intent retained', async()=>{
    const {wire,calls}=fixture('twenty',80);
    const taps=Array.from({length:20},()=>enqueueQuantityStep(wire,0,'up',{debounceMs:20}));
    assert.equal(wire.lines[0].quantity,'1'); assert.equal(isDocumentBusy(wire),true);
    await Promise.all(taps);
    assert.equal(calls.length,1); assert.deepEqual(calls[0].directions,Array(20).fill('up'));
    assert.equal(wire.lines[0].quantity,'21');assert.equal(isDocumentBusy(wire),false);
});
test('mixed directions preserve intermediate minimum rather than netting taps',async()=>{
    const {wire,calls}=fixture('mixed');
    await Promise.all(['down','down','up','up'].map(direction=>enqueueQuantityStep(wire,0,direction,{debounceMs:5})));
    assert.deepEqual(calls[0].directions,['down','down','up','up']);assert.equal(wire.lines[0].quantity,'3');
});
test('fractional strings delegated intact with no client decimal calculation',async()=>{
    const {wire}=fixture('fraction');wire.lines[0].quantity='1.250';
    wire.batchStepQuantity=async(index,expected,identity,directions)=>{
        assert.equal(expected,'1.250');assert.deepEqual(directions,['up','up']);return{ok:true,quantity:'1.27'};
    };
    const results=await Promise.all([enqueueQuantityStep(wire,0,'up'),enqueueQuantityStep(wire,0,'up')]);
    assert.equal(results[0].quantity,'1.27');assert.equal(wire.lines[0].quantity,'1.250');
});
test('Save Scan and Add flush a pending batch and wait behind it',async()=>{
    for(const method of ['saveDraft','scanCameraBarcode','addLine']){
        const {wire,calls}=fixture(`flush-${method}`,10);
        const taps=Array.from({length:20},()=>enqueueQuantityStep(wire,0,'up'));
        const operation=enqueueDocumentOperation(wire,method,['CODE']);
        await Promise.all([...taps,operation]);
        assert.deepEqual(calls.map(call=>call.method),['quantity',method==='saveDraft'?'save':method==='addLine'?'add':'scan']);
        if(method!=='addLine') assert.equal(calls[1].quantity,'21');
    }
});
test('taps during an in-flight batch reserve FIFO before immediate Save and Scan',async()=>{
    const {wire,calls}=fixture('inflight',40);
    const first=enqueueQuantityStep(wire,0,'up',{debounceMs:0});await wait(5);
    const second=enqueueQuantityStep(wire,0,'up',{debounceMs:100});
    const third=enqueueQuantityStep(wire,0,'up',{debounceMs:100});
    const save=enqueueDocumentOperation(wire,'saveDraft');const scan=enqueueDocumentOperation(wire,'scanCameraBarcode',['NEXT']);
    await Promise.all([first,second,third,save,scan]);
    assert.deepEqual(calls.map(call=>call.method),['quantity','quantity','save','scan']);
    assert.deepEqual(calls.slice(0,2).map(call=>call.expected),['1','2']);assert.equal(calls[2].quantity,'4');
});
test('alternating row batches preserve global FIFO and bounded payloads',async()=>{
    const {wire,calls}=fixture('rows');wire.lines.push({...wire.lines[0],product_id:11});
    const pending=[enqueueQuantityStep(wire,0,'up'),enqueueQuantityStep(wire,1,'up'),enqueueQuantityStep(wire,0,'down')];
    await Promise.all(pending);assert.deepEqual(calls.map(call=>call.identity.product_id),[10,11,10]);
    const next=fixture('bounded');await Promise.all(Array.from({length:201},()=>enqueueQuantityStep(next.wire,0,'up',{debounceMs:1})));
    assert.deepEqual(next.calls.map(call=>call.directions.length),[100,100,1]);assert.equal(next.wire.lines[0].quantity,'202');
});
test('ambiguous quantity failure quarantines subsequent quantities and every queued operation; no replay',async()=>{
    const {wire,calls}=fixture('failure');let requests=0;
    wire.batchStepQuantity=async()=>{requests++;await wait(5);throw Error('network timeout');};
    const first=enqueueQuantityStep(wire,0,'up',{debounceMs:0});
    const save=enqueueDocumentOperation(wire,'saveDraft');const scan=enqueueDocumentOperation(wire,'scanCameraBarcode',['NEXT']);
    const outcomes=await Promise.allSettled([first,save,scan]);assert.ok(outcomes.every(result=>result.status==='rejected'));
    assert.equal(requests,1);assert.equal(calls.length,0);assert.equal(isDocumentBlocked(wire),true);
    await assert.rejects(enqueueDocumentOperation(wire,'saveDraft'),/blocked/);await wait(20);assert.equal(requests,1);
    await resyncDocumentQueue(wire);assert.equal(isDocumentBlocked(wire),false);assert.equal(requests,1);
    assert.deepEqual(calls.map(call=>call.method),['recalculate']);
    await enqueueDocumentOperation(wire,'saveDraft');assert.equal(calls.at(-1).quantity,'1');
});
test('server refusal and changed lots quarantine without applying or saving the wrong row',async()=>{
    for(const refusal of [false,true]){
        const {wire,calls}=fixture(`stale-${refusal}`);
        if(refusal)wire.batchStepQuantity=async()=>({ok:false,message:'stale quantity'});
        const tap=enqueueQuantityStep(wire,0,'up');if(!refusal)wire.lines[0].lots=[{lot_id:55,quantity:'1'}];
        const save=enqueueDocumentOperation(wire,'saveDraft');await Promise.all([assert.rejects(tap),assert.rejects(save)]);
        assert.equal(calls.length,0);assert.equal(wire.lines[0].quantity,'1');await resyncDocumentQueue(wire);
    }
});
test('recovery cannot start during an unresolved request and failed recovery remains quarantined',async()=>{
    const {wire}=fixture('recovery');let finish;wire.batchStepQuantity=()=>new Promise((resolve,reject)=>{finish=()=>reject(Error('timeout'));});
    const tap=enqueueQuantityStep(wire,0,'up',{debounceMs:0});await wait(5);
    await resyncDocumentQueue(wire);assert.equal(isDocumentBusy(wire),true);
    finish();await assert.rejects(tap);wire.recalculate=async()=>{throw Error('still offline');};
    await assert.rejects(resyncDocumentQueue(wire));assert.equal(isDocumentBlocked(wire),true);
});
test('unresolved request times out truthfully; recovery waits for its late response and never replays',async()=>{
    const {wire,calls}=fixture('never-settles');let finish;let requests=0;
    wire.batchStepQuantity=()=>{requests++;return new Promise(resolve=>{finish=()=>{wire.lines[0].quantity='2';resolve({ok:true,quantity:'2'});};});};
    const tap=enqueueQuantityStep(wire,0,'up',{debounceMs:0,timeoutMs:10});
    const save=enqueueDocumentOperation(wire,'saveDraft');
    const outcomes=await Promise.allSettled([tap,save]);
    assert.ok(outcomes.every(result=>result.status==='rejected'));
    assert.equal(isDocumentBlocked(wire),true);assert.equal(isDocumentBusy(wire),true);
    await assert.rejects(resyncDocumentQueue(wire),/Wait/);assert.equal(calls.length,0);
    finish();await wait(1);assert.equal(isDocumentBusy(wire),false);assert.equal(isDocumentBlocked(wire),true);
    await resyncDocumentQueue(wire);assert.equal(wire.lines[0].quantity,'2');assert.equal(requests,1);
    assert.deepEqual(calls.map(call=>call.method),['recalculate']);
});
test('legacy generic FIFO stays serialized and independent documents remain independent',async()=>{
    const trace=[];let active=0,max=0;
    const perform=async name=>{active++;max=Math.max(max,active);await wait(5);trace.push(name);active--;if(name==='fail')throw Error('refused');};
    const wire={$id:'legacy',scanCameraBarcode:code=>perform(code),incrementQuantity:()=>perform('quantity'),saveDraft:()=>perform('save')};
    await Promise.all([enqueueDocumentOperation(wire,'scanCameraBarcode',['first']),enqueueDocumentOperation(wire,'incrementQuantity'),enqueueDocumentOperation(wire,'scanCameraBarcode',['fail']).catch(()=>false),enqueueDocumentOperation(wire,'saveDraft').catch(()=>false)]);
    assert.deepEqual(trace,['first','quantity','fail']);assert.equal(isDocumentBlocked(wire),true);assert.equal(max,1);assert.equal(isDocumentBusy(wire),false);
    await assert.rejects(enqueueDocumentOperation(wire,'post'));const other=fixture('separate');await enqueueDocumentOperation(other.wire,'saveDraft');
});
test('hardware strings remain intact and review-required scan never restores already-added code',async()=>{
    const received=[];const wire={$id:'hardware',scanCameraBarcode:async code=>{received.push(code);await wait(5);return {ok:true};}};
    const entry=barcodeEntry(wire);entry.$el={dataset:{networkError:'offline'}};entry.entry='A';const a=entry.submit();entry.entry='B';const b=entry.submit();entry.entry='typing';
    await Promise.all([a,b]);assert.deepEqual(received,['A','B']);assert.equal(entry.entry,'typing');
    wire.scanCameraBarcode=async()=>({ok:true,requiresReview:true,message:'Review other lines'});entry.entry='ALREADY-ADDED';await entry.submit();
    assert.equal(entry.entry,'');assert.equal(entry.scanned,false);assert.equal(entry.feedback,'Review other lines');
});
