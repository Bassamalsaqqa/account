import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';

const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
const stream = () => { throw new Error('Text corrections never request microphone capture'); };
async function fixture() {
 let create; const rootListeners=new Map(), navigationListeners=new Map();
 const window={Alpine:{data:(name,factory)=>{assert.equal(name,'transactionCorrections');create=factory;}},addEventListener:(name,fn)=>navigationListeners.set(name,fn),removeEventListener:name=>navigationListeners.delete(name)};
 const document={addEventListener:(name,fn)=>navigationListeners.set(name,fn),removeEventListener:name=>navigationListeners.delete(name)};
 const code=await fs.readFile(new URL('../../resources/js/transaction-recorder.js',import.meta.url),'utf8');
 vm.runInNewContext(code,{window,document,fetch:()=>assert.fail('No audio or provider transport in browser corrections')});
 const wire={$el:{addEventListener:(name,callback)=>rootListeners.set(name,callback),removeEventListener:(name)=>rootListeners.delete(name)}};
 const recorder=create(wire,{changedError:'form changed',deliveryError:'provider unavailable'});recorder.init();
 return {recorder,wire,rootListeners,navigationListeners};
}

test('manual edit during typed inference discards the prepared patch and never applies late fields', async()=>{
 const inference=deferred(), discarded=[], applied=[];
 const f=await fixture();
 f.wire.correctAiInput=()=>inference.promise;
 f.wire.discardPreparedAiInput=async token=>discarded.push(token);
 f.wire.applyPreparedAiInput=async token=>applied.push(token);
 const pending=f.recorder.applyTyped();
 const human={value:'9',getAttributeNames:()=>['wire:model.blur'],getAttribute:()=> 'lines.0.quantity'};
 f.rootListeners.get('input')({target:human});
 inference.resolve('prepared-patch'); await pending;
 assert.deepEqual(discarded,['prepared-patch']);assert.deepEqual(applied,[]);
 assert.equal(human.value,'9');assert.equal(f.recorder.error,'form changed');f.recorder.destroy();
});

test('a current prepared patch disables only enabled controls during the local commit and restores them', async()=>{
 const inference=deferred(),commit=deferred(),applied=[];
 const f=await fixture();
 const edit={disabled:false},restricted={disabled:true},save={disabled:false};
 f.wire.$el.querySelectorAll=()=>[edit,restricted,save];
 f.wire.correctAiInput=()=>inference.promise;
 f.wire.applyPreparedAiInput=async token=>{applied.push(token);await commit.promise;};
 const pending=f.recorder.applyTyped();
 assert.equal(edit.disabled,false);assert.equal(save.disabled,false);
 inference.resolve('prepared-patch');await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(applied,['prepared-patch']);assert.equal(edit.disabled,true);assert.equal(save.disabled,true);
 commit.resolve();await pending;
 assert.equal(edit.disabled,false);assert.equal(save.disabled,false);assert.equal(restricted.disabled,true);
 assert.equal(f.recorder.busy,false);f.recorder.destroy();
});

test('local commit failure restores editable controls and leaves the manual workflow usable', async()=>{
 const f=await fixture(),control={disabled:false};
 f.wire.$el.querySelectorAll=()=>[control];
 f.wire.correctAiInput=async()=> 'prepared-patch';
 f.wire.applyPreparedAiInput=async()=>{throw new Error('stale');};
 await f.recorder.applyTyped();
 assert.equal(control.disabled,false);assert.equal(f.recorder.busy,false);
 assert.equal(f.recorder.error,'provider unavailable');f.recorder.destroy();
});

