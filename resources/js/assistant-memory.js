// Only random temporary capability tokens/pathnames enter tab storage, never form fields or audio.
const key = 'accounting-assistant-form';
const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
let active;
const load = () => {
    try {
        const value = JSON.parse(sessionStorage.getItem(key) || 'null');
        return value && uuid.test(value.token) && ['/invoices/create', '/purchases/create'].includes(value.path) ? value : null;
    } catch { return null; }
};
const forget = () => {
    const previous = active;
    active = null;
    try { sessionStorage.removeItem(key); } catch { /* tab storage may be unavailable */ }
    if (!previous) return;
    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    if (!csrf) return; // Server TTL remains fail closed if delivery is unavailable.
    const data = new FormData();
    data.append('token', previous.token); data.append('_token', csrf);
    if (navigator.sendBeacon?.('/assistant/memory/discard', data)) return;
    fetch('/assistant/memory/discard', { method: 'POST', credentials: 'same-origin', body: data, keepalive: true }).catch(() => {});
};
const sameForm = (target) => {
    const url = new URL(target, window.location.href);
    return url.origin === window.location.origin && url.pathname === active?.path && url.searchParams.get('assistant') === active?.token;
};
const start = () => {
    active = load();
    if (active && !sameForm(window.location.href)) forget();
    const url = new URL(window.location.href);
    const token = url.searchParams.get('assistant');
    if (['/invoices/create', '/purchases/create'].includes(url.pathname) && uuid.test(token || '')) {
        active = { token, path: url.pathname };
        try { sessionStorage.setItem(key, JSON.stringify(active)); } catch { /* encrypted server TTL still applies */ }
    }
};
document.addEventListener('click', (event) => {
    const link = event.target?.closest?.('a[href]');
    if (!active || !link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey
        || link.target === '_blank' || link.hasAttribute('download')) return;
    if (!sameForm(link.href)) forget();
});
document.addEventListener('submit', (event) => {
    if (!active || event.defaultPrevented) return;
    const path = new URL(event.target.action || window.location.href, window.location.href).pathname;
    if (['/company/switch', '/logout'].includes(path)) forget();
});
document.addEventListener('livewire:navigating', (event) => {
    if (active && event.detail?.url && !sameForm(event.detail.url)) forget();
});
window.navigation?.addEventListener('navigate', (event) => {
    const target = new URL(event.destination.url, window.location.href);
    // The same-origin locale POST redirects back to this form; it is not a new workspace.
    if (target.origin === window.location.origin && target.pathname === '/locale'
        && ['ar', 'en'].includes(event.formData?.get('locale'))) return;
    if (active && !sameForm(event.destination.url)) forget();
});
document.addEventListener('livewire:navigated', start);
start();
// A closed/crashed/offline tab cannot guarantee beacon delivery; fixed encrypted-state TTL is the final boundary.
