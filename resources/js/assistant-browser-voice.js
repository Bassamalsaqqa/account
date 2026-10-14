// Browser speech produces editable text only. Provider dispatch always requires Send or completed cancellable grace.
export function browserVoice(wire, options, runtime = globalThis) {
    let recognition, timer, quietTimer, graceTimer, generation = 0, original = '', accepted = '', finished = false, autoSubmitCancelled = false;
    const Recognition = runtime.SpeechRecognition || runtime.webkitSpeechRecognition;
    const message = key => options.messages?.[key] || key;
    const lang = options.locale === 'ar' ? 'ar-PS' : 'en-US';

    const clampMs = (val, fallback, min = 200, max = 10000) => {
        const num = Number(val);
        return Number.isFinite(num) && num > 0 ? Math.min(Math.max(Math.round(num), min), max) : fallback;
    };
    const autoSubmitEnabled = options.autoSubmit === true;
    const quietMs = clampMs(options.quietMs, 1000);
    const graceMs = clampMs(options.graceMs, 1250);

    const setTimer = (fn, ms) => (runtime.setTimeout ? runtime.setTimeout(fn, ms) : setTimeout(fn, ms));
    const clearTimer = id => {
        if (!id) return;
        if (runtime.clearTimeout) runtime.clearTimeout(id);
        else clearTimeout(id);
    };

    const clearAutoSubmit = () => {
        if (quietTimer) { clearTimer(quietTimer); quietTimer = undefined; }
        if (graceTimer) { clearTimer(graceTimer); graceTimer = undefined; }
    };

    const stopTimer = () => { if (timer) runtime.clearInterval(timer); timer = undefined; };
    const disconnect = () => {
        if (!recognition) return;
        recognition.onresult = recognition.onerror = recognition.onend = recognition.onstart = recognition.onspeechstart = null;
        recognition.abort();
        recognition = undefined;
    };
    return {
        recording: false, busy: false, seconds: 0, error: '', interim: '',
        transcriptReady: false,
        sentText: '', sending: false,
        grace: false,
        init() {
            this.leave = () => this.clear();
            this.visibilityHandler = () => {
                if (runtime.document?.visibilityState === 'hidden') this.clear();
            };
            runtime.document?.addEventListener?.('livewire:navigating', this.leave);
            runtime.addEventListener?.('pagehide', this.leave);
            runtime.document?.addEventListener?.('visibilitychange', this.visibilityHandler);
        },
        destroy() {
            this.clear();
            runtime.document?.removeEventListener?.('livewire:navigating', this.leave);
            runtime.removeEventListener?.('pagehide', this.leave);
            runtime.document?.removeEventListener?.('visibilitychange', this.visibilityHandler);
        },
        async start() {
            if (this.recording || this.busy || this.sending) return;
            this.error = '';
            clearAutoSubmit();
            this.grace = false;
            autoSubmitCancelled = false;
            if (!runtime.isSecureContext || !Recognition) { this.error = message('unsupported'); return; }
            const turn = ++generation;
            this.busy = true;
            let requested = false;
            try {
                const candidate = new Recognition();
                this.transcriptReady = false;
                this.interim = '';
                original = String(wire.text || '');
                accepted = ''; finished = false;
                recognition = candidate;
                recognition.lang = lang;
                recognition.continuous = true;
                recognition.interimResults = true;
                recognition.maxAlternatives = 1;
                recognition.onspeechstart = () => {
                    if (turn !== generation) return;
                    clearAutoSubmit();
                    this.grace = false;
                };
                recognition.onstart = () => {
                    if (turn !== generation || autoSubmitCancelled) return;
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

                    if (!autoSubmitEnabled) return;

                    clearAutoSubmit();
                    this.grace = false;

                    if (accepted.length > 0 && this.interim.length === 0) {
                        const plannedText = String(wire.text || '');
                        quietTimer = setTimer(() => {
                            if (turn !== generation || autoSubmitCancelled || this.sending) return;
                            if (!accepted || this.interim.length > 0 || String(wire.text || '') !== plannedText) return;
                            this.grace = true;
                            this.transcriptReady = true;
                            graceTimer = setTimer(async () => {
                                if (turn !== generation || autoSubmitCancelled || this.sending) return;
                                if (!accepted || String(wire.text || '') !== plannedText) { this.edit(); return; }
                                this.grace = false;
                                if (this.recording || recognition) {
                                    finished = true;
                                    stopTimer();
                                    disconnect();
                                    this.recording = this.busy = false;
                                }
                                try {
                                    await this.send();
                                } catch {
                                    // Never retry failures automatically
                                }
                            }, graceMs);
                        }, quietMs);
                    }
                };
                recognition.onerror = event => {
                    if (turn !== generation) return;
                    clearAutoSubmit();
                    this.grace = false;
                    autoSubmitCancelled = true;
                    if (event.error !== 'aborted') this.error = message(['not-allowed', 'service-not-allowed'].includes(event.error) ? 'permission' : 'unavailable');
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
                    if (!accepted && !this.error && !finished) {
                        clearAutoSubmit();
                        this.grace = false;
                        this.error = message('noSpeech');
                    }
                    this.interim = '';
                    disconnect();
                };
                recognition.start();
                requested = true;
            } catch {
                if (turn === generation) {
                    ++generation;
                    clearAutoSubmit();
                    this.grace = false;
                    autoSubmitCancelled = true;
                    this.error = message('unavailable');
                    this.recording = false;
                    disconnect();
                    stopTimer();
                }
            } finally {
                if (!requested) this.busy = false;
            }
        },
        stop() {
            clearAutoSubmit();
            this.grace = false;
            autoSubmitCancelled = true;
            if (!recognition) return;
            finished = true;
            // Final results can arrive between stop() and onend. Freeze Send/start until onend.
            this.busy = true;
            recognition.stop();
            this.recording = false;
            stopTimer();
        },
        edit() {
            ++generation;
            clearAutoSubmit();
            this.grace = false;
            autoSubmitCancelled = true;
            if (recognition) {
                finished = true;
                stopTimer();
                disconnect();
                this.recording = this.busy = false;
            }
            this.transcriptReady = Boolean(String(wire.text || '').trim());
            this.$refs?.description?.focus?.();
        },
        cancelVoice() {
            ++generation;
            clearAutoSubmit();
            this.grace = false;
            autoSubmitCancelled = true;
            disconnect(); stopTimer();
            if (this.recording || accepted || this.transcriptReady) wire.$set('text', original, false);
            this.recording = this.busy = this.transcriptReady = false;
            this.interim = ''; accepted = '';
        },
        clear() {
            this.cancelVoice();
            this.error = '';
            this.sentText = '';
            runtime.speechSynthesis?.cancel?.();
        },
        async send() {
            const wasInGrace = this.grace;
            clearAutoSubmit();
            this.grace = false;
            autoSubmitCancelled = true;
            if (this.sending || this.busy || !String(wire.text || '').trim()) return;
            if (this.recording) {
                if (!wasInGrace) return;
                finished = true;
                stopTimer();
                disconnect();
                this.recording = false;
            }
            ++generation;
            this.sending = true;
            this.sentText = String(wire.text).slice(0, 4000);
            try { await wire.prepare(); }
            finally { this.sending = false; this.transcriptReady = false; accepted = ''; }
        },
        suggest(text) {
            if (this.recording || this.sending) return;
            clearAutoSubmit();
            this.grace = false;
            autoSubmitCancelled = true;
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
