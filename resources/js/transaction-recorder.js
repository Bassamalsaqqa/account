import { encodePcmWave } from './ai-wave';

const register = () => window.Alpine.data('transactionRecorder', (wire, options) => {
    // Keep audio and media handles outside Alpine/Livewire serialization.
    let stream, context, source, node, timer, chunks = [], wav, samples = 0, flush, abort;
    let generation = 0, recognition, localText = '', intentionalStop = false, recordedRevision;
    const method = () => options.correction ? options.method : (wire.speechMethod || options.method);
    const acknowledge = async (prepared, turn) => {
        if (typeof prepared !== 'string') return;
        if (turn !== generation) {
            await wire.discardPreparedAiInput(prepared);
            return;
        }
        // Inference leaves all form fields editable. Only the short local commit is protected from new input.
        const controls = [...(wire.$el?.querySelectorAll?.('input, select, textarea, button') || [])];
        const enabled = controls.filter(control => !control.disabled);
        enabled.forEach(control => { control.disabled = true; });
        try { await wire.applyPreparedAiInput(prepared); }
        finally { enabled.forEach(control => { control.disabled = false; }); }
    };
    const deliver = async (text, token) => {
        if (!options.correction) return wire.receiveTranscript(text, token);
        const turn = generation;
        const prepared = await wire.receiveAiCorrection(text, token, recordedRevision);
        await acknowledge(prepared, turn);
    };
    return {
        recording: false, busy: false, ready: false, seconds: 0, error: '',
        supported: Boolean(window.isSecureContext && navigator.mediaDevices?.getUserMedia && window.AudioContext && window.AudioWorkletNode),
        init() {
            this.leave = () => this.clear();
            this.manualEdit = (event) => {
                if (!options.correction || !(this.recording || this.busy || this.ready)) return;
                const binding = event.target?.getAttributeNames?.().find(name => name.startsWith('wire:model'));
                if (!binding || event.target.getAttribute(binding).startsWith('aiCorrection')) return;
                this.clear(); this.error = options.changedError || options.deliveryError;
            };
            wire.$el?.addEventListener('input', this.manualEdit, true);
            wire.$el?.addEventListener('change', this.manualEdit, true);
            window.addEventListener('pagehide', this.leave);
            document.addEventListener('livewire:navigating', this.leave);
        },
        async applyTyped() {
            if (this.recording || this.busy) return;
            const turn = generation;
            this.busy = true; this.error = '';
            try {
                const prepared = await wire.correctAiInput();
                await acknowledge(prepared, turn);
            } catch { if (turn === generation) this.error = options.deliveryError; }
            finally { if (turn === generation) this.busy = false; }
        },
        async start() {
            if (this.recording || this.busy) return;
            recordedRevision = wire.aiFormRevision;
            if (method() === 'disabled') { this.error = options.permissionError; return; }
            if (method() !== 'cloud' && await this.startLocal()) return;
            if (method() === 'local_only') { this.error = options.permissionError; return; }
            if (!(options.consent ?? wire.consent) || !this.supported || options.maxSeconds < 1) { this.error = options.permissionError; return; }
            this.clear(); this.error = '';
            const turn = generation;
            try {
                this.busy = true;
                const captureContext = new AudioContext({ sampleRate: 48000 });
                context = captureContext;
                if (!captureContext.audioWorklet) throw new Error('unsupported');
                // Permission prompts are cancellable by teardown even if the browser resolves later.
                const acquired = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true }, video: false });
                if (turn !== generation) { acquired.getTracks().forEach(track => track.stop()); return; }
                stream = acquired;
                await captureContext.audioWorklet.addModule(new URL('./ai-audio-worklet.js', import.meta.url).href);
                if (turn !== generation) return;
                await captureContext.resume();
                if (turn !== generation) return;
                source = captureContext.createMediaStreamSource(stream);
                node = new AudioWorkletNode(captureContext, 'sales-pcm-capture');
                node.port.onmessage = (event) => {
                    if (event.data.samples && turn === generation) {
                        const remaining = captureContext.sampleRate * options.maxSeconds - samples;
                        const chunk = event.data.samples.slice(0, Math.max(0, remaining));
                        if (chunk.length) { chunks.push(chunk); samples += chunk.length; }
                        this.seconds = Math.ceil(samples / captureContext.sampleRate);
                    }
                    if (event.data.flushed && turn === generation) flush?.();
                };
                source.connect(node); node.connect(captureContext.destination);
                this.recording = true;
                timer = setTimeout(() => { this.clear(); this.error = options.limitError; }, options.maxSeconds * 1000);
            } catch {
                if (turn === generation) { this.clear(); this.error = options.permissionError; }
            } finally { if (turn === generation) this.busy = false; }
        },
        async stop(intentional = false) {
            if (recognition) {
                intentionalStop = intentional;
                recognition.stop();
                return;
            }
            if (!this.recording) return;
            const turn = generation, captureContext = context, captureNode = node;
            this.recording = false; this.busy = true; clearTimeout(timer);
            try {
                source?.disconnect(); stream?.getTracks().forEach(track => track.stop());
                await new Promise((resolve, reject) => {
                    const deadline = setTimeout(() => reject(new Error('flush_timeout')), 2000);
                    flush = () => { clearTimeout(deadline); resolve(); };
                    captureNode.port.postMessage('flush');
                });
                if (turn !== generation) return;
                wav = new Blob([encodePcmWave(chunks, captureContext.sampleRate, options.maxSeconds)], { type: 'audio/wav' });
                chunks = []; this.ready = intentional;
            } catch { if (turn === generation) { this.clear(); this.error = options.permissionError; } }
            finally {
                captureNode?.disconnect(); captureContext?.close().catch(() => {});
                if (turn === generation) { context = undefined; this.busy = false; }
            }
            if (turn === generation && intentional && wav) await this.transcribe();
        },
        async transcribe() {
            if (!['cloud', 'local_if_supported'].includes(method())) { this.clear(); this.error = options.permissionError; return; }
            if (!wav || !(options.consent ?? wire.consent) || this.busy) return;
            const turn = generation, requestAbort = new AbortController();
            this.busy = true; this.error = ''; abort = requestAbort;
            const deadline = setTimeout(() => requestAbort.abort(), 30000);
            try {
                const data = new FormData();
                data.append('audio', wav, 'recording.wav');
                data.append('form_key', options.formKey || wire.formKey); data.append('context', options.context || wire.context);
                data.append('locale', options.locale);
                if (wire.speechConnection) data.append('connection_id', wire.speechConnection);
                data.append('request_key', crypto.randomUUID());
                const response = await fetch(options.url, {
                    method: 'POST', credentials: 'same-origin', signal: requestAbort.signal,
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' }, body: data,
                });
                const reply = await response.json();
                if (!response.ok || typeof reply.text !== 'string' || reply.text.length > 4000) throw new Error('unavailable');
                if (turn === generation) {
                    wav = undefined; this.ready = false;
                    await deliver(reply.text, reply.audio_token);
                }
            } catch { if (turn === generation) this.error = options.deliveryError; }
            finally { clearTimeout(deadline); if (turn === generation) this.busy = false; }
        },
        async startLocal() {
            const Speech = window.SpeechRecognition || window.webkitSpeechRecognition;
            const locale = options.locale === 'ar' ? 'ar-PS' : 'en-US';
            if (!Speech || typeof Speech.available !== 'function' || !('processLocally' in Speech.prototype)) return false;
            const turn = generation;
            try {
                const available = await Speech.available({ langs: [locale], processLocally: true });
                if (turn !== generation) return true;
                if (available !== 'available') return false;
                this.clear();
                const ownTurn = generation, local = new Speech();
                recognition = local; local.processLocally = true; local.lang = locale;
                local.continuous = true; local.interimResults = false;
                local.onresult = (event) => {
                    if (ownTurn !== generation) return;
                    for (let index = event.resultIndex; index < event.results.length; index++) {
                        if (event.results[index].isFinal) localText += event.results[index][0].transcript + ' ';
                    }
                    if (localText.length > 4000) { this.clear(); this.error = options.limitError; }
                };
                local.onerror = () => { if (ownTurn === generation) { this.clear(); this.error = options.permissionError; } };
                local.onend = async () => {
                    if (ownTurn !== generation) return;
                    const text = localText.trim(), send = intentionalStop;
                    clearTimeout(timer); recognition = undefined; this.recording = false;
                    if (!send || !text) return;
                    this.busy = true;
                    try { await deliver(text, null); }
                    catch { if (ownTurn === generation) this.error = options.deliveryError; }
                    finally { if (ownTurn === generation) this.busy = false; localText = ''; }
                };
                local.start(); this.recording = true; this.seconds = 0;
                timer = setTimeout(() => { this.clear(); this.error = options.limitError; }, Math.min(60, options.maxSeconds || 60) * 1000);
                return true;
            } catch {
                if (turn === generation) { this.clear(); this.error = options.permissionError; }
                return true; // A failed local start never silently sends audio to a cloud recognizer.
            }
        },
        clear() {
            generation++; intentionalStop = false; recognition?.abort(); recognition = undefined; localText = ''; abort?.abort(); clearTimeout(timer); source?.disconnect(); node?.disconnect();
            stream?.getTracks().forEach(track => track.stop()); context?.close().catch(() => {});
            stream = context = source = node = wav = flush = abort = undefined;
            chunks = []; samples = 0; this.recording = this.busy = this.ready = false; this.seconds = 0;
        },
        destroy() {
            this.clear(); wire.$el?.removeEventListener('input', this.manualEdit, true);
            wire.$el?.removeEventListener('change', this.manualEdit, true);
            window.removeEventListener('pagehide', this.leave);
            document.removeEventListener('livewire:navigating', this.leave);
        },
    };
});

if (window.Alpine) register();
else document.addEventListener('alpine:init', register, { once: true });
