import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import {reportPartyCombobox} from '../../resources/js/report-party-combobox.js';
const event = key => ({key, preventDefault() { this.prevented = true; }});
function setup(lookup = async () => [{id:'7', label:'عميل (C7)'}]) {
    const commits = [];
    return {state: reportPartyCombobox({lookup, commit: id => commits.push(id), emptyLabel:'All'}), commits};
}
test('arrows and Enter commit only an authorized option; typing and Escape preserve identity', async () => {
    const {state:s, commits} = setup();
    s.sync('9', [{id:'9',label:'Selected'}]);
    s.input('new'); s.key(event('Escape'));
    assert.equal(s.selected,'9'); assert.equal(s.term,'Selected'); assert.deepEqual(commits,[]);
    await s.search(); s.key(event('ArrowDown')); s.key(event('Enter'));
    assert.deepEqual(commits,['7']); assert.equal(s.term,'عميل (C7)'); assert.equal(s.open,false);
});
test('stale responses cannot replace results or restore dismissed popup', async () => {
    const pending = []; const {state:s} = setup(() => new Promise(resolve => pending.push(resolve)));
    const a = s.search(); s.term='other'; const b=s.search();
    pending[1]([{id:'2',label:'New'}]); await b;
    pending[0]([{id:'1',label:'Old'}]); await a;
    assert.equal(s.options[1].label,'New');
    const c=s.search(); s.dismiss(); pending[2]([{id:'3',label:'Late'}]); await c;
    assert.equal(s.open,false); assert.equal(s.busy,false);
});
test('Tab restores selected label; clear is explicit; reset and authorization loss erase old identity', async () => {
    const {state:s, commits} = setup(); s.sync('7',[{id:'7',label:'Selected'}]);
    s.input('query'); s.key(event('Tab')); assert.equal(s.term,'Selected'); assert.deepEqual(commits,[]);
    await s.search(); s.choose(0); assert.deepEqual(commits,['']); assert.equal(s.term,'');
    s.sync('7',[{id:'7',label:'Selected'}]); s.sync('7',[]); assert.equal(s.term,'');
    s.sync('',[]); assert.equal(s.selected,'');
});
test('failed lookup never commits; Enter while loading cannot submit', async () => {
    const {state:s, commits} = setup(async () => {throw Error('Denied');});
    const request=s.search(); const enter=event('Enter'); s.key(enter); await request;
    assert.equal(enter.prevented,true); assert.equal(s.failed,true); assert.deepEqual(commits,[]);
});
test('single field has isolated bidirectional labels and complete combobox relationships', () => {
    const blade=readFileSync(new URL('../../resources/views/components/report-party-selector.blade.php',import.meta.url),'utf8');
    assert.equal((blade.match(/<input /g)||[]).length,1); assert.ok(!blade.includes('<select'));
    for (const token of ['role="combobox"','role="listbox"','role="option"','aria-activedescendant','aria-expanded','aria-controls','x-id=', '<bdi dir="auto"','min-h-[44px]']) assert.ok(blade.includes(token),token);
});

test('initial hydration and watched reset do not reactively overwrite a new selection', async () => {
    let notify; let disposed = false; const commits=[];
    const s=reportPartyCombobox({initialSelected:'9',initialValues:[{id:'9',label:'Existing'}],emptyLabel:'All',
        lookup:async () => [{id:'7',label:'New'}], commit:id=>commits.push(id),
        watch:listener=>{notify=listener;return ()=>{disposed=true;};}});
    s.init(); await s.search(); s.choose(1);
    assert.equal(s.selected,'7'); assert.equal(s.term,'New'); assert.deepEqual(commits,['7']);
    notify(''); assert.equal(s.selected,''); assert.equal(s.term,'');
    s.destroy(); assert.equal(disposed,true);
});
test('selected formatted label opens unfiltered lookup and a sole match accepts Enter', async () => {
    const terms=[]; const {state:s,commits}=setup(async term=>{terms.push(term);return [{id:'7',label:'New (C7)'}];});
    s.sync('9',[{id:'9',label:'Existing (C9)'}]); await s.search();
    assert.deepEqual(terms,['']); s.key(event('Enter')); assert.deepEqual(commits,['7']);
});
test('keyboard scroll targets rendered options rather than the x-for template', async () => {
    const {state:s}=setup(); await s.search(); let scrolled=false;
    s.$refs={list:{querySelectorAll:selector=>{assert.equal(selector,'[role="option"]');return [{}, {scrollIntoView:()=>{scrolled=true;}}];}}};
    s.$nextTick=callback=>callback(); s.key(event('ArrowDown')); assert.equal(s.active,1); assert.equal(scrolled,true);
});
test('Blade uses explicit server watcher and keyed hydration instead of a literal reactive sync effect', () => {
    const blade=readFileSync(new URL('../../resources/views/components/report-party-selector.blade.php',import.meta.url),'utf8');
    assert.ok(!blade.includes('x-effect=')); assert.ok(blade.includes('$wire.$watch')); assert.ok(blade.includes('hash('));
});
