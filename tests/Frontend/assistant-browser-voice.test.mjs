import test from 'node:test';
import assert from 'node:assert/strict';
import { browserVoice } from '../../resources/js/assistant-browser-voice.js';

function fixture({ secure = true, supported = true, autoSubmit = false, quietMs = 1000, graceMs = 1250 } = {}) {
    const instances = [], calls = [], intervals = new Map(), timeouts = new Map();
    let nextTimer = 0, currentTime = 0;
    class Speech {
        constructor() { instances.push(this); }
        start() { calls.push('recognition.start'); }
        stop() { calls.push('recognition.stop'); this.onend?.(); }
        abort() { calls.push('recognition.abort'); }
    }
    const wire = { text: '', $set(field, text, live) { this[field] = text; calls.push(['set', field, live]); }, async prepare() { calls.push('prepare'); } };
    const documentListeners = new Map();
    const windowListeners = new Map();
    const doc = {
        visibilityState: 'visible',
        addEventListener(type, fn) {
            if (!documentListeners.has(type)) documentListeners.set(type, new Set());
            documentListeners.get(type).add(fn);
        },
        removeEventListener(type, fn) {
            documentListeners.get(type)?.delete(fn);
        },
        trigger(type) {
            documentListeners.get(type)?.forEach(fn => fn());
        }
    };
    const runtime = {
        isSecureContext: secure,
        document: doc,
        ...(supported ? { SpeechRecognition: Speech } : {}),
        setInterval(fn, ms) { const id = ++nextTimer; intervals.set(id, fn); return id; },
        clearInterval(id) { intervals.delete(id); },
        setTimeout(fn, ms) {
            const id = ++nextTimer;
            timeouts.set(id, { fn, triggerAt: currentTime + ms });
            return id;
        },
        clearTimeout(id) { timeouts.delete(id); },
        addEventListener(type, fn) {
            if (!windowListeners.has(type)) windowListeners.set(type, new Set());
            windowListeners.get(type).add(fn);
        },
        removeEventListener(type, fn) {
            windowListeners.get(type)?.delete(fn);
        },
        trigger(type) {
            windowListeners.get(type)?.forEach(fn => fn());
        }
    };
    const advanceTimers = async (ms) => {
        const targetTime = currentTime + ms;
        while (true) {
            let earliestId = null, earliestTime = Infinity;
            for (const [id, item] of timeouts.entries()) {
                if (item.triggerAt <= targetTime && item.triggerAt < earliestTime) {
                    earliestTime = item.triggerAt;
                    earliestId = id;
                }
            }
            if (earliestId === null) break;
            const item = timeouts.get(earliestId);
            timeouts.delete(earliestId);
            currentTime = item.triggerAt;
            item.fn();
            await new Promise(resolve => setImmediate(resolve));
        }
        currentTime = targetTime;
        await new Promise(resolve => setImmediate(resolve));
    };
    const ui = browserVoice(wire, { locale: 'ar', maxSeconds: 60, autoSubmit, quietMs, graceMs }, runtime);
    return { ui, wire, calls, instances, intervals, timeouts, advanceTimers, doc, runtime };
}

test('opening does not record, obtain consent or dispatch', () => {
    const { ui, calls } = fixture(); ui.init();
    assert.equal('remoteVoiceConsent' in ui, false); assert.deepEqual(calls, []);
});
test('unsupported and insecure environments preserve text entry', async () => {
    for (const options of [{ secure: false }, { supported: false }]) {
        const { ui, wire, calls } = fixture(options); wire.text = 'رسالة'; await ui.start();
        assert.equal(ui.error, 'unsupported'); assert.equal(wire.text, 'رسالة'); assert.deepEqual(calls, []);
    }
});
test('microphone starts through browser permission with no application gate', async () => {
    const { ui, calls, instances } = fixture(); await ui.start();
    assert.deepEqual(calls, ['recognition.start']);
    assert.equal(instances[0].lang, 'ar-PS');
    assert.equal('voiceDisclosure' in ui, false);
    assert.equal('remoteVoiceConsent' in ui, false);
});

