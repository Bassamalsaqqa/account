import test from 'node:test';
import assert from 'node:assert/strict';
import { enqueueDocumentOperation, isDocumentBusy } from '../../resources/js/document-operations.js';
import { barcodeEntry } from '../../resources/js/barcode-entry.js';

test('mixed scans, quantities and save wait for one document and failures are never replayed', async () => {
    const trace = []; let active = 0, maxActive = 0;
    const perform = async name => { active++; maxActive = Math.max(active,maxActive); await new Promise(r=>setTimeout(r,5)); trace.push(name); active--; if(name==='fail') throw Error('refused'); return name; };
    const wire = {$id:'queue-test', scanCameraBarcode:code=>perform(code), incrementQuantity:()=>perform('quantity'), saveDraft:()=>perform('save')};
    const pending = [enqueueDocumentOperation(wire,'scanCameraBarcode',['first']), enqueueDocumentOperation(wire,'incrementQuantity'), enqueueDocumentOperation(wire,'scanCameraBarcode',['fail']).catch(()=>false), enqueueDocumentOperation(wire,'saveDraft')];
    assert.equal(isDocumentBusy(wire),true);
    await Promise.all(pending);
    assert.deepEqual(trace,['first','quantity','fail','save']); assert.equal(maxActive,1); assert.equal(isDocumentBusy(wire),false);
});

test('hardware scan captures successive strings without losing the later input', async () => {
    const received=[];
    const wire={$id:'hardware-test',scanCameraBarcode:async code=>{received.push(code); await new Promise(r=>setTimeout(r,5)); return {ok:true};}};
    const entry=barcodeEntry(wire); entry.$el={dataset:{networkError:'offline'}};
    entry.entry='A';const a=entry.submit(); entry.entry='B';const b=entry.submit(); entry.entry='partially typed';
    await Promise.all([a,b]); assert.deepEqual(received,['A','B']); assert.equal(entry.entry,'partially typed');
});

test('independent documents do not block each other and unknown operations are rejected', async () => {
    await assert.rejects(enqueueDocumentOperation({$id:'bad'},'post'));
    const first={$id:'separate-a',saveDraft:()=>new Promise(r=>setTimeout(r,15))};
    const second={$id:'separate-b',saveDraft:async()=> 'done'};
    const pending=enqueueDocumentOperation(first,'saveDraft');
    assert.equal(await enqueueDocumentOperation(second,'saveDraft'),'done'); await pending;
});

test('hardware entry distinguishes an added item needing review without restoring or replaying it', async () => {
    const wire = {$id:'hardware-review', scanCameraBarcode:async () => ({ok:true, requiresReview:true, message:'Review other lines'})};
    const entry = barcodeEntry(wire);
    entry.$el = {dataset:{networkError:'offline'}};
    entry.entry = 'ALREADY-ADDED';
    await entry.submit();
    assert.equal(entry.entry, '');
    assert.equal(entry.scanned, false);
    assert.equal(entry.feedback, 'Review other lines');
});
