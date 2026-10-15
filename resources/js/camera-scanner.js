import { enqueueDocumentOperation } from './document-operations.js';

/**
 * Reusable Alpine.js camera barcode scanner component.
 *
 * Implements:
 * - Native BarcodeDetector guard + @zxing/browser fallback.
 * - Strict lifecycle teardown (pagehide, hidden visibility, offline, Livewire navigation, Alpine destroy).
 * - Immediate track teardown on offline / close / navigation / error.
 * - Session token guards preventing late getUserMedia, late getSupportedFormats, and late decodeFromStream leaks.
 * - Non-overlapping sequential native detection guard.
 * - Visual absence detection allowing re-scanning of the same code after being removed.
 * - Bounded FIFO queue for distinct rapid scans, serialized one wire call at a time.
 * - In-flight wire queue preserved across close/reopen; busy state not prematurely reset.
 * - Feedback opt-in default false; triggered only AFTER server ok=true.
 * - Web Audio AudioContext properly closed on tone completion.
 * - Generic localized error messages without raw stack/message leaks.
 */
export function cameraScanner(wire, options = {}) {
    const nav = options.navigator || (typeof navigator !== 'undefined' ? navigator : null);
    const doc = options.document || (typeof document !== 'undefined' ? document : null);
    const win = options.window || (typeof window !== 'undefined' ? window : null);
    const createReader = options.createReader || (async () => {
        const { BrowserMultiFormatReader } = await import('@zxing/browser');
        return new BrowserMultiFormatReader();
    });
    const slowServerThresholdMs = options.slowServerThresholdMs ?? 2500;

    let activeSession = 0;
    let stream = null;
    let zxingControls = null;
    let nativeDetectTimer = null;
    let isDetectingNative = false;
    let previouslyFocused = null;
    let slowTimer = null;
    let absentSince = null;
    const absentGraceMs = options.absentGraceMs ?? 700;

    // Per-instance wire request queue (survives close/reopen)
    const scanQueue = [];


    return {
        isOpen: false,
        status: 'idle', // idle | starting | scanning | processing | slow_server | permission_denied | no_camera | unsupported | offline | error
        errorMessage: '',
        lastScannedCode: null,
        feedbackEnabled: false, // Default opt-in is false
        busy: false,

        init() {
            this.handleVisibilityChange = () => {
                if (doc?.visibilityState === 'hidden' && this.isOpen) {
                    this.close();
                }
            };
            this.handlePageHide = () => {
                if (this.isOpen) {
                    this.close();
                }
            };
            this.handleOffline = () => {
                // Immediately stop all tracks on offline, preserve offline message, require explicit reopen
                this.stopTracks();
                this.isOpen = false;
                this.status = 'offline';
                this.errorMessage = options.offlineMessage || 'Offline';
            };
            this.handleLivewireNav = () => {
                if (this.isOpen) {
                    this.close();
                }
            };

            doc?.addEventListener('visibilitychange', this.handleVisibilityChange);
            win?.addEventListener('pagehide', this.handlePageHide);
            win?.addEventListener('offline', this.handleOffline);
            doc?.addEventListener('livewire:navigating', this.handleLivewireNav);
        },

        destroy() {
            this.stopTracks();
            doc?.removeEventListener('visibilitychange', this.handleVisibilityChange);
            win?.removeEventListener('pagehide', this.handlePageHide);
            win?.removeEventListener('offline', this.handleOffline);
            doc?.removeEventListener('livewire:navigating', this.handleLivewireNav);
        },

        async open() {
            if (this.isOpen) return;

            previouslyFocused = doc?.activeElement;
            this.isOpen = true;
            this.status = 'starting';
            this.errorMessage = '';
            this.lastScannedCode = null;

            const sessionToken = ++activeSession;

            if (nav && !nav.onLine) {
                this.stopTracks();
                this.isOpen = false;
                this.status = 'offline';
                this.errorMessage = options.offlineMessage || 'Offline';
                return;
            }

            if (!nav?.mediaDevices?.getUserMedia) {
                this.stopTracks();
                this.isOpen = false;
                this.status = 'unsupported';
                this.errorMessage = options.unsupportedMessage || 'Unsupported';
                return;
            }

            try {
                const mediaStream = await nav.mediaDevices.getUserMedia({
                    video: {
                        facingMode: { ideal: 'environment' },
                        width: { ideal: 1280 },
                        height: { ideal: 720 },
                    },
                    audio: false,
                });

                // User closed modal or session changed while getUserMedia was pending
                if (sessionToken !== activeSession || !this.isOpen) {
                    mediaStream.getTracks().forEach((track) => track.stop());
                    return;
                }

                stream = mediaStream;
                const videoEl = this.$refs?.video;

                if (videoEl) {
                    videoEl.srcObject = mediaStream;
                    videoEl.setAttribute('playsinline', 'true');
                    videoEl.muted = true;
                    await videoEl.play().catch(() => {});
                }

                if (sessionToken !== activeSession || !this.isOpen) {
                    mediaStream.getTracks().forEach(track => track.stop());
                    return;
                }

                this.status = 'scanning';
                await this.startDetection(sessionToken, videoEl);
            } catch (err) {
                if (sessionToken !== activeSession) return;
                this.stopTracks();

                const errName = err?.name || '';
                if (errName === 'NotAllowedError' || errName === 'PermissionDeniedError') {
                    this.status = 'permission_denied';
                } else if (errName === 'NotFoundError' || errName === 'DevicesNotFoundError') {
                    this.status = 'no_camera';
                } else if (errName === 'NotSupportedError') {
                    this.status = 'unsupported';
                } else {
                    this.status = 'camera_error';
                }
            }
        },

        async startDetection(sessionToken, videoEl, fallbackOnly = false) {
            if (sessionToken !== activeSession || !this.isOpen) {
                return;
            }

            // 1. Guard: Native BarcodeDetector check
            let detector = null;
            if (!fallbackOnly && win && 'BarcodeDetector' in win) {
                try {
                    const formats = await win.BarcodeDetector.getSupportedFormats();
                    // Validate session token after async getSupportedFormats resolves
                    if (sessionToken !== activeSession || !this.isOpen) {
                        return;
                    }
                    if (Array.isArray(formats) && ['code_128', 'ean_13'].every(format => formats.includes(format))) {
                        detector = new win.BarcodeDetector({ formats });
                    }
                } catch {
                    detector = null;
                }
            }

            // Ensure session still valid
            if (sessionToken !== activeSession || !this.isOpen) {
                return;
            }

            if (detector && videoEl) {
                let nativeFailed = false;
                const scheduleNextDetect = () => {
                    if (nativeFailed || sessionToken !== activeSession || !this.isOpen) return;
                    nativeDetectTimer = setTimeout(async () => {
                        if (sessionToken !== activeSession || !this.isOpen) return;
                        if (isDetectingNative) {
                            scheduleNextDetect();
                            return;
                        }

                        isDetectingNative = true;
                        try {
                            const barcodes = await detector.detect(videoEl);
                            if (sessionToken !== activeSession || !this.isOpen) return;

                            if (barcodes && barcodes.length > 0) {
                                const raw = barcodes[0].rawValue;
                                this.onCodeDetected(raw);
                            } else {
                                // Barcode visibly removed: re-arm adjacent detection for same code
                                this.onCodeAbsent();
                            }
                        } catch {
                            nativeFailed = true;
                            if (sessionToken === activeSession && this.isOpen) await this.startDetection(sessionToken, videoEl, true);
                        } finally {
                            isDetectingNative = false;
                            scheduleNextDetect();
                        }
                    }, 66);
                };

                scheduleNextDetect();
                return;
            }

            // 2. Fallback: Local @zxing/browser MultiFormatReader
            try {
                const reader = await createReader();
                if (sessionToken !== activeSession || !this.isOpen) return;
                if (typeof reader.decodeFromStream === 'function' && videoEl && stream) {
                    const controls = await reader.decodeFromStream(stream, videoEl, (result, err) => {
                        if (sessionToken !== activeSession || !this.isOpen) return;
                        if (result) {
                            this.onCodeDetected(result.getText());
                        } else {
                            this.onCodeAbsent();
                        }
                    });

                    // Late controls check: if closed while decodeFromStream was awaiting
                    if (sessionToken !== activeSession || !this.isOpen) {
                        try {
                            controls?.stop();
                        } catch {}
                        return;
                    }

                    zxingControls = controls;
                }
            } catch {
                if (sessionToken === activeSession) {
                    this.stopTracks();
                    this.status = 'camera_error';
                }
            }
        },

        stopTracks() {
            activeSession++;
            if (slowTimer) {
                clearTimeout(slowTimer);
                slowTimer = null;
            }
            if (nativeDetectTimer) {
                clearTimeout(nativeDetectTimer);
                nativeDetectTimer = null;
            }
            isDetectingNative = false;

            if (zxingControls) {
                try {
                    zxingControls.stop();
                } catch {}
                zxingControls = null;
            }
            if (stream) {
                try {
                    stream.getTracks().forEach((track) => track.stop());
                } catch {}
                stream = null;
            }
            const videoEl = this.$refs?.video;
            if (videoEl) {
                try {
                    videoEl.srcObject = null;
                } catch {}
            }
        },

        close() {
            this.stopTracks();
            this.isOpen = false;
            this.status = 'idle';
            this.errorMessage = '';
            // Do NOT reset busy: true if background queue is still resolving wire requests

            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                try {
                    previouslyFocused.focus();
                } catch {}
                previouslyFocused = null;
            }
        },

        scanAgain() {
            if (!stream) { this.close(); this.open(); return; }
            absentSince = null;
            this.lastScannedCode = null;
            this.errorMessage = '';
            this.status = 'scanning';
        },

        onCodeAbsent() {
            absentSince ??= Date.now();
            if (Date.now() - absentSince >= absentGraceMs) this.lastScannedCode = null;
        },

        toggleFeedback() {
            this.feedbackEnabled = !this.feedbackEnabled;
        },

        triggerFeedback() {
            if (!this.feedbackEnabled) return;

            // Subtle vibration (50ms)
            if (nav?.vibrate) {
                try {
                    nav.vibrate(50);
                } catch {}
            }

            // Subtle audio tone via Web Audio, closing AudioContext on completion
            try {
                const AudioCtx = win?.AudioContext || win?.webkitAudioContext;
                if (AudioCtx) {
                    const ctx = new AudioCtx();
                    const osc = ctx.createOscillator();
                    const gain = ctx.createGain();
                    osc.connect(gain);
                    gain.connect(ctx.destination);
                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(880, ctx.currentTime);
                    gain.gain.setValueAtTime(0.08, ctx.currentTime);
                    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.1);

                    osc.onended = () => {
                        try {
                            ctx.close();
                        } catch {}
                    };

                    osc.start();
                    osc.stop(ctx.currentTime + 0.1);
                }
            } catch {}
        },

        onCodeDetected(rawCode) {
            if (!this.isOpen || typeof rawCode !== 'string') return;
            const code = rawCode.trim();
            if (!code || code.length > 128) return;
            absentSince = null;
            if (code === this.lastScannedCode) return;
            if (nav && !nav.onLine) { this.handleOffline(); return; }
            if (scanQueue.length >= 5) {
                this.errorMessage = options.queueFullMessage || options.networkErrorMessage || 'Please wait, then scan again';
                this.status = 'error';
                return;
            }
            this.lastScannedCode = code;
            const sessionToken = activeSession;
            // Enqueue immediately in the document FIFO, so Save cannot overtake a scan
            // already accepted from a later video frame.
            const operation = { code, sessionToken };
            scanQueue.push(operation);
            this.busy = true;
            this.status = 'processing';
            this.errorMessage = '';
            const timer = setTimeout(() => {
                if (this.isOpen && sessionToken === activeSession) this.status = 'slow_server';
            }, slowServerThresholdMs);
            const request = typeof options.onScan === 'function'
                ? enqueueDocumentOperation({ $id: wire?.$id || wire || this, scanCameraBarcode: options.onScan }, 'scanCameraBarcode', [code])
                : enqueueDocumentOperation(wire, 'scanCameraBarcode', [code]);
            request.then(response => {
                if (!this.isOpen || sessionToken !== activeSession) return;
                if (response?.ok) {
                    this.status = 'scanning';
                    this.errorMessage = '';
                    this.triggerFeedback();
                } else {
                    this.status = 'error';
                    this.errorMessage = response?.message || options.networkErrorMessage || 'Scan error';
                }
            }).catch(() => {
                if (this.isOpen && sessionToken === activeSession) {
                    this.status = 'error';
                    this.errorMessage = options.networkErrorMessage || 'Network error';
                }
            }).finally(() => {
                clearTimeout(timer);
                const index = scanQueue.indexOf(operation);
                if (index >= 0) scanQueue.splice(index, 1);
                this.busy = scanQueue.length > 0;
            });
        },
    };
}

// Auto-register with Alpine if present in browser
if (typeof window !== 'undefined') {
    const register = () => {
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            window.Alpine.data('cameraScanner', cameraScanner);
        }
    };
    if (window.Alpine) {
        register();
    } else {
        document.addEventListener('alpine:init', register, { once: true });
    }
}