test('two sequential recordings preserve editable transcript and never auto-send', async () => {
    const { ui, wire, calls, instances } = fixture(); wire.text = 'original';
    for (let i = 0; i < 2; i++) {
        await ui.start(); const speech = instances[i]; speech.onstart();
        speech.onresult({ results: [{ isFinal: true, 0: { transcript: `words${i}` } }] });
        ui.stop(); assert.equal(ui.transcriptReady, true);
        assert.equal(speech.onresult, null);
    }
    assert.equal(wire.text, 'original words0 words1');
    wire.text = 'manually edited'; await ui.send();
    assert.equal(calls.filter(x => x === 'recognition.start').length, 2);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('pending permission blocks duplicate start; results never dispatch automatically', async () => {
    const { ui, wire, calls, instances } = fixture();
    await ui.start(); await ui.start(); assert.equal(instances.length, 1);
    instances[0].onstart();
    instances[0].onresult({ results: [Object.assign([{ transcript: 'بيع قطعتين' }], { isFinal: true })] });
    assert.equal(wire.text, 'بيع قطعتين'); assert.equal(calls.includes('prepare'), false);
    ui.stop(); assert.equal(ui.transcriptReady, true);
    wire.text = 'بيع ثلاث قطع'; await ui.send(); assert.equal(calls.filter(call => call === 'prepare').length, 1);
});
test('cancel discards recognized text and blocks delayed result callbacks', async () => {
    const { ui, wire, instances, calls } = fixture(); wire.text = 'original'; await ui.start();
    instances[0].onstart(); const delayed = instances[0].onresult;
    instances[0].onresult({ results: [Object.assign([{ transcript: 'secret' }], { isFinal: true })] });
    ui.cancelVoice(); delayed({ results: [Object.assign([{ transcript: 'stale' }], { isFinal: true })] });
    assert.equal(wire.text, 'original'); assert.equal(calls.includes('prepare'), false); assert.equal(ui.recording, false);
});
test('permission refusal is sanitized and never retries', async () => {
    const { ui, instances, calls } = fixture(); await ui.start();
    const delayed = instances[0].onresult;
    instances[0].onerror({ error: 'not-allowed', message: 'private details' });
    delayed({ results: [{ isFinal: true, 0: { transcript: 'stale after failure' } }] });
    assert.equal(ui.error, 'permission'); assert.equal(ui.busy, false);
    assert.equal(ui.transcriptReady, false);
    assert.equal(instances[0].onresult, null);
    assert.equal(calls.filter(call => call === 'recognition.start').length, 1);
});
test('listening duration and transcript size remain bounded', async () => {
    const { ui, wire, instances, intervals } = fixture(); await ui.start(); instances[0].onstart();
    instances[0].onresult({ results: [Object.assign([{ transcript: 'a'.repeat(4500) }], { isFinal: true })] });
    assert.equal(wire.text.length, 4000);
    const tick = [...intervals.values()][0]; for (let i = 0; i < 60; i++) tick();
    assert.equal(ui.recording, false); assert.equal(intervals.size, 0);
});
test('concurrent Send clicks dispatch only once and explicit retry remains possible', async () => {
    const { ui, wire, calls } = fixture(); wire.text = 'sales summary';
    let finish; wire.prepare = () => { calls.push('prepare'); return new Promise(resolve => { finish = resolve; }); };
    const first = ui.send(); await ui.send(); assert.equal(calls.length, 1); finish(); await first;
    assert.equal(ui.sending, false);
});

test('Stop waits for final transcript and end before allowing Send or another recording', async () => {
    const { ui, wire, calls, instances } = fixture();

    await ui.start();
    const speech = instances[0];
    speech.onstart();
    speech.stop = () => calls.push('stop-pending');
    ui.stop();
    assert.equal(ui.busy, true);
    await ui.send();
    await ui.start();
    assert.equal(instances.length, 1);
    assert.equal(calls.includes('prepare'), false);
    speech.onresult({ results: [{ isFinal: true, 0: { transcript: 'final words' } }] });
    const stale = speech.onresult;
    speech.onend();
    assert.equal(ui.busy, false);
    assert.equal(wire.text, 'final words');
    assert.equal(speech.onresult, null);
    await ui.send();
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
    ui.cancelVoice();
    stale({ results: [{ isFinal: true, 0: { transcript: 'late words' } }] });
    assert.equal(wire.text, 'final words');
});

test('denial, cancellation and recognition errors permit only explicit fresh starts', async () => {
    const { ui, wire, calls, instances, intervals } = fixture(); wire.text = 'keep';
    await ui.start(); instances[0].onerror({ error: 'not-allowed' });
    assert.equal(ui.error, 'permission'); assert.equal(wire.text, 'keep');
    await ui.start(); instances[1].onstart(); ui.cancelVoice();
    assert.equal(ui.recording, false); assert.equal(wire.text, 'keep');
    await ui.start(); instances[2].onstart(); instances[2].onerror({ error: 'network' });
    assert.equal(ui.error, 'unavailable'); assert.equal(intervals.size, 0);
    await ui.start(); instances[3].onstart(); instances[3].onend();
    assert.equal(calls.filter(x => x === 'recognition.start').length, 4);
    assert.equal(calls.includes('prepare'), false);
});

test('navigation aborts pending recognition and ignores delayed results', async () => {
    const { ui, wire, instances, calls } = fixture(); wire.text = 'keep';
    ui.init(); await ui.start(); const delayed = instances[0].onresult;
    ui.leave(); delayed({ results: [{ isFinal: true, 0: { transcript: 'stale' } }] });
    assert.equal(wire.text, 'keep'); assert.equal(ui.busy, false);
    assert.equal(calls.includes('prepare'), false);
});

test('flag off manual path: stable final transcript never auto-submits without explicit Send', async () => {
    const { ui, wire, calls, instances, advanceTimers } = fixture({ autoSubmit: false });
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'فاتورة يدوي' } }] });
    await advanceTimers(5000);
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);
    ui.stop();
    assert.equal(ui.transcriptReady, true);
    await ui.send();
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('completion: stable final nonempty transcript auto-submits after quiet heuristic and visible grace', async () => {
    const { ui, wire, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'فاتورة مبيعات' } }] });
    assert.equal(wire.text, 'فاتورة مبيعات');
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);

    // After 999ms of quiet: still quiet phase, not in grace yet
    await advanceTimers(999);
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);

    // At 1000ms: quiet completes, enters visible grace
    await advanceTimers(1);
    assert.equal(ui.grace, true);
    assert.equal(ui.transcriptReady, true);
    assert.equal(calls.includes('prepare'), false);

    // During grace (at 1249ms of grace): still waiting
    await advanceTimers(1249);
    assert.equal(ui.grace, true);
    assert.equal(calls.includes('prepare'), false);

    // Grace expires at 1250ms: autodispatch executes exactly once
    await advanceTimers(1);
    assert.equal(ui.grace, false);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('final/interim: interim callbacks never start quiet heuristic or grace timer', async () => {
    const { ui, calls, instances, timeouts, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: false, 0: { transcript: 'كلام مؤقت غير نهائي' } }] });
    assert.equal(ui.interim, 'كلام مؤقت غير نهائي');
    assert.equal(timeouts.size, 0);
    await advanceTimers(5000);
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);
});

