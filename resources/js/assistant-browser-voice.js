// Browser speech produces editable text only. Provider dispatch always requires Send.
export function browserVoice(wire, options, runtime = globalThis) {
    let recognition, timer, generation = 0, original = '', accepted = '', finished = false;
    const Recognition = runtime.SpeechRecognition || runtime.webkitSpeechRecognition;
    const message = key => options.messages?.[key] || key;
    const lang = options.locale === 'ar' ? 'ar-PS' : 'en-US';
    const stopTimer = () => { if (timer) runtime.clearInterval(timer); timer = undefined; };
    const disconnect = () => {
        if (!recognition) return;
        recognition.onresult = recognition.onerror = recognition.onend = recognition.onstart = null;
        recognition.abort();
        recognition = undefined;
    };
    return {
        recording: false, busy: false, seconds: 0, error: '', interim: '',
        voiceDisclosure: false, remoteVoiceConsent: false, localVoice: false, transcriptReady: false,
        sentText: '', sending: false,
        init() {
            this.leave = () => this.clear();
            runtime.document?.addEventListener('livewire:navigating', this.leave);
            runtime.addEventListener?.('pagehide', this.leave);
        },
        destroy() {
            this.clear();
            runtime.document?.removeEventListener('livewire:navigating', this.leave);
            runtime.removeEventListener?.('pagehide', this.leave);
        },
        async start() {
            if (this.recording || this.busy || this.sending) return;
            this.error = '';
            if (!runtime.isSecureContext || !Recognition) { this.error = message('unsupported'); return; }
            const turn = ++generation;
            this.busy = true;
            let local = false;
            let requested = false;
            try {
                const candidate = new Recognition();
                if ('processLocally' in candidate && typeof Recognition.available === 'function') {
                    // Only an installed, positively verified language pack establishes local processing.
                    local = await Recognition.available({ langs: [lang], processLocally: true }) === 'available';
                }
                if (turn !== generation) return;
                if (!local && !this.remoteVoiceConsent) {
                    this.voiceDisclosure = true;
                    return;
                }
                this.voiceDisclosure = false;
                this.localVoice = local;
                this.transcriptReady = false;
                this.interim = '';
                original = String(wire.text || '');
                accepted = ''; finished = false;
                recognition = candidate;
                recognition.lang = lang;
                recognition.continuous = true;
                recognition.interimResults = true;
                recognition.maxAlternatives = 1;
                if (local) recognition.processLocally = true;
                recognition.onstart = () => {
                    if (turn !== generation) return;
                    this.busy = false;
                    this.recording = true;
                    this.seconds = 0;
                    timer = runtime.setInterval(() => {
                        if (++this.seconds >= (options.maxSeconds || 60)) this.stop();
                    }, 1000);
                };
                recognition.onresult = event => {
                    if (turn !== generation) return;
                    let finalText = '', interimText = '';
                    for (let i = 0; i < event.results.length; i++) {
                        const result = event.results[i];
                        if (result.isFinal) finalText += result[0].transcript + ' ';
                        else interimText += result[0].transcript + ' ';
                    }
                    accepted = finalText.trim().slice(0, 4000);
                    this.interim = interimText.trim().slice(0, 4000);
                    // Update a deferred model: recognition never calls prepare/receiveTranscript.
                    wire.$set('text', [original, accepted].filter(Boolean).join(' ').slice(0, 4000), false);
                };
                recognition.onerror = event => {
                    if (turn !== generation || event.error === 'aborted') return;
                    this.error = message(['not-allowed', 'service-not-allowed'].includes(event.error) ? 'permission' : 'unavailable');
                    ++generation;
                    this.recording = this.busy = false;
                    this.transcriptReady = accepted.length > 0;
                    this.interim = '';
                    stopTimer();
                    disconnect();
                };
                recognition.onend = () => {
                    if (turn !== generation) return;
                    this.recording = this.busy = false;
                    stopTimer();
                    this.transcriptReady = accepted.length > 0;
                    if (!accepted && !this.error && !finished) this.error = message('noSpeech');
                    this.interim = '';
                    disconnect();
                };
                recognition.start();
                requested = true;
            } catch {
                if (turn === generation) { this.error = message('unavailable'); this.recording = false; stopTimer(); }
            } finally {
                if (turn === generation && !requested) this.busy = false;
            }
        },
        stop() {
            if (!recognition) return;
            finished = true;
            // Final results can arrive between stop() and onend. Freeze Send/start until onend.
            this.busy = true;
            recognition.stop();
            this.recording = false;
            stopTimer();
        },
        cancelVoice() {
            ++generation;
            disconnect(); stopTimer();
            if (this.recording || accepted || this.transcriptReady) wire.$set('text', original, false);
            this.recording = this.busy = this.transcriptReady = false;
            this.interim = ''; accepted = '';
            this.voiceDisclosure = false;
        },
        clear() {
            this.cancelVoice();
            this.remoteVoiceConsent = false;
            this.error = '';
            this.sentText = '';
            runtime.speechSynthesis?.cancel();
        },
        async send() {
            if (this.sending || this.recording || this.busy || !String(wire.text || '').trim()) return;
            this.sending = true;
            this.sentText = String(wire.text).slice(0, 4000);
            try { await wire.prepare(); }
            finally { this.sending = false; this.transcriptReady = false; accepted = ''; }
        },
        suggest(text) {
            if (this.recording || this.sending) return;
            wire.$set('text', text, false);
            this.$refs?.description?.focus();
        },
    };
}

if (typeof window !== 'undefined') {
    const register = () => window.Alpine.data('accountingAssistant', (wire, options) => browserVoice(wire, options, window));
    if (window.Alpine) register();
    else document.addEventListener('alpine:init', register, { once: true });
}
