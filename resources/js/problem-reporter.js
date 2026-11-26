// Lazy-loaded web problem reporter modal; zero network/DB overhead unless opened.
const REQ_ID_RE = /^req_[0-9a-f]{32}$/;

let draftState = {
    category: 'other',
    note: '',
    includeDiagnostics: true,
    idempotencyKey: null,
};

let activeDialog = null;
let lastTrigger = null;
let listenersBound = false;

export function createIdempotencyKey(cryptoObj = globalThis.crypto) {
    if (cryptoObj && typeof cryptoObj.randomUUID === 'function') {
        return cryptoObj.randomUUID();
    }
    const bytes = new Uint8Array(16);
    if (cryptoObj && typeof cryptoObj.getRandomValues === 'function') {
        cryptoObj.getRandomValues(bytes);
    } else {
        for (let i = 0; i < 16; i += 1) {
            bytes[i] = Math.floor(Math.random() * 256);
        }
    }
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

export function detectViewportBucket(width = globalThis.innerWidth) {
    if (typeof width !== 'number' || !Number.isFinite(width)) return 'desktop';
    if (width < 640) return 'mobile';
    if (width < 1024) return 'tablet';
    return 'desktop';
}

export function sanitizeObservedFailure(raw) {
    if (!raw || typeof raw !== 'object') return null;
    const requestId = typeof raw.requestId === 'string' && REQ_ID_RE.test(raw.requestId) ? raw.requestId : null;
    const status = Number.isInteger(raw.status) && raw.status >= 400 && raw.status <= 599 ? raw.status : null;
    if (!requestId && !status) return null;
    return { requestId, status };
}

export function buildReportPayload({
    idempotencyKey,
    category,
    note,
    includeDiagnostics,
    routeName,
    pageRequestId,
    observedFailure,
    viewport,
}) {
    const payload = {
        idempotency_key: idempotencyKey,
        category: category || 'other',
        note: String(note || '').trim(),
        include_diagnostics: Boolean(includeDiagnostics),
    };

    if (!payload.include_diagnostics) {
        return payload;
    }

    const screen = {};
    if (typeof routeName === 'string' && /^[a-zA-Z0-9._-]{1,120}$/.test(routeName.trim())) {
        screen.route_name = routeName.trim();
    }
    if (typeof pageRequestId === 'string' && REQ_ID_RE.test(pageRequestId.trim())) {
        screen.page_request_id = pageRequestId.trim();
    }
    const cleanFailure = sanitizeObservedFailure(observedFailure);
    if (cleanFailure) {
        screen.livewire_failed = true;
        if (cleanFailure.requestId) screen.failed_request_id = cleanFailure.requestId;
        if (cleanFailure.status) screen.failed_status = cleanFailure.status;
    }
    if (viewport === 'mobile' || viewport === 'tablet' || viewport === 'desktop') {
        screen.viewport = viewport;
    }

    payload.screen = screen;
    return payload;
}

export function resetProblemReporterState() {
    draftState = {
        category: 'other',
        note: '',
        includeDiagnostics: true,
        idempotencyKey: null,
    };
}

function bindGlobalResetListeners() {
    if (listenersBound || typeof document === 'undefined') return;
    listenersBound = true;
    const clearTransient = () => {
        if (typeof window !== 'undefined') {
            window.__appLastFailedRequest = null;
        }
        resetProblemReporterState();
        if (activeDialog?.open) {
            activeDialog.close();
        }
    };
    document.addEventListener('livewire:navigating', clearTransient);
    window.addEventListener('pagehide', clearTransient);
}

function readMeta(name) {
    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content') || '';
}

function ensureDialog() {
    if (activeDialog && activeDialog.isConnected) return activeDialog;
    const tpl = document.getElementById('problem-reporter-template');
    if (!tpl || !('content' in tpl)) return null;

    const fragment = tpl.content.cloneNode(true);
    document.body.appendChild(fragment);
    const dialog = document.getElementById('problem-reporter-dialog');
    if (!dialog) return null;

    const form = dialog.querySelector('[data-pr-form]');
    const categoryEl = dialog.querySelector('[data-pr-category]');
    const noteEl = dialog.querySelector('[data-pr-note]');
    const counterEl = dialog.querySelector('[data-pr-count]');
    const toggleEl = dialog.querySelector('[data-pr-include]');
    const includedTextEl = dialog.querySelector('[data-pr-included-text]');
    const metaPreviewEl = dialog.querySelector('[data-pr-meta-preview]');
    const errorEl = dialog.querySelector('[data-pr-error]');
    const successEl = dialog.querySelector('[data-pr-success]');
    const refEl = dialog.querySelector('[data-pr-ref]');
    const copyBtn = dialog.querySelector('[data-pr-copy]');
    const submitBtn = dialog.querySelector('[data-pr-submit]');
    const submitLabelEl = dialog.querySelector('[data-pr-submit-label]');
    const resetBtn = dialog.querySelector('[data-pr-another]');
    let isSubmitting = false;

    const syncUi = () => {
        const len = noteEl.value.length;
        counterEl.textContent = `${len} / 1000`;
        const included = toggleEl.checked;
        includedTextEl.textContent = included
            ? dialog.dataset.includedWith || ''
            : dialog.dataset.includedWithout || '';

        const routeName = readMeta('app-route-name');
        const pageReq = readMeta('app-request-id');
        const failure = sanitizeObservedFailure(window.__appLastFailedRequest);
        if (included) {
            const parts = [];
            if (routeName) parts.push(routeName);
            if (pageReq) parts.push(pageReq);
            if (failure?.requestId) parts.push(`failed:${failure.requestId}`);
            else if (failure?.status) parts.push(`HTTP ${failure.status}`);
            metaPreviewEl.textContent = parts.join(' · ');
            metaPreviewEl.hidden = parts.length === 0;
        } else {
            metaPreviewEl.textContent = '';
            metaPreviewEl.hidden = true;
        }
    };

    const showError = (msg) => {
        errorEl.textContent = msg;
        errorEl.hidden = false;
    };

    const clearError = () => {
        errorEl.textContent = '';
        errorEl.hidden = true;
    };

    const invalidateDraftKey = () => {
        draftState.idempotencyKey = createIdempotencyKey();
    };

    categoryEl.addEventListener('change', () => {
        draftState.category = categoryEl.value;
        invalidateDraftKey();
    });

    noteEl.addEventListener('input', () => {
        draftState.note = noteEl.value;
        invalidateDraftKey();
        clearError();
        syncUi();
    });

    toggleEl.addEventListener('change', () => {
        draftState.includeDiagnostics = toggleEl.checked;
        invalidateDraftKey();
        syncUi();
    });

    const closeSafely = () => {
        if (isSubmitting) return;
        if (dialog.open) dialog.close();
    };

    dialog.addEventListener('cancel', (event) => {
        if (isSubmitting) {
            event.preventDefault();
            return;
        }
        closeSafely();
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) closeSafely();
    });

    dialog.querySelectorAll('[data-pr-close]').forEach((btn) => {
        btn.addEventListener('click', closeSafely);
    });

    dialog.addEventListener('close', () => {
        const fallback = document.querySelector('[data-report-problem-trigger]:not([inert])')
            || document.getElementById('app-main')
            || document.getElementById('platform-main');
        const target = lastTrigger && lastTrigger.isConnected && !lastTrigger.closest('[inert]') && lastTrigger.getClientRects().length > 0
            ? lastTrigger
            : fallback;
        target?.focus?.();
    });

    copyBtn?.addEventListener('click', async () => {
        const ref = refEl.textContent.trim();
        if (!ref) return;
        try {
            await navigator.clipboard.writeText(ref);
            const prev = copyBtn.textContent;
            copyBtn.textContent = dialog.dataset.copiedLabel || 'Copied';
            setTimeout(() => { copyBtn.textContent = prev; }, 1500);
        } catch {}
    });

    resetBtn?.addEventListener('click', () => {
        resetProblemReporterState();
        draftState.idempotencyKey = createIdempotencyKey();
        categoryEl.value = draftState.category;
        noteEl.value = '';
        toggleEl.checked = true;
        successEl.hidden = true;
        form.hidden = false;
        clearError();
        syncUi();
        noteEl.focus();
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (isSubmitting) return;
        clearError();

        const trimmed = noteEl.value.trim();
        if (trimmed.length < 5) {
            showError(dialog.dataset.noteShortError || 'Please enter at least 5 characters.');
            noteEl.focus();
            return;
        }

        if (!draftState.idempotencyKey) {
            draftState.idempotencyKey = createIdempotencyKey();
        }

        const payload = buildReportPayload({
            idempotencyKey: draftState.idempotencyKey,
            category: categoryEl.value,
            note: trimmed,
            includeDiagnostics: toggleEl.checked,
            routeName: readMeta('app-route-name'),
            pageRequestId: readMeta('app-request-id'),
            observedFailure: window.__appLastFailedRequest,
            viewport: detectViewportBucket(window.innerWidth),
        });

        isSubmitting = true;
        submitBtn.disabled = true;
        const origLabel = submitLabelEl.textContent;
        submitLabelEl.textContent = dialog.dataset.submittingLabel || origLabel;

        try {
            const csrf = readMeta('csrf-token');
            const res = await fetch(dialog.dataset.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(payload),
            });

            if (res.status === 429) {
                showError(dialog.dataset.rateError || dialog.dataset.genericError);
                return;
            }
            if (res.status === 401 || res.status === 419) {
                showError(dialog.dataset.sessionError || dialog.dataset.genericError);
                return;
            }

            const data = await res.json().catch(() => null);
            if (!res.ok || !data || typeof data.public_ref !== 'string') {
                const reqRef = res.headers?.get?.('X-Request-Id');
                const baseMsg = data?.errors?.note?.[0] || data?.message || dialog.dataset.genericError;
                showError(reqRef ? `${baseMsg} (${reqRef})` : baseMsg);
                return;
            }

            refEl.textContent = data.public_ref;
            form.hidden = true;
            successEl.hidden = false;
            resetProblemReporterState();
            copyBtn?.focus();
        } catch {
            showError(dialog.dataset.genericError || 'Submission failed.');
        } finally {
            isSubmitting = false;
            submitBtn.disabled = false;
            submitLabelEl.textContent = origLabel;
        }
    });

    dialog._syncProblemReporter = () => {
        categoryEl.value = draftState.category;
        noteEl.value = draftState.note;
        toggleEl.checked = draftState.includeDiagnostics;
        if (!draftState.idempotencyKey) {
            draftState.idempotencyKey = createIdempotencyKey();
        }
        successEl.hidden = true;
        form.hidden = false;
        clearError();
        syncUi();
    };

    activeDialog = dialog;
    return dialog;
}

export function openProblemReporter(triggerEl = null) {
    bindGlobalResetListeners();
    lastTrigger = triggerEl || document.activeElement;
    const dialog = ensureDialog();
    if (!dialog) return;
    dialog._syncProblemReporter?.();
    if (!dialog.open) {
        dialog.showModal();
    }
    const focusTarget = dialog.querySelector('[data-pr-note]');
    focusTarget?.focus();
}
