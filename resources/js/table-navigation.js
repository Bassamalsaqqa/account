// Delegate once: works with Livewire morphs without observers or per-row listeners.
const interactive = 'a,button,input,select,textarea,label,summary,[role="button"],[role="checkbox"],[contenteditable="true"]';

function rowLink(event) {
    if (!(event.target instanceof Element) || event.target.closest(interactive)) return null;
    const row = event.target.closest('tbody tr');
    if (!row || row.closest('[data-entry-form]')) return null;
    const links = [...row.querySelectorAll('a[data-row-link]'), ...row.querySelectorAll('a[href]:not([data-row-link])')];
    return links.find(link => {
        const url = new URL(link.href, location.href);
        return !link.hasAttribute('download') && link.target !== '_blank'
            && url.origin === location.origin && /^https?:$/.test(url.protocol)
            && url.pathname !== location.pathname && !link.closest('[data-row-navigation="off"]');
    }) || null;
}

document.addEventListener('click', event => {
    if (event.defaultPrevented || event.button !== 0 || window.getSelection()?.toString()) return;
    const link = rowLink(event);
    if (!link) return;
    if (event.ctrlKey || event.metaKey || event.shiftKey) {
        window.open(link.href, '_blank', 'noopener');
    } else {
        link.click();
    }
});