test('new speech: interim and new final speech reset quiet heuristic timer', async () => {
    const { ui, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'الدفعة الأولى' } }] });
    await advanceTimers(800);
    assert.equal(ui.grace, false);

    // New interim speech arrives, resetting quiet timer
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'الدفعة الأولى' } }, { isFinal: false, 0: { transcript: 'والثانية' } }] });
    await advanceTimers(500);
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);

    // Speech settles into new final
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'الدفعة الأولى والثانية' } }] });
    await advanceTimers(999);
    assert.equal(ui.grace, false);
    await advanceTimers(1);
    assert.equal(ui.grace, true);
    await advanceTimers(1250);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('new speech: speech during grace cancels grace and restarts cycle', async () => {
    const { ui, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'مبيعات نقدية' } }] });
    await advanceTimers(1000);
    assert.equal(ui.grace, true);

    // Speaking during grace resets grace
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'مبيعات نقدية' } }, { isFinal: false, 0: { transcript: 'للعميل أحمد' } }] });
    assert.equal(ui.grace, false);
    await advanceTimers(2000);
    assert.equal(calls.includes('prepare'), false);

    // Settles into final
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'مبيعات نقدية للعميل أحمد' } }] });
    await advanceTimers(1000);
    assert.equal(ui.grace, true);
    await advanceTimers(1250);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('stop/cancel/edit: stop button cancels autodispatch and preserves manual review', async () => {
    const { ui, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'إيقاف يدوي' } }] });
    await advanceTimers(500);
    ui.stop();
    await advanceTimers(5000);
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);
    assert.equal(ui.transcriptReady, true);
    await ui.send();
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('stop/cancel/edit: cancelVoice discards transcript, clears timers and prevents server request', async () => {
    const { ui, wire, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    wire.text = 'أصلي';
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'جديد' } }] });
    await advanceTimers(1000);
    assert.equal(ui.grace, true);

    ui.cancelVoice();
    assert.equal(ui.grace, false);
    assert.equal(wire.text, 'أصلي');
    await advanceTimers(5000);
    assert.equal(calls.includes('prepare'), false);
});

