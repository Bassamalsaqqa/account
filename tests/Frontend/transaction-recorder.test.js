import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import { encodePcmWave } from '../../resources/js/ai-wave.js';

const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
const stream = () => { const track={stopped:false,stop(){this.stopped=true;}}; return {track,getTracks:()=>[track]}; };
async function fixture(getUserMedia, fetcher = async()=>({ok:true,json:async()=>({text:'Editable transcript',audio_token:'synthetic-token'})}), localSpeech = null, method = 'cloud', correction = false) {
 let create; const contexts=[], nodes=[], changes=[], rootListeners=new Map();
 class Context {
  constructor(){this.sampleRate=48000;this.destination={};this.closed=false;this.audioWorklet={addModule:async()=>{}};contexts.push(this);}
  resume(){return Promise.resolve();} close(){this.closed=true;return Promise.resolve();}
  createMediaStreamSource(){return {connect(){},disconnect(){}};}
 }
 class Worklet {
  constructor(){this.port={onmessage:null,postMessage:()=>this.port.onmessage({data:{flushed:true}})};nodes.push(this);}
  connect(){} disconnect(){}
 }
 const window={SpeechRecognition:localSpeech,isSecureContext:true,AudioContext:Context,AudioWorkletNode:Worklet,Alpine:{data:(name,factory)=>{create=factory;}},addEventListener(){},removeEventListener(){}};
 const document={addEventListener(){},removeEventListener(){},querySelector:()=>({content:'synthetic-csrf'})};
 const code=(await fs.readFile(new URL('../../resources/js/transaction-recorder.js',import.meta.url),'utf8')).replace(/^import .*?;s*/, '').replaceAll('import.meta.url',JSON.stringify(import.meta.url));
 vm.runInNewContext(code,{window,document,navigator:{mediaDevices:{getUserMedia}},AudioContext:Context,AudioWorkletNode:Worklet,encodePcmWave,URL,Blob,FormData,AbortController,crypto:globalThis.crypto,fetch:fetcher,setTimeout,clearTimeout});
 const wire={aiFormRevision:3,$el:{addEventListener:(name,callback)=>rootListeners.set(name,callback),removeEventListener:(name)=>rootListeners.delete(name)},receiveAiCorrection:async(text,token,revision)=>changes.push([text,token,revision]),consent:true,formKey:'synthetic-form',context:'sales',receiveTranscript:async(text,token)=>changes.push([text,token])};
 const recorder=create(wire,{url:'http://127.0.0.1/sales-assistant/audio',maxSeconds:60,method,correction,changedError:'form changed',locale:'ar',limitError:'limit',permissionError:'permission denied',deliveryError:'provider unavailable'});recorder.init();
 return {recorder,wire,contexts,nodes,changes,rootListeners};
}

test('denied microphone permission closes capture and leaves manual input untouched',async()=>{
 const f=await fixture(async()=>{throw new Error('denied');});await f.recorder.start();
 assert.equal(f.recorder.error,'permission denied');assert.equal(f.recorder.recording,false);assert.equal(f.recorder.busy,false);assert.equal(f.contexts[0].closed,true);assert.deepEqual(f.changes,[]);f.recorder.destroy();
});

test('discard and restart cannot resurrect an earlier pending microphone request',async()=>{
 const first=deferred(),second=deferred();let calls=0;
 const f=await fixture(()=>++calls===1?first.promise:second.promise);
 const old=f.recorder.start();f.recorder.clear();const current=f.recorder.start();
 const oldStream=stream();first.resolve(oldStream);await old;
 assert.equal(oldStream.track.stopped,true);assert.equal(f.recorder.busy,true);assert.equal(f.nodes.length,0);
 const newStream=stream();second.resolve(newStream);await current;
 assert.equal(f.recorder.recording,true);assert.equal(newStream.track.stopped,false);assert.equal(f.nodes.length,1);
 f.recorder.destroy();assert.equal(newStream.track.stopped,true);assert.equal(f.contexts.every(c=>c.closed),true);
});

