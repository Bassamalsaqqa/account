import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import { encodePcmWave } from '../../resources/js/ai-wave.js';

const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
const stream = () => { const track={stopped:false,stop(){this.stopped=true;}}; return {track,getTracks:()=>[track]}; };
async function fixture(getUserMedia, fetcher = async()=>({ok:true,json:async()=>({text:'Editable transcript'})})) {
 let create; const contexts=[], nodes=[], changes=[];
 class Context {
  constructor(){this.sampleRate=48000;this.destination={};this.closed=false;this.audioWorklet={addModule:async()=>{}};contexts.push(this);}
  resume(){return Promise.resolve();} close(){this.closed=true;return Promise.resolve();}
  createMediaStreamSource(){return {connect(){},disconnect(){}};}
 }
 class Worklet {
  constructor(){this.port={onmessage:null,postMessage:()=>this.port.onmessage({data:{flushed:true}})};nodes.push(this);}
  connect(){} disconnect(){}
 }
 const window={isSecureContext:true,AudioContext:Context,AudioWorkletNode:Worklet,Alpine:{data:(name,factory)=>{create=factory;}},addEventListener(){},removeEventListener(){}};
 const document={addEventListener(){},removeEventListener(){},querySelector:()=>({content:'synthetic-csrf'})};
 const code=(await fs.readFile(new URL('../../resources/js/ai-sale-recorder.js',import.meta.url),'utf8')).replace(/^import .*?;s*/, '').replaceAll('import.meta.url',JSON.stringify(import.meta.url));
 vm.runInNewContext(code,{window,document,navigator:{mediaDevices:{getUserMedia}},AudioContext:Context,AudioWorkletNode:Worklet,encodePcmWave,URL,Blob,FormData,AbortController,crypto:globalThis.crypto,fetch:fetcher,setTimeout,clearTimeout});
 const wire={aiConsent:true,$set:async(name,value)=>changes.push([name,value])};
 const recorder=create(wire,{url:'http://127.0.0.1/sales-assistant/audio',maxSeconds:60,permissionError:'permission denied',deliveryError:'provider unavailable'});recorder.init();
 return {recorder,wire,contexts,nodes,changes};
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

test('captured samples stop before explicit transcription and only editable text is returned',async()=>{
 const media=stream();let sent;
 const f=await fixture(async()=>media,async(url,request)=>{sent=request;return {ok:true,json:async()=>({text:'خمس كراتين ILS'})};});
 await f.recorder.start();f.nodes[0].port.onmessage({data:{samples:new Float32Array(48000)}});await f.recorder.stop();
 assert.equal(media.track.stopped,true);assert.equal(f.recorder.ready,true);assert.equal(sent,undefined);
 await f.recorder.transcribe();assert.equal(sent.credentials,'same-origin');assert.equal(sent.headers['X-CSRF-TOKEN'],'synthetic-csrf');
 const audio=sent.body.get('audio');const header=new DataView(await audio.arrayBuffer());assert.equal(header.getUint32(40,true),96000);
 assert.deepEqual(f.changes,[['aiText','خمس كراتين ILS']]);assert.equal(f.recorder.ready,false);f.recorder.destroy();
});

test('discard aborts an in-flight upload and ignores its late transcript',async()=>{
 const response=deferred(),media=stream();let sent;
 const f=await fixture(async()=>media,async(url,request)=>{sent=request;return response.promise;});
 await f.recorder.start();f.nodes[0].port.onmessage({data:{samples:new Float32Array(48)}});await f.recorder.stop();
 const pending=f.recorder.transcribe();f.recorder.clear();assert.equal(sent.signal.aborted,true);
 response.resolve({ok:true,json:async()=>({text:'Must be discarded'})});await pending;
 assert.deepEqual(f.changes,[]);assert.equal(f.recorder.ready,false);assert.equal(f.recorder.busy,false);f.recorder.destroy();
});
