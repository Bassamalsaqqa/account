import test from 'node:test';
import assert from 'node:assert/strict';
import { browserVoice } from '../../resources/js/assistant-browser-voice.js';

function fixture({ local = false, available = 'unavailable', secure = true, supported = true } = {}) {
    const instances = [], calls = [], intervals = new Map();
    let nextTimer = 0;
    class Speech {
        static async available() { return available; }
        constructor() { if (local) this.processLocally = false; instances.push(this); }
        start() { calls.push('recognition.start'); }
        stop() { calls.push('recognition.stop'); this.onend?.(); }
        abort() { calls.push('recognition.abort'); }
    }
    const wire = { text: '', $set(field, text, live) { this[field] = text; calls.push(['set', field, live]); }, async prepare() { calls.push('prepare'); } };
    const runtime = { isSecureContext: secure, ...(supported ? { SpeechRecognition: Speech } : {}), setInterval(fn) { intervals.set(++nextTimer, fn); return nextTimer; }, clearInterval(id) { intervals.delete(id); } };
    const ui = browserVoice(wire, { locale: 'ar', maxSeconds: 60 }, runtime);
    return { ui, wire, calls, instances, intervals };
}

test('opening does not record, obtain consent or dispatch', () => {
    const { ui, calls } = fixture(); ui.init();
    assert.equal(ui.remoteVoiceConsent, false); assert.deepEqual(calls, []);
});
test('unsupported and insecure environments preserve text entry', async () => {
    for (const options of [{ secure: false }, { supported: false }]) {
        const { ui, wire, calls } = fixture(options); wire.text = 'رسالة'; await ui.start();
        assert.equal(ui.error, 'unsupported'); assert.equal(wire.text, 'رسالة'); assert.deepEqual(calls, []);
    }
});
test('remote recognition cannot start before explicit consent', async () => {
    const { ui, calls } = fixture(); await ui.start();
    assert.equal(ui.voiceDisclosure, true); assert.deepEqual(calls, []);
    ui.remoteVoiceConsent = true; await ui.start(); assert.deepEqual(calls, ['recognition.start']);
});
test('installed local language pack uses forced local processing without remote consent', async () => {
    const { ui, instances, calls } = fixture({ local: true, available: 'available' }); await ui.start();
    assert.equal(instances[0].processLocally, true); assert.equal(instances[0].lang, 'ar-PS');
    assert.equal(ui.localVoice, true); assert.deepEqual(calls, ['recognition.start']);
});
test('local-capable browser without installed pack still requires remote consent', async () => {
    const { ui, calls } = fixture({ local: true }); await ui.start();
    assert.equal(ui.voiceDisclosure, true); assert.deepEqual(calls, []);
});
test('pending permission blocks duplicate start; results never dispatch automatically', async () => {
    const { ui, wire, calls, instances } = fixture(); ui.remoteVoiceConsent = true;
    await ui.start(); await ui.start(); assert.equal(instances.length, 1);
    instances[0].onstart();
    instances[0].onresult({ results: [Object.assign([{ transcript: 'بيع قطعتين' }], { isFinal: true })] });
    assert.equal(wire.text, 'بيع قطعتين'); assert.equal(calls.includes('prepare'), false);
    ui.stop(); assert.equal(ui.transcriptReady, true);
    wire.text = 'بيع ثلاث قطع'; await ui.send(); assert.equal(calls.filter(call => call === 'prepare').length, 1);
});
test('cancel discards recognized text and blocks delayed result callbacks', async () => {
    const { ui, wire, instances, calls } = fixture(); wire.text = 'original'; ui.remoteVoiceConsent = true; await ui.start();
    instances[0].onstart(); const delayed = instances[0].onresult;
    instances[0].onresult({ results: [Object.assign([{ transcript: 'secret' }], { isFinal: true })] });
    ui.cancelVoice(); delayed({ results: [Object.assign([{ transcript: 'stale' }], { isFinal: true })] });
    assert.equal(wire.text, 'original'); assert.equal(calls.includes('prepare'), false); assert.equal(ui.recording, false);
});
test('permission refusal is sanitized and never retries', async () => {
    const { ui, instances, calls } = fixture(); ui.remoteVoiceConsent = true; await ui.start();
    const delayed = instances[0].onresult;
    instances[0].onerror({ error: 'not-allowed', message: 'private details' });
    delayed({ results: [{ isFinal: true, 0: { transcript: 'stale after failure' } }] });
    assert.equal(ui.error, 'permission'); assert.equal(ui.busy, false);
    assert.equal(ui.transcriptReady, false);
    assert.equal(instances[0].onresult, null);
    assert.equal(calls.filter(call => call === 'recognition.start').length, 1);
});
test('listening duration and transcript size remain bounded', async () => {
    const { ui, wire, instances, intervals } = fixture(); ui.remoteVoiceConsent = true; await ui.start(); instances[0].onstart();
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
    ui.remoteVoiceConsent = true;
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