test('intentional Stop uploads captured samples once and automatically continues into unsaved workflow',async()=>{
 const media=stream();let sent;
 const f=await fixture(async()=>media,async(url,request)=>{sent=request;return {ok:true,json:async()=>({text:'خمس كراتين ILS',audio_token:'synthetic-token'})};});
 await f.recorder.start();f.nodes[0].port.onmessage({data:{samples:new Float32Array(48000)}});await f.recorder.stop(true);
 assert.equal(media.track.stopped,true);assert.equal(f.recorder.ready,false);
 assert.equal(sent.credentials,'same-origin');assert.equal(sent.headers['X-CSRF-TOKEN'],'synthetic-csrf');
 const audio=sent.body.get('audio');const header=new DataView(await audio.arrayBuffer());assert.equal(header.getUint32(40,true),96000);
 assert.deepEqual(f.changes,[['خمس كراتين ILS','synthetic-token']]);assert.equal(f.recorder.ready,false);f.recorder.destroy();
});

test('discard aborts an in-flight upload and ignores its late transcript',async()=>{
 const response=deferred(),media=stream();let sent;
 const f=await fixture(async()=>media,async(url,request)=>{sent=request;return response.promise;});
 await f.recorder.start();f.nodes[0].port.onmessage({data:{samples:new Float32Array(48)}});const pending=f.recorder.stop(true);await new Promise(resolve=>setImmediate(resolve));f.recorder.clear();assert.equal(sent.signal.aborted,true);
 response.resolve({ok:true,json:async()=>({text:'Must be discarded'})});await pending;
 assert.deepEqual(f.changes,[]);assert.equal(f.recorder.ready,false);assert.equal(f.recorder.busy,false);f.recorder.destroy();
});


test('local-only refuses an absent installed language pack without microphone capture or cloud upload',async()=>{
 let captures=0,uploads=0;
 class LocalSpeech { static available=async()=> 'downloadable'; }
 LocalSpeech.prototype.processLocally=false;
 const f=await fixture(async()=>{captures++;return stream();},async()=>{uploads++;throw new Error('must not upload');},LocalSpeech,'local_only');
 await f.recorder.start();
 assert.equal(captures,0);assert.equal(uploads,0);assert.equal(f.recorder.recording,false);assert.equal(f.recorder.error,'permission denied');f.recorder.destroy();
});

test('installed local recognition is explicitly on-device and only intentional Stop submits its transcript',async()=>{
 let instance,uploads=0,captures=0,availability;
 class LocalSpeech {
  static available=async(request)=>{availability=request;return 'available';};
  constructor(){instance=this;}
  start(){} stop(){this.onend?.();} abort(){}
 }
 LocalSpeech.prototype.processLocally=false;
 const f=await fixture(async()=>{captures++;return stream();},async()=>{uploads++;throw new Error('must not upload');},LocalSpeech,'local_only');
 await f.recorder.start();assert.equal(instance.processLocally,true);assert.equal(instance.lang,'ar-PS');assert.equal(availability.processLocally,true);
 instance.onresult({resultIndex:0,results:[Object.assign([{transcript:'بيع خمس كراتين'}],{isFinal:true})]});
 await f.recorder.stop(true);await new Promise(resolve=>setImmediate(resolve));
 assert.deepEqual(f.changes,[['بيع خمس كراتين',null]]);assert.equal(uploads,0);assert.equal(captures,0);f.recorder.destroy();
});

test('an unexpected local recognition end never submits a transcript',async()=>{
 let instance;
 class LocalSpeech {static available=async()=> 'available';constructor(){instance=this;}start(){}abort(){}}
 LocalSpeech.prototype.processLocally=false;
 const f=await fixture(async()=>stream(),async()=>{throw new Error('must not upload');},LocalSpeech,'local_only');
 await f.recorder.start();instance.onresult({resultIndex:0,results:[Object.assign([{transcript:'Partial'}],{isFinal:true})]});await instance.onend();
 assert.deepEqual(f.changes,[]);assert.equal(f.recorder.recording,false);f.recorder.destroy();
});

