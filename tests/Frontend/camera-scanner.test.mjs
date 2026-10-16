import test from 'node:test';
import assert from 'node:assert/strict';
import { enqueueDocumentOperation } from '../../resources/js/document-operations.js';
import { cameraScanner } from '../../resources/js/camera-scanner.js';

function createMockTrack() {
    return {
        stopped: false,
        stop() {
            this.stopped = true;
        },
    };
}

function createMockStream(tracks = [createMockTrack()]) {
    return {
        tracks,
        getTracks() {
            return this.tracks;
        },
    };
}

function createMockEnvironment() {
    const listeners = new Map();
    const docListeners = new Map();

    const mockDoc = {
        visibilityState: 'visible',
        activeElement: { focus: () => {} },
        addEventListener(event, fn) {
            docListeners.set(event, fn);
        },
        removeEventListener(event) {
            docListeners.delete(event);
        },
        trigger(event) {
            const fn = docListeners.get(event);
            if (fn) fn();
        },
    };

    const mockWin = {
        addEventListener(event, fn) {
            listeners.set(event, fn);
        },
        removeEventListener(event) {
            listeners.delete(event);
        },
        trigger(event) {
            const fn = listeners.get(event);
            if (fn) fn();
        },
    };

    const mockTrack = createMockTrack();
    const mockStream = createMockStream([mockTrack]);

    const mockNav = {
        onLine: true,
        vibrateCalls: [],
        vibrate(ms) {
            this.vibrateCalls.push(ms);
        },
        mediaDevices: {
            getUserMediaCalls: 0,
            shouldDelayPromise: false,
            resolveDelayed: null,
            getUserMedia() {
                this.getUserMediaCalls++;
                if (this.shouldDelayPromise) {
                    return new Promise((resolve) => {
                        this.resolveDelayed = () => resolve(mockStream);
                    });
                }
                return Promise.resolve(mockStream);
            },
        },
    };

    const mockReader = {
        decodeCalls: 0,
        stoppedControls: false,
        shouldDelayDecode: false,
        resolveDecode: null,
        decodeFromStream(stream, videoEl, cb) {
            this.decodeCalls++;
            this.cb = cb;
            const controls = {
                stop: () => {
                    this.stoppedControls = true;
                },
            };
            if (this.shouldDelayDecode) {
                return new Promise((resolve) => {
                    this.resolveDecode = () => resolve(controls);
                });
            }
            return Promise.resolve(controls);
        },
    };

    return {
        mockDoc,
        mockWin,
        mockNav,
        mockTrack,
        mockStream,
        mockReader,
    };
}

test('a hidden scanner leaves Escape untouched; an open scanner consumes it and releases tracks', async () => {
    const env = createMockEnvironment();
    const scanner = cameraScanner({}, {navigator: env.mockNav, document: env.mockDoc, window: env.mockWin, createReader: () => env.mockReader});
    const trace = [];
    const event = {preventDefault: () => trace.push('prevent'), stopPropagation: () => trace.push('stop')};
    scanner.handleEscape(event);
    assert.deepEqual(trace, []);
    await scanner.open();
    scanner.handleEscape(event);
    assert.deepEqual(trace, ['prevent', 'stop']);
    assert.equal(scanner.isOpen, false);
    assert.equal(env.mockTrack.stopped, true);
});

test('an added scan needing line review is not green success, not replayed and gives no success feedback', async () => {
    const env = createMockEnvironment();
    const received = [];
    const wire = {scanCameraBarcode: async code => {received.push(code); return {ok:true, requiresReview:true, message:'Item added; review lines'};}};
    const scanner = cameraScanner(wire, {navigator: env.mockNav, document: env.mockDoc, window: env.mockWin, createReader: () => env.mockReader});
    scanner.feedbackEnabled = true;
    await scanner.open();
    scanner.onCodeDetected('ADDED');
    await new Promise(r => setTimeout(r, 10));
    assert.equal(scanner.status, 'review');
    assert.equal(scanner.errorMessage, 'Item added; review lines');
    assert.deepEqual(env.mockNav.vibrateCalls, []);
    scanner.onCodeDetected('ADDED');
    await new Promise(r => setTimeout(r, 10));
    assert.deepEqual(received, ['ADDED']);
    scanner.close();
});

test('explicit open() starts stream and stops on explicit close()', async () => {
    const env = createMockEnvironment();
    const wire = { scanCameraBarcode: async () => ({ ok: true, message: 'Success' }) };
    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    scanner.init();
    assert.equal(scanner.isOpen, false);
    assert.equal(scanner.status, 'idle');

    await scanner.open();
    assert.equal(scanner.isOpen, true);
    assert.equal(scanner.status, 'scanning');

    scanner.close();
    assert.equal(scanner.isOpen, false);
    assert.equal(env.mockTrack.stopped, true);
});