test('a changed select invalidates pending correction just like typed financial input', async()=>{
 const inference=deferred(),discarded=[];
 const f=await fixture();
 f.wire.correctAiInput=()=>inference.promise;f.wire.discardPreparedAiInput=async token=>discarded.push(token);
 f.wire.applyPreparedAiInput=async()=>assert.fail('changed unit must refuse late patch');
 const pending=f.recorder.applyTyped();
 f.rootListeners.get('change')({target:{getAttributeNames:()=>['wire:model'],getAttribute:()=> 'lines.0.product_unit_id'}});
 inference.resolve('prepared-patch');await pending;
 assert.deepEqual(discarded,['prepared-patch']);assert.equal(f.recorder.error,'form changed');
 f.recorder.destroy();assert.equal(f.rootListeners.size,0);
});

test('explicit original-transcript submission uses its protected replacement acknowledgement without applying a patch',async()=>{
 const f=await fixture(),calls=[];
 f.wire.prepareAiTranscript=async()=>{calls.push('prepare transcript');return 'replacement';};
 f.wire.applyPreparedAiTranscript=async token=>calls.push(['acknowledge replacement',token]);
 f.wire.correctAiInput=async()=>assert.fail('original description is not a patch instruction');
 assert.deepEqual(calls,[]);await f.recorder.applyTyped(true);
 assert.deepEqual(calls,['prepare transcript',['acknowledge replacement','replacement']]);f.recorder.destroy();
});

test('human edit while reprocessing the original transcript cancels navigation and discards only the replacement',async()=>{
 const pending=deferred(),calls=[];
 const f=await fixture();
 f.wire.prepareAiTranscript=()=>pending.promise;
 f.wire.discardPreparedAiTranscript=async token=>calls.push(['discard replacement',token]);
 f.wire.applyPreparedAiTranscript=async()=>assert.fail('late replacement must not navigate');
 const processing=f.recorder.applyTyped(true);
 f.rootListeners.get('input')({target:{getAttributeNames:()=>['wire:model'],getAttribute:()=> 'notes'}});
 pending.resolve('replacement');await processing;
 assert.deepEqual(calls,[['discard replacement','replacement']]);assert.equal(f.recorder.error,'form changed');f.recorder.destroy();
});

test('transcript candidate clarification still waits for a fresh browser acknowledgement',async()=>{
 const f=await fixture(),calls=[];
 f.wire.prepareAiTranscriptClarification=async()=>{calls.push('clarify locally');return 'replacement';};
 f.wire.applyPreparedAiTranscript=async token=>calls.push(['acknowledge replacement',token]);
 f.wire.prepareAiTranscript=async()=>assert.fail('clarification must not run a second inference');
 await f.recorder.applyTyped(true,true);
 assert.deepEqual(calls,['clarify locally',['acknowledge replacement','replacement']]);f.recorder.destroy();
});

test('initialization and cancellation never submit input or dispatch audio',async()=>{
 const f=await fixture();
 f.wire.correctAiInput=()=>assert.fail('Opening corrections must not submit');
 assert.equal(f.recorder.busy,false);
 assert.equal('start' in f.recorder,false);assert.equal('transcribe' in f.recorder,false);
 f.recorder.clear();f.recorder.destroy();
 assert.equal(f.rootListeners.size,0);assert.equal(f.navigationListeners.size,0);
});

test('navigation discards a late prepared patch without applying it',async()=>{
 const f=await fixture(), pending=deferred(), discarded=[];
 f.wire.correctAiInput=()=>pending.promise;
 f.wire.discardPreparedAiInput=async token=>discarded.push(token);
 f.wire.applyPreparedAiInput=()=>assert.fail('Navigation must prevent local apply');
 const action=f.recorder.applyTyped();
 f.navigationListeners.get('livewire:navigating')();
 pending.resolve('late-patch');await action;
 assert.deepEqual(discarded,['late-patch']);f.recorder.destroy();
});

test('repeated clicks while inference is pending dispatch one explicit correction',async()=>{
 const f=await fixture(), pending=deferred();let calls=0;
 f.wire.correctAiInput=()=>{calls++;return pending.promise;};
 const first=f.recorder.applyTyped();await f.recorder.applyTyped();
 assert.equal(calls,1);pending.resolve(null);await first;
 assert.equal(f.recorder.busy,false);f.recorder.destroy();
});
