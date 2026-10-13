import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {
    isPwaConfigured,
    isPwaEligible,
    rollbackPwa,
    showUpdateNotification,
    registerServiceWorker
} from '../../resources/js/pwa.js';

// Load public/sw.js source code
const swPath = path.resolve(process.cwd(), 'public/sw.js');
const swCode = fs.readFileSync(swPath, 'utf8');

/**
 * Creates a mock Service Worker test harness with mock CacheStorage, fetch, and event dispatchers.
 */
function createSwHarness(origin = 'http://localhost') {
    const cachesMap = new Map();

    function normalizeKey(request) {
        const str = typeof request === 'string' ? request : request.url;
        if (str.startsWith('http')) return str;
        return `${origin}${str.startsWith('/') ? '' : '/'}${str}`;
    }

    class MockCache {
        constructor(name) {
            this.name = name;
            this.items = new Map();
        }
        async put(request, response) {
            const key = normalizeKey(request);
            this.items.set(key, response);
        }
        async match(request) {
            const key = normalizeKey(request);
            return this.items.get(key) || undefined;
        }
        async delete(request) {
            const key = normalizeKey(request);
            return this.items.delete(key);
        }
        async keys() {
            return Array.from(this.items.keys());
        }
    }

    const mockCaches = {
        async open(cacheName) {
            if (!cachesMap.has(cacheName)) {
                cachesMap.set(cacheName, new MockCache(cacheName));
            }
            return cachesMap.get(cacheName);
        },
        async has(cacheName) {
            return cachesMap.has(cacheName);
        },
        async delete(cacheName) {
            return cachesMap.delete(cacheName);
        },
        async keys() {
            return Array.from(cachesMap.keys());
        },
        async match(request) {
            // Global match should NEVER be called by sw.js under Codex invariants
            globalMatchCalls++;
            const key = normalizeKey(request);
            for (const cache of cachesMap.values()) {
                const match = await cache.match(key);
                if (match) return match;
            }
            return undefined;
        }
    };

    let globalMatchCalls = 0;

    class MockHeaders {
        constructor(init = {}) {
            this._headers = new Map();
            if (init instanceof MockHeaders) {
                init._headers.forEach((v, k) => this._headers.set(k.toLowerCase(), v));
            } else if (Array.isArray(init)) {
                init.forEach(([k, v]) => this._headers.set(k.toLowerCase(), v));
            } else {
                Object.entries(init).forEach(([k, v]) => this._headers.set(k.toLowerCase(), v));
            }
        }
        get(name) {
            return this._headers.get(name.toLowerCase()) || null;
        }
        has(name) {
            return this._headers.has(name.toLowerCase());
        }
        set(name, value) {
            this._headers.set(name.toLowerCase(), String(value));
        }
    }

    class MockRequest {
        constructor(url, options = {}) {
            this.url = url.startsWith('http') ? url : `${origin}${url}`;
            this.method = options.method || 'GET';
            this.headers = new MockHeaders(options.headers || {});
            this.mode = options.mode || 'cors';
            this.destination = options.destination || '';
        }
    }

    class MockResponse {
        constructor(body = '', options = {}) {
            this.body = body;
            this.status = options.status || 200;
            this.ok = this.status >= 200 && this.status < 300;
            this.type = options.type || 'basic';
            this.redirected = options.redirected || false;
            this.headers = new MockHeaders(options.headers || {});
        }
        clone() {
            return new MockResponse(this.body, {
                status: this.status,
                type: this.type,
                redirected: this.redirected,
                headers: this.headers
            });
        }
        async text() {
            return typeof this.body === 'string' ? this.body : JSON.stringify(this.body);
        }
    }

    // Default static fetch handler returning correct Content-Type for allowlist
    let fetchHandler = async (req, opts = {}) => {
        const urlStr = typeof req === 'string' ? req : req.url;
        const u = new URL(urlStr);
        let contentType = 'text/plain';
        if (u.pathname.endsWith('.html')) contentType = 'text/html; charset=utf-8';
        else if (u.pathname.endsWith('.webmanifest')) contentType = 'application/manifest+json';
        else if (u.pathname.endsWith('.svg')) contentType = 'image/svg+xml';
        else if (u.pathname.endsWith('.png')) contentType = 'image/png';

        return new MockResponse('STATIC_CONTENT', {
            status: 200,
            type: 'basic',
            headers: { 'Content-Type': contentType }
        });
    };

    const listeners = new Map();
    let skipWaitingCalled = false;
    let claimCalled = false;

    const sandbox = {
        self: {
            location: { origin },
            addEventListener(type, fn) {
                if (!listeners.has(type)) listeners.set(type, []);
                listeners.get(type).push(fn);
            },
            skipWaiting() {
                skipWaitingCalled = true;
            },
            clients: {
                async claim() {
                    claimCalled = true;
                }
            }
        },
        caches: mockCaches,
        fetch: (req, opts) => fetchHandler(req, opts),
        URL,
        Request: MockRequest,
        Response: MockResponse,
        Headers: MockHeaders,
        console
    };

    sandbox.self.caches = mockCaches;
    sandbox.self.fetch = sandbox.fetch;

    vm.createContext(sandbox);
    vm.runInContext(swCode, sandbox);

    async function dispatchInstall() {
        const installFns = listeners.get('install') || [];
        for (const fn of installFns) {
            let promise;
            const event = {
                waitUntil(p) { promise = p; }
            };
            fn(event);
            if (promise) await promise;
        }
    }

    async function dispatchActivate() {
        const activateFns = listeners.get('activate') || [];
        for (const fn of activateFns) {
            let promise;
            const event = {
                waitUntil(p) { promise = p; }
            };
            fn(event);
            if (promise) await promise;
        }
    }

    async function dispatchFetch(request) {
        const fetchFns = listeners.get('fetch') || [];
        for (const fn of fetchFns) {
            let responsePromise = null;
            let intercepted = false;
            const event = {
                request,
                respondWith(p) {
                    intercepted = true;
                    responsePromise = p;
                }
            };
            fn(event);
            if (intercepted) {
                return { intercepted: true, response: await responsePromise };
            }
        }
        return { intercepted: false, response: null };
    }

    function dispatchMessage(data) {
        const msgFns = listeners.get('message') || [];
        for (const fn of msgFns) {
            fn({ data });
        }
    }

    return {
        sandbox,
        cachesMap,
        mockCaches,
        MockRequest,
        MockResponse,
        dispatchInstall,
        dispatchActivate,
        dispatchFetch,
        dispatchMessage,
        setFetchHandler(fn) { fetchHandler = fn; },
        get skipWaitingCalled() { return skipWaitingCalled; },
        get claimCalled() { return claimCalled; },
        get globalMatchCalls() { return globalMatchCalls; }
    };
}