test('stop/cancel/edit: manual edit cancels autodispatch and keeps transcript editable', async () => {
    const { ui, wire, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'نص مسجل' } }] });
    await advanceTimers(1000);
    assert.equal(ui.grace, true);

    wire.text = 'نص معدل يدويا';
    ui.edit();
    assert.equal(ui.grace, false);
    await advanceTimers(5000);
    assert.equal(calls.includes('prepare'), false);
    assert.equal(wire.text, 'نص معدل يدويا');
    await ui.send();
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('stale callbacks: delayed callbacks from superseded recordings never trigger autodispatch', async () => {
    const { ui, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onstart();
    const staleCallback = instances[0].onresult;
    ui.cancelVoice();

    staleCallback({ results: [{ isFinal: true, 0: { transcript: 'متأخر' } }] });
    await advanceTimers(5000);
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);
});

test('navigation/hidden: page hidden and navigating clear timers and cancel autodispatch', async () => {
    const { ui, calls, instances, advanceTimers, doc } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    ui.init();
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'إخفاء الصفحة' } }] });
    await advanceTimers(1000);
    assert.equal(ui.grace, true);

    doc.visibilityState = 'hidden';
    doc.trigger('visibilitychange');
    assert.equal(ui.grace, false);
    await advanceTimers(5000);
    assert.equal(calls.includes('prepare'), false);
});

test('errors/denial: permission denial and recognition errors never auto-send or retry', async () => {
    const { ui, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    await ui.start();
    instances[0].onerror({ error: 'not-allowed' });
    await advanceTimers(5000);
    assert.equal(ui.error, 'permission');
    assert.equal(ui.grace, false);
    assert.equal(calls.includes('prepare'), false);
});

test('repeated recordings: consecutive recordings each respect autodispatch boundaries', async () => {
    const { ui, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    // First turn
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'تسجيل أول' } }] });
    await advanceTimers(2250);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);

    // Second turn
    await ui.start();
    instances[1].onstart();
    instances[1].onresult({ results: [{ isFinal: true, 0: { transcript: 'تسجيل ثان' } }] });
    await advanceTimers(2250);
    assert.equal(calls.filter(x => x === 'prepare').length, 2);
});

test('Send during grace/double click: manual Send during grace dispatches immediately and exactly once', async () => {
    const { ui, wire, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    let resolvePrepare;
    wire.prepare = () => {
        calls.push('prepare');
        return new Promise(resolve => { resolvePrepare = resolve; });
    };

    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'إرسال مبكر' } }] });
    await advanceTimers(1000);
    assert.equal(ui.grace, true);

    const firstClick = ui.send();
    const secondClick = ui.send();
    assert.equal(ui.grace, false);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);

    resolvePrepare();
    await Promise.all([firstClick, secondClick]);
    await advanceTimers(5000);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('failure without retry: failed wire.prepare does not trigger automatic retry', async () => {
    const { ui, wire, calls, instances, advanceTimers } = fixture({ autoSubmit: true, quietMs: 1000, graceMs: 1250 });
    let attempts = 0;
    wire.prepare = async () => {
        attempts++;
        calls.push('prepare');
        throw new Error('Paid provider rate limit or network error');
    };

    await ui.start();
    instances[0].onstart();
    instances[0].onresult({ results: [{ isFinal: true, 0: { transcript: 'عملية فاشلة' } }] });
    await advanceTimers(2250);
    assert.equal(attempts, 1);
    assert.equal(ui.sending, false);

    await advanceTimers(5000);
    assert.equal(attempts, 1);
    assert.equal(calls.filter(x => x === 'prepare').length, 1);
});

test('aborted recognition after a final result cancels pending automatic dispatch', async () => {
    const {ui, instances, calls, advanceTimers} = fixture({autoSubmit:true});
    await ui.start(); instances[0].onstart();
    instances[0].onresult({results:[{isFinal:true,0:{transcript:'final'}}]});
    await advanceTimers(1000);
    instances[0].onerror({error:'aborted'});
    await advanceTimers(5000);
    assert.equal(calls.includes('prepare'),false);
    assert.equal(ui.grace,false);
});

