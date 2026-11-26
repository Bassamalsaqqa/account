const queues = new Map();
const allowed = new Set(['scanCameraBarcode', 'incrementQuantity', 'decrementQuantity', 'stepQuantity', 'addLine', 'saveDraft', 'saveAndConfirm', 'save', 'stepInventoryQuantity', 'stepReturnQuantity', 'returnFullRemaining', 'payRemaining', 'useFullBalance']);
const identityFields = ['row_key', 'id', 'product_id', 'product_unit_id', 'item_description', 'unit_price', 'unit_cost', 'discount_type', 'discount_value', 'tax_rate_id', 'tax_inclusive', 'lot_id', 'lots', 'lot_allocations', 'warehouse_id', 'purchase_line_id', 'sales_invoice_line_id'];
const keyFor = wire => wire.$id || wire.id || wire;
const copy = value => JSON.parse(JSON.stringify(value));
const linesFor = wire => wire.lines ?? wire.$get?.('lines') ?? wire.get?.('lines');
const lineFor = (wire, index) => {
    const all = linesFor(wire);
    return Array.isArray(all) ? all[index] : (wire.$get?.(`lines.${index}`) ?? wire.get?.(`lines.${index}`));
};
const resolveLiveLineIndex = (wire, fallbackIndex, rowKey = null) => {
    if (rowKey !== null && rowKey !== undefined && rowKey !== '') {
        const all = linesFor(wire);
        if (Array.isArray(all)) {
            return all.findIndex(line => line && String(line.row_key ?? '') === String(rowKey));
        }
    }
    return fallbackIndex;
};
const identityFor = line => Object.fromEntries(identityFields.map(key => [key, line?.[key] ?? null]));
const same = (first, second) => JSON.stringify(first) === JSON.stringify(second);
const error = message => new Error(message);
function stateFor(wire) {
    const key = keyFor(wire);
    if (!queues.has(key)) queues.set(key, {wire, jobs: [], active: null, open: null, blocked: false, failed: [], confirmed: new Map()});
    return queues.get(key);
}
function announce(state) {
    if (typeof window !== 'undefined') window.dispatchEvent(new CustomEvent('document-operation-state', {detail: {id: state.wire.$id, busy: isDocumentBusy(state.wire), blocked: state.blocked}}));
}
export function isDocumentBusy(wire) {
    const state = queues.get(keyFor(wire));
    return Boolean(state && (state.active || state.jobs.length));
}
export function isDocumentBlocked(wire) { return Boolean(queues.get(keyFor(wire))?.blocked); }
function seal(state) {
    const job = state.open;
    if (!job) return;
    clearTimeout(job.timer);
    job.ready = true;
    state.open = null;
}
function settle(job, method, result) { for (const deferred of job.waiters) deferred[method](result); }
function focusSubmissionError(wire) {
    if (typeof window === 'undefined' || !window.requestAnimationFrame) return;
    // Livewire morphing and the caller's saving=false must finish before focus.
    window.requestAnimationFrame(() => window.requestAnimationFrame(() => {
        const root = wire.$el;
        if (!root?.querySelector('[data-form-attention]')) return;
        const field = [...root.querySelectorAll('[aria-invalid=true]')].find(el => el.getClientRects().length && !el.disabled);
        const target = field ?? root.querySelector('[data-form-attention]');
        target?.focus();
        target?.scrollIntoView({block: 'center', behavior: 'instant'});
    }));
}
function quarantine(state, job, failure) {
    state.blocked = true;
    state.failed = [job];
    seal(state);
    for (const queued of state.jobs.splice(0)) {
        if (queued.kind === 'quantity') state.failed.push(queued);
        settle(queued, 'reject', error('Document operation blocked; review pending quantity changes'));
    }
    settle(job, 'reject', failure);
    announce(state);
}
async function drain(state) {
    if (state.active || state.blocked || !state.jobs[0]?.ready) return;
    const job = state.jobs.shift();
    state.active = job;
    announce(state);
    try {
        let result;
        if (job.kind === 'quantity') {
            const current = lineFor(state.wire, job.index);
            if (!current || !same(identityFor(current), job.identity)) throw error('Line changed; review pending quantity changes');
            const confirmedKey = `${job.identity?.row_key || job.index}:${job.field || 'quantity'}`;
            const confirmed = state.confirmed.get(confirmedKey);
            const expected = confirmed && same(confirmed.identity, job.identity) ? confirmed.quantity : job.expected;
            // A timeout is an ambiguous outcome, not permission to cancel/replay the edit.
            // Keep the actual request active so a late response cannot race recovery.
            job.timeout = setTimeout(() => {
                job.timedOut = true;
                quarantine(state, job, error('Quantity confirmation timed out; wait for the current request before reviewing'));
            }, job.timeoutMs);
            result = await state.wire.batchStepQuantity(job.index, expected, job.identity, [...job.directions], job.field || 'quantity');
            if (!result?.ok || typeof result.quantity !== 'string') throw error(result?.message || 'Quantity change refused; review the line');
            state.confirmed.set(confirmedKey, {identity: job.identity, quantity: result.quantity});
        } else {
            result = await state.wire[job.method](...job.args);
            state.confirmed.clear();
        }
        settle(job, 'resolve', result);
    } catch (failure) {
        if (!job.timedOut) quarantine(state, job, failure);
        else settle(job, 'reject', failure);
    } finally {
        clearTimeout(job.timeout);
        state.active = null;
        announce(state);
        if (job.kind !== 'quantity' && ['save', 'saveDraft', 'saveAndConfirm'].includes(job.method)) focusSubmissionError(state.wire);
        if (!state.blocked && !state.jobs.length) queues.delete(keyFor(state.wire));
        else void drain(state);
    }
}
function waiter(job) { return new Promise((resolve, reject) => job.waiters.push({resolve, reject})); }
export function stepExactString(str, direction, quantum = '1', min = '0', max = null) {
    if (typeof quantum === 'boolean') {
        quantum = quantum ? '0.01' : '1';
    }
    str = String(str ?? '').trim();
    if (!str) str = '0';

    const cleanNumber = s => {
        s = String(s ?? '').trim();
        return s === '' ? '0' : s;
    };

    const qStr = cleanNumber(quantum);
    const minStr = cleanNumber(min);
    const maxStr = max !== null && max !== undefined && String(max).trim() !== '' ? cleanNumber(max) : null;

    const getDecimals = s => {
        const dot = s.indexOf('.');
        return dot === -1 ? 0 : s.length - dot - 1;
    };

    const scale = Math.max(getDecimals(str), getDecimals(qStr), getDecimals(minStr), maxStr ? getDecimals(maxStr) : 0);

    const toBigIntScaled = (s, targetScale) => {
        const isNeg = s.startsWith('-');
        if (isNeg) s = s.slice(1);
        const dot = s.indexOf('.');
        let whole = dot === -1 ? s : s.slice(0, dot);
        let frac = dot === -1 ? '' : s.slice(dot + 1);
        if (frac.length < targetScale) {
            frac = frac.padEnd(targetScale, '0');
        } else if (frac.length > targetScale) {
            frac = frac.slice(0, targetScale);
        }
        const combined = (whole || '0') + frac;
        try {
            const b = BigInt(combined);
            return isNeg ? -b : b;
        } catch {
            return 0n;
        }
    };

    const currentVal = toBigIntScaled(str, scale);
    const stepVal = toBigIntScaled(qStr, scale);
    const minVal = toBigIntScaled(minStr, scale);
    const maxVal = maxStr !== null ? toBigIntScaled(maxStr, scale) : null;

    let nextVal = direction === 'up' ? currentVal + stepVal : currentVal - stepVal;
    if (nextVal < minVal) {
        nextVal = minVal;
    }
    if (maxVal !== null && nextVal > maxVal) {
        nextVal = maxVal;
    }

    if (scale === 0) {
        return nextVal.toString();
    }

    const isNeg = nextVal < 0n;
    const absVal = isNeg ? -nextVal : nextVal;
    const padded = absVal.toString().padStart(scale + 1, '0');
    const wholePart = padded.slice(0, padded.length - scale);
    const fracPart = padded.slice(padded.length - scale);
    return `${isNeg ? '-' : ''}${wholePart}.${fracPart}`;
}