test('correction delivery carries the revision captured at microphone start', async()=>{
 const f=await fixture(async()=>stream(),async()=>({ok:true,json:async()=>({text:'change quantity to 7',audio_token:'synthetic-token'})}),null,'cloud',true);
 await f.recorder.start(); f.wire.aiFormRevision=4;
 f.nodes[0].port.onmessage({data:{samples:new Float32Array(48)}});
 await f.recorder.stop(true);
 assert.deepEqual(f.changes,[['change quantity to 7','synthetic-token',3]]);
 f.recorder.destroy(); assert.equal(f.rootListeners.size,0);
});

test('follow-up recording never serializes an absent Livewire property as a provider connection', async()=>{
 let sent;
 const f=await fixture(async()=>stream(),async(url,request)=>{
  sent=request;return {ok:true,json:async()=>({text:'change quantity to 7',audio_token:'synthetic-token'})};
 },null,'cloud',true);
 // Livewire resolves absent properties as action callables; a plain-object fixture hid this bug.
 f.wire.speechConnection=()=>{};
 await f.recorder.start();f.nodes[0].port.onmessage({data:{samples:new Float32Array(48000)}});
 await f.recorder.stop(true);
 assert.equal(sent.body.has('connection_id'),false);
 assert.deepEqual(f.changes,[['change quantity to 7','synthetic-token',3]]);
 f.recorder.destroy();
});

test('initial recording retains the explicitly selected allowed speech connection', async()=>{
 let sent;
 const f=await fixture(async()=>stream(),async(url,request)=>{
  sent=request;return {ok:true,json:async()=>({text:'Editable transcript',audio_token:'synthetic-token'})};
 });
 f.wire.speechConnection='a9ab7575-2b9d-4c8f-9a5e-ff5e40af0810';
 await f.recorder.start();f.nodes[0].port.onmessage({data:{samples:new Float32Array(48000)}});
 await f.recorder.stop(true);
 assert.equal(sent.body.get('connection_id'),f.wire.speechConnection);
 f.recorder.destroy();
});

test('manual financial input cancels pending speech and preserves the human edit', async()=>{
 const response=deferred(); let sent;
 const f=await fixture(async()=>stream(),async(url,request)=>{sent=request;return response.promise;},null,'cloud',true);
 await f.recorder.start(); f.nodes[0].port.onmessage({data:{samples:new Float32Array(48)}});
 const pending=f.recorder.stop(true); await new Promise(resolve=>setImmediate(resolve));
 const human={value:'83.125',getAttributeNames:()=>['wire:model.blur'],getAttribute:()=> 'lines.0.unit_price'};
 f.rootListeners.get('input')({target:human});
 assert.equal(sent.signal.aborted,true); assert.equal(f.recorder.error,'form changed');
 response.resolve({ok:true,json:async()=>({text:'change quantity to 7',audio_token:'synthetic-token'})});
 await pending;
 assert.deepEqual(f.changes,[]); assert.equal(human.value,'83.125'); f.recorder.destroy();
});

test('a newly selected local-only or text-only method cannot upload through stale cloud options', async()=>{
 for(const selected of ['local_only','disabled']){
  let captures=0,uploads=0;
  const f=await fixture(async()=>{captures++;return stream();},async()=>{uploads++;throw new Error('must not upload');});
  f.wire.speechMethod=selected;
  await f.recorder.start();
  assert.equal(captures,0);assert.equal(uploads,0);assert.equal(f.recorder.error,'permission denied');
  f.recorder.destroy();
 }
});

test('manual edit during typed inference discards the prepared patch and never applies late fields', async()=>{
 const inference=deferred(), discarded=[], applied=[];
 const f=await fixture(async()=>stream(),undefined,null,'cloud',true);
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
 const f=await fixture(async()=>stream(),undefined,null,'cloud',true);
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
 const f=await fixture(async()=>stream(),undefined,null,'cloud',true),control={disabled:false};
 f.wire.$el.querySelectorAll=()=>[control];
 f.wire.correctAiInput=async()=> 'prepared-patch';
 f.wire.applyPreparedAiInput=async()=>{throw new Error('stale');};
 await f.recorder.applyTyped();
 assert.equal(control.disabled,false);assert.equal(f.recorder.busy,false);
 assert.equal(f.recorder.error,'provider unavailable');f.recorder.destroy();
});

