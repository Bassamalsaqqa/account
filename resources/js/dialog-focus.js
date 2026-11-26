const pendingTriggers = new WeakMap();
export function rememberDialogTrigger(trigger, doc = document) {
    const root = trigger?.closest('[wire\\:id]');
    if (root) pendingTriggers.set(root, {trigger, href: doc.location.href, at: Date.now()});
}

export function captureDialogReturnTarget(dialog, doc = document) {
    const root = dialog.closest('[wire\\:id]');
    const pending = root ? pendingTriggers.get(root) : null;
    if (root) pendingTriggers.delete(root);
    let trigger = doc.activeElement;
    // wire:loading disabled the opener before the response mounted this dialog.
    if ((trigger === doc.body || dialog.contains(trigger)) && pending?.href === doc.location.href && Date.now() - pending.at <= 60000) trigger = pending.trigger;
    if (!trigger || trigger === doc.body || dialog.contains(trigger)) return null;
    return {trigger, id: trigger.id, root, href: doc.location.href};
}

export function restoreDialogReturnTarget(saved, doc = document, frame = requestAnimationFrame) {
    if (!saved) return;
    // Alpine trap teardown and Livewire's morph finish before this bounded return.
    frame(() => frame(() => {
        if (doc.location.href !== saved.href || (saved.root && !saved.root.isConnected)) return;
        const trigger = saved.id ? doc.getElementById(saved.id) : saved.trigger;
        if (!trigger?.isConnected || (saved.root && !saved.root.contains(trigger)) ||
            trigger.disabled || trigger.getAttribute('aria-disabled') === 'true' ||
            trigger.closest('[inert]') || !trigger.getClientRects().length) return;
        if (doc.activeElement !== doc.body && doc.activeElement !== saved.trigger && doc.activeElement !== trigger) return;
        trigger.focus({preventScroll: true});
    }));
}

if (typeof window !== 'undefined') {
    window.captureDialogReturnTarget = captureDialogReturnTarget;
    window.restoreDialogReturnTarget = restoreDialogReturnTarget;
    document.addEventListener('click', event => {
        const trigger = event.target.closest('button[wire\\:click], a[wire\\:click]');
        if (trigger) rememberDialogTrigger(trigger);
    }, true);
    document.addEventListener('livewire:navigating', () => {
        // Detached component roots are weakly held; route identity also guards return.
        for (const root of document.querySelectorAll('[wire\\:id]')) pendingTriggers.delete(root);
    });
}