export function flushQuantityBatch(wire) {
    const state = queues.get(keyFor(wire));
    if (state) { seal(state); void drain(state); }
}
export function enqueueQuantityStep(wire, index, direction, options = {}) {
    const requestedIndex = Number.isInteger(index) ? index : Number(index ?? -1);
    const liveIndex = resolveLiveLineIndex(wire, requestedIndex, options.rowKey ?? null);
    if (!wire || !Number.isSafeInteger(liveIndex) || liveIndex < 0 || !['up', 'down'].includes(direction)) return Promise.reject(error('Invalid quantity operation'));
    const state = stateFor(wire);
    if (state.blocked) return Promise.reject(error('Document operation blocked; review pending quantity changes'));
    const line = lineFor(wire, liveIndex);
    const field = options.field || 'quantity';
    const currentQty = line?.[field];
    if (!line || typeof currentQty !== 'string') return Promise.reject(error('Invalid quantity line'));
    const identity = copy(identityFor(line));
    let job = state.open;
    if (!job || job.index !== liveIndex || job.field !== field || !same(job.identity, identity) || job.directions.length >= 100) {
        if (state.jobs.length >= 10) return Promise.reject(error('Document queue full'));
        seal(state);
        job = {kind: 'quantity', field, index: liveIndex, expected: currentQty, identity, directions: [], waiters: [], ready: false, timer: null, created: Date.now(), timeoutMs: Math.min(30000, Math.max(1, Number(options.timeoutMs) || 30000))};
        state.jobs.push(job);
        state.open = job;
    }
    job.directions.push(direction);
    const promise = waiter(job);
    clearTimeout(job.timer);
    const delay = Math.min(150, Math.max(0, options.debounceMs ?? 100), Math.max(0, 300 - (Date.now() - job.created)));
    if (job.directions.length === 100) seal(state);
    else job.timer = setTimeout(() => { if (state.open === job) seal(state); void drain(state); }, delay);
    announce(state);
    void drain(state);
    return promise;
}
export function enqueueDocumentOperation(wire, method, args = []) {
    if (!wire || !allowed.has(method)) return Promise.reject(error('Invalid document operation'));
    const state = stateFor(wire);
    if (state.blocked) return Promise.reject(error('Document operation blocked; review pending quantity changes'));
    if (state.jobs.length >= 10) return Promise.reject(error('Document queue full'));
    seal(state);
    const job = {kind: 'operation', method, args, waiters: [], ready: true};
    const promise = waiter(job);
    state.jobs.push(job);
    announce(state);
    void drain(state);
    return promise;
}
// Explicit recovery never automatically replays an ambiguous request or a queued Save.
// The user first reviews the still-visible confirmed quantity and chooses to discard unconfirmed taps.
export async function resyncDocumentQueue(wire, refreshOnly = false) {
    const state = queues.get(keyFor(wire));
    if (!state?.blocked) return;
    if (state.active || state.jobs.length) throw error('Wait for the current request to finish');
    if (refreshOnly) await wire.$refresh();
    else await wire.recalculate();
    state.failed = [];
    state.confirmed.clear();
    state.blocked = false;
    queues.delete(keyFor(wire));
    announce(state);
}
export function touchQuantityStepper(wire, config = {}) {
    const fallbackIndex = Number.isInteger(config.index) ? config.index : Number(config.index ?? 0);
    const configuredRowKey = config.rowKey ?? null;
    const quantum = config.quantum ?? '1';
    const min = config.min ?? '0';
    const max = config.max ?? null;
    const field = config.field || 'quantity';
    const baseOptions = { field };
    if (config.debounceMs !== undefined) baseOptions.debounceMs = config.debounceMs;
    if (config.timeoutMs !== undefined) baseOptions.timeoutMs = config.timeoutMs;
    if (configuredRowKey !== null && configuredRowKey !== undefined && configuredRowKey !== '') {
        baseOptions.rowKey = configuredRowKey;
    }

    return {
        pending: 0,
        failed: false,
        recovering: false,
        optimisticQty: null,
        confirmedQty: null,
        batchEpoch: 0,
        tap(direction) {
            const input = this.$refs?.qtyInput;
            const liveDomIndex = Number.isInteger(Number(this.$refs?.stepperMeta?.dataset?.lineIndex))
                ? Number(this.$refs.stepperMeta.dataset.lineIndex)
                : fallbackIndex;
            const activeRowKey = this.$refs?.stepperMeta?.dataset?.rowKey || configuredRowKey;
            const targetIndex = resolveLiveLineIndex(wire, liveDomIndex, activeRowKey || null);
            if (!Number.isSafeInteger(targetIndex) || targetIndex < 0) {
                this.failed = true;
                return Promise.reject(error('Line changed; review pending quantity changes'));
            }
            const stepOptions = activeRowKey ? { ...baseOptions, rowKey: activeRowKey } : baseOptions;
            this.batchEpoch = (this.batchEpoch || 0) + 1;
            const currentEpoch = this.batchEpoch;
            if (!this.optimisticQty) {
                const currentValue = input ? input.value : '';
                this.optimisticQty = currentValue;
                this.confirmedQty = currentValue;
            }
            this.optimisticQty = stepExactString(
                this.optimisticQty,
                direction,
                quantum,
                min,
                max
            );
            if (input) {
                input.value = this.optimisticQty;
            }
            this.pending++;
            return enqueueQuantityStep(wire, targetIndex, direction, stepOptions)
                .then(result => {
                    if (this.batchEpoch !== currentEpoch) return;
                    if (result && typeof result.quantity === 'string') {
                        this.confirmedQty = result.quantity;
                    }
                })
                .catch(() => {
                    if (this.batchEpoch !== currentEpoch) return;
                    this.failed = true;
                    if (this.confirmedQty !== null && input) {
                        input.value = this.confirmedQty;
                    }
                })
                .finally(() => {
                    this.pending--;
                    if (this.pending === 0 && this.batchEpoch === currentEpoch) {
                        if (!this.failed && this.confirmedQty !== null && input) {
                            input.value = this.confirmedQty;
                        }
                        this.optimisticQty = null;
                    }
                });
        },
        onInputFocus() {
            if (this.pending > 0) {
                flushQuantityBatch(wire);
            }
        },
        onInputTyped() {
            this.batchEpoch = (this.batchEpoch || 0) + 1;
            this.optimisticQty = null;
            this.confirmedQty = null;
        },
        onOperationState(detail) {
            if (!detail || detail.id == null || detail.id !== wire?.$id) return;
            if (detail.blocked) this.failed = true;
            else if (!detail.busy) this.failed = false;
        },
        async resync() {
            this.recovering = true;
            try {
                await resyncDocumentQueue(wire);
                this.failed = false;
            } catch {
                this.failed = true;
            } finally {
                this.recovering = false;
            }
        },
    };
}
if (typeof window !== 'undefined') {
    Object.assign(window, {enqueueDocumentOperation, enqueueQuantityStep, isDocumentBusy, isDocumentBlocked, resyncDocumentQueue, flushQuantityBatch, stepExactString, touchQuantityStepper});
    const register = () => {
        if (window.Alpine && typeof window.Alpine.data === 'function') {
            window.Alpine.data('touchQuantityStepper', touchQuantityStepper);
        }
    };
    if (window.Alpine && typeof window.Alpine.data === 'function') register();
    else if (typeof document !== 'undefined') document.addEventListener('alpine:init', register, { once: true });
}
