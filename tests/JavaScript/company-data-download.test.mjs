import test from 'node:test';
import assert from 'node:assert/strict';
import {companyDataDownload} from '../../resources/js/company-data-download.js';

const make = () => companyDataDownload({preparing:'Preparing',failed:'Failed safely',started:'Download started',confirmRequired:'Confirm password'});
global.FormData=class {};
const form={action:'/settings/company-data/download'};
const response=(body,expected=body.length,status=200)=>new Response(body,{status,headers:{'content-type':'application/zip','content-length':String(expected)}});

test('complete ZIP starts download once; rapid double submit is ignored', async()=>{
    let finish, calls=0, downloads=0;
    global.fetch=()=>{calls++; return new Promise(resolve=>{finish=resolve;});};
    global.document={body:{appendChild(){}},createElement:()=>({click(){downloads++;},remove(){}})};
    const target=make();
    const work=target.download(form);
    await target.download(form);
    assert.equal(calls,1);
    finish(response('PK-synthetic'));
    await work;
    assert.equal(downloads,1);
    assert.equal(target.preparing,false);
    assert.equal(target.message,'Download started');
});

test('revocation/truncated transfer does not create a misleading completed download', async()=>{
    let downloads=0;
    global.document={body:{appendChild(){}},createElement:()=>({click(){downloads++;},remove(){}})};
    global.fetch=async()=>response('partial',1000);
    const target=make();
    await target.download(form);
    assert.equal(downloads,0);
    assert.equal(target.error,'Failed safely');
    assert.equal(target.preparing,false);
});

test('expired confirmation exposes only reauthentication feedback', async()=>{
    global.fetch=async()=>new Response('{}',{status:423,headers:{'content-type':'application/json'}});
    const target=make();
    await target.download(form);
    assert.equal(target.needsConfirmation,true);
    assert.equal(target.error,'Confirm password');
    assert.equal(target.message,'');
});

test('server preparation failure and oversized archive fail before downloading', async()=>{
    const target=make();
    global.fetch=async()=>new Response('{}',{status:422,headers:{'content-type':'application/json'}});
    await target.download(form);
    assert.equal(target.error,'Failed safely');
    global.fetch=async()=>response('PK',52_428_801);
    await target.download(form);
    assert.equal(target.error,'Failed safely');
});

test('teardown prevents stale response from starting download', async()=>{
    let finish,downloads=0;
    global.fetch=()=>new Promise(resolve=>{finish=resolve;});
    global.document={body:{appendChild(){}},createElement:()=>({click(){downloads++;},remove(){}})};
    const target=make();
    const work=target.download(form);
    target.destroy();
    finish(response('PK-synthetic'));
    await work;
    assert.equal(downloads,0);
});