// -----------------------------------------------------------------------------
// SERVICE WORKER TESTS
// -----------------------------------------------------------------------------

test('SW install: precaches exact allowlist with credentials:omit, cache:no-store and validates Content-Type', async () => {
    const harness = createSwHarness();
    const fetchedOpts = [];
    harness.setFetchHandler(async (req, opts) => {
        fetchedOpts.push(opts);
        const u = new URL(typeof req === 'string' ? req : req.url);
        let ct = 'text/plain';
        if (u.pathname.endsWith('.html')) ct = 'text/html';
        else if (u.pathname.endsWith('.webmanifest')) ct = 'application/manifest+json';
        else if (u.pathname.endsWith('.svg')) ct = 'image/svg+xml';
        else if (u.pathname.endsWith('.png')) ct = 'image/png';
        return new harness.MockResponse('CONTENT', {
            status: 200,
            type: 'basic',
            headers: { 'Content-Type': ct }
        });
    });

    await harness.dispatchInstall();

    // Verify fetch security options were sent
    for (const opt of fetchedOpts) {
        assert.equal(opt.credentials, 'omit');
        assert.equal(opt.cache, 'no-store');
        assert.equal(opt.redirect, 'error');
    }

    const cache = await harness.mockCaches.open('sta-pwa-v1');
    const cachedKeys = await cache.keys();
    assert.equal(cachedKeys.length, 6);
    assert.ok(cachedKeys.includes('http://localhost/offline.html'));
    assert.ok(cachedKeys.includes('http://localhost/manifest.webmanifest'));
    assert.ok(cachedKeys.includes('http://localhost/icons/icon-192.png'));
});

