// Load only on explicit user action; keep the underlying draft untouched on failure.
export async function launchProblemReporter(trigger, load = () => import('./problem-reporter'), doc = document) {
    try {
        const mod = await load();
        await mod.openProblemReporter(trigger);
        doc.getElementById('problem-reporter-load-error')?.remove();
        return true;
    } catch {
        let fallback = doc.getElementById('problem-reporter-load-error');
        if (!fallback) {
            fallback = doc.createElement('p');
            fallback.id = 'problem-reporter-load-error';
            fallback.setAttribute('role', 'alert');
            fallback.className = 'p-3 text-sm text-danger bg-surface border border-danger rounded-control';
            trigger.insertAdjacentElement('afterend', fallback);
        }
        const ar = doc.documentElement.lang === 'ar';
        const ref = doc.querySelector('meta[name="app-request-id"]')?.content;
        fallback.textContent = ar ? 'تعذّر فتح الإبلاغ. تحقق من الاتصال وحاول مجدداً.' : 'Could not open reporting. Check your connection and retry.';
        if (/^req_[0-9a-f]{32}$/.test(ref || '')) fallback.textContent += ' ' + (ar ? 'المرجع: ' : 'Reference: ') + ref;
        return false;
    }
}
