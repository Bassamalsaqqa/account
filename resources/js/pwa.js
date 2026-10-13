/**
 * Small Trader Accounting — Secure Shell-Only PWA Client Manager
 *
 * Safety Invariants:
 * 1. Safe rollback when disabled: Unregisters /sw.js and cleans owned 'sta-pwa-*' caches
 *    without touching foreign registrations or foreign caches.
 * 2. Safe controllerchange: NEVER reloads automatically on initial registration or background
 *    updates over unsaved forms. Reloads ONLY when the user explicitly clicks Update in this tab.
 * 3. Handles registration.waiting already present on load.
 * 4. Non-disruptive update notification placed at top of viewport, never obstructing bottom
 *    launchers or Save/Post action controls.
 */

export function isPwaConfigured(doc = document) {
    if (!doc) return false;
    const meta = doc.querySelector('meta[name="pwa-enabled"]');
    return meta ? meta.getAttribute('content') === 'true' : false;
}

export function isPwaEligible(win = window) {
    if (!win) return false;
    const isSecure = Boolean(win.isSecureContext);
    const hasServiceWorker = Boolean(win.navigator && 'serviceWorker' in win.navigator);
    const isConfigured = isPwaConfigured(win.document);
    return isConfigured && isSecure && hasServiceWorker;
}

/**
 * Safe rollback: if PWA is disabled in deployment config, unregister /sw.js
 * and clear owned caches without touching foreign registrations or caches.
 */
export async function rollbackPwa(win = window) {
    if (!win || !win.navigator || !('serviceWorker' in win.navigator)) {
        return;
    }

    try {
        if (typeof win.navigator.serviceWorker.getRegistrations === 'function') {
            const registrations = await win.navigator.serviceWorker.getRegistrations();
            for (const reg of registrations) {
                const scriptUrl = reg.active?.scriptURL || reg.waiting?.scriptURL || reg.installing?.scriptURL || '';
                const ownScript = new URL('/sw.js', win.location.origin).href;
                const ownScope = new URL('/', win.location.origin).href;
                if (scriptUrl === ownScript && reg.scope === ownScope) {
                    await reg.unregister();
                }
            }
        }

        if (win.caches && typeof win.caches.keys === 'function') {
            const keys = await win.caches.keys();
            for (const key of keys) {
                if (key.startsWith('sta-pwa-')) {
                    await win.caches.delete(key);
                }
            }
        }
    } catch {
        // Rollback failures should not break the host application
    }
}

let userExplicitlyAcceptedUpdate = false;

export function showUpdateNotification(win = window, worker = null) {
    const doc = win.document;
    if (!doc || doc.getElementById('pwa-update-bar')) return null;

    const isRtl = doc.documentElement.getAttribute('dir') === 'rtl';
    const bar = doc.createElement('div');
    bar.id = 'pwa-update-bar';
    bar.setAttribute('role', 'alert');
    bar.setAttribute('aria-live', 'polite');
    // Positioned at top of viewport, strictly avoiding bottom launcher and Save/Post buttons
    bar.className = 'fixed top-3 inset-x-3 max-w-lg mx-auto z-40 bg-slate-900 text-white px-4 py-2.5 rounded-lg shadow-xl flex items-center gap-3 text-xs sm:text-sm font-semibold border border-slate-700';

    const text = doc.createElement('span');
    text.className = 'flex-1';
    text.textContent = isRtl
        ? 'تحديث جديد متوفر للنظام'
        : 'A new system update is available';

    const updateBtn = doc.createElement('button');
    updateBtn.type = 'button';
    updateBtn.className = 'min-h-[44px] min-w-[44px] px-3 py-1.5 bg-primary hover:bg-primary-hover text-white rounded font-bold cursor-pointer transition-colors focus-visible:outline-2 focus-visible:outline-white';
    updateBtn.textContent = isRtl ? 'تحديث الآن' : 'Update now';
    updateBtn.addEventListener('click', () => {
        userExplicitlyAcceptedUpdate = true;
        if (worker && typeof worker.postMessage === 'function') {
            worker.postMessage({ type: 'SKIP_WAITING' });
        } else {
            win.location.reload();
        }
    });

    const dismissBtn = doc.createElement('button');
    dismissBtn.type = 'button';
    dismissBtn.className = 'min-h-[44px] min-w-[44px] p-2 text-slate-400 hover:text-white cursor-pointer rounded focus-visible:outline-2 focus-visible:outline-white';
    dismissBtn.setAttribute('aria-label', isRtl ? 'إغلاق' : 'Close');
    dismissBtn.textContent = '✕';
    dismissBtn.addEventListener('click', () => bar.remove());

    bar.appendChild(text);
    bar.appendChild(updateBtn);
    bar.appendChild(dismissBtn);
    doc.body.appendChild(bar);

    return bar;
}

export async function registerServiceWorker(win = window) {
    if (!isPwaEligible(win)) {
        await rollbackPwa(win);
        return null;
    }

    try {
        const registration = await win.navigator.serviceWorker.register('/sw.js', { scope: '/' });

        // Handle waiting worker already present on load
        if (registration.waiting && win.navigator.serviceWorker.controller) {
            showUpdateNotification(win, registration.waiting);
        }

        // Handle updates detected during lifecycle
        registration.addEventListener('updatefound', () => {
            const installingWorker = registration.installing;
            if (!installingWorker) return;

            installingWorker.addEventListener('statechange', () => {
                if (installingWorker.state === 'installed' && win.navigator.serviceWorker.controller) {
                    showUpdateNotification(win, installingWorker);
                }
            });
        });

        // Invariant: controllerchange MUST NOT reload unless the user explicitly requested it in this tab
        win.navigator.serviceWorker.addEventListener('controllerchange', () => {
            if (userExplicitlyAcceptedUpdate) {
                userExplicitlyAcceptedUpdate = false;
                win.location.reload();
            }
        });

        return registration;
    } catch {
        return null;
    }
}

export function initPwa(win = window) {
    if (!isPwaEligible(win)) {
        rollbackPwa(win);
        return false;
    }

    registerServiceWorker(win);
    // Leave installation to the browser's native controls; no suppressed prompt or extra onboarding.
    return true;
}

// Auto-initialize when running in browser
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => initPwa(window), { once: true });
    } else {
        initPwa(window);
    }
}
