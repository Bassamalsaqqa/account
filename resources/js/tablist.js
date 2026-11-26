const pendingTabFocus = new WeakMap();
let livewireTabHookRegistered = false;

export function isRtlTablist(tablist, doc = document) {
    const scopedDir = tablist?.closest?.('[dir]')?.getAttribute?.('dir')?.toLowerCase();
    if (scopedDir === 'rtl' || scopedDir === 'ltr') {
        return scopedDir === 'rtl';
    }

    const view = tablist?.ownerDocument?.defaultView || doc?.defaultView;
    if (view && typeof view.getComputedStyle === 'function' && tablist) {
        try {
            const direction = view.getComputedStyle(tablist).direction;
            if (direction === 'rtl' || direction === 'ltr') {
                return direction === 'rtl';
            }
        } catch {
            // Synthetic unit-test stubs may not represent real DOM Nodes.
        }
    }

    return doc?.documentElement?.dir === 'rtl' || doc?.body?.dir === 'rtl';
}

export function rememberTabFocusTarget(tab, tablist, doc = document) {
    const root = tablist?.closest?.('[wire\\:id]');
    if (!root || !tab?.id) return null;
    const saved = {
        id: tab.id,
        root,
        href: doc?.location?.href ?? '',
        at: Date.now(),
    };
    pendingTabFocus.set(root, saved);
    return saved;
}

export function consumePendingTabFocusTarget(root, doc = document) {
    if (!root) return null;
    const saved = pendingTabFocus.get(root);
    pendingTabFocus.delete(root);
    if (!saved || (doc?.location?.href ?? '') !== saved.href || Date.now() - saved.at > 15000) {
        return null;
    }
    return saved;
}

export function restoreTabFocusTarget(saved, doc = document, frame = requestAnimationFrame) {
    if (!saved) return;
    frame(() => frame(() => {
        if ((doc?.location?.href ?? '') !== saved.href || (saved.root && !saved.root.isConnected)) return;
        const target = saved.id ? doc.getElementById(saved.id) : null;
        if (!target?.isConnected || (saved.root && !saved.root.contains(target)) ||
            target.disabled || target.getAttribute?.('aria-disabled') === 'true' ||
            target.closest?.('[inert]') || !target.getClientRects?.().length) return;
        if (doc.activeElement && doc.activeElement !== doc.body && doc.activeElement !== target) return;
        target.focus?.({preventScroll: true});
    }));
}

function registerLivewireTabFocusHook() {
    if (livewireTabHookRegistered || typeof window === 'undefined' || typeof window.Livewire === 'undefined') {
        return;
    }
    livewireTabHookRegistered = true;
    window.Livewire.hook('commit', ({component, succeed, fail}) => {
        const root = component?.el;
        const saved = root ? consumePendingTabFocusTarget(root) : null;
        if (!saved) return;
        succeed(() => restoreTabFocusTarget(saved));
        fail(() => restoreTabFocusTarget(saved));
    });
}

export function handleTablistKeydown(event, tablist, doc = document) {
    if (!tablist || !event) return false;

    const tabs = Array.from(tablist.querySelectorAll('[role="tab"]'))
        .filter(tab => !tab.disabled && tab.getAttribute?.('aria-disabled') !== 'true');
    if (tabs.length === 0) return false;

    const active = doc?.activeElement;
    const currentIdx = tabs.indexOf(active);
    if (currentIdx === -1) return false;

    const isRtl = isRtlTablist(tablist, doc);
    let nextIdx = currentIdx;

    switch (event.key) {
        case 'ArrowRight':
            nextIdx = isRtl
                ? (currentIdx - 1 + tabs.length) % tabs.length
                : (currentIdx + 1) % tabs.length;
            break;
        case 'ArrowLeft':
            nextIdx = isRtl
                ? (currentIdx + 1) % tabs.length
                : (currentIdx - 1 + tabs.length) % tabs.length;
            break;
        case 'Home':
            nextIdx = 0;
            break;
        case 'End':
            nextIdx = tabs.length - 1;
            break;
        default:
            return false;
    }

    event.preventDefault();
    const target = tabs[nextIdx];
    registerLivewireTabFocusHook();
    rememberTabFocusTarget(target, tablist, doc);
    target?.focus?.();
    target?.click?.();
    return true;
}

if (typeof window !== 'undefined') {
    window.handleTablistKeydown = handleTablistKeydown;
    if (typeof document !== 'undefined') {
        document.addEventListener('livewire:init', registerLivewireTabFocusHook);
        document.addEventListener('livewire:navigating', () => {
            for (const root of document.querySelectorAll('[wire\\:id]')) {
                pendingTabFocus.delete(root);
            }
        });
    }
}