test('SW install negative: rejects redirected response or login HTML masquerading as static', async () => {
    // 1. Login HTML masquerading as manifest
    const harness1 = createSwHarness();
    harness1.setFetchHandler(async (req) => {
        const u = new URL(typeof req === 'string' ? req : req.url);
        if (u.pathname.endsWith('.webmanifest')) {
            return new harness1.MockResponse('<!DOCTYPE html><html><body>Login</body></html>', {
                status: 200,
                type: 'basic',
                headers: { 'Content-Type': 'text/html' } // WRONG Content-Type
            });
        }
        return new harness1.MockResponse('OK', {
            status: 200,
            type: 'basic',
            headers: { 'Content-Type': u.pathname.endsWith('.html') ? 'text/html' : 'image/png' }
        });
    });

    await assert.rejects(
        async () => harness1.dispatchInstall(),
        /Unexpected Content-Type/
    );

    // 2. Redirected response
    const harness2 = createSwHarness();
    harness2.setFetchHandler(async () => {
        return new harness2.MockResponse('OK', {
            status: 200,
            type: 'basic',
            redirected: true, // REDIRECTED
            headers: { 'Content-Type': 'text/html' }
        });
    });

    await assert.rejects(
        async () => harness2.dispatchInstall(),
        /Invalid response/
    );

    // 3. Private / no-store header
    const harness3 = createSwHarness();
    harness3.setFetchHandler(async () => {
        return new harness3.MockResponse('OK', {
            status: 200,
            type: 'basic',
            headers: {
                'Content-Type': 'text/html',
                'Cache-Control': 'private, no-store'
            }
        });
    });

    await assert.rejects(
        async () => harness3.dispatchInstall(),
        /private\/no-store/
    );
});

test('SW fetch: uses only CACHE_NAME and refuses foreign cache substitution', async () => {
    const harness = createSwHarness();
    await harness.dispatchInstall();

    // Place an asset in a foreign cache
    const foreignCache = await harness.mockCaches.open('foreign-cache-v1');
    await foreignCache.put(
        new harness.MockRequest('/offline.html'),
        new harness.MockResponse('FOREIGN_OFFLINE_SHELL')
    );

    // Ensure sw.js does not call global caches.match
    const req = new harness.MockRequest('/offline.html');
    const res = await harness.dispatchFetch(req);
    assert.equal(res.intercepted, true);
    assert.equal(harness.globalMatchCalls, 0, 'Must NOT invoke global caches.match');

    const text = await res.response.text();
    assert.notEqual(text, 'FOREIGN_OFFLINE_SHELL', 'Must NOT read from foreign cache');
});

test('SW fetch: no wildcard build caching; non-allowlisted and encoded paths bypass worker', async () => {
    const harness = createSwHarness();
    await harness.dispatchInstall();

    const bypassPaths = [
        '/build/assets/app-abc123.js',
        '/build/assets/app-xyz789.css',
        '/offline.html%20',
        '/%2e%2e/offline.html',
        '/manifest.webmanifest?v=2'
    ];

    for (const p of bypassPaths) {
        const req = new harness.MockRequest(p);
        const res = await harness.dispatchFetch(req);
        assert.equal(res.intercepted, false, `Path ${p} must bypass service worker`);
    }
});

test('SW fetch: credential-bearing, sensitive, Livewire, and API requests bypass worker', async () => {
    const harness = createSwHarness();
    await harness.dispatchInstall();

    // Credential-bearing headers
    for (const [hdr, val] of [['Authorization', 'Bearer 123'], ['Cookie', 'session=abc'], ['X-CSRF-TOKEN', 'token']]) {
        const req = new harness.MockRequest('/offline.html', {
            headers: { [hdr]: val }
        });
        const res = await harness.dispatchFetch(req);
        assert.equal(res.intercepted, false, `Requests with ${hdr} must bypass worker`);
    }

    // Sensitive routes
    const sensitive = ['/api/v1/invoices', '/livewire/update', '/login', '/logout', '/documents/1.pdf'];
    for (const s of sensitive) {
        const req = new harness.MockRequest(s);
        const res = await harness.dispatchFetch(req);
        assert.equal(res.intercepted, false, `Sensitive route ${s} must bypass worker`);
    }
});

