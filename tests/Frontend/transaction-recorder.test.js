import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';
import { encodePcmWave } from '../../resources/js/ai-wave.js';

const deferred = () => { let resolve, reject; const promise = new Promise((a,b) => { resolve=a; reject=b; }); return {promise,resolve,reject}; };
const stream = () => { const track={stopped:false,stop(){this.stopped=true;}}; return {track,getTracks:()=>[track]}; };
async function fixture(getUserMedia, fetcher = async()=>({ok:true,json:async()=>({text:'Editable transcript',audio_token:'synthetic-token'})}), localSpeech = null, method = 'cloud') {
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
 const window={SpeechRecognition:localSpeech,isSecureContext:true,AudioContext:Context,AudioWorkletNode:Worklet,Alpine:{data:(name,factory)=>{create=factory;}},addEventListener(){},removeEventListener(){}};
 const document={addEventListener(){},removeEventListener(){},querySelector:()=>({content:'synthetic-csrf'})};
 const code=(await fs.readFile(new URL('../../resources/js/transaction-recorder.js',import.meta.url),'utf8')).replace(/^import .*?;s*/, '').replaceAll('import.meta.url',JSON.stringify(import.meta.url));
 vm.runInNewContext(code,{window,document,navigator:{mediaDevices:{getUserMedia}},AudioContext:Context,AudioWorkletNode:Worklet,encodePcmWave,URL,Blob,FormData,AbortController,crypto:globalThis.crypto,fetch:fetcher,setTimeout,clearTimeout});
 const wire={consent:true,formKey:'synthetic-form',context:'sales',receiveTranscript:async(text,token)=>changes.push([text,token])};
 const recorder=create(wire,{url:'http://127.0.0.1/sales-assistant/audio',maxSeconds:60,method,locale:'ar',limitError:'limit',permissionError:'permission denied',deliveryError:'provider unavailable'});recorder.init();
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