test('handleOffline immediately stops all tracks and sets offline status requiring explicit reopen', async () => {
    const env = createMockEnvironment();
    const wire = { scanCameraBarcode: async () => ({ ok: true }) };
    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    scanner.init();
    await scanner.open();
    assert.equal(scanner.isOpen, true);
    assert.equal(env.mockTrack.stopped, false);

    // Trigger offline event
    env.mockWin.trigger('offline');

    // Must immediately tear down tracks and close modal
    assert.equal(scanner.isOpen, false);
    assert.equal(scanner.status, 'offline');
    assert.equal(env.mockTrack.stopped, true);
});

test('late permission resolve / user closed modal before getUserMedia resolved', async () => {
    const env = createMockEnvironment();
    env.mockNav.mediaDevices.shouldDelayPromise = true;

    const wire = { scanCameraBarcode: async () => ({ ok: true }) };
    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    scanner.init();
    const openPromise = scanner.open();
    assert.equal(scanner.isOpen, true);
    assert.equal(scanner.status, 'starting');

    // User closes before permission is resolved
    scanner.close();
    assert.equal(scanner.isOpen, false);

    env.mockNav.mediaDevices.resolveDelayed();
    await openPromise;

    assert.equal(scanner.isOpen, false);
    assert.equal(env.mockTrack.stopped, true);
});

test('late ZXing decodeFromStream resolve after close immediately stops controls', async () => {
    const env = createMockEnvironment();
    env.mockReader.shouldDelayDecode = true;

    const wire = { scanCameraBarcode: async () => ({ ok: true }) };
    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    scanner.init();
    scanner.$refs = { video: { setAttribute: () => {}, play: () => Promise.resolve() } };
    const openPromise = scanner.open();
    await new Promise((r) => setTimeout(r, 5));

    // User closes while decodeFromStream is still resolving
    scanner.close();
    assert.equal(scanner.isOpen, false);

    // decodeFromStream now finishes
    env.mockReader.resolveDecode();
    await openPromise;

    // Controls must have been stopped immediately
    assert.equal(env.mockReader.stoppedControls, true);
});

test('late native BarcodeDetector.getSupportedFormats race after close does not install detector', async () => {
    const env = createMockEnvironment();
    let resolveFormats;
    env.mockWin.BarcodeDetector = {
        getSupportedFormats: () =>
            new Promise((resolve) => {
                resolveFormats = () => resolve(['qr_code', 'ean_13']);
            }),
    };

    const wire = { scanCameraBarcode: async () => ({ ok: true }) };
    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    scanner.init();
    const openPromise = scanner.open();
    await new Promise((r) => setTimeout(r, 5));

    // Close before formats resolve
    scanner.close();
    assert.equal(scanner.isOpen, false);

    resolveFormats();
    await openPromise;

    assert.equal(scanner.isOpen, false);
    assert.equal(env.mockTrack.stopped, true);
});

test('visual absence detection allows re-scanning same barcode after removal', async () => {
    const env = createMockEnvironment();
    const scanned = [];
    const wire = {
        scanCameraBarcode: async (code) => {
            scanned.push(code);
            return { ok: true };
        },
    };

    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    await scanner.open();

    // Frame 1: Code '11111'
    scanner.onCodeDetected('11111');
    await new Promise((r) => setTimeout(r, 10));
    assert.equal(scanned.length, 1);

    // Frame 2: Same code held in view (adjacent duplicate suppressed)
    scanner.onCodeDetected('11111');
    await new Promise((r) => setTimeout(r, 10));
    assert.equal(scanned.length, 1);

    // Frame 3: Code removed from view (empty frame)
    scanner.onCodeAbsent();

    await new Promise(r => setTimeout(r, 710));
    scanner.onCodeAbsent();

    // Frame 4: Code '11111' presented again
    scanner.onCodeDetected('11111');
    await new Promise((r) => setTimeout(r, 10));
    assert.equal(scanned.length, 2);
});