test('speech-start during grace cancels dispatch before another transcript arrives', async () => {
    const {ui,instances,calls,advanceTimers}=fixture({autoSubmit:true});
    await ui.start(); instances[0].onstart();
    instances[0].onresult({results:[{isFinal:true,0:{transcript:'first'}}]});
    await advanceTimers(1000); assert.equal(ui.grace,true);
    instances[0].onspeechstart(); await advanceTimers(5000);
    assert.equal(calls.includes('prepare'),false);
    instances[0].onresult({results:[{isFinal:true,0:{transcript:'first second'}}]});
    await advanceTimers(2250);
    assert.equal(calls.filter(x=>x==='prepare').length,1);
});

test('manual edits and Send invalidate captured callbacks from the old recognition', async () => {
    for(const action of ['edit','send']) {
        const {ui,wire,instances,calls,advanceTimers}=fixture({autoSubmit:true});
        await ui.start(); instances[0].onstart();
        instances[0].onresult({results:[{isFinal:true,0:{transcript:'first'}}]});
        const stale=instances[0].onresult;
        await advanceTimers(1000);
        if(action==='edit'){wire.text='corrected';ui.edit();} else await ui.send();
        stale({results:[{isFinal:true,0:{transcript:'late'}}]});
        await advanceTimers(5000);
        assert.equal(wire.text,action==='edit'?'corrected':'first');
        assert.equal(calls.filter(x=>x==='prepare').length,action==='edit'?0:1);
    }
});

test('changed text without an input event and truthy nonboolean flags cannot autosubmit', async () => {
    const changed=fixture({autoSubmit:true});
    await changed.ui.start(); changed.instances[0].onstart();
    changed.instances[0].onresult({results:[{isFinal:true,0:{transcript:'first'}}]});
    await changed.advanceTimers(1000); changed.wire.text='other instruction';
    await changed.advanceTimers(1250); assert.equal(changed.calls.includes('prepare'),false);
    const disabled=fixture({autoSubmit:'false'});
    await disabled.ui.start(); disabled.instances[0].onstart();
    disabled.instances[0].onresult({results:[{isFinal:true,0:{transcript:'first'}}]});
    await disabled.advanceTimers(5000); assert.equal(disabled.calls.includes('prepare'),false);
});

test('offline event cancels active recording, preserves editable text and prevents submission', async () => {
    const {ui, instances, calls, advanceTimers, runtime, wire} = fixture({autoSubmit: true});
    ui.init();
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({results: [{isFinal: true, 0: {transcript: 'recording text'}}]});
    await advanceTimers(500); // within quiet heuristic
    assert.equal(ui.recording, true);

    // Trigger network disconnection event
    runtime.trigger('offline');

    assert.equal(ui.recording, false);
    assert.equal(ui.grace, false);
    assert.equal(ui.error, 'offline');
    assert.equal(wire.text, 'recording text');
    assert.equal(calls.includes('recognition.abort'), true);

    // Ensure delayed timer expiration does NOT dispatch prepare
    await advanceTimers(5000);
    assert.equal(calls.includes('prepare'), false);
    ui.destroy();
});

test('offline event during grace period aborts auto-submission', async () => {
    const {ui, instances, calls, advanceTimers, runtime} = fixture({autoSubmit: true});
    ui.init();
    await ui.start();
    instances[0].onstart();
    instances[0].onresult({results: [{isFinal: true, 0: {transcript: 'graceful text'}}]});
    await advanceTimers(1000); // enter grace period
    assert.equal(ui.grace, true);

    // Disconnect while user was in grace countdown
    runtime.trigger('offline');

    assert.equal(ui.grace, false);
    assert.equal(ui.error, 'offline');

    // Wait past grace period
    await advanceTimers(3000);
    assert.equal(calls.includes('prepare'), false);
    ui.destroy();
});

test('starting voice while offline rejects immediately with offline message', async () => {
    const {ui, calls, runtime} = fixture();
    runtime.navigator = { onLine: false };
    ui.init();
    await ui.start();

    assert.equal(ui.error, 'offline');
    assert.equal(calls.includes('recognition.start'), false);
    assert.equal(ui.recording, false);
    ui.destroy();
});

test('offline without an event still blocks dispatch and keeps the final transcript', async () => {
    const {ui, instances, calls, advanceTimers, runtime, wire} = fixture({autoSubmit:true});
    await ui.start(); instances[0].onstart();
    instances[0].onresult({results:[{isFinal:true,0:{transcript:'sales this month'}}]});
    await advanceTimers(1000);
    runtime.navigator = {onLine:false};
    await advanceTimers(1250);
    assert.equal(calls.includes('prepare'),false);
    assert.equal(wire.text,'sales this month');
    assert.equal(ui.error,'offline');
});