test('manual edit after speech transcription but during interpretation refuses the late correction', async()=>{
 const inference=deferred(),discarded=[],applied=[];
 const f=await fixture(async()=>stream(),undefined,null,'cloud',true);
 f.wire.receiveAiCorrection=()=>inference.promise;
 f.wire.discardPreparedAiInput=async token=>discarded.push(token);
 f.wire.applyPreparedAiInput=async token=>applied.push(token);
 await f.recorder.start();f.nodes[0].port.onmessage({data:{samples:new Float32Array(48)}});
 const pending=f.recorder.stop(true);await new Promise(resolve=>setImmediate(resolve));
 const human={value:'83.125',getAttributeNames:()=>['wire:model.blur'],getAttribute:()=> 'lines.0.unit_price'};
 f.rootListeners.get('input')({target:human});inference.resolve('prepared-speech-patch');await pending;
 assert.deepEqual(discarded,['prepared-speech-patch']);assert.deepEqual(applied,[]);
 assert.equal(human.value,'83.125');assert.equal(f.recorder.error,'form changed');f.recorder.destroy();
});

test('a changed select invalidates pending correction just like typed financial input', async()=>{
 const inference=deferred(),discarded=[];
 const f=await fixture(async()=>stream(),undefined,null,'cloud',true);
 f.wire.correctAiInput=()=>inference.promise;f.wire.discardPreparedAiInput=async token=>discarded.push(token);
 f.wire.applyPreparedAiInput=async()=>assert.fail('changed unit must refuse late patch');
 const pending=f.recorder.applyTyped();
 f.rootListeners.get('change')({target:{getAttributeNames:()=>['wire:model'],getAttribute:()=> 'lines.0.product_unit_id'}});
 inference.resolve('prepared-patch');await pending;
 assert.deepEqual(discarded,['prepared-patch']);assert.equal(f.recorder.error,'form changed');
 f.recorder.destroy();assert.equal(f.rootListeners.size,0);
});

test('explicit original-transcript submission uses its protected replacement acknowledgement without applying a patch',async()=>{
 const f=await fixture(async()=>stream(),undefined,null,'disabled',true),calls=[];
 f.wire.prepareAiTranscript=async()=>{calls.push('prepare transcript');return 'replacement';};
 f.wire.applyPreparedAiTranscript=async token=>calls.push(['acknowledge replacement',token]);
 f.wire.correctAiInput=async()=>assert.fail('original description is not a patch instruction');
 assert.deepEqual(calls,[]);await f.recorder.applyTyped(true);
 assert.deepEqual(calls,['prepare transcript',['acknowledge replacement','replacement']]);f.recorder.destroy();
});

test('human edit while reprocessing the original transcript cancels navigation and discards only the replacement',async()=>{
 const pending=deferred(),calls=[];
 const f=await fixture(async()=>stream(),undefined,null,'disabled',true);
 f.wire.prepareAiTranscript=()=>pending.promise;
 f.wire.discardPreparedAiTranscript=async token=>calls.push(['discard replacement',token]);
 f.wire.applyPreparedAiTranscript=async()=>assert.fail('late replacement must not navigate');
 const processing=f.recorder.applyTyped(true);
 f.rootListeners.get('input')({target:{getAttributeNames:()=>['wire:model'],getAttribute:()=> 'notes'}});
 pending.resolve('replacement');await processing;
 assert.deepEqual(calls,[['discard replacement','replacement']]);assert.equal(f.recorder.error,'form changed');f.recorder.destroy();
});

test('transcript candidate clarification still waits for a fresh browser acknowledgement',async()=>{
 const f=await fixture(async()=>stream(),undefined,null,'disabled',true),calls=[];
 f.wire.prepareAiTranscriptClarification=async()=>{calls.push('clarify locally');return 'replacement';};
 f.wire.applyPreparedAiTranscript=async token=>calls.push(['acknowledge replacement',token]);
 f.wire.prepareAiTranscript=async()=>assert.fail('clarification must not run a second inference');
 await f.recorder.applyTyped(true,true);
 assert.deepEqual(calls,['clarify locally',['acknowledge replacement','replacement']]);f.recorder.destroy();
});