test('distinct rapid scans are queued boundedly and executed sequentially one at a time', async () => {
    const env = createMockEnvironment();
    const executed = [];
    let inFlight = 0;
    let maxInFlight = 0;

    const wire = {
        scanCameraBarcode: async (code) => {
            inFlight++;
            maxInFlight = Math.max(maxInFlight, inFlight);
            await new Promise((r) => setTimeout(r, 20));
            executed.push(code);
            inFlight--;
            return { ok: true };
        },
    };

    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    await scanner.open();

    // Rapid distinct scans in quick succession
    scanner.onCodeDetected('CODE_A');
    scanner.onCodeDetected('CODE_B');
    scanner.onCodeDetected('CODE_C');

    await new Promise((r) => setTimeout(r, 100));

    assert.equal(maxInFlight, 1, 'Wire requests must never overlap');
    assert.deepEqual(executed, ['CODE_A', 'CODE_B', 'CODE_C']);
});

test('in-flight queue is preserved across modal close and does not prematurely reset busy', async () => {
    const env = createMockEnvironment();
    let wireFinished = false;

    const wire = {
        scanCameraBarcode: async (code) => {
            await new Promise((r) => setTimeout(r, 40));
            wireFinished = true;
            return { ok: true };
        },
    };

    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    await scanner.open();
    scanner.onCodeDetected('IN_FLIGHT_ITEM');
    assert.equal(scanner.busy, true);

    // User closes modal while wire call is still running
    scanner.close();
    assert.equal(scanner.busy, true, 'Busy must stay true until background wire request completes');

    await new Promise((r) => setTimeout(r, 60));
    assert.equal(wireFinished, true);
    assert.equal(scanner.busy, false);
});

test('feedback opt-in defaults to false and triggers only after server confirmation', async () => {
    const env = createMockEnvironment();
    let wireOk = false;

    const wire = {
        scanCameraBarcode: async (code) => {
            await new Promise((r) => setTimeout(r, 15));
            return { ok: wireOk };
        },
    };

    const scanner = cameraScanner(wire, {
        navigator: env.mockNav,
        document: env.mockDoc,
        window: env.mockWin,
        createReader: () => env.mockReader,
    });

    assert.equal(scanner.feedbackEnabled, false);
    scanner.feedbackEnabled = true;

    await scanner.open();

    // Failure case: wire returns ok=false
    wireOk = false;
    scanner.onCodeDetected('FAIL_ITEM');
    await new Promise((r) => setTimeout(r, 30));
    assert.equal(env.mockNav.vibrateCalls.length, 0, 'No feedback on server failure');

    // Success case: wire returns ok=true
    wireOk = true;
    scanner.onCodeDetected('SUCCESS_ITEM');
    await new Promise((r) => setTimeout(r, 30));
    assert.equal(env.mockNav.vibrateCalls.length, 1, 'Feedback triggers after server confirmation');
});


test('save cannot overtake either of two camera readings already accepted', async () => {
    const env = createMockEnvironment();
    const trace = [];
    const wire = {
        $id: 'camera-save-ordering',
        scanCameraBarcode: async code => { await new Promise(r => setTimeout(r, 5)); trace.push(code); return { ok: true }; },
        saveDraft: async () => { trace.push('save'); },
    };
    const scanner = cameraScanner(wire, { navigator: env.mockNav, document: env.mockDoc, window: env.mockWin, createReader: () => env.mockReader });
    await scanner.open();
    scanner.onCodeDetected('FIRST');
    scanner.onCodeDetected('SECOND');
    await enqueueDocumentOperation(wire, 'saveDraft');
    assert.deepEqual(trace, ['FIRST', 'SECOND', 'save']);
    scanner.close();
});

test('backgrounding, pagehide, navigation and teardown release tracks and ignore late frames', async () => {
    for (const event of ['visibilitychange', 'pagehide', 'livewire:navigating', 'destroy']) {
        const env = createMockEnvironment();
        const received = [];
        const wire = { scanCameraBarcode: async code => { received.push(code); return { ok: true }; } };
        const scanner = cameraScanner(wire, { navigator: env.mockNav, document: env.mockDoc, window: env.mockWin, createReader: () => env.mockReader });
        scanner.$refs = { video: { setAttribute() {}, play: async () => {} } };
        scanner.init();
        await scanner.open();
        if (event === 'visibilitychange') { env.mockDoc.visibilityState = 'hidden'; env.mockDoc.trigger(event); }
        else if (event === 'pagehide') env.mockWin.trigger(event);
        else if (event === 'destroy') scanner.destroy();
        else env.mockDoc.trigger(event);
        assert.equal(env.mockTrack.stopped, true, event);
        assert.equal(env.mockReader.stoppedControls, true, event);
        env.mockReader.cb({ getText: () => 'LATE' });
        await new Promise(r => setTimeout(r, 0));
        assert.deepEqual(received, [], event);
    }
});