test('SW fetch: navigation is network-only with cache:no-store and never cached; falls back to offline shell on network error', async () => {
    const harness = createSwHarness();
    await harness.dispatchInstall();

    let fetchOptions = null;
    harness.setFetchHandler(async (req, opts) => {
        fetchOptions = opts;
        return new harness.MockResponse('<!DOCTYPE html><html><body>Private Financial Document</body></html>', {
            status: 200,
            type: 'basic',
            headers: { 'Content-Type': 'text/html' }
        });
    });

    const navReq = new harness.MockRequest('/sales/invoices/100', {
        mode: 'navigate',
        headers: { 'Accept': 'text/html' }
    });

    const res = await harness.dispatchFetch(navReq);
    assert.equal(res.intercepted, true);
    assert.equal(fetchOptions.cache, 'no-store', 'Navigation fetch must specify cache:no-store');

    // Private navigation response is NOT cached
    const cache = await harness.mockCaches.open('sta-pwa-v1');
    const cachedNav = await cache.match(navReq);
    assert.equal(cachedNav, undefined, 'Private navigation page must NEVER be cached');

    // Simulate network error
    harness.setFetchHandler(async () => {
        throw new Error('Network failure');
    });

    const resOffline = await harness.dispatchFetch(navReq);
    assert.equal(resOffline.intercepted, true);
    const body = await resOffline.response.text();
    assert.equal(body, 'STATIC_CONTENT', 'Offline navigation must fallback to precached /offline.html shell');
});

test('SW activate: cleans up ONLY owned caches (sta-pwa-*) and preserves foreign caches', async () => {
    const harness = createSwHarness();
    await harness.mockCaches.open('sta-pwa-v0');
    await harness.mockCaches.open('sta-pwa-v1');
    await harness.mockCaches.open('foreign-cache-v1');

    await harness.dispatchActivate();

    const keys = await harness.mockCaches.keys();
    assert.ok(!keys.includes('sta-pwa-v0'));
    assert.ok(keys.includes('sta-pwa-v1'));
    assert.ok(keys.includes('foreign-cache-v1'), 'Foreign cache must be preserved');
});

// -----------------------------------------------------------------------------
// CLIENT MANAGER & ROLLBACK TESTS
// -----------------------------------------------------------------------------

