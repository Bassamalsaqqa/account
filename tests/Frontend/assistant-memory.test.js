import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import vm from 'node:vm';

const token='11111111-1111-4111-8111-111111111111';
async function fixture(url, prior=null) {
    const stored=new Map(prior ? [['accounting-assistant-form',JSON.stringify(prior)]] : []);
    const events=new Map(), navigation=new Map(), sent=[];
    const window={location:new URL(url),navigation:{addEventListener:(name,fn)=>navigation.set(name,fn)}};
    const document={querySelector:()=>({content:'synthetic-csrf'}),addEventListener:(name,fn)=>events.set(name,fn)};
    const sessionStorage={getItem:key=>stored.get(key),setItem:(key,value)=>stored.set(key,value),removeItem:key=>stored.delete(key)};
    const navigator={sendBeacon:(url,body)=>{sent.push({url,body});return true;}};
    vm.runInNewContext(await fs.readFile(new URL('../../resources/js/assistant-memory.js',import.meta.url),'utf8'),
        {window,document,sessionStorage,navigator,URL,FormData,fetch:()=>{throw Error('Unexpected fallback');}});
    return {stored,events,navigation,sent,window};
}
test('reload and language submission retain only random token and pathname',async()=>{
    const f=await fixture('http://127.0.0.1/invoices/create?assistant='+token,{token,path:'/invoices/create'});
    f.events.get('submit')({target:{action:'http://127.0.0.1/locale'}});
    const formData=new FormData(); formData.append('locale','ar');
    f.navigation.get('navigate')({destination:{url:'http://127.0.0.1/locale'},formData});
    f.navigation.get('navigate')({destination:{url:f.window.location.href}});
    assert.equal(f.sent.length,0);
    assert.deepEqual(JSON.parse(f.stored.get('accounting-assistant-form')),{token,path:'/invoices/create'});
});
test('locale-looking ordinary or foreign navigation still discards assistant memory',async()=>{
    for (const url of ['http://127.0.0.1/locale','https://example.test/locale']) {
        const f=await fixture('http://127.0.0.1/purchases/create?assistant='+token);
        const formData=new FormData(); formData.append('locale','en');
        f.navigation.get('navigate')({destination:{url},formData:url.startsWith('https')?formData:null});
        assert.equal(f.sent.length,1); assert.equal(f.stored.size,0);
    }
});
test('leaving through ordinary navigation sends only the owned token and CSRF to the local cleanup endpoint',async()=>{
    const f=await fixture('http://127.0.0.1/invoices/create?assistant='+token);
    f.navigation.get('navigate')({destination:{url:'http://127.0.0.1/dashboard'}});
    assert.equal(f.stored.size,0);assert.equal(f.sent.length,1);
    assert.equal(f.sent[0].url,'/assistant/memory/discard');
    assert.deepEqual([...f.sent[0].body.entries()],[['token',token],['_token','synthetic-csrf']]);
});
test('fallback page initialization expires a prior form before remembering a new form',async()=>{
    const next='22222222-2222-4222-8222-222222222222';
    const f=await fixture('http://127.0.0.1/purchases/create?assistant='+next,{token,path:'/invoices/create'});
    assert.equal(f.sent.length,1);
    assert.deepEqual(JSON.parse(f.stored.get('accounting-assistant-form')),{token:next,path:'/purchases/create'});
});
test('company switch and logout discard memory but opening another tab does not',async()=>{
    for (const action of ['company/switch','logout']) {
        const f=await fixture('http://127.0.0.1/invoices/create?assistant='+token);
        f.events.get('click')({button:0,target:{closest:()=>({target:'_blank',href:'http://127.0.0.1/dashboard',hasAttribute:()=>false})}});
        assert.equal(f.sent.length,0);
        f.events.get('submit')({target:{action:'http://127.0.0.1/'+action}});
        assert.equal(f.sent.length,1);assert.equal(f.stored.size,0);
    }
});
