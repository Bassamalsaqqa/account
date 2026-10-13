/**
 * Small Trader Accounting — Shell-Only Service Worker (A6 Secure Mobile/PWA)
 *
 * Invariants:
 * 1. Strict finite allowlist precaching: Only exact PRECACHE_URLS are cached. No dynamic or wildcard runtime caching.
 * 2. Zero financial caching: All Livewire, API, authenticated, dynamic, and financial paths bypass the worker.
 * 3. Cache isolation: Lookups and storage strictly use CACHE_NAME; global caches.match is never called.
 * 4. Safe install validation: Precached assets are fetched with credentials:'omit', cache:'no-store', redirect:'error',
 *    and validated for status 200, unredirected same-origin type, expected Content-Type, and non-private headers.
 * 5. Network-only navigation with cache:'no-store': Network failure falls back to the precached /offline.html shell.
 * 6. Owned namespace cleanup: On activation, only caches matching sta-pwa-* (and not current CACHE_NAME) are purged.
 */

const CACHE_VERSION = 'v1';
const CACHE_PREFIX = 'sta-pwa-';
const CACHE_NAME = `${CACHE_PREFIX}${CACHE_VERSION}`;

const PRECACHE_URLS = [
    '/offline.html',
    '/manifest.webmanifest',
    '/icons/icon.svg',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-maskable.png',
];

function isExpectedContentType(path, contentType) {
    if (!contentType) return false;
    const lower = contentType.toLowerCase();
    if (path.endsWith('.html')) {
        return lower.includes('text/html');
    }
    if (path.endsWith('.webmanifest')) {
        return lower.includes('application/manifest+json') || lower.includes('application/json') || lower.includes('text/json');
    }
    if (path.endsWith('.svg')) {
        return lower.includes('image/svg+xml');
    }
    if (path.endsWith('.png')) {
        return lower.includes('image/png');
    }
    return false;
}

function isSensitiveRoute(pathname, request) {
    if (
        pathname.startsWith('/api/') ||
        pathname.startsWith('/livewire/') ||
        pathname.startsWith('/sanctum/') ||
        pathname.startsWith('/assistant/') ||
        pathname.startsWith('/companies/') ||
        pathname.startsWith('/documents/') ||
        pathname.startsWith('/reports/') ||
        pathname === '/login' ||
        pathname === '/logout' ||
        pathname === '/locale'
    ) {
        return true;
    }

    if (request && request.headers) {
        if (
            request.headers.has('authorization') ||
            request.headers.has('cookie') ||
            request.headers.has('x-livewire') ||
            request.headers.has('x-csrf-token')
        ) {
            return true;
        }
    }

    return false;
}

self.addEventListener('install', (event) => {
    event.waitUntil(
        (async () => {
            const cache = await caches.open(CACHE_NAME);
            for (const path of PRECACHE_URLS) {
                const url = new URL(path, self.location.origin);
                const response = await fetch(url.href, {
                    credentials: 'omit',
                    cache: 'no-store',
                    redirect: 'error',
                });

                if (!response.ok || response.status !== 200 || response.redirected || response.type !== 'basic') {
                    throw new Error(`Invalid response for precached asset: ${path}`);
                }

                const cacheControl = (response.headers.get('cache-control') || '').toLowerCase();
                if (cacheControl.includes('no-store') || cacheControl.includes('private')) {
                    throw new Error(`Precache asset ${path} returned private/no-store headers`);
                }

                const contentType = response.headers.get('content-type') || '';
                if (!isExpectedContentType(path, contentType)) {
                    throw new Error(`Unexpected Content-Type for ${path}: ${contentType}`);
                }

                await cache.put(url.href, response);
            }
        })()
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        (async () => {
            const keys = await caches.keys();
            await Promise.all(
                keys.map((key) => {
                    if (key.startsWith(CACHE_PREFIX) && key !== CACHE_NAME) {
                        return caches.delete(key);
                    }
                    return Promise.resolve();
                })
            );
            if (self.clients && typeof self.clients.claim === 'function') {
                await self.clients.claim();
            }
        })()
    );
});

self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        if (typeof self.skipWaiting === 'function') {
            self.skipWaiting();
        }
    }
});

self.addEventListener('fetch', (event) => {
    const request = event.request;

    // 1. Non-GET requests are never intercepted
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // 2. Cross-origin requests are never intercepted
    if (url.origin !== self.location.origin) {
        return;
    }

    // 3. Requests with query strings or percent-encoded paths are never intercepted
    if (url.search || request.url.includes('%')) {
        return;
    }

    // 4. Sensitive, API, Livewire, or credential-bearing requests bypass worker completely
    if (isSensitiveRoute(url.pathname, request)) {
        return;
    }

    // 5. Navigation requests: network-only with cache:'no-store', fallback to precached /offline.html
    const isNavigation = request.mode === 'navigate' ||
        request.destination === 'document' ||
        (request.headers.get('accept') && request.headers.get('accept').includes('text/html'));

    if (isNavigation) {
        event.respondWith(
            (async () => {
                try {
                    return await fetch(request, { cache: 'no-store' });
                } catch {
                    const cache = await caches.open(CACHE_NAME);
                    const offlineShell = await cache.match(new URL('/offline.html', self.location.origin).href);
                    if (offlineShell) return offlineShell;
                    return new Response('Offline', { status: 503, statusText: 'Service Unavailable' });
                }
            })()
        );
        return;
    }

    // 6. Exact precache allowlist: served strictly from owned CACHE_NAME
    if (PRECACHE_URLS.includes(url.pathname)) {
        event.respondWith(
            (async () => {
                const cache = await caches.open(CACHE_NAME);
                const cached = await cache.match(url.href);
                if (cached) return cached;
                return fetch(request);
            })()
        );
        return;
    }

    // 7. All other requests (e.g. non-allowlisted assets, build paths) bypass the worker
});