function createMockWindow({ pwaEnabled = true, secure = true, swSupported = true, waitingWorker = null } = {}) {
    const elements = new Map();
    const listeners = new Map();
    const cachesMap = new Map();

    const mockDoc = {
        documentElement: {
            getAttribute(name) { return name === 'dir' ? 'rtl' : null; }
        },
        querySelector(selector) {
            if (selector === 'meta[name="pwa-enabled"]') {
                return pwaEnabled ? { getAttribute: (attr) => attr === 'content' ? 'true' : null } : null;
            }
            return null;
        },
        getElementById(id) {
            return elements.get(id) || null;
        },
        createElement(tagName) {
            const el = {
                tagName,
                attributes: new Map(),
                children: [],
                className: '',
                textContent: '',
                innerHTML: '',
                listeners: new Map(),
                setAttribute(k, v) { this.attributes.set(k, v); },
                getAttribute(k) { return this.attributes.get(k) || null; },
                appendChild(c) { this.children.push(c); },
                addEventListener(evt, fn) {
                    if (!this.listeners.has(evt)) this.listeners.set(evt, []);
                    this.listeners.get(evt).push(fn);
                },
                click() {
                    const fns = this.listeners.get('click') || [];
                    fns.forEach(fn => fn());
                },
                remove() {
                    if (this.id) elements.delete(this.id);
                }
            };
            return el;
        },
        body: {
            appendChild(child) {
                if (child.id) elements.set(child.id, child);
            }
        }
    };

    let registeredSw = null;
    let unregisterCalls = 0;
    const navigator = {
        ...(swSupported ? {
            serviceWorker: {
                async register(url, opts) {
                    registeredSw = { url, opts };
                    return {
                        addEventListener() {},
                        installing: null,
                        waiting: waitingWorker
                    };
                },
                async getRegistrations() {
                    return [
                        { scope: 'http://localhost/', active: { scriptURL: 'http://localhost/sw.js' }, async unregister() { unregisterCalls++; } },
                        { scope: 'http://localhost/other/', active: { scriptURL: 'http://localhost/other/sw.js' }, async unregister() { throw new Error('Other scope unregister'); } },
                        { active: { scriptURL: 'http://localhost/foreign-worker.js' }, async unregister() { throw new Error('Foreign unregister'); } }
                    ];
                },
                addEventListener(evt, fn) {
                    if (!listeners.has(`sw:${evt}`)) listeners.set(`sw:${evt}`, []);
                    listeners.get(`sw:${evt}`).push(fn);
                },
                controller: {}
            }
        } : {})
    };

    const win = {
        isSecureContext: secure,
        document: mockDoc,
        navigator,
        caches: {
            async keys() {
                return ['sta-pwa-v1', 'foreign-cache-1'];
            },
            async delete(k) {
                cachesMap.set(k, 'deleted');
            }
        },
        location: {
            origin: 'http://localhost',
            reloaded: false,
            reload() { this.reloaded = true; }
        },
        addEventListener(evt, fn) {
            if (!listeners.has(evt)) listeners.set(evt, []);
            listeners.get(evt).push(fn);
        },
        removeEventListener(evt, fn) {
            const list = listeners.get(evt) || [];
            listeners.set(evt, list.filter(f => f !== fn));
        },
        trigger(evt, data) {
            const list = listeners.get(evt) || [];
            list.forEach(fn => fn(data));
        },
        triggerSw(evt, data) {
            const list = listeners.get(`sw:${evt}`) || [];
            list.forEach(fn => fn(data));
        },
        get registeredSw() { return registeredSw; },
        get unregisterCalls() { return unregisterCalls; },
        get deletedCaches() { return cachesMap; }
    };

    return win;
}

test('rollbackPwa: unregisters ONLY /sw.js and purges ONLY sta-pwa-* caches', async () => {
    const win = createMockWindow({ pwaEnabled: false });
    await rollbackPwa(win);

    assert.equal(win.unregisterCalls, 1, 'Must unregister owned /sw.js');
    assert.equal(win.deletedCaches.has('sta-pwa-v1'), true, 'Must delete owned cache');
    assert.equal(win.deletedCaches.has('foreign-cache-1'), false, 'Must NOT delete foreign cache');
});

test('controllerchange MUST NOT reload unless user explicitly clicked Update', async () => {
    const win = createMockWindow({ pwaEnabled: true });
    await registerServiceWorker(win);

    // Background or initial controllerchange without user click
    win.triggerSw('controllerchange');
    assert.equal(win.location.reloaded, false, 'Must NOT reload automatically without explicit user action');

    // Show update notification and simulate explicit click
    let skipWaitingPosted = false;
    const fakeWorker = {
        postMessage(data) {
            if (data?.type === 'SKIP_WAITING') skipWaitingPosted = true;
        }
    };
    const bar = showUpdateNotification(win, fakeWorker);
    const updateBtn = bar.children.find(c => c.tagName === 'button' && c.textContent.includes('تحديث'));
    updateBtn.click();
    assert.equal(skipWaitingPosted, true);

    // Now controllerchange occurs after explicit click
    win.triggerSw('controllerchange');
    assert.equal(win.location.reloaded, true, 'Must reload when user explicitly accepted update');
});

test('registration.waiting on load displays update notification', async () => {
    const fakeWaiting = { postMessage() {} };
    const win = createMockWindow({ pwaEnabled: true, waitingWorker: fakeWaiting });

    await registerServiceWorker(win);

    const bar = win.document.getElementById('pwa-update-bar');
    assert.ok(bar, 'Update bar must be displayed if worker is waiting on load');
    assert.ok(bar.className.includes('top-3'), 'Update bar must be at top of viewport');
});

test('disabled and unsupported deployments never register a worker', async () => {
    for (const options of [{pwaEnabled:false}, {secure:false}, {swSupported:false}]) {
        const win = createMockWindow(options);
        assert.equal(await registerServiceWorker(win), null);
        assert.equal(win.registeredSw, null);
    }
});
